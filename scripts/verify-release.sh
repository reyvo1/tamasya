#!/usr/bin/env bash
# Source/unit checks only; transport fixtures use temporary loopback listeners.
# No hotel application startup, DB import, provider delivery or production mutation.
set -euo pipefail
TASK_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$TASK_ROOT"
sha256sum -c FILE_CHECKSUMS.sha256
while read -r digest path; do
  path="${path#\*}"
  case "$path" in
    *.php) php -l "$path" >/dev/null ;;
    *.js|*.mjs) node --check "$path" ;;
  esac
done < FILE_CHECKSUMS.sha256
python3 -m compileall -q tests/uat_rc1 tests/uat_prd
bash -n scripts/verify-release.sh tests/uat_rc1/run_two_node_complete.sh tests/uat_rc1/run_hq_multi_property_complete.sh tests/uat_prd/run_saas_runtime_smoke.sh tests/uat_prd/mysql-authenticated-ready.sh tests/uat_prd/mysql-bootstrap-runtime-boundary.sh
sh -n deploy/runtime-storage-init.sh
php tests/regression.php
node tests/regression.mjs
php tests/production-hardening-regression.php
node tests/production-hardening-regression.mjs
node tests/report-download-ui-regression.mjs
php tests/owner-readonly-regression.php
node tests/owner-readonly-regression.mjs
node tests/ui-financial-logic-regression.mjs
node tests/growth-ui-currency-regression.mjs
php tests/finance-cash-tax-regression.php
node tests/finance-cash-tax-regression.mjs
php tests/booking-negotiation-regression.php
php tests/growth-kpi-regression.php
php tests/telegram-charge-payment-regression.php
php tests/telegram-shift-regression.php
php tests/finance-regression.php
php tests/hybrid-regression.php
node tests/realtime-regression.mjs
node tests/prd-architecture-regression.mjs
node tests/prd-performance-regression.mjs
node tests/prd-deployment-regression.mjs
node tests/prd-scale-runtime-regression.mjs
node tests/prd-storage-runtime-regression.mjs
node tests/prd-database-readiness-regression.mjs
node tests/prd-database-authority-regression.mjs
node tests/prd-trigger-metadata-regression.mjs
node tests/prd-browser-harness-regression.mjs
php tests/prd-runtime-schema-authority-regression.php
php tests/prd-provisioning-attestation-regression.php
node tests/prd-runtime-config-contract-regression.mjs
php tests/prd-external-adapter-path-regression.php
php tests/prd-outbound-tls-regression.php
python3 tests/prd-schema-authority-regression.py
node tests/prd-pos-business-date-regression.mjs
php tests/device-contract-regression.php
php tests/database-tls-regression.php
printf "All source checks and 36 regression suites passed.\n"
