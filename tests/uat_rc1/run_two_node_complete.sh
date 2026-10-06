#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOG="$ROOT/tests/uat_rc1/logs"
mkdir -p "$LOG"
MYSQL_A_ROOT=(mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot -proot_ci_only)
MYSQL_B_ROOT=(mysql --protocol=TCP -h127.0.0.1 -P3307 -uroot -proot_ci_only)
DUMP=/tmp/tamasya-efc-node-seed.sql
A_DB=tamasya_rc1_node_a
B_DB=tamasya_rc1_node_b
A_CFG=/tmp/tamasya-efc-node-a-db.php
B_CFG=/tmp/tamasya-efc-node-b-db.php
read -r A_PORT B_PORT A_BACKEND_PORT B_BACKEND_PORT A_RECOVERY_BACKEND_PORT EPHEMERAL_LOW EPHEMERAL_HIGH < <(python3 - <<'PYPORTS'
import socket
from pathlib import Path

try:
    low, high = map(int, Path('/proc/sys/net/ipv4/ip_local_port_range').read_text().split())
except Exception:
    low, high = 32768, 60999

# Public node listeners and one-shot PHP backend listeners come from verified
# free ports below the runner's current client-ephemeral range. Public URLs stay
# stable through outage/recovery by using a socat reuseaddr TCP proxy, while the
# recovered PHP process gets a fresh backend port and never fights TIME_WAIT.
start = 12000
stop = min(30000, low - 1)
if stop - start < 5:
    start, stop = 1024, max(1030, low - 1)

held=[]; ports=[]
for port in range(start, stop + 1):
    sock=socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        sock.bind(('127.0.0.1', port))
    except OSError:
        sock.close(); continue
    held.append(sock); ports.append(port)
    if len(ports) == 5:
        break
if len(ports) != 5:
    raise SystemExit('No five free non-ephemeral loopback ports available for two-node UAT')
print(*ports, low, high)
for sock in held:
    sock.close()
PYPORTS
)
A_URL="http://127.0.0.1:${A_PORT}"
B_URL="http://127.0.0.1:${B_PORT}"
printf '{"nodeAPublicPort":%s,"nodeBPublicPort":%s,"nodeAInitialBackendPort":%s,"nodeBBackendPort":%s,"nodeARecoveryBackendPort":%s,"kernelEphemeralLow":%s,"kernelEphemeralHigh":%s}\n' "$A_PORT" "$B_PORT" "$A_BACKEND_PORT" "$B_BACKEND_PORT" "$A_RECOVERY_BACKEND_PORT" "$EPHEMERAL_LOW" "$EPHEMERAL_HIGH" > "$LOG/two-node-ports.json"
SECRET='efc-two-node-shared-secret-0123456789abcdef'
CLUSTER='efc-two-node-hotel-cluster'

cleanup(){
  for p in /tmp/tamasya-efc-node-a.pid /tmp/tamasya-efc-node-a-proxy.pid /tmp/tamasya-efc-node-b.pid /tmp/tamasya-efc-node-b-proxy.pid; do
    if [[ -s "$p" ]]; then kill "$(cat "$p")" 2>/dev/null || true; fi
  done
}
reset_trust(){ "${MYSQL_A_ROOT[@]}" -e "SET GLOBAL log_bin_trust_function_creators=0" >/dev/null 2>&1 || true; "${MYSQL_B_ROOT[@]}" -e "SET GLOBAL log_bin_trust_function_creators=0" >/dev/null 2>&1 || true; }
trap 'reset_trust; cleanup' EXIT

mysqldump --protocol=TCP -h127.0.0.1 -P3306 -uroot -proot_ci_only --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF tamasya_rc1_uat > "$DUMP"
"${MYSQL_A_ROOT[@]}" -e "DROP DATABASE IF EXISTS $A_DB; CREATE DATABASE $A_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL PRIVILEGES ON $A_DB.* TO 'tamasya_ci'@'%'; FLUSH PRIVILEGES; SET GLOBAL log_bin_trust_function_creators=1;"
"${MYSQL_B_ROOT[@]}" -e "DROP DATABASE IF EXISTS $B_DB; CREATE DATABASE $B_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL PRIVILEGES ON $B_DB.* TO 'tamasya_ci'@'%'; FLUSH PRIVILEGES; SET GLOBAL log_bin_trust_function_creators=1;"
"${MYSQL_A_ROOT[@]}" "$A_DB" < "$DUMP"
"${MYSQL_B_ROOT[@]}" "$B_DB" < "$DUMP"
"${MYSQL_A_ROOT[@]}" -e "SET GLOBAL log_bin_trust_function_creators=0"
"${MYSQL_B_ROOT[@]}" -e "SET GLOBAL log_bin_trust_function_creators=0"

for spec in "A:$A_DB" "B:$B_DB"; do
  IFS=: read -r side db <<<"$spec"
  if [[ "$side" == A ]]; then MYSQL_NODE=("${MYSQL_A_ROOT[@]}"); else MYSQL_NODE=("${MYSQL_B_ROOT[@]}"); fi
  "${MYSQL_NODE[@]}" "$db" -e "DELETE FROM node_cluster_events; DELETE FROM node_cluster_members; DELETE FROM node_cluster_state; DELETE FROM node_sync_outbox; DELETE FROM node_sync_conflicts; DELETE FROM node_sync_runs; DELETE FROM node_sync_nonces; DELETE FROM node_sync_receipts; UPDATE node_sync_settings SET node_id='uninitialized',node_role='local_backup',sync_enabled=1,last_primary_revision=0,local_mutation_counter=0,last_mirrored_local_counter=0,mutation_guard_ready=0,mirror_in_progress=0,mirror_started_at=NULL,local_security_initialized=0,last_push_at=NULL,last_pull_at=NULL,last_success_at=NULL,last_error=NULL WHERE id='system_default'; DELETE FROM request_operation_receipts; DELETE FROM user_sessions;"
done
cat >"$A_CFG" <<PHP
<?php
\$db_host='127.0.0.1'; \$db_port=3306; \$db_name='$A_DB'; \$db_user='tamasya_ci'; \$db_pass='tamasya_ci_only';
PHP
cat >"$B_CFG" <<PHP
<?php
\$db_host='127.0.0.1'; \$db_port=3307; \$db_name='$B_DB'; \$db_user='tamasya_ci'; \$db_pass='tamasya_ci_only';
PHP
chmod 600 "$A_CFG" "$B_CFG"

start_node(){
  local id="$1" kind="$2" initial="$3" self="$4" peer_id="$5" peer="$6" cfg="$7" db="$8" public_port="$9" backend_port="${10}" pid="${11}" proxy_pid="${12}" log="${13}" proxy_log="${14}"
  env APP_CREDENTIALS_FILE="$cfg" APP_EXPECTED_DB_NAME="$db" APP_REQUIRE_EXPECTED_DB_NAME=1 APP_URL="$self" APP_ALLOWED_ORIGINS="$self" \
    TAMASYA_NODE_MODE=flexible NODE_CLUSTER_ENABLED=1 NODE_SYNC_ENABLED=1 TAMASYA_CLUSTER_ID="$CLUSTER" TAMASYA_NODE_ID="$id" TAMASYA_NODE_KIND="$kind" TAMASYA_NODE_INITIAL_ROLE="$initial" \
    NODE_CLUSTER_PUBLIC_URL="$self" NODE_CLUSTER_PEER_ID="$peer_id" NODE_CLUSTER_PEER_URL="$peer" NODE_SYNC_PRIMARY_URL="$peer" NODE_SYNC_SELF_URL="$self" \
    NODE_SYNC_FORWARD_WHEN_ONLINE=1 NODE_SYNC_ALLOW_HTTP_LOCAL=1 NODE_SYNC_SHARED_SECRET="$SECRET" TAMASYA_ALLOWED_NODE_IDS="$peer_id" NODE_CLUSTER_LEASE_TTL_SECONDS=300 NODE_CLUSTER_LEASE_RENEW_WINDOW_SECONDS=90 \
    php -S "127.0.0.1:$backend_port" -t "$ROOT" >"$log" 2>&1 &
  echo $! > "$pid"
  socat TCP-LISTEN:"$public_port",reuseaddr,fork TCP:127.0.0.1:"$backend_port" >"$proxy_log" 2>&1 &
  echo $! > "$proxy_pid"
}
wait_node(){
  local url="$1" log="$2" pidfile="$3" proxy_log="$4" proxy_pidfile="$5"
  for i in {1..45}; do
    if curl -fsS "$url/index.html" >/dev/null; then return 0; fi
    if [[ ! -s "$pidfile" ]] || ! kill -0 "$(cat "$pidfile")" 2>/dev/null; then cat "$log"; return 1; fi
    if [[ ! -s "$proxy_pidfile" ]] || ! kill -0 "$(cat "$proxy_pidfile")" 2>/dev/null; then cat "$proxy_log"; return 1; fi
    sleep 1
  done
  cat "$log"; cat "$proxy_log"; return 1
}
stop_node_a(){
  local php_pid proxy_pid
  php_pid="$(cat /tmp/tamasya-efc-node-a.pid)"; proxy_pid="$(cat /tmp/tamasya-efc-node-a-proxy.pid)"
  kill "$proxy_pid" "$php_pid"
  wait "$proxy_pid" 2>/dev/null || true
  wait "$php_pid" 2>/dev/null || true
  rm -f /tmp/tamasya-efc-node-a.pid /tmp/tamasya-efc-node-a-proxy.pid
  for i in {1..30}; do
    if ! curl -fsS "$A_URL/index.html" >/dev/null 2>&1; then return 0; fi
    sleep .1
  done
  echo "Node A public endpoint remained reachable after owned proxy/backend shutdown" >&2
  return 1
}
node_env(){
  local id="$1" kind="$2" initial="$3" self="$4" peer_id="$5" peer="$6" cfg="$7" db="$8"
  shift 8
  env APP_CREDENTIALS_FILE="$cfg" APP_EXPECTED_DB_NAME="$db" APP_REQUIRE_EXPECTED_DB_NAME=1 APP_URL="$self" APP_ALLOWED_ORIGINS="$self" \
    TAMASYA_NODE_MODE=flexible NODE_CLUSTER_ENABLED=1 NODE_SYNC_ENABLED=1 TAMASYA_CLUSTER_ID="$CLUSTER" TAMASYA_NODE_ID="$id" TAMASYA_NODE_KIND="$kind" TAMASYA_NODE_INITIAL_ROLE="$initial" \
    NODE_CLUSTER_PUBLIC_URL="$self" NODE_CLUSTER_PEER_ID="$peer_id" NODE_CLUSTER_PEER_URL="$peer" NODE_SYNC_PRIMARY_URL="$peer" NODE_SYNC_SELF_URL="$self" \
    NODE_SYNC_FORWARD_WHEN_ONLINE=1 NODE_SYNC_ALLOW_HTTP_LOCAL=1 NODE_SYNC_SHARED_SECRET="$SECRET" TAMASYA_ALLOWED_NODE_IDS="$peer_id" NODE_CLUSTER_LEASE_TTL_SECONDS=300 NODE_CLUSTER_LEASE_RENEW_WINDOW_SECONDS=90 "$@"
}

start_node efc-node-a local primary "$A_URL" efc-node-b "$B_URL" "$A_CFG" "$A_DB" "$A_PORT" "$A_BACKEND_PORT" /tmp/tamasya-efc-node-a.pid /tmp/tamasya-efc-node-a-proxy.pid "$LOG/two-node-a.log" "$LOG/two-node-a-proxy.log"
start_node efc-node-b hosting standby "$B_URL" efc-node-a "$A_URL" "$B_CFG" "$B_DB" "$B_PORT" "$B_BACKEND_PORT" /tmp/tamasya-efc-node-b.pid /tmp/tamasya-efc-node-b-proxy.pid "$LOG/two-node-b.log" "$LOG/two-node-b-proxy.log"
wait_node "$A_URL" "$LOG/two-node-a.log" /tmp/tamasya-efc-node-a.pid "$LOG/two-node-a-proxy.log" /tmp/tamasya-efc-node-a-proxy.pid; wait_node "$B_URL" "$LOG/two-node-b.log" /tmp/tamasya-efc-node-b.pid "$LOG/two-node-b-proxy.log" /tmp/tamasya-efc-node-b-proxy.pid
# Warm primary state, then let standby adopt exact fencing/lease and mirror canonical data.
curl -fsS "$A_URL/api.php?action=ping" >/dev/null
node_env efc-node-b hosting standby "$B_URL" efc-node-a "$A_URL" "$B_CFG" "$B_DB" php "$ROOT/node_sync_agent.php" | tee "$LOG/two-node-agent-initial.json"
EFC_NODE_A_URL="$A_URL" EFC_NODE_B_URL="$B_URL" EFC_NODE_A_DB_CONFIG="$A_CFG" EFC_NODE_B_DB_CONFIG="$B_CFG" EFC_NODE_A_DB_NAME="$A_DB" EFC_NODE_B_DB_NAME="$B_DB" python3 "$ROOT/tests/uat_rc1/scenario_two_node_hybrid_complete.py" --phase online-forward

# True primary outage: stop A; standby must stay fenced and refuse local business writes.
stop_node_a
EFC_NODE_A_URL="$A_URL" EFC_NODE_B_URL="$B_URL" EFC_NODE_A_DB_CONFIG="$A_CFG" EFC_NODE_B_DB_CONFIG="$B_CFG" EFC_NODE_A_DB_NAME="$A_DB" EFC_NODE_B_DB_NAME="$B_DB" python3 "$ROOT/tests/uat_rc1/scenario_two_node_hybrid_complete.py" --phase primary-outage

# Recover A, mirror to B, perform checksum-gated planned switchover A -> B.
start_node efc-node-a local primary "$A_URL" efc-node-b "$B_URL" "$A_CFG" "$A_DB" "$A_PORT" "$A_RECOVERY_BACKEND_PORT" /tmp/tamasya-efc-node-a.pid /tmp/tamasya-efc-node-a-proxy.pid "$LOG/two-node-a-recovered.log" "$LOG/two-node-a-proxy-recovered.log"
wait_node "$A_URL" "$LOG/two-node-a-recovered.log" /tmp/tamasya-efc-node-a.pid "$LOG/two-node-a-proxy-recovered.log" /tmp/tamasya-efc-node-a-proxy.pid
node_env efc-node-b hosting standby "$B_URL" efc-node-a "$A_URL" "$B_CFG" "$B_DB" php "$ROOT/node_sync_agent.php" | tee "$LOG/two-node-agent-recovery.json"
EFC_NODE_A_URL="$A_URL" EFC_NODE_B_URL="$B_URL" EFC_NODE_A_DB_CONFIG="$A_CFG" EFC_NODE_B_DB_CONFIG="$B_CFG" EFC_NODE_A_DB_NAME="$A_DB" EFC_NODE_B_DB_NAME="$B_DB" python3 "$ROOT/tests/uat_rc1/scenario_two_node_hybrid_complete.py" --phase planned-switch

# A is now standby: run its agent against B and prove reverse mirror convergence.
node_env efc-node-a local primary "$A_URL" efc-node-b "$B_URL" "$A_CFG" "$A_DB" php "$ROOT/node_sync_agent.php" | tee "$LOG/two-node-agent-mirror-back.json"
EFC_NODE_A_URL="$A_URL" EFC_NODE_B_URL="$B_URL" EFC_NODE_A_DB_CONFIG="$A_CFG" EFC_NODE_B_DB_CONFIG="$B_CFG" EFC_NODE_A_DB_NAME="$A_DB" EFC_NODE_B_DB_NAME="$B_DB" python3 "$ROOT/tests/uat_rc1/scenario_two_node_hybrid_complete.py" --phase final
