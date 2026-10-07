#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
STACK="${TAMASYA_PRD_STACK:-tamasya-prd-r3-runtime}"
PRIVATE="${TAMASYA_PRD_PRIVATE_DIR:-/tmp/tamasya-prd-r3-runtime}"
PORT="${TAMASYA_PRD_HTTP_PORT:-38190}"
IMAGE="${TAMASYA_IMAGE:-tamasya-prd-ci:local}"
DB_CONTAINER="${STACK}-db"
NETWORK="${STACK}_default"
MYSQL_IMAGE="${TAMASYA_PRD_MYSQL_IMAGE:-mysql:8.4@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242}"
export TAMASYA_STACK="$STACK" TAMASYA_PRIVATE_DIR="$PRIVATE" TAMASYA_HTTP_PORT="$PORT" TAMASYA_IMAGE="$IMAGE"
COMPOSE=(docker compose -f deploy/compose.yaml -f deploy/compose.saas.yaml)

mkdir -p "$PRIVATE"
cat >"$PRIVATE/runtime.env" <<ENV
APP_ENV=test
APP_DEBUG=0
APP_CREDENTIALS_FILE=/run/secrets/db_credentials.php
APP_URL=http://127.0.0.1:${PORT}
APP_EXPECTED_DB_NAME=tamasya_prd_saas
APP_REQUIRE_EXPECTED_DB_NAME=1
APP_TIMEZONE=Asia/Makassar
DB_TLS_REQUIRED=0
TAMASYA_COMPANY_ID=prd-ci-company
TAMASYA_PROPERTY_ID=prd-ci-property
TAMASYA_PROPERTY_CODE=PRDCI
TAMASYA_PROPERTY_NAME=TAMASYA PRD CI PROPERTY
TAMASYA_PROPERTY_CURRENCY=IDR
TAMASYA_PROPERTY_COUNTRY=ID
TAMASYA_PROPERTY_LOCALE=id-ID
TAMASYA_HYBRID_OUTBOX_DIR=/var/lib/tamasya/outbox
BACKUP_DIR=/var/lib/tamasya/backups
ENV
cat >"$PRIVATE/db_credentials.php" <<PHP
<?php
\$db_host='${DB_CONTAINER}';\$db_port=3306;\$db_name='tamasya_prd_saas';\$db_user='tamasya_ci';\$db_pass='tamasya-ci-only';
PHP

cleanup(){
  docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
  (cd "$ROOT" && "${COMPOSE[@]}" --profile workers down -v --remove-orphans >/dev/null 2>&1 || true)
}
trap cleanup EXIT
cd "$ROOT"

# Make repeated developer/CI executions deterministic; this is cleanup only.
# Correctness does not rely on it: runtime volumes use nocopy and a serialized
# one-shot storage initializer below.
"${COMPOSE[@]}" --profile workers down -v --remove-orphans >/dev/null 2>&1 || true

# Root-cause proof: create api+api2+web together on brand-new shared volumes.
# The storage-init dependency must serialize volume ownership/directory creation;
# no API replica is allowed to populate the Docker volume from its image layer.
"${COMPOSE[@]}" up -d --no-build api api2 web
init_id=$("${COMPOSE[@]}" ps -a -q storage-init)
[[ -n "$init_id" ]] || { echo "storage-init container missing"; exit 1; }
init_state=$(docker inspect -f '{{.State.Status}} {{.State.ExitCode}}' "$init_id")
[[ "$init_state" == "exited 0" ]] || { echo "storage-init failed: $init_state"; "${COMPOSE[@]}" logs storage-init; exit 1; }

for _ in $(seq 1 30); do docker network inspect "$NETWORK" >/dev/null 2>&1 && break; sleep .25; done
docker network inspect "$NETWORK" >/dev/null

docker run -d --rm --name "$DB_CONTAINER" --network "$NETWORK" \
  -e MYSQL_ROOT_PASSWORD=root-ci-only -e MYSQL_DATABASE=tamasya_prd_saas \
  "$MYSQL_IMAGE" >/dev/null

# Production-equivalent authority boundary: the disposable DBA/migration identity
# owns schema creation (including canonical triggers); the PHP runtime identity is
# created afterwards with data-plane grants only.  This intentionally reproduces
# the provision_property.php contract instead of letting the app user act as DBA.
bash tests/uat_prd/mysql-bootstrap-runtime-boundary.sh \
  "$DB_CONTAINER" tamasya_prd_saas root-ci-only tamasya_ci tamasya-ci-only \
  SELECT,INSERT,UPDATE,DELETE property database_setup.sql

TABLE_COUNT=$(docker exec -e MYSQL_PWD=tamasya-ci-only "$DB_CONTAINER" \
  mysql --protocol=TCP -h127.0.0.1 --batch --skip-column-names -utamasya_ci tamasya_prd_saas \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")
[[ "$TABLE_COUNT" -eq 113 ]] || { echo "unexpected canonical table count after migration-authority bootstrap: $TABLE_COUNT" >&2; exit 1; }
# Do not use the restricted runtime identity as trigger-authority evidence. MySQL
# intentionally hides trigger metadata when the account lacks TRIGGER privilege.
# mysql-bootstrap-runtime-boundary.sh already verified the exact trigger set and
# signatures with migration authority, then proved that the runtime identity
# cannot SHOW CREATE TRIGGER. Here runtime only proves its data-plane visibility.
RUNTIME_VISIBLE_TRIGGERS=$(docker exec -e MYSQL_PWD=tamasya-ci-only "$DB_CONTAINER" \
  mysql --protocol=TCP -h127.0.0.1 --batch --skip-column-names -utamasya_ci tamasya_prd_saas \
  -e "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE()")
echo "PASS canonical MySQL bootstrap by isolated migration authority; restricted runtime tables=$TABLE_COUNT visibleTriggerMetadata=$RUNTIME_VISIBLE_TRIGGERS (not used as schema authority)"

PING_BODY=/tmp/tamasya-prd-runtime-ping.json
PING_CODE=/tmp/tamasya-prd-runtime-ping.code
rm -f "$PING_BODY" "$PING_CODE"
ping_ready=0
for _ in $(seq 1 60); do
  code=$(curl -sS -o "$PING_BODY" -w '%{http_code}' "http://127.0.0.1:${PORT}/api.php?action=ping" || true)
  printf '%s' "$code" >"$PING_CODE"
  if [[ "$code" == "200" ]] && python3 - "$PING_BODY" <<'PY'
import json,sys
try: x=json.load(open(sys.argv[1],encoding='utf-8'))
except Exception: raise SystemExit(1)
raise SystemExit(0 if x.get('success') is True and x.get('liveness') is True and x.get('ready') is True else 1)
PY
  then ping_ready=1; break; fi
  sleep 1
done
if [[ "$ping_ready" != "1" ]]; then
  echo "SaaS API readiness failed after canonical DB bootstrap; last HTTP=$(cat "$PING_CODE" 2>/dev/null || echo none)" >&2
  echo '--- last API response body ---' >&2; cat "$PING_BODY" >&2 2>/dev/null || true; echo >&2
  echo '--- api/api2/web logs ---' >&2; "${COMPOSE[@]}" logs --tail=200 api api2 web >&2 || true
  exit 1
fi
python3 - "$PING_BODY" <<'PY'
import json,sys
x=json.load(open(sys.argv[1],encoding='utf-8'))
assert x.get('success') is True and x.get('liveness') is True and x.get('ready') is True, x
assert x.get('buildId')=='20261007-growth-kpi-r13', x
print('PASS SaaS routed API+MySQL ping',x.get('requestId'),x.get('serverRevision'))
PY

running=$("${COMPOSE[@]}" ps --status running --services | sort | tr '\n' ' ')
for service in api api2 web; do grep -qw "$service" <<<"$running" || { echo "missing running service $service"; exit 1; }; done

# Writable-boundary + shared-volume proof from the actual non-root API replicas.
"${COMPOSE[@]}" exec -T api sh -ceu '
  test "$(id -u)" != 0
  test -w /var/lib/tamasya/outbox
  test -w /var/lib/tamasya/backups
  test -w /var/www/tamasya/uploads/public-site
  if touch /var/www/tamasya/.prd-should-not-write 2>/dev/null; then exit 91; fi
  printf "%s\n" prd-r3-shared-state > /var/lib/tamasya/outbox/.prd-storage-proof
'
"${COMPOSE[@]}" exec -T api2 sh -ceu '
  test "$(cat /var/lib/tamasya/outbox/.prd-storage-proof)" = prd-r3-shared-state
  printf "%s" "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1\" height=\"1\"></svg>" > /var/www/tamasya/uploads/public-site/prd-r3-storage-proof.svg
'
curl -fsS "http://127.0.0.1:${PORT}/uploads/public-site/prd-r3-storage-proof.svg" | grep -F '<svg' >/dev/null

echo "PASS serialized nocopy storage init, non-root writable boundaries and cross-replica shared state"

PORT_ENV="$PORT" python3 - <<'PY'
import concurrent.futures,json,os,urllib.request
port=os.environ["PORT_ENV"]
def one(i):
    with urllib.request.urlopen(f"http://127.0.0.1:{port}/api.php?action=ping&probe={i}",timeout=10) as r:
        x=json.load(r)
    assert x.get("success") is True and x.get("ready") is True, x
    return x.get("requestId")
with concurrent.futures.ThreadPoolExecutor(max_workers=12) as ex:
    ids=list(ex.map(one,range(96)))
assert len(ids)==96 and all(ids), ids[:5]
print("PASS 96 concurrent DB-backed routed API reads")
PY

# Worker uses the same initialized private storage and canonical PHP authority.
"${COMPOSE[@]}" --profile workers up -d --no-build worker
for _ in $(seq 1 20); do
  worker_running=$("${COMPOSE[@]}" --profile workers ps --status running --services | grep -c '^worker$' || true)
  [[ "$worker_running" == "1" ]] && break
  sleep .5
done
"${COMPOSE[@]}" --profile workers ps --status running --services | grep -qx worker
"${COMPOSE[@]}" --profile workers exec -T worker sh -ceu '
  test "$(id -u)" != 0
  test -w /var/lib/tamasya/outbox
  test -w /var/lib/tamasya/backups
  test "$(cat /var/lib/tamasya/outbox/.prd-storage-proof)" = prd-r3-shared-state
'
echo "PASS worker shares initialized durable state without becoming a root runtime"

# Restart proof: storage contents must survive API/web/worker restarts and the
# initializer must remain a completed one-shot service rather than racing again.
"${COMPOSE[@]}" restart api api2 web >/dev/null
"${COMPOSE[@]}" --profile workers restart worker >/dev/null
for _ in $(seq 1 40); do curl -fsS "http://127.0.0.1:${PORT}/api.php?action=ping" >/dev/null 2>&1 && break; sleep .5; done
curl -fsS "http://127.0.0.1:${PORT}/api.php?action=ping" >/dev/null
"${COMPOSE[@]}" exec -T api sh -ceu 'test "$(cat /var/lib/tamasya/outbox/.prd-storage-proof)" = prd-r3-shared-state'
curl -fsS "http://127.0.0.1:${PORT}/uploads/public-site/prd-r3-storage-proof.svg" >/dev/null
init_state=$(docker inspect -f '{{.State.Status}} {{.State.ExitCode}}' "$init_id")
[[ "$init_state" == "exited 0" ]] || { echo "storage-init unexpectedly changed state: $init_state"; exit 1; }
echo "PASS durable state survives horizontal runtime restart without reinitialization race"

# Safe degradation: no request is replayed to a second upstream, but Nginx must
# quarantine a failed replica for subsequent requests and continue on the live one.
"${COMPOSE[@]}" stop api2 >/dev/null
ok=0; bad=0
for _ in $(seq 1 12); do
  code=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/api.php?action=ping" || true)
  if [[ "$code" == "200" ]]; then ok=$((ok+1)); else bad=$((bad+1)); fi
  sleep .15
done
[[ "$ok" -ge 9 ]] || { echo "healthy replica did not carry subsequent reads: ok=$ok bad=$bad"; exit 1; }
[[ "$bad" -le 3 ]] || { echo "too many failed reads after replica loss: ok=$ok bad=$bad"; exit 1; }
"${COMPOSE[@]}" start api2 >/dev/null
sleep 1
printf 'PASS one replica loss is quarantined for subsequent reads without enabling cross-upstream request replay (ok=%s bad=%s)\n' "$ok" "$bad"

code=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/deploy/README.md")
[[ "$code" == "403" || "$code" == "404" ]] || { echo "private deploy path exposed: $code"; exit 1; }
code=$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${PORT}/tests/")
[[ "$code" == "403" || "$code" == "404" ]] || { echo "tests path exposed: $code"; exit 1; }

echo "PASS horizontal SaaS PHP-FPM/Nginx/MySQL runtime: serialized storage init, api+api2+web+worker, 96 concurrent reads, restart persistence, replica-loss degradation and private-path denial"
