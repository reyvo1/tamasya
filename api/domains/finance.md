# Finance
Ledger kanonik, PBJT, folio, settlement, sinkronisasi transaksi offline.

ROUTES: 030_ota_cleanup_audit.php, 040_transactions_sync.php
SUPPORT: 0185_finance_catalog_identity.php, 018_canonical_business_policy.php,
         019_canonical_financial_semantics.php, 025_financial_posting_authority.php,
         026_financial_mutation_authority.php, 030_booking_finance.php,
         075_flexible_historical_backfill.php, 100_cleanup_tools.php

Invariant: server-authoritative ledger, PBJT snapshot inclusive,
           offline lifecycle replay via sync_operations journal (idempoten),
           reconciliation Rp0 sebelum production GO.
