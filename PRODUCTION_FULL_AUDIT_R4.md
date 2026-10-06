# TAMASYA PRODUCTION FULL AUDIT R4

Date: 2026-09-10
Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2-canonical-report-r3-production-audit-r4`
Base: fresh-extract verified Canonical Report Engine R3.

## Purpose
R4 is a production-readiness full-surface audit and conservative performance-hardening release. It retains the verified P0 Split Backfill fix, Consistency Guard, flexible no-cron maintenance, Primary/Standby single-writer protections, hybrid offline queues, Telegram canonical finance workflows, and Canonical Report Engine R3.

## Full-surface coverage
The production audit maps and verifies the application roots and descendants: Dashboard; Front Office (Kamar, Reservasi, Layanan Tamu/Bantuan); Operasional (Pusat Operasional & Kontrol, Aset & Stok, Tugas & Notifikasi); Keuangan; Laporan; SDM (Karyawan, Absensi, Cuti, Simpanan Karyawan); Digital Website; Sistem (Kategori & Subkategori, Integrasi/API, Database, Koneksi Lokal); plus POS/Minibar, Growth Suite, Enterprise Suite, Multi-property Foundation and Property Setup.

The R4 static/runtime-contract audit checks menu route -> chunk -> loader -> render target, standalone button/form wiring, local asset graph, frontend action -> backend dispatcher coverage, mutation Operation-ID protection, canonical transaction writer authority, Telegram callback/state/authorization coverage, offline queue persistence/replay markers, Service Worker API bypass, node-sync fingerprint/fencing/lease protection, split/tax/accounting/report parity, report PDF/XLSX/email wiring, Consistency Guard, flexible maintenance and release hygiene.

## Telegram Bot
- 120 generated callback values/prefixes are covered by handlers and authorization classification.
- 40 assigned conversation states have a text, callback-driven, or explicit legacy-cleanup completion path.
- Telegram route has no direct INSERT/UPDATE/DELETE against `transactions`.
- Financial effects continue through canonical finance functions/ledger invariants.
- Split cash vs Transfer/QRIS reporting and `/integritas` Consistency Guard remain wired.

## Hybrid online/offline and two-server safety
- Offline queue state covers rooms, bookings, transactions, booking actions, lifecycle operations, inventory, maintenance and deletion queues; the snapshot participates in persistent scoped storage and pagehide/visibility persistence.
- Financial reservation creation is fail-closed when already offline; ambiguous online interruption reuses operation identity through canonical replay logic.
- Sync retry uses bounded exponential backoff and preserves permanent-failure state.
- Service Worker bypasses API endpoints and only caches versioned static assets.
- Node outbox validates durable payload fingerprints before replay.
- Replay requires operation binding, property/context identity, fencing token, leadership epoch and valid primary lease.
- Uncertain remote outcomes are recorded as uncertain rather than retried as an unsafe independent second write.
- Consistency Guard retains deep Primary/Standby checksum verification; cluster polling safety cadence remains unchanged.

## R4 performance hardening
Performance changes are deliberately conservative and do not change accounting or transaction semantics:
- The lightweight `/api/server-revision` poll remains every 30 seconds while a visible/login session is active.
- Full `hotel-data` fallback refresh is relaxed from 60 seconds to 5 minutes when revision did not change. A changed server revision still triggers refresh without waiting five minutes.
- Primary/Standby cluster safety polling remains 15 seconds online / 5 seconds offline because it is a safety mechanism.
- Growth Suite and Enterprise Suite endpoint probes are visibility-aware and use a 30-second periodic fallback instead of unconditional 10-second polling; focus/visibility regain triggers an immediate probe.
- Growth PMS link integration replaces unconditional 2.5-second whole-DOM scanning with debounced MutationObserver updates plus a visible-only 15-second fallback.
- Device heartbeat, pending-offline sync cadence, support inbox cadence and POS standalone refresh are retained where they protect current operational state.
- The application footer now describes the actual `PHP Hybrid Back-end` and two-server synchronization instead of the stale `Node.js Back-end` label.

## Database compatibility
R4 does not add or modify database schema. `database_setup.sql` remains SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`, byte-identical to R3. All 13 migration files are byte-identical to R3.

## Browser/live-environment testing boundary
A real Chromium smoke attempt was made against a local fixture, but this sandbox blocks localhost and `file://` browser navigation by organization policy. Therefore browser E2E clicking is deliberately NOT reported as PASS. The executable JS/PHP regression, route/action graph, handler wiring, generated report artifacts and protocol simulations remain valid evidence. Production MySQL, real Telegram provider/webhook, SMTP delivery, real devices/printers and two physical Primary/Standby machines remain deployment/UAT boundaries.

## Release gate
R4 may be called final only after the candidate ZIP is extracted into a brand-new directory and the 27-suite regression, full syntax checks, configurator self-test, manifest checksum verification, database/migration comparison, hygiene check and ZIP archive test all PASS on the extracted contents.


## R4.1 — Service Worker path / SPA rewrite hotfix — 2026-09-11
- Root cause confirmed from staging console: the requested URL was `database_verifity.php`, while the real utility is `database_verify.php`. The missing typo path was rewritten to `index.html`, so PWA registration incorrectly requested `/database_verifity.php/sw.js` and received HTML.
- SPA rewrite now returns HTTP 404 for missing file-like requests (`.php`, `.js`, `.css`, `.json`, `.pdf`, `.xlsx`, etc.) instead of serving `index.html`.
- Service Worker base resolution now uses URL semantics and works for root or subfolder installs.
- Correct verifier URL remains `database_verify.php`.
- No database/schema/migration changes.
