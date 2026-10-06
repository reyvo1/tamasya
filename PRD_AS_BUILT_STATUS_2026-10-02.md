# TAMASYA Hybrid Modular Architecture — PRD As-Built Closure R10 Candidate

Date: 3 October 2026  
PRD authority: `PRD_TAMASYA.txt` v1.0, 15 September 2026  
Locked software parent: `639d811cf6ad4c336b7814f815bb02a76861d6f9`  
Exact R10 parent / R9 GitHub commit: `f88ec8081b781fac6c440065ac7ff9e710ce3d0b`  
Runtime build identity remains `20261002-prd-closure-r1`; R8 is an authority-boundary root-fix wave, not a business release fork.

## R6 GitHub evidence

The exact R6 run kept source/security green and completed **682/682 business scenario assertions with 0 FAIL**. Browser ran 34 tests: 33 passed and one mobile POS lifecycle failed because the hotel date crossed midnight while the page stayed open: sale date became `2026-10-03` while `#sales-to` remained `2026-10-02`. Two-node/HQ evidence was subsequently absent only because later workflow steps were skipped after the browser failure.

The PRD SaaS job reached canonical database bootstrap but returned repeated HTTP 500. Source inspection found the smoke fixture omitted `APP_TIMEZONE`, `TAMASYA_PROPERTY_CODE`, and `TAMASYA_PROPERTY_NAME` although `api.php` explicitly requires those fields. R7 fixes that invalid fixture and preserves the response body/container logs on future readiness failure.

## R7 architecture correction

Runtime DB least privilege and canonical trigger verification are now one coherent authority chain. Migration/DBA authority imports and verifies exact canonical tables/trigger signatures, then records exact `database_setup.sql` SHA-256, release, patch and migration run in `schema_release_state`. Runtime remains DML-only. Direct trigger metadata wins when visible; deliberately hidden metadata may use only an exact matching migration-authority attestation. Missing/stale attestation or unclassified visibility remains fail-closed.

Production provisioning emits the attestation as a separate reviewed DBA step. Backup/restore guidance explicitly requires trigger-complete restore-grade evidence rather than weakening the verifier.

## R7 POS correction

POS automatic sales filters now follow the server-authoritative `businessDate` / `businessMonthStart` after bootstrap/focus refresh and on entry to the Sales view, including midnight and month rollover. Explicit custom historical periods remain custom. POS policy/client assets are cache-busted and included in the service-worker contract.

## Local deterministic evidence

All source-level suites are green: PHP 68/68, JS 21/21, Finance 9/9, Hybrid 33/33, Realtime 16/16, architecture 5/5, performance 7/7, deployment 7/7, scale 7/7, storage 7/7, DB readiness 8/8, DB authority 11/11, trigger metadata 5/5, browser harness 5/5, runtime schema authority 6/6, provisioning attestation 5/5, runtime config 4/4, POS business date 7/7, device 9, database TLS 4.

## PRD phase status if exact R7 commit is fully GREEN

| Phase | Software status | Remaining evidence boundary |
|---|---|---|
| 0 — Baseline Lock | COMPLETE | same-commit manifest/UAT |
| 1 — Property Isolation | COMPLETE | target deployment smoke |
| 2 — Enterprise Read Model | COMPLETE | target deployment smoke |
| 3 — Async Foundation | COMPLETE | target provider commissioning |
| 4 — VPS Profile | SOFTWARE COMPLETE / TARGET COMMISSIONING | real host sizing/ops |
| 5 — Realtime / IoT | SOFTWARE COMPLETE / REAL DEVICE COMMISSIONING | physical device/provider |
| 6 — SaaS Scale | SOFTWARE COMPLETE IN CI / REAL HA COMMISSIONING | real multi-fault-domain HA/DR |

## Final-lock rule

R7 remains a **candidate** until the exact R7 commit passes every GitHub job. If all jobs are GREEN, freeze that exact commit as `TAMASYA PRD SOFTWARE FINAL`; do not create another development wave merely to rename it.

## R7 GitHub evidence and R8 authority closure

The exact R7 GitHub run completed **731/731 hotel scenario assertions with 0 FAIL** and **34/34 Playwright tests PASS**. The horizontal SaaS PMS runtime also passed 113 canonical tables, 5 canonical triggers, exact source attestation, DML-only runtime, two API replicas, 96 concurrent DB reads, shared storage/worker, restart persistence and replica-loss degradation. The remaining PRD failure was isolated to HQ authority verification after the HQ SQL files imported successfully: MySQL exposed seven actual HQ tables while the verifier derived zero expected tables.

The root cause was not HQ application behavior. The authority parser recognized only backtick-quoted `CREATE TABLE` identifiers while HQ schemas use ordinary unquoted names. In addition, the shared bootstrap helper still assumed every database owned PMS `schema_release_state`; HQ deliberately does not. R8 fixes both layers together. The verifier now accepts quoted/unquoted/`IF NOT EXISTS`/schema-qualified identifiers and fails closed on a zero-object parse. The bootstrap boundary requires an explicit `property` or `hq` profile. Property PMS retains canonical release/source attestation; HQ retains strict object verification and ordered source-set digest evidence without inventing PMS metadata. Runtime privileges remain restricted, and HQ DELETE denial is behaviorally tested.

R8 adds a real parser/profile regression over the canonical PMS schema, HQ base/delivery schemas, HQ control-plane schema, mixed identifier fixtures, and empty-parser fail-closed behavior. No previous UAT or business assertion is removed or relaxed.

## R8 final-lock rule

R8 remains a **candidate** until the exact R8 commit passes every GitHub job. If all jobs are GREEN, freeze that exact commit as `TAMASYA PRD SOFTWARE FINAL`; do not change tested source merely to rename the release.

## R8 GitHub evidence and R9 external-adapter packaging closure

Exact R8 GitHub commit: `d698c66710fe86c51a849054009a20bed727e832` (workflow run #41 / check suite `100491665851`). The strict source/security gate passed. The full hotel business suite completed **731/731 PASS, 0 FAIL** and Playwright completed **34/34 PASS**. The PRD horizontal SaaS runtime completed the 113-table/5-trigger canonical authority check, property attestation, DML-only runtime boundary, routed API/MySQL ping, serialized shared storage, 96 concurrent reads, worker shared state, restart persistence, replica-loss degradation, private-path denial, and realtime process smoke.

The only failure occurred after HQ schema bootstrap itself succeeded. `hq/schema.sql` + `hq/delivery_schema.sql` were applied and the new R8 HQ authority verifier passed seven tables. The next external-adapter container then failed before executing any delivery/object-storage assertion because the test harness was mounted at `/opt/tamasya-tests`, but `hq_external_adapters.php` inferred application code using `dirname(__DIR__,2)`. From the external mount that resolves to `/opt`, so it attempted `/opt/hq/delivery.php`; production application code actually lives at `/var/www/tamasya/hq/delivery.php` and tests are intentionally absent from the production image.

R9 fixes the packaging contract rather than weakening the adapter UAT. External tests remain outside the production image. `runtime-source-root.php` resolves an explicit `TAMASYA_TEST_APP_ROOT`, validates the release manifest, and verifies SHA-256 for every critical HQ dependency before loading it. Local execution retains a repo-relative fallback. GitHub now binds the tests separately, declares `/var/www/tamasya` explicitly as the source under test, verifies HQ files are present in the built image, and runs the adapter container read-only with dropped capabilities and `no-new-privileges`. A dedicated regression proves that a tests-only mount is rejected and a manifest mismatch fails closed.

No hotel business logic, financial authority, booking/POS code, database schema, or previous UAT assertion is relaxed or removed by R9. R9 remains a candidate until the exact R9 commit passes all GitHub jobs.



## R9 GitHub evidence and R10 outbound TLS trust closure

Exact R9 GitHub commit: `f88ec8081b781fac6c440065ac7ff9e710ce3d0b` (workflow run `37100900748` / check suite `100495794667`). The source/syntax/security job passed. The full hotel/business job completed **731/731 PASS, 0 FAIL** and Playwright completed **34/34 PASS**. The PRD deployment job also passed production-image boundary, canonical property bootstrap, horizontal SaaS runtime, 96 concurrent DB reads, shared storage/worker/restart/replica-loss checks, realtime process execution, HQ seven-table authority bootstrap, and HQ least-privilege runtime provisioning.

The remaining failure was the first real outbound private-TLS request. The mock endpoint uses a CI private CA. The workflow mounted that CA and exported `CURL_CA_BUNDLE` / `SSL_CERT_FILE`, but production HQ adapter code itself did not set a CA bundle on the PHP/libcurl handles. `hq/delivery.php` therefore returned `uncertain / ACK_NOT_VERIFIED` before the expected terminal ACK could be accepted. The same missing explicit trust contract also affected the object-storage adapter, which had not yet executed because delivery failed first.

R10 fixes production code rather than relaxing the UAT. `deliveryDestinations[*].caFile` and `objectStorage.caFile` are optional absolute PEM paths. When configured, HQ validates the file and applies it directly with `CURLOPT_CAINFO`, while keeping `CURLOPT_SSL_VERIFYPEER=true` and `CURLOPT_SSL_VERIFYHOST=2`. Public endpoints can continue using the image/system trust store by omitting `caFile`. Missing/relative private CA configuration fails closed before network send.

Delivery ACK classification is now explicit and evidence-preserving: transport failures return a sanitized `TRANSPORT_ERROR_<curl errno>` code, HTTP 202 remains non-terminal, malformed/wrong ACKs have distinct codes, and only the exact HTTP 200 + `success:true` + matching `jobId` + matching `reportId` + `status:"delivered"` combination becomes delivered. `uncertain` jobs still are not auto-retried.

The GitHub private-TLS simulation no longer relies on environment-only CA injection. It passes `caFile` through the same HQ private configuration contract production uses, tests an unreadable CA fail-closed case, retains wrong-ACK and HTTP-202 tests, and exercises immutable object-storage PUT/GET/duplicate behavior through the same CA contract. No hotel business, finance, booking, POS, browser, database-authority, or least-privilege assertion is removed or weakened.

## R10 final-lock rule

R10 remains a **candidate** until the exact R10 commit passes every GitHub job. If all jobs are GREEN, freeze that exact commit as `TAMASYA PRD SOFTWARE FINAL`. Real provider credentials, physical smart-lock/device commissioning, and production HA/DR commissioning remain deployment evidence rather than CI claims.
