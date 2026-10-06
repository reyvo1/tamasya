# TAMASYA R4.2 Maintenance Bootstrap

Date: 2026-09-11

## Problem fixed
R4.1 correctly blocks the production API when `schema_release_state.patch_level` does not match the active source. The release finalizer also correctly refuses to finalize when mandatory finance semantic roles are not bound. On an existing staging database this could create an operational deadlock: the app is intentionally closed before login, while the operator is told to use Master Kategori inside that closed app.

## Permanent fix
R4.2 adds `release_semantic_bindings.php`, a dedicated maintenance-only browser/CLI tool.

The tool:
- requires the existing `ADMIN_WEB_TOOLS_ENABLED=1` and `ADMIN_WEB_TOOLS_SECRET` gate for browser use;
- requires HTTPS unless the existing staging-only HTTP override is explicitly enabled;
- verifies database safety, property identity and canonical baseline before mutation;
- keeps the main hotel API closed while patch mismatch exists;
- can bind only the semantic category roles required by the finalizer;
- can explicitly create a property-owned category if the operator chooses to do so;
- does not perform DDL;
- does not update `schema_release_state`;
- does not mutate bookings, transactions, journals or balances;
- records a maintenance activity entry when possible.

When active POS products exist, POS semantic roles are also included.

## Safe deployment sequence for an existing database
1. Keep production/staging traffic closed while patch mismatch exists.
2. Backup the database.
3. Run `database_verify.php` and confirm canonical baseline is ready.
4. Open `release_semantic_bindings.php` with the Admin Web Tool Secret.
5. Bind each missing semantic role to the correct existing category, or explicitly create a new master category.
6. Run `release_hardening_finalize.php`.
7. Re-run `database_verify.php`; require `patchLevelMatchesSource=true`.
8. Run `preflight_check.php` and UAT before opening traffic.

## Database compatibility
No schema or migration files changed from R4.1. `database_setup.sql` remains the canonical V137 schema and all 13 official migrations are byte-identical.
