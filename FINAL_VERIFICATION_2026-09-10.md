# TAMASYA RELEASE VERIFICATION — PRODUCTION FULL AUDIT R4

Date: 2026-09-10
Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2-canonical-report-r3-production-audit-r4`
Base: fresh-extract verified Canonical Report Engine R3.

## Scope
R4 performs a new full production audit across all menu/submenu roots, Telegram, canonical finance/tax/accounting, reports, hybrid offline queues, Service Worker, Primary/Standby synchronization and optional modules, then applies conservative performance hardening without weakening financial or cluster safety.

## New production audit evidence
- Combined regression gate: **27/27 suites PASS** pre-package, all return codes 0.
- New R4 full-surface audit: **163/163 checks PASS**.
- Frontend direct API mapping in R4 audit: **71 actions, 0 missing backend dispatcher**.
- Telegram generated callbacks: **120 covered, 0 missing handler, 0 missing authorization classification**.
- Telegram assigned states: **40 covered by text/callback/legacy-cleanup paths, 0 orphan states**.
- Navigation registry roots/submenus, primary Dashboard/Keuangan/Laporan/Digital Website and five additive modules resolve to existing render/controller assets.
- Canonical transaction SQL writer set remains restricted to the approved finance authority/schema-alignment files.
- Core source has no TODO/FIXME/STUB/MOCK/DUMMY workflow marker and release hygiene has no editor/backup/log artifacts.

## Retained regression families
The 27-suite gate keeps all previous verified families: main regression (R4 cache expectation only; business assertions unchanged), Backfill/Extra tax, Split mutation/reconciliation/P0 regression, transactionKind/accounting matrix, tax+shift, shift finalization, Pindah Kamar, Telegram checkout/callback/split/Guard, UI parity, offline lifecycle and pending replay, node/offline sync, Consistency Guard + integration, flexible no-cron maintenance + Standby read-only, Gate6 integrity, and Canonical Report Engine.

## Performance changes
- Server-revision polling remains 30s; full hotel-data fallback changes from 60s to 5m only when revision is unchanged.
- Cluster safety polling remains 15s online / 5s offline.
- Growth/Enterprise endpoint periodic probes are visibility-aware and 30s, with immediate focus/visibility triggers.
- Growth PMS link DOM scan uses debounced MutationObserver plus visible-only 15s fallback instead of unconditional 2.5s scanning.
- Stale footer label updated to PHP Hybrid Back-end / two-server synchronization.
- Build/Service Worker cache bumped to R4 so production cannot silently mix R3 and R4 app-core chunks.

## Database compatibility
- `database_setup.sql` SHA-256 remains `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf` and is byte-identical to R3.
- All **13** migrations are byte-identical to R3.
- **No new table, column or migration.**

## Testing boundary
Chromium browser E2E was attempted, but the sandbox blocks localhost and file:// navigation by policy. It is not counted as PASS. This environment also does not supply the production MySQL instance, real Telegram provider/webhook, SMTP provider, two physical servers or all target devices/printers. Those remain deployment/UAT checks and are not falsely claimed here.

## Strict release rule
Pre-package PASS is not sufficient. The candidate ZIP must be fresh-extracted and all **27 suites + lint/configurator/manifest/database/hygiene/archive checks** must remain PASS before final naming.


## R4.1 — Service Worker path / SPA rewrite hotfix — 2026-09-11
- Root cause confirmed from staging console: the requested URL was `database_verifity.php`, while the real utility is `database_verify.php`. The missing typo path was rewritten to `index.html`, so PWA registration incorrectly requested `/database_verifity.php/sw.js` and received HTML.
- SPA rewrite now returns HTTP 404 for missing file-like requests (`.php`, `.js`, `.css`, `.json`, `.pdf`, `.xlsx`, etc.) instead of serving `index.html`.
- Service Worker base resolution now uses URL semantics and works for root or subfolder installs.
- Correct verifier URL remains `database_verify.php`.
- No database/schema/migration changes.

## R4.2 MAINTENANCE BOOTSTRAP ADDENDUM — 2026-09-11
- Added `release_semantic_bindings.php` to resolve the safe-finalization deadlock when the main API is closed by patch mismatch but finance semantic roles still require binding.
- Tool is protected by Admin Web Tool secret/HTTPS and is limited to finance category master-data semantic binding (or explicit new category creation).
- It does not execute DDL, does not modify schema_release_state, and does not touch bookings, transactions, journals or balances.
- `release_hardening_finalize.php` now returns the maintenance tool as the next step when semantic binding is missing.
- Targeted safety regression: 15/15 PASS.
- Syntax: PHP 101/101, JS/MJS 57/57, shell 2/2, JSON 5/5 PASS.
- Configurator self-test: ok=true.
- database_setup.sql unchanged; all 13 migrations unchanged from R4.1.
