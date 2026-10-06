# TAMASYA Enterprise RC1 — Source Audit Continuation & GitHub UAT Handoff

Date: 2026-10-01 (Asia/Makassar)
Baseline owner: `TAMASYA-ENTERPRISE-RC1-2026-09-22(1).zip`
Baseline SHA-256: `1ec065c805b046a2ba094e66ab9f730b73e6511cd6dacd81dae75cc2ae31b96e`
Audit rule: RC1 is the owner. H1 is not a baseline and was not used as source.

## Result

**SOURCE AUDIT: PASS after corrective changes.**

Local source gate after fixes:
- PHP regression: 49/49 PASS
- JS/SW/idempotency/build/router regression: 15/15 PASS
- Finance regression: 9/9 PASS
- Hybrid/multi-property regression: 33/33 PASS
- Realtime regression: 16/16 PASS
- Device contract: 9/9 PASS
- Database TLS contract: 4/4 PASS
- **Total: 135/135 assertions PASS**
- PHP syntax: PASS for all PHP files
- JavaScript syntax: PASS for all JS/MJS files
- GitHub UAT Python harness compile: PASS
- GitHub Actions workflow YAML parse: PASS

**GITHUB FRESH-MYSQL UAT: WORKFLOW READY, NOT CLAIMED PASS YET.**
The runner workflow is included at `.github/workflows/tamasya-enterprise-rc1-uat.yml`. It must be executed in the target GitHub repository; this audit package does not manufacture a GitHub PASS result.

## Handoff findings closed

| Gate | Status | Closure |
|---|---|---|
| A1 Telegram post-commit | PASS | Canonical booking flow commits via `tamasyaFinancialCommit($pdo)` before `broadcastTelegramNotification(...)`; existing incident formatter regression also passes. |
| A6 operation-ID / retry | FIXED + PASS | Compiled `assets/chunks/app-shared.js` no longer clears the operation ID for 202/408/425/429/5xx. Regression checks both global guard and compiled wrapper. |
| Refund authority cash/split | SOURCE PASS / GITHUB DB UAT INCLUDED | Finance path and prior RC1 scenario contract preserved; fresh-MySQL scenario verifies original-bank refund and split cancellation net-zero. |
| Backfill/manual split/tax | SOURCE PASS / GITHUB DB UAT INCLUDED | Historical rule-by-date, document snapshot, unresolved tax, split historical payment, reported/closed period amendment and move-period net delta are covered by portable RC1 scenarios. |
| A7 permission fail-closed | FIXED + PASS | Telegram notification permission parsing now uses the canonical fail-closed permission override path; corrupt JSON/group and explicit false are regression-tested. |
| API/SW/cache Build ID | FIXED + PASS | Active runtime normalized to build `20261001-enterprise-rc1-audit1`; SW registration is externalized and versioned; cache/version parity regression passes. |
| POS discount/shift/stock | SOURCE PASS / GITHUB DB UAT INCLUDED | Deterministic cent allocation and `FOR UPDATE` shift behavior pass source regression; MySQL UAT exercises stock 409, idempotent sale and void/refund. |
| Maintenance/overlap | SOURCE PASS / GITHUB DB UAT INCLUDED | Adjacent/overlap/zero-duration invariants pass regression; MySQL UAT exercises room maintenance blocking and inspection release. |
| DB safety / migration authority | PASS WITH LEGACY FAIL-CLOSED HARDENING | No active API bootstrap call path to `010_runtime_migrations.php`/`080_schema_alignment.php` was found. Legacy RC4.4 tax migrator now refuses to start before DDL if old reconciliation helpers are unavailable. Official versioned migrations remain the schema-change path. |
| Enterprise/Hybrid isolation | PASS at source-contract level | 33 hybrid assertions pass: strict snapshot, tenant/property scoping, viewer fail-closed, checksum/invariant validation, missing-snapshot handling. GitHub workflow additionally tests fresh property transaction source; full external HQ TLS pair remains a deployment/UAT gate when HQ bridge is enabled. |
| Canonical report parity | SOURCE PASS / GITHUB DB UAT INCLUDED | Report engine regressions pass; fresh-MySQL UAT exercises canonical integrity plus CSV/XLSX/PDF snapshot checksum identity. |
| Static route/security | FIXED + PASS | Inline SW script removed, root CSP now uses `script-src 'self'`, inline event handlers/scripts scan clean. |
| Endpoint migration manifest | FIXED + PASS | `095_canonical_reports.php` was active in `api/router.php` but absent from `MODULARIZATION_MANIFEST.json`. Manifest now exactly matches all 24 active router modules and a regression prevents stale PASS. |

## Corrective changes made in this continuation

1. Fixed operation-ID lifetime in compiled `assets/chunks/app-shared.js` for retryable HTTP outcomes.
2. Normalized active browser/API/SW Build-ID and cache identity to Enterprise RC1 audit build.
3. Externalized service-worker registration to `assets/sw-register.js`; removed root CSP dependency on inline scripts.
4. Hardened Telegram notification permission parsing to fail closed on malformed/corrupt permission data.
5. Added fail-closed guard to legacy RC4.4 tax migrator before any legacy DDL path can run without required reconciliation helpers.
6. Added source regressions for the above invariants.
7. Corrected stale modularization manifest by adding active route `095_canonical_reports.php`, plus exact router↔manifest regression.
8. Added portable Linux/GitHub RC1 UAT harness under `tests/uat_rc1/`.
9. Added comprehensive GitHub Actions workflow with source gate, fresh MySQL bootstrap, transaction scenarios, concurrency race, canonical report/export parity, final ledger consistency, backup and isolated restore drill.

## GitHub comprehensive UAT coverage

The included workflow creates a disposable MySQL 8.4 database and imports the exact `database_setup.sql`, bootstraps a disposable property/admin, then runs:

- property setup and semantic finance bindings;
- reservation vs check-in lifecycle and overlap conflicts;
- room charge, booking payment, overpayment rejection, checkout and housekeeping;
- cash/bank/split payments and cancellation/refund authority;
- POS deterministic discount, stock conflict, operation replay, void/refund;
- maintenance block and inspection release;
- historical/backfill by effective tax date, document tax snapshots, unresolved-tax preservation, split historical payment;
- reported/closed accounting-period amendment controls;
- transaction allocations, accounting reports, OTA settlement, offline historical sync/idempotency/conflict;
- authorization/ledger mutation controls;
- two-process concurrency races for booking overlap and same operation ID;
- canonical report consistency and CSV/XLSX/PDF snapshot identity;
- final journal-balance and duplicate-document checks;
- application node-local backup, SHA verification, restore into an isolated database, key row-count parity, trigger parity and restored journal-balance check.

Every scenario result file is aggregated. A false assertion makes the Actions job fail even if the scenario process itself exits zero. Evidence logs are uploaded as the `tamasya-enterprise-rc1-uat-evidence` artifact.

## Remaining gate before production sign-off

Do not call this package production-approved merely from the local source audit. Production sign-off still requires the included GitHub fresh-MySQL workflow to be green on the target repo, and deployment-specific checks (real mail/Telegram provider where enabled, real HTTPS/reverse-proxy headers, and two-node/HQ failover/TLS pair only when those modes are enabled).
