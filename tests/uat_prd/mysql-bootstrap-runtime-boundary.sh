#!/usr/bin/env bash
set -euo pipefail

CONTAINER="${1:?container name required}"
DB_NAME="${2:?database name required}"
ROOT_PASS="${3:?root password required}"
RUNTIME_USER="${4:?runtime user required}"
RUNTIME_PASS="${5:?runtime password required}"
RUNTIME_PRIVS="${6:?runtime privileges required}"
AUTHORITY_PROFILE="${7:?authority profile required: property|hq}"
shift 7
SCHEMA_FILES=("$@")

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]] || { echo "unsafe database identifier: $DB_NAME" >&2; exit 64; }
[[ "$RUNTIME_USER" =~ ^[A-Za-z0-9_]{1,64}$ ]] || { echo "unsafe runtime user identifier: $RUNTIME_USER" >&2; exit 64; }
[[ "$RUNTIME_PASS" =~ ^[A-Za-z0-9._-]{8,128}$ ]] || { echo "CI runtime password contains unsupported characters" >&2; exit 64; }
case "$RUNTIME_PRIVS" in
  SELECT,INSERT,UPDATE,DELETE|SELECT,INSERT,UPDATE) ;;
  *) echo "unsupported runtime privilege profile: $RUNTIME_PRIVS" >&2; exit 64;;
esac
case "$AUTHORITY_PROFILE" in
  property|hq) ;;
  *) echo "unsupported authority profile: $AUTHORITY_PROFILE (expected property|hq)" >&2; exit 64;;
esac
[[ ${#SCHEMA_FILES[@]} -ge 1 ]] || { echo "at least one schema file is required" >&2; exit 64; }
for schema in "${SCHEMA_FILES[@]}"; do
  [[ -f "$schema" && -r "$schema" ]] || { echo "schema file not readable: $schema" >&2; exit 66; }
done

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
READY="$ROOT/tests/uat_prd/mysql-authenticated-ready.sh"

# Schema/bootstrap authority is deliberately separate from application runtime.
# Root here represents a disposable CI migration/DBA authority; its credential is
# never mounted into the PHP-FPM, worker, realtime or HQ runtime containers.
"$READY" "$CONTAINER" "$DB_NAME" root "$ROOT_PASS" 60 1

for schema in "${SCHEMA_FILES[@]}"; do
  echo "Applying schema as isolated migration authority: $schema"
  docker exec -i -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 --connect-timeout=5 -uroot "$DB_NAME" < "$schema"
done

# Strict schema verification belongs to the migration/DBA authority, not the
# DML-only runtime identity. MySQL hides information_schema.TRIGGERS from users
# without TRIGGER privilege, so asking the runtime account to count triggers
# creates a false "missing trigger" failure even when the canonical triggers are
# present. Verify exact fresh tables + trigger signatures here, while privileged
# metadata visibility is intentionally available, then discard that authority.
VERIFY="$ROOT/tests/uat_prd/mysql-canonical-authority-verify.py"
VERIFY_TMP="$(mktemp -d)"
cleanup_verify(){ rm -rf "$VERIFY_TMP"; }
trap cleanup_verify RETURN

docker exec -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
  mysql --protocol=TCP -h127.0.0.1 --connect-timeout=5 -uroot "$DB_NAME" --batch --skip-column-names \
  -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME" \
  >"$VERIFY_TMP/tables.tsv"
docker exec -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
  mysql --protocol=TCP -h127.0.0.1 --connect-timeout=5 -uroot "$DB_NAME" --batch --skip-column-names \
  -e "SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME" \
  >"$VERIFY_TMP/triggers.tsv"
python3 "$VERIFY" --tables "$VERIFY_TMP/tables.tsv" --triggers "$VERIFY_TMP/triggers.tsv" "${SCHEMA_FILES[@]}"
rm -rf "$VERIFY_TMP"
trap - RETURN

# Property PMS and HQ are separate schema authorities.  Only the property PMS
# owns schema_release_state and the canonical database_setup.sql attestation.
# HQ must never be forced through property-specific metadata simply because both
# use the same least-privilege bootstrap harness.
if [[ "$AUTHORITY_PROFILE" == "property" ]]; then
  IFS=$'\t' read -r SOURCE_RELEASE SOURCE_PATCH SOURCE_CHECKSUM < <(php -r '
  require "release_contract.php";
  echo TAMASYA_SCHEMA_RELEASE, "\t", TAMASYA_PATCH_LEVEL, "\t", tamasyaCanonicalDatabaseSourceChecksum(), "\n";
  ')
  [[ "$SOURCE_RELEASE" =~ ^[A-Za-z0-9._:-]{1,100}$ ]] || { echo "unsafe source release identity" >&2; exit 79; }
  [[ "$SOURCE_PATCH" =~ ^[A-Za-z0-9._:-]{1,150}$ ]] || { echo "unsafe source patch identity" >&2; exit 79; }
  [[ "$SOURCE_CHECKSUM" =~ ^[a-f0-9]{64}$ ]] || { echo "invalid canonical source checksum" >&2; exit 79; }
  MIGRATION_RUN_ID="authority_${SOURCE_CHECKSUM:0:16}"
  docker exec -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 --connect-timeout=5 -uroot "$DB_NAME" -e "
      UPDATE schema_release_state
         SET current_release='${SOURCE_RELEASE}',
             patch_level='${SOURCE_PATCH}',
             source_checksum='${SOURCE_CHECKSUM}',
             migration_run_id='${MIGRATION_RUN_ID}',
             maintenance_required=0,
             updated_at=CURRENT_TIMESTAMP
       WHERE id='system_default';
    "
  ATTESTATION="$(docker exec -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 --batch --skip-column-names -uroot "$DB_NAME" \
    -e "SELECT CONCAT(current_release,'|',patch_level,'|',COALESCE(source_checksum,''),'|',COALESCE(migration_run_id,''),'|',maintenance_required) FROM schema_release_state WHERE id='system_default'")"
  [[ "$ATTESTATION" == "${SOURCE_RELEASE}|${SOURCE_PATCH}|${SOURCE_CHECKSUM}|${MIGRATION_RUN_ID}|0" ]] || {
    echo "migration authority attestation verification failed: $ATTESTATION" >&2; exit 79;
  }
  echo "PASS property migration-authority release attestation: release=$SOURCE_RELEASE patch=$SOURCE_PATCH checksum=$SOURCE_CHECKSUM run=$MIGRATION_RUN_ID"
else
  # HQ has its own schema lifecycle and deliberately does not contain the PMS
  # schema_release_state table.  Preserve source provenance in the UAT evidence
  # by hashing the exact ordered schema set instead of inventing PMS metadata.
  SOURCE_SET_DIGEST="$(python3 - "${SCHEMA_FILES[@]}" <<'PYDIGEST'
import hashlib,sys
h=hashlib.sha256()
for raw in sys.argv[1:]:
    name=raw.encode('utf-8')
    data=open(raw,'rb').read()
    h.update(len(name).to_bytes(4,'big')); h.update(name)
    h.update(len(data).to_bytes(8,'big')); h.update(data)
print(h.hexdigest())
PYDIGEST
)"
  [[ "$SOURCE_SET_DIGEST" =~ ^[a-f0-9]{64}$ ]] || { echo "invalid HQ schema source-set digest" >&2; exit 79; }
  echo "PASS HQ migration-authority schema source-set digest: $SOURCE_SET_DIGEST"
fi

# Create the runtime identity only after strict schema bootstrap verification,
# then grant the minimum
# data-plane privileges needed by this runtime profile.  No CREATE/ALTER/DROP,
# TRIGGER, GRANT OPTION or global administrative privilege is granted.
docker exec -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
  mysql --protocol=TCP -h127.0.0.1 --connect-timeout=5 -uroot "$DB_NAME" -e "
    CREATE USER IF NOT EXISTS '${RUNTIME_USER}'@'%' IDENTIFIED BY '${RUNTIME_PASS}';
    ALTER USER '${RUNTIME_USER}'@'%' IDENTIFIED BY '${RUNTIME_PASS}';
    REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${RUNTIME_USER}'@'%';
    GRANT ${RUNTIME_PRIVS} ON \`${DB_NAME}\`.* TO '${RUNTIME_USER}'@'%';
  "

"$READY" "$CONTAINER" "$DB_NAME" "$RUNTIME_USER" "$RUNTIME_PASS" 30 1

if docker exec -e MYSQL_PWD=definitely-wrong "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 --connect-timeout=3 -u"$RUNTIME_USER" "$DB_NAME" -e 'SELECT 1' >/dev/null 2>&1; then
  echo "runtime account unexpectedly accepted a wrong password" >&2
  exit 73
fi

GRANTS="$(docker exec -e MYSQL_PWD="$RUNTIME_PASS" "$CONTAINER" \
  mysql --protocol=TCP -h127.0.0.1 --batch --skip-column-names -u"$RUNTIME_USER" "$DB_NAME" -e "SHOW GRANTS FOR CURRENT_USER")"
printf '%s\n' "$GRANTS"

for forbidden in 'ALL PRIVILEGES' 'CREATE' 'ALTER' 'DROP' 'TRIGGER' 'GRANT OPTION' 'SUPER'; do
  if grep -Eiq "(^|[ ,])${forbidden}([ ,]|$)" <<<"$GRANTS"; then
    echo "runtime account contains forbidden privilege: $forbidden" >&2
    exit 74
  fi
done

# Prove the privilege boundary behaviorally as well as by SHOW GRANTS.  A runtime
# credential must not be able to mutate schema even though it can read/write data.
probe="__tamasya_runtime_ddl_probe"
if docker exec -e MYSQL_PWD="$RUNTIME_PASS" "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 --connect-timeout=3 -u"$RUNTIME_USER" "$DB_NAME" \
    -e "CREATE TABLE ${probe}(id INT PRIMARY KEY)" >/dev/null 2>&1; then
  docker exec -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 -uroot "$DB_NAME" -e "DROP TABLE IF EXISTS ${probe}" >/dev/null 2>&1 || true
  echo "runtime account unexpectedly created schema objects" >&2
  exit 75
fi

if docker exec -e MYSQL_PWD="$RUNTIME_PASS" "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 --connect-timeout=3 -u"$RUNTIME_USER" "$DB_NAME" \
    -e "SET GLOBAL log_bin_trust_function_creators=1" >/dev/null 2>&1; then
  echo "runtime account unexpectedly changed a global database variable" >&2
  exit 76
fi

# HQ runtime intentionally omits DELETE. Prove the restriction behaviorally on a
# real imported table with a no-op predicate; the statement must be rejected at
# privilege evaluation before any row could be changed.
if [[ "$AUTHORITY_PROFILE" == "hq" ]]; then
  FIRST_RUNTIME_TABLE="$(docker exec -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 --batch --skip-column-names -uroot "$DB_NAME" \
    -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME LIMIT 1")"
  [[ "$FIRST_RUNTIME_TABLE" =~ ^[A-Za-z0-9_$]{1,64}$ ]] || { echo "invalid HQ runtime table probe identifier" >&2; exit 76; }
  if docker exec -e MYSQL_PWD="$RUNTIME_PASS" "$CONTAINER" \
      mysql --protocol=TCP -h127.0.0.1 --connect-timeout=3 -u"$RUNTIME_USER" "$DB_NAME" \
      -e "DELETE FROM \`${FIRST_RUNTIME_TABLE}\` WHERE 1=0" >/dev/null 2>&1; then
    echo "HQ runtime account unexpectedly has DELETE privilege on $FIRST_RUNTIME_TABLE" >&2
    exit 76
  fi
  echo "PASS HQ runtime DELETE remains denied on $FIRST_RUNTIME_TABLE"
fi

# If the imported schema contains triggers, prove the DML-only runtime cannot
# inspect their definitions and prove the migration authority still sees every
# trigger afterwards. This catches accidental TRIGGER grant escalation without
# confusing metadata invisibility with schema loss.
EXPECTED_TRIGGER_COUNT="$(python3 - "${SCHEMA_FILES[@]}" <<'PYCOUNT'
import re,sys
names=set()
for raw in sys.argv[1:]:
    text=open(raw,encoding='utf-8').read()
    names.update(re.findall(r'^\s*CREATE\s+TRIGGER\s+`([^`]+)`',text,re.I|re.M))
print(len(names))
PYCOUNT
)"
if [[ "$EXPECTED_TRIGGER_COUNT" -gt 0 ]]; then
  FIRST_TRIGGER="$(python3 - "${SCHEMA_FILES[@]}" <<'PYFIRST'
import re,sys
for raw in sys.argv[1:]:
    text=open(raw,encoding='utf-8').read()
    m=re.search(r'^\s*CREATE\s+TRIGGER\s+`([^`]+)`',text,re.I|re.M)
    if m:
        print(m.group(1));break
PYFIRST
)"
  if docker exec -e MYSQL_PWD="$RUNTIME_PASS" "$CONTAINER" \
      mysql --protocol=TCP -h127.0.0.1 --connect-timeout=3 -u"$RUNTIME_USER" "$DB_NAME" \
      -e "SHOW CREATE TRIGGER \`$FIRST_TRIGGER\`" >/dev/null 2>&1; then
    echo "runtime account unexpectedly has trigger-definition visibility: $FIRST_TRIGGER" >&2
    exit 77
  fi
  AUTH_TRIGGER_COUNT="$(docker exec -e MYSQL_PWD="$ROOT_PASS" "$CONTAINER" \
    mysql --protocol=TCP -h127.0.0.1 --connect-timeout=5 -uroot "$DB_NAME" --batch --skip-column-names \
    -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")"
  [[ "$AUTH_TRIGGER_COUNT" -eq "$EXPECTED_TRIGGER_COUNT" ]] || {
    echo "migration authority trigger count changed after runtime provisioning: expected=$EXPECTED_TRIGGER_COUNT actual=$AUTH_TRIGGER_COUNT" >&2
    exit 78
  }
  echo "PASS runtime trigger metadata is intentionally hidden while migration authority verifies count=$AUTH_TRIGGER_COUNT"
fi

echo "PASS schema bootstrap/runtime privilege separation: profile=$AUTHORITY_PROFILE database=$DB_NAME runtime=$RUNTIME_USER privileges=$RUNTIME_PRIVS"
