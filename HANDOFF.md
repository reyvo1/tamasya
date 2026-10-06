# HANDOFF - Catatan Perubahan (ditulis MINI AI AGEN)

## 2026-09-05 23:26
- Keluhan: saya mengalami kendala transaksi manual backfill di pembayaran split dia tidak ke split pembayaranya jadi dia masuk 1 akun saja kan seharusnya dia masuk ke 2 akun kan di split pembayaranya tapi klw tr
- AI penulis: clario/glm-5.3
- Hasil patch:
```
@api/modules/finance/075_flexible_historical_backfill.php bar.1-188 (sekitar bar.28): AI: TIDAK BISA PATCH: Potongan kode yang disertakan (baris 1-188 dari 075_flexible_historical_backfill.php) hanya berisi hel
  [OK] @migrations/V137_FIX32_TX_SPLIT_PAYMENT_COLUMNS.sql : 501 char diganti 2078 char.
[UJI] sintaks OK (@api/modules/finance/025_financial_posting_authority.php).
  [OK] @api/modules/finance/025_financial_posting_authority.php : 1020 char diganti 1957 char.
  [DEP] @api/modules/finance/026_financial_mutation_authority.php memakai: tamasyaCanonicalTransactionColumns
  [DEP] @api/modules/finance/030_booking_finance.php memakai: tamasyaPostFinancialTransaction
  [DEP] @api/modules/front_office/035_guest_security_deposits.php memakai: tamasyaPostFinancialTransaction
  [DEP] @api/modules/front_office/040_rooms_checkout_housekeeping.php memakai: tamasyaPostFinancialTransaction
  [DEP] @api/modules/growth_enterprise_locked/107_enterprise_completion.php memakai: tamasyaPostFinancialTransaction
@api/modules/finance/019_canonical_financial_semantics.php bar.1-173 (sekitar bar.13): AI: TIDAK BISA PATCH: Cuplikan `019_canonical_financial_semantics.php` ini hanya berisi klasifikasi semantik keuangan (detek
[UJI] sintaks OK (@api/modules/finance/026_financial_mutation_authority.php).
  [OK] @api/modules/finance/026_financial_mutation_authority.php : 139 char diganti 161 char.
[UJI] sintaks OK (@api/modules/finance/026_financial_mutation_authority.php).
  [OK] @api/modules/finance/026_financial_mutation_authority.php : 236 char diganti 2272 char.
[UJI] sintaks OK (@api/modules/finance/026_financial_mutation_authority.php).
  [OK] @api/modules/finance/026_financial_mutation_authority.php : 307 char diganti 406 char.
  [DEP] @api/modules/finance/030_booking_finance.php memakai: tamasyaMutateFinancialTransaction
  [DEP] @api/modules/front_office/040_rooms_checkout_housekeeping.php memakai: tamasyaFillTransactionAllocationMetadata, tamasyaTouchTransactionAllocationMetadata
  [DEP] @api/routes/030_ota_cleanup_audit.php memakai: tamasyaDeleteFinancialTransactions, tamasyaMutateFinancialTransaction, tamasyaMutateFinancialTransactions, tamasyaUpdateBookingLinkedTransactionMetadata
  [DEP] @api/routes/040_transactions_sync.php memakai: tamasyaApplySyncedTransactionPatch, tamasyaDeleteFinancialTransactions, tamasyaEnsureTransactionDocumentNumber, tamasyaMutateFinancialTransaction
  [DEP] @api/routes/090_operations_communications.php memakai: tamasyaLockTransactionsForShift, tamasyaSetTransactionReconciliation
  [DEP] @api/modules/finance/030_booking_finance.php memakai: tamasyaMutateFinancialTransaction
  [DEP] @api/modules/front_office/040_rooms_checkout_housekeeping.php memakai: tamasyaFillTransactionAllocationMetadata, tamasyaTouchTransactionAllocationMetadata
  [DEP] @api/routes/030_ota_cleanup_audit.php memakai: tamasyaDeleteFinancialTransactions, tamasyaMutateFinancialTransaction, tamasyaMutateFinancialTransactions, tamasyaUpdateBookingLinkedTransactionMetadata
  [DEP] @api/routes/040_transactions_sync.php memakai: tamasyaApplySyncedTransactionPatch, tamasyaDeleteFinancialTransactions, tamasyaEnsureTransactionDocumentNumber, tamasyaMutateFinancialTransaction
  [DEP] @api/routes/090_operations_communications.php memakai: tamasyaLockTransactionsForShift, tamasyaSetTransactionReconciliation
  [DEP] @api/modules/finance/030_booking_finance.php memakai: tamasyaMutateFinancialTransaction
  [DEP] @api/modules/front_office/040_rooms_checkout_housekeeping.php memakai: tamasyaFillTransactionAllocationMetadata, tamasyaTouchTransactionAllocationMetadata
  [DEP] @api/routes/030_ota_cleanup_audit.php memakai: tamasyaDeleteFinancialTransactions, tamasyaMutateFinancialTransaction, tamasyaMutateFinancialTransactions, tamasyaUpdateBookingLinkedTransactionMetadata
  [DEP] @api/routes/040_transactions_sync.php memakai: tamasyaApplySyncedTransactionPatch, tamasyaDeleteFinancialTransactions, tamasyaEnsureTransactionDocumentNumber, tamasyaMutateFinancialTransaction
  [DEP] @api/routes/090_operations_communications.php memakai: tamasyaLockTransactionsForShift, tamasyaSetTransactionReconciliation
[UJI] sintaks OK (@api/routes/040_transactions_sync.php).
  [OK] @api/routes/040_transactions_sync.php : 749 char diganti 1706 char.
```

## 2026-09-10 - FINAL TRANSACTION / BACKFILL / TELEGRAM / SYNC HARDENING
- Scope: full remediation of manual/historical backfill, OTA settlement classification, normal Telegram money flows, canonical accounting, PBJT tax selection, shifts, refund/reversal, browser offline queue, and Primary/Standby replay safety.
- OTA is now fail-closed. Arbitrary guest/free-text labels are never treated as OTA. OTA receivable requires explicit OTA evidence; booking source remains provenance and never substitutes for the actual settlement account.
- Historical/backfill create/edit/delete/replay uses canonical transaction authority, stable full-economic replay checks, durable tombstones, explicit cash clearing, one-row split cash+bank snapshots, and historical shift exemption.
- Online reservation/checkout/backfill/offline split payments use the same one-row ledger shape. Refund and audit reversal preserve the original cash/bank legs.
- Telegram manual finance uses the same manual-finance policy as Web, has no direct transaction SQL writer, does not inherit a booking's old settlement account, and uses one canonical server-side tax authority. Processed Telegram replay is claimed before current tax rules are evaluated, so later tax-rule/catalog-label changes cannot corrupt an old successful retry.
- Accounting/journal and cash/shift/report readers use the same split/technical-account semantics. Piutang OTA is non-liquid; cash and bank legs are reported separately.
- Live tax rules fail closed: an explicit active rule is required, including explicit 0%/non-tax rules. Historical entries may remain explicitly unresolved when evidence is insufficient.
- Generic browser offline transaction sync only accepts new historical_import/manual entries; live/booking/system kinds must use canonical server workflows. Primary/Standby remains single-writer with signed replay, receipts, fencing/epoch/revision checks and uncertain-event handling.
- Database schema was not destructively changed by this remediation. database_setup.sql remains byte-identical to the supplied canonical schema (SHA-256 87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf).
- Final sandbox verification: main regression 166/166 PASS; tax+shift 17/17 PASS; transactionKind accounting matrix 100/100 PASS; PHP lint 97/97 PASS; JS/MJS lint 55/55 PASS; shell lint 2/2 PASS; configurator self-test PASS; unexpected transaction writer files 0; Telegram direct transaction SQL 0; Telegram booking-tax preview calls 0; Service Worker assets 47/47 present; app-core version graph 27/27 on 20260910-finance-sync-clean1.
- Environment limitation: this sandbox exposes PDO drivers=[] and no live MySQL/Telegram/two physical TAMASYA nodes, so live production DB/webhook/failover drills remain deployment/UAT checks rather than falsely claimed sandbox tests.

## P0 BACKFILL SPLIT PAYMENT FIX — 2026-09-10
- Previous final status revoked after live user test found Manual Backfill Split Payment could appear entirely as Tunai.
- Fixed dual settlement-selector ambiguity: when split is active, top-level `bankAccountId` is cleared/disabled; `splitTransferBankAccountId` is authoritative for transfer/QRIS leg.
- Fixed Finance/Report/Shift projections to use split cash and split transfer legs instead of gross amount.
- Added server fail-closed conflict guard for differing top-level/split transfer account selectors.
- Bumped Service Worker/core asset token to `20260910-split-backfill-p0fix1`.
- New dedicated P0 regression has 39 checks and fails the old release while passing the patched source.
- Pre-package runner: 20/20 suites PASS; integrity 22/22; lint PHP 97, JS/MJS 55, shell 2, JSON 5; configurator self-test PASS.
- No database schema/migration change in this P0 fix.
- Do not call final until regression is repeated from a fresh extraction of the produced ZIP.

## CONSISTENCY GUARD R1 — 2026-09-10
- Source continuation: tested P0 Split Backfill release; old revoked pre-P0 final was not reused.
- Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1`.
- Added `consistency_guard_support.php` as the permanent server-side read-only integrity engine.
- Guard checks Split snapshot/account, journal projection/balance, live tax snapshots and rule references, historical Backfill tax review, Room/Extra allocations, shift cash, bank reconciliation, offline device queues, node outbox fingerprint/conflicts/revision, cluster fencing/lease, deep Primary/Standby dataset checksum during maintenance, and runtime API failures.
- Web System Health reads `consistency-guard-status`; endpoint is GET-only and Admin/Manager/Finance restricted.
- Telegram adds `/integritas` and `Akun & Diagnosa -> Consistency Guard`, with callback included in global fail-closed role classification. Telegram cash reporting is split-aware (Tunai vs Transfer/QRIS).
- `maintenance_cron.php` runs Guard with `deepCluster=true` and persists evidence only under Primary mutation lock into existing `data_integrity_issues` / `system_alerts`; no auto-fix of business data.
- Deep checksum uses the existing authenticated peer-status channel and deterministic replication-surface checksum. Local/peer mismatch without pending outbox is FAIL; pending synchronization or unreachable peer is WARNING.
- Database schema is unchanged from tested P0: database_setup.sql SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`; all 13 migration files unchanged.
- Pre-package verification after fixing Guard callback global role-map and build lineage: 23/23 suites PASS; main 166/166; Split P0 39/39; Guard injection 20/20; Telegram Guard 9/9; Guard integration 20/20; UI audit 22/22; integrity 22/22; PHP 98/98; JS/MJS 55/55; shell 2/2; JSON 5/5; configurator `ok=true`.
- Final ZIP must still be fresh-extracted and all gates rerun from that extraction before release.

## CONSISTENCY GUARD R2 FLEXIBLE MAINTENANCE — 2026-09-10
- Continuation source: fresh-extract verified Consistency Guard R1. Revoked pre-P0 release was not reused.
- Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2`.
- Goal: Guard must work on cheap shared hosting without cron and remain compatible with future cPanel cron/VPS/local/offline/two-server deployments.
- Added portable `run-if-due` scheduler in `consistency_guard_support.php` using existing `runtime_request_events`; no schema/migration was added.
- Default cadence is light 30m, deep 12h, retry backoff 5m. Environment overrides are supported; cron is explicitly optional.
- Added authenticated stateless `consistency-guard-maintenance` POST trigger. It is excluded from the business mutation/outbox path and invokes the same Guard engine.
- Added browser `assets/flexible-maintenance-addon.js`: online + logged-in aware, fire-and-forget, periodic/visibility/online triggers. Server-side due state and advisory locking prevent browser-tab multiplication from becoming repeated scans.
- Node sync agent also calls the same scheduler; Standby/local-backup scans are read-only and never persist Guard issues or bump primary revision.
- Opportunistic Guard refuses to run inside an existing caller/business DB transaction (`caller_transaction_active`), so it cannot join/commit/rollback hotel transactions or couple scheduler writes to their lifecycle.
- `maintenance_cron.php` remains available as OPTIONAL full maintenance (backup + retention cleanup + deep Guard) when a deployment supports cron. It is no longer a Guard dependency.
- System Health exposes Auto Guard state, last automatic light/deep run and cron=optional. Telegram `/integritas` continues to expose Guard safely to Admin/Manager/Finance.
- Entire app-core/chunk/Service Worker runtime graph is normalized to the single R2 build token to avoid mixed-cache/duplicate module instances.
- Pre-package flexible runner: 25/25 suites PASS, all return codes 0. Flexible scheduler test 21/21 PASS; Standby scheduler 4/4 PASS; integrity graph 22/22 PASS.
- Pre-package lint: PHP 98/98, JS/MJS 56/56, shell 2/2, JSON 5/5; configurator self-test `ok=true`.
- Database compatibility retained: `database_setup.sql` SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`; all 13 migration files byte-identical to tested P0.
- Release rule remains strict: package, extract to a brand-new directory, rerun all 25 suites + lint/configurator/integrity/manifest/archive checks before calling final.


## CANONICAL REPORT ENGINE R3 — 2026-09-10
- Continuation source: fresh-extract verified Flex Consistency Guard R2.
- Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2-canonical-report-r3-production-audit-r4`.
- Added server-side `canonical_report_support.php` and `api/routes/095_canonical_reports.php`; one canonical snapshot feeds PDF, Excel XLSX, CSV, JSON and email attachments.
- Official report output does not copy the application DOM and does not require Chrome/headless browser, Node.js, LibreOffice, or cron.
- Added 17 report types across finance, tax, journal, cash/bank, reservations/check-out, shift, reconciliation, Backfill/history, payroll, attendance, inventory, Night Audit, Housekeeping and audit log.
- PDF is a standalone A4 document and wraps long values without silent truncation. Excel is real OpenXML with multi-sheet output, frozen headers, filters and numeric accounting cells.
- Split Payment keeps separate cash and transfer/QRIS legs and selected transfer account in every canonical export.
- Legacy finance email now attaches canonical PDF + XLSX. New canonical email route defaults to PDF+XLSX and can add CSV, with a 12 MB safety cap for shared hosting.
- Every report carries Report ID, SHA-256 content checksum, period, build/user/property metadata and Consistency Guard state. Incomplete/truncated archive datasets fail closed.
- Existing quick browser-print paths remain available but are explicitly marked as quick print; official documents use the canonical report center.
- No database schema/migration changes. `database_setup.sql` stays SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`; all 13 migrations unchanged.
- Pre-package combined runner: 26/26 suites PASS; report-specific regression 33/33 PASS; lint PHP 100/100, JS/MJS 57/57, shell 2/2, JSON 5/5; configurator `ok=true`.
- Release still requires fresh extraction of the produced ZIP and repeat of all 26 suites + package checks before final naming.

## PRODUCTION FULL AUDIT R4 — 2026-09-10
- Continuation source: fresh-extract verified Canonical Report Engine R3; no rollback to older ZIP.
- Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2-canonical-report-r3-production-audit-r4`.
- Added full-surface release audit across Dashboard, Front Office, Operasional, Keuangan, Laporan, SDM, Digital Website, Sistem, optional modules and Telegram.
- New audit currently passes 163/163 checks, including 71 frontend direct API actions and 120 generated Telegram callbacks; no orphan Telegram state or direct Telegram transaction SQL writer was found.
- Performance hardening: server-revision remains 30s but unchanged-revision full hotel-data fallback is 5m; Growth/Enterprise probes become visible-only 30s; Growth PMS link replaces unconditional 2.5s DOM scan with MutationObserver + 15s visible fallback. Cluster safety polling remains 15s/5s and was intentionally not weakened.
- Service Worker/core graph bumped to R4 cache v153 to prevent mixed R3/R4 browser code after deploy.
- Database schema unchanged: database_setup.sql SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`; all 13 migrations unchanged from R3.
- Browser E2E attempts were blocked by sandbox policy for localhost and file://. Do not claim live browser/MySQL/Telegram-provider/two-physical-server verification; these remain production UAT boundaries.
- Pre-package combined gate after R4 audit: 27/27 suites PASS. Final release still requires fresh-extract rerun plus syntax/configurator/manifest/database/hygiene/archive checks.


## R4.1 — Service Worker path / SPA rewrite hotfix — 2026-09-11
- Root cause confirmed from staging console: the requested URL was `database_verifity.php`, while the real utility is `database_verify.php`. The missing typo path was rewritten to `index.html`, so PWA registration incorrectly requested `/database_verifity.php/sw.js` and received HTML.
- SPA rewrite now returns HTTP 404 for missing file-like requests (`.php`, `.js`, `.css`, `.json`, `.pdf`, `.xlsx`, etc.) instead of serving `index.html`.
- Service Worker base resolution now uses URL semantics and works for root or subfolder installs.
- Correct verifier URL remains `database_verify.php`.
- No database/schema/migration changes.

## R4.2 — Maintenance Semantic Binding Bootstrap — 2026-09-11
- Fixed a deployment deadlock discovered on staging: API is correctly fail-closed while patch_level mismatches, but `release_hardening_finalize.php` can require Master Kategori semantic bindings that were previously only configurable inside the blocked app.
- Added `release_semantic_bindings.php`, protected by the existing Admin Web Tool secret + HTTPS gate. It validates database safety, property identity and canonical baseline before any change.
- The tool only binds `categories.system_key/is_system` to an explicitly selected active category, or explicitly creates a new property-owned category. It cannot finalize patch level, run DDL, or mutate bookings/transactions/journals/balances.
- Finalizer errors for missing semantic roles now point to the maintenance tool.
- Database schema remains unchanged; database_setup.sql and all 13 migrations are byte-identical to R4.1.
- Targeted safety checks 15/15 PASS; PHP lint 101/101, JS/MJS 57/57, shell 2/2, JSON 5/5; configurator self-test ok=true.
