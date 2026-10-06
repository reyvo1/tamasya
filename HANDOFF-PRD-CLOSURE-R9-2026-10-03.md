# TAMASYA PRD Closure R9 — External Adapter Runtime Path Root Fix

## Exact parent

- Repository: `reyvo1/testamasya`
- Branch: `rc1/post-green-owner-harness-v2`
- Exact R8 GitHub parent: `d698c66710fe86c51a849054009a20bed727e832`
- R8 workflow run: `37099321998` / run #41
- R8 check suite: `100491665851`
- Locked pre-PRD source remains `639d811cf6ad4c336b7814f815bb02a76861d6f9`.

## What R8 GitHub proved

- Source/syntax/security gate: PASS.
- Full hotel business UAT: **731/731 PASS, 0 FAIL**.
- Playwright desktop/mobile: **34/34 PASS**.
- Horizontal SaaS PMS: canonical **113 tables / 5 triggers**, property release attestation, DML-only runtime, two API replicas, 96 concurrent DB reads, shared storage, worker, restart persistence, replica-loss degradation and private-path denial: PASS.
- Realtime process smoke: PASS.
- HQ bootstrap: `hq/schema.sql` + `hq/delivery_schema.sql` imported, exact **7-table / 0-trigger** authority verification PASS, HQ runtime `SELECT,INSERT,UPDATE` boundary PASS.

## Evidence-backed R8 failure

The external HQ adapter process failed before a single delivery/object-storage assertion executed:

`require(/opt/hq/delivery.php): No such file or directory`

This is not an HQ application failure. Production images intentionally exclude `tests/`. The workflow bind-mounted tests at `/opt/tamasya-tests`, while application code remained in the image under `/var/www/tamasya`. The harness used `dirname(__DIR__,2)` as if its own path were still inside the repository, so the external mount incorrectly resolved the app root as `/opt`.

## R9 root fix

1. New `tests/uat_prd/runtime-source-root.php` makes the application root an explicit runtime contract (`TAMASYA_TEST_APP_ROOT`) instead of deriving it from the external test mount.
2. The resolver fails closed unless `FILE_CHECKSUMS.sha256` exists and every required HQ dependency exists and matches its exact release SHA-256.
3. `hq_external_adapters.php` loads HQ code only through that verified root.
4. GitHub keeps tests excluded from the production image, mounts them separately, and explicitly points the harness at `/var/www/tamasya`.
5. The external adapter container is additionally read-only, gets a tmpfs only for `/tmp`, drops all capabilities and uses `no-new-privileges`.
6. Production-image validation now lints `hq/core.php`, `hq/reporting.php`, `hq/delivery.php`, and `hq/object_storage.php` inside the built image.
7. New `prd-external-adapter-path-regression.php` proves local fallback, relocated harness resolution, tests-only root rejection, manifest-tamper rejection, no legacy `/opt/hq` inference, and hardened workflow wiring.

## Non-weakening statement

R9 removes no existing scenario, assertion, timeout, privilege boundary, financial gate, browser test, schema verification, adapter ACK check or object-storage checksum. It changes no production hotel business logic because the R8 evidence does not identify a production-code defect at this failure point.

## Next gate

Overlay R9 only on exact commit `d698c66710fe86c51a849054009a20bed727e832`, verify the new release manifest, commit once, and push the same branch. If every job is GREEN, freeze that exact commit as TAMASYA PRD SOFTWARE FINAL. If a new failure appears, continue from its first evidence-backed failure without weakening any already-green gate.

## Local validation

All deterministic suites remain green, including the new external-adapter path regression (6/6). PHP lint: 132 files. JS/MJS syntax: 82 files. Python compile, shell syntax and workflow YAML: PASS. Docker/TLS/MySQL execution is intentionally not claimed locally because Docker is unavailable here; GitHub must prove that exact runtime gate.
