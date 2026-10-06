# TAMASYA PRD Closure R10 — Outbound Private TLS Trust Root Fix

## Exact parent

- Repository: `reyvo1/testamasya`
- Branch: `rc1/post-green-owner-harness-v2`
- Exact R9 GitHub parent from workflow evidence: `f88ec8081b781fac6c440065ac7ff9e710ce3d0b`
- R9 workflow run: `37100900748`
- R9 check suite: `100495794667`
- Locked pre-PRD source remains `639d811cf6ad4c336b7814f815bb02a76861d6f9`.

## What R9 GitHub proved

- Source/syntax/security gate: PASS.
- Full hotel/business UAT: **731/731 PASS, 0 FAIL**.
- Playwright desktop/mobile: **34/34 PASS**.
- Production PHP-FPM image/non-root boundary: PASS.
- Property canonical bootstrap: **113 tables / 5 triggers**, authority separation and DML-only runtime: PASS.
- Horizontal SaaS: two API replicas, 96 concurrent DB reads, shared storage/worker, restart persistence, replica-loss degradation and private path denial: PASS.
- Realtime process smoke: PASS.
- HQ DB: seven-table authority verification and `SELECT,INSERT,UPDATE` runtime boundary with DELETE denied: PASS.

## Evidence-backed R9 failure

The first outbound HQ delivery attempt returned:

`FAIL HTTPS delivery bridge exact ACK accepted ... "status":"uncertain","code":"ACK_NOT_VERIFIED"`

The CI endpoint uses a private CA. The workflow mounted the CA and only exported `CURL_CA_BUNDLE` / `SSL_CERT_FILE`. `hq/delivery.php` and `hq/object_storage.php` kept peer/hostname verification enabled but had no application-level CA bundle option and did not set `CURLOPT_CAINFO`. Therefore the private trust root was not part of the HQ adapter configuration contract. Delivery stopped before the object-storage stage.

The exact mock success route always emits HTTP 200 with `success:true`, matching `jobId`, matching `reportId`, and `status:"delivered"` after a valid signed request. The production ACK gate remains strict; R10 does not change that acceptance rule.

## R10 root fix

1. `hq/core.php` adds one shared private-CA resolver. `caFile` is optional, must be an absolute readable file, and invalid/missing configured CA fails closed.
2. `hq/delivery.php` applies configured private CA directly with `CURLOPT_CAINFO` while retaining `CURLOPT_SSL_VERIFYPEER=true` and `CURLOPT_SSL_VERIFYHOST=2`.
3. Delivery response classification is explicit: transport errno, non-final HTTP, malformed JSON, wrong job/report/status are distinct evidence codes. Only exact terminal ACK becomes `delivered`.
4. `uncertain` delivery is still never auto-retried. Config/CA failures before network become `blocked`.
5. `hq/object_storage.php` uses the same explicit `caFile` contract and keeps SigV4, checksum, immutable PUT, TLS peer/hostname verification and GET read-back.
6. GitHub external TLS simulation passes `caFile` through the HQ private config instead of relying on environment-only CA variables.
7. The simulation retains HTTP 202 and wrong-ACK checks, adds missing-CA fail-closed proof, and still requires immutable object PUT/GET/duplicate behavior.
8. New `tests/prd-outbound-tls-regression.php` checks CA validation, exact ACK semantics, strict TLS options, and workflow wiring.
9. Existing scale regression was updated to assert the refactored strict ACK decision helper instead of an obsolete source-code literal. Coverage is stronger, not weaker.
10. Deployment documentation now explains explicit private CA configuration for delivery bridges and S3-compatible storage.

## Non-weakening statement

R10 removes no hotel/business scenario, browser test, finance assertion, schema check, runtime privilege boundary, TLS verification, HMAC check, exact ACK requirement, object checksum, immutable object rule, timeout safety, or fail-closed behavior.

No financial transaction, booking, POS, tax, journal, or canonical property database code is changed by R10.

## Local evidence

- PHP base regression: 68/68.
- JS base regression: 21/21.
- Finance: 9/9.
- Hybrid: 33/33.
- Realtime: 16/16.
- Architecture/performance/deployment/scale/storage/readiness/authority/trigger/browser/runtime contracts remain green.
- New outbound TLS regression: 6/6.
- Docker/private-TLS integration is not claimed locally because Docker is unavailable in this execution environment. GitHub must prove the exact R10 runtime gate.

## Next gate

Overlay R10 only on exact parent `f88ec8081b781fac6c440065ac7ff9e710ce3d0b`, verify `FILE_CHECKSUMS.sha256`, commit once and push the same branch.

If every GitHub job is GREEN, freeze that exact commit as TAMASYA PRD SOFTWARE FINAL. If not, continue only from the first new evidence-backed failure and do not weaken already-green UAT.
