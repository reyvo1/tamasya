# TAMASYA PRD Closure R7 — Runtime Authority + POS Midnight Root Fix Macro

## Exact parent

- Repository: `reyvo1/testamasya`
- Branch: `rc1/post-green-owner-harness-v2`
- Exact R6 GitHub parent: `0b14263d6a6687299ab49c43a41fbacca14a1f61`
- Locked pre-PRD source remains `639d811cf6ad4c336b7814f815bb02a76861d6f9`.

## What the R6 logs actually proved

Source/security remained green. Completed hotel scenario assertions were **682/682 PASS, 0 FAIL**. Browser was **33/34 PASS**; the only browser failure was mobile POS because a sale created after the hotel date rolled to `2026-10-03` was compared with a stale sales filter still set to `2026-10-02`. The PRD SaaS runtime reached canonical DB bootstrap but then returned repeated HTTP 500 while using a fixture that omitted mandatory API startup identity fields.

## R7 root fixes

1. **Valid runtime fixture** — SaaS smoke now supplies `APP_TIMEZONE`, property ID/code/name, currency/country/locale, matching the API's real startup contract. Failed readiness captures HTTP body and api/api2/web logs.
2. **Migration-authority release attestation** — privileged schema import + exact trigger verification occurs before runtime user creation; only then source checksum/release/patch/migration run are recorded. DML-only runtime can delegate trigger integrity only to an exact matching attestation.
3. **Production provisioning parity** — `provision_property.php` now emits separate `release_attestation.sql`; deployment docs require import → strict verify → attest → runtime and keep DBA credentials out of runtime.
4. **POS business-date rollover** — default sales period follows the server-authoritative business date across midnight/month rollover without overwriting an explicitly custom historical range. New policy/client URLs are cache-busted and service-worker aware.
5. Added dedicated regression gates for runtime schema authority, provisioning attestation, runtime config completeness, and POS business-date rollover. Existing UAT gates are retained.

## Next gate

Overlay R7 only on exact commit `0b14263d6a6687299ab49c43a41fbacca14a1f61`, verify the manifest, commit once and push the same branch. If every GitHub job is green, freeze that exact commit as **TAMASYA PRD SOFTWARE FINAL**. If red, use the first new real failure and its captured diagnostic body/logs; do not weaken gates.
