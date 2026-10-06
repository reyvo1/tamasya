#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOG="$ROOT/tests/uat_rc1/logs"; mkdir -p "$LOG"
MYSQL_ROOT=(mysql --protocol=TCP -h127.0.0.1 -P3306 -uroot -proot_ci_only)
DUMP=/tmp/tamasya-efc-hq-property-seed.sql
P1_DB=tamasya_rc1_hq_prop1; P2_DB=tamasya_rc1_hq_prop2; HQ_DB=tamasya_rc1_hq
P1_CFG=/tmp/tamasya-efc-hq-p1-db.php; P2_CFG=/tmp/tamasya-efc-hq-p2-db.php; HQ_CFG=/tmp/tamasya-efc-hq-config.json
COMPANY=company-rc1-uat; P1_ID=hotel-hq-01; P2_ID=hotel-hq-02
SECRET='efc-hq-property-secret-0123456789abcdef0123456789'
VIEWER='efc-hq-viewer-token-0123456789abcdef'
read -r HQ_INTERNAL_PORT HQ_TLS_PORT P1_PORT P2_PORT EPHEMERAL_LOW EPHEMERAL_HIGH < <(python3 - <<'PYPORTS'
import socket
from pathlib import Path
try:
    low, high = map(int, Path('/proc/sys/net/ipv4/ip_local_port_range').read_text().split())
except Exception:
    low, high = 32768, 60999
start = 12000
stop = min(30000, low - 1)
if stop - start < 4:
    start, stop = 1024, max(1028, low - 1)
held=[]; ports=[]
for port in range(start, stop + 1):
    sock=socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        sock.bind(('127.0.0.1', port))
    except OSError:
        sock.close(); continue
    held.append(sock); ports.append(port)
    if len(ports) == 4:
        break
if len(ports) != 4:
    raise SystemExit('No four free non-ephemeral loopback ports available for HQ UAT')
print(*ports, low, high)
for sock in held:
    sock.close()
PYPORTS
)
HQ_TLS="https://127.0.0.1:${HQ_TLS_PORT}"; P1_URL="http://127.0.0.1:${P1_PORT}"; P2_URL="http://127.0.0.1:${P2_PORT}"
printf '{"hqInternalPort":%s,"hqTlsPort":%s,"property1Port":%s,"property2Port":%s,"kernelEphemeralLow":%s,"kernelEphemeralHigh":%s}\n' "$HQ_INTERNAL_PORT" "$HQ_TLS_PORT" "$P1_PORT" "$P2_PORT" "$EPHEMERAL_LOW" "$EPHEMERAL_HIGH" > "$LOG/hq-ports.json"
cleanup(){
 for p in /tmp/tamasya-efc-hq-internal.pid /tmp/tamasya-efc-hq-tls.pid /tmp/tamasya-efc-hq-p1.pid /tmp/tamasya-efc-hq-p2.pid; do [[ -s "$p" ]] && kill "$(cat "$p")" 2>/dev/null || true; done
}
reset_trust(){ "${MYSQL_ROOT[@]}" -e "SET GLOBAL log_bin_trust_function_creators=0" >/dev/null 2>&1 || true; }
trap 'reset_trust; cleanup' EXIT

mysqldump --protocol=TCP -h127.0.0.1 -P3306 -uroot -proot_ci_only --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF tamasya_rc1_uat > "$DUMP"
"${MYSQL_ROOT[@]}" -e "DROP DATABASE IF EXISTS $P1_DB; DROP DATABASE IF EXISTS $P2_DB; DROP DATABASE IF EXISTS $HQ_DB; CREATE DATABASE $P1_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE DATABASE $P2_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE DATABASE $HQ_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL PRIVILEGES ON $P1_DB.* TO 'tamasya_ci'@'%'; GRANT ALL PRIVILEGES ON $P2_DB.* TO 'tamasya_ci'@'%'; GRANT ALL PRIVILEGES ON $HQ_DB.* TO 'tamasya_ci'@'%'; FLUSH PRIVILEGES; SET GLOBAL log_bin_trust_function_creators=1;"
"${MYSQL_ROOT[@]}" "$P1_DB" < "$DUMP"; "${MYSQL_ROOT[@]}" "$P2_DB" < "$DUMP"
"${MYSQL_ROOT[@]}" -e "SET GLOBAL log_bin_trust_function_creators=0"
for spec in "$P1_DB:$P1_ID:HQ01:TAMASYA HQ PROPERTY 1" "$P2_DB:$P2_ID:HQ02:TAMASYA HQ PROPERTY 2"; do
 IFS=: read -r db pid pcode pname <<<"$spec"
 "${MYSQL_ROOT[@]}" "$db" -e "UPDATE property_settings SET company_id='$COMPANY',property_id='$pid',property_code='$pcode',property_name='$pname',timezone='Asia/Makassar',currency='IDR',country_code='ID',locale='id-ID',invoice_prefix='$pcode' WHERE id='system_default'; DELETE FROM user_sessions; DELETE FROM request_operation_receipts; DELETE FROM node_cluster_events; DELETE FROM node_cluster_members; DELETE FROM node_cluster_state; UPDATE node_sync_settings SET node_id='online-primary',node_role='online_primary',sync_enabled=0,last_primary_revision=0,local_mutation_counter=0,last_mirrored_local_counter=0,mutation_guard_ready=0,mirror_in_progress=0,mirror_started_at=NULL,last_error=NULL WHERE id='system_default';"
done
cat >"$P1_CFG" <<PHP
<?php
\$db_host='127.0.0.1'; \$db_port=3306; \$db_name='$P1_DB'; \$db_user='tamasya_ci'; \$db_pass='tamasya_ci_only';
PHP
cat >"$P2_CFG" <<PHP
<?php
\$db_host='127.0.0.1'; \$db_port=3306; \$db_name='$P2_DB'; \$db_user='tamasya_ci'; \$db_pass='tamasya_ci_only';
PHP
chmod 600 "$P1_CFG" "$P2_CFG"
VIEWER_HASH="$(printf %s "$VIEWER" | sha256sum | awk '{print $1}')"
cat >"$HQ_CFG" <<JSON
{
  "dsn":"mysql:host=127.0.0.1;port=3306;dbname=$HQ_DB;charset=utf8mb4",
  "username":"tamasya_ci",
  "password":"tamasya_ci_only",
  "properties":{"$COMPANY":{"$P1_ID":{"enabled":true,"secret":"$SECRET"},"$P2_ID":{"enabled":true,"secret":"$SECRET"}}},
  "viewers":[{"enabled":true,"tokenSha256":"$VIEWER_HASH","companyId":"$COMPANY","propertyIds":["$P1_ID","$P2_ID"],"role":"company_admin","principalId":"efc-hq-viewer"}]
}
JSON
chmod 600 "$HQ_CFG"
TAMASYA_HQ_CONFIG_FILE="$HQ_CFG" php "$ROOT/hq/install.php" --apply-empty-hq-database | tee "$LOG/hq-install.log"

# Locally trusted TLS termination: production direct-push code keeps peer/hostname verification enabled.
CA_KEY=/tmp/tamasya-efc-ca.key; CA_CERT=/tmp/tamasya-efc-ca.crt; SRV_KEY=/tmp/tamasya-efc-hq.key; SRV_CSR=/tmp/tamasya-efc-hq.csr; SRV_CERT=/tmp/tamasya-efc-hq.crt; SRV_PEM=/tmp/tamasya-efc-hq.pem
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$CA_KEY" -out "$CA_CERT" -days 1 -subj '/CN=TAMASYA EFC Local CA' >/dev/null 2>&1
cat >/tmp/tamasya-efc-hq-openssl.cnf <<'EOF'
[req]
distinguished_name=dn
req_extensions=req_ext
prompt=no
[dn]
CN=127.0.0.1
[req_ext]
subjectAltName=@alt_names
[alt_names]
IP.1=127.0.0.1
EOF
openssl req -new -newkey rsa:2048 -nodes -keyout "$SRV_KEY" -out "$SRV_CSR" -config /tmp/tamasya-efc-hq-openssl.cnf >/dev/null 2>&1
openssl x509 -req -in "$SRV_CSR" -CA "$CA_CERT" -CAkey "$CA_KEY" -CAcreateserial -out "$SRV_CERT" -days 1 -extensions req_ext -extfile /tmp/tamasya-efc-hq-openssl.cnf >/dev/null 2>&1
cat "$SRV_KEY" "$SRV_CERT" > "$SRV_PEM"
sudo cp "$CA_CERT" /usr/local/share/ca-certificates/tamasya-efc-local-ca.crt
sudo update-ca-certificates >/dev/null

TAMASYA_HQ_CONFIG_FILE="$HQ_CFG" php -S "127.0.0.1:$HQ_INTERNAL_PORT" -t "$ROOT/hq" >"$LOG/hq-server.log" 2>&1 & echo $! >/tmp/tamasya-efc-hq-internal.pid
socat OPENSSL-LISTEN:"$HQ_TLS_PORT",reuseaddr,fork,cert="$SRV_PEM",cafile="$CA_CERT",verify=0 TCP:127.0.0.1:"$HQ_INTERNAL_PORT" >"$LOG/hq-tls.log" 2>&1 & echo $! >/tmp/tamasya-efc-hq-tls.pid

start_property(){
 local cfg="$1" db="$2" pid="$3" pcode="$4" pname="$5" url="$6" port="$7" outbox="$8" pidfile="$9" logfile="${10}"
 mkdir -p "$outbox"; chmod 700 "$outbox"
 env APP_CREDENTIALS_FILE="$cfg" APP_EXPECTED_DB_NAME="$db" APP_REQUIRE_EXPECTED_DB_NAME=1 APP_URL="$url" APP_ALLOWED_ORIGINS="$url" \
   TAMASYA_COMPANY_ID="$COMPANY" TAMASYA_PROPERTY_ID="$pid" TAMASYA_PROPERTY_CODE="$pcode" TAMASYA_PROPERTY_NAME="$pname" TAMASYA_PROPERTY_CURRENCY=IDR TAMASYA_PROPERTY_COUNTRY=ID \
   TAMASYA_NODE_ROLE=online_primary NODE_CLUSTER_ENABLED=0 NODE_SYNC_ENABLED=0 TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED=1 TAMASYA_HQ_BRIDGE_ENABLED=1 TAMASYA_HQ_ALLOW_WRITEBACK=0 TAMASYA_CROSS_PROPERTY_RESERVATION_ENABLED=0 \
   TAMASYA_HQ_HUB_URL="$HQ_TLS" TAMASYA_HQ_SHARED_SECRET="$SECRET" TAMASYA_HYBRID_OUTBOX_DIR="$outbox" \
   php -S "127.0.0.1:$port" -t "$ROOT" >"$logfile" 2>&1 & echo $! > "$pidfile"
}
start_property "$P1_CFG" "$P1_DB" "$P1_ID" HQ01 'TAMASYA HQ PROPERTY 1' "$P1_URL" "$P1_PORT" /tmp/tamasya-efc-hq-outbox-p1 /tmp/tamasya-efc-hq-p1.pid "$LOG/hq-property1.log"
start_property "$P2_CFG" "$P2_DB" "$P2_ID" HQ02 'TAMASYA HQ PROPERTY 2' "$P2_URL" "$P2_PORT" /tmp/tamasya-efc-hq-outbox-p2 /tmp/tamasya-efc-hq-p2.pid "$LOG/hq-property2.log"
wait_http_child(){
 local url="$1" pidfile="$2" logfile="$3"
 for i in {1..45}; do
   if curl -fsS "$url" >/dev/null; then return 0; fi
   if [[ ! -s "$pidfile" ]] || ! kill -0 "$(cat "$pidfile")" 2>/dev/null; then cat "$logfile"; return 1; fi
   sleep 1
 done
 cat "$logfile"; return 1
}
wait_http_child "$P1_URL/index.html" /tmp/tamasya-efc-hq-p1.pid "$LOG/hq-property1.log"
wait_http_child "$P2_URL/index.html" /tmp/tamasya-efc-hq-p2.pid "$LOG/hq-property2.log"
for i in {1..45}; do
  if curl --cacert "$CA_CERT" -fsS -H "Authorization: Bearer $VIEWER" "$HQ_TLS/api.php?action=overview" >/dev/null; then break; fi
  if ! kill -0 "$(cat /tmp/tamasya-efc-hq-internal.pid)" 2>/dev/null; then cat "$LOG/hq-server.log"; exit 1; fi
  if ! kill -0 "$(cat /tmp/tamasya-efc-hq-tls.pid)" 2>/dev/null; then cat "$LOG/hq-tls.log"; exit 1; fi
  sleep 1
done
curl --cacert "$CA_CERT" -fsS -H "Authorization: Bearer $VIEWER" "$HQ_TLS/api.php?action=overview" >/dev/null

EFC_HQ_PROPERTY1_URL="$P1_URL" EFC_HQ_PROPERTY2_URL="$P2_URL" EFC_HQ_URL="$HQ_TLS" EFC_HQ_VIEWER_TOKEN="$VIEWER" EFC_HQ_PROPERTY1_ID="$P1_ID" EFC_HQ_PROPERTY2_ID="$P2_ID" TAMASYA_COMPANY_ID="$COMPANY" python3 "$ROOT/tests/uat_rc1/scenario_hq_multi_property_complete.py"
