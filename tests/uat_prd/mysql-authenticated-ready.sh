#!/usr/bin/env bash
set -euo pipefail

CONTAINER="${1:?container name required}"
DB_NAME="${2:?database name required}"
DB_USER="${3:?database user required}"
DB_PASS="${4:?database password required}"
ATTEMPTS="${5:-60}"
SLEEP_SECONDS="${6:-1}"

case "$ATTEMPTS" in ''|*[!0-9]*) echo "invalid attempts: $ATTEMPTS" >&2; exit 64;; esac

query=(mysql --protocol=TCP -h127.0.0.1 --connect-timeout=3 --batch --skip-column-names -u"$DB_USER" "$DB_NAME" -e 'SELECT 1')

for i in $(seq 1 "$ATTEMPTS"); do
  if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
    echo "database container disappeared before readiness: $CONTAINER" >&2
    exit 70
  fi
  state="$(docker inspect -f '{{.State.Status}}' "$CONTAINER" 2>/dev/null || true)"
  if [[ "$state" == "exited" || "$state" == "dead" ]]; then
    echo "database container stopped before readiness: $CONTAINER state=$state" >&2
    docker logs "$CONTAINER" >&2 || true
    exit 71
  fi

  if docker exec -e MYSQL_PWD="$DB_PASS" "$CONTAINER" "${query[@]}" 2>/dev/null | grep -qx '1'; then
    echo "PASS authenticated MySQL readiness: container=$CONTAINER database=$DB_NAME user=$DB_USER attempt=$i"
    exit 0
  fi
  sleep "$SLEEP_SECONDS"
done

echo "authenticated MySQL readiness timed out: container=$CONTAINER database=$DB_NAME user=$DB_USER" >&2
docker logs "$CONTAINER" >&2 || true
exit 72
