# TAMASYA PRD Closure R8 — HQ Schema Authority Root Fix

## Exact parent

- Repository: `reyvo1/testamasya`
- Branch: `rc1/post-green-owner-harness-v2`
- Exact R7 GitHub parent: `85d72c4ad70c8450899f3f92b517dc389c89e4b5`
- Locked pre-PRD source remains `639d811cf6ad4c336b7814f815bb02a76861d6f9`.

## What the R7 GitHub evidence proved

The R7 run fixed the previous application/runtime failures. Full hotel UAT completed **731/731 PASS, 0 FAIL**; Playwright completed **34/34 PASS** across desktop/mobile; horizontal SaaS runtime passed canonical 113-table/5-trigger bootstrap, release attestation, least-privilege runtime, two API replicas, 96 concurrent reads, shared storage, worker, restart persistence, replica-loss degradation, and private-path denial. Realtime process smoke also completed before the HQ stage.

The only failing PRD job stopped at HQ schema authority verification immediately after the two HQ SQL files imported successfully. MySQL reported seven actual HQ tables, but the verifier reported zero expected tables. Root cause: the R7 parser only recognized backtick-quoted `CREATE TABLE` identifiers, while HQ schema files intentionally use ordinary unquoted identifiers. A second latent defect existed behind that failure: the shared bootstrap helper always attempted PMS `schema_release_state` attestation, even though the isolated HQ database deliberately has no PMS release-state table.

## R8 root fixes

1. `mysql-canonical-authority-verify.py` now has one explicit MySQL identifier grammar supporting backtick-quoted, ordinary unquoted, `IF NOT EXISTS`, and schema-qualified table names. Trigger signatures continue to be checked. Zero parsed tables is now a fail-closed parser error.
2. `mysql-bootstrap-runtime-boundary.sh` now requires an explicit authority profile: `property` or `hq`. PMS retains exact canonical release/source attestation in `schema_release_state`; HQ no longer receives invented PMS metadata and instead records a deterministic ordered schema-source digest in UAT evidence after strict object verification.
3. PMS and HQ runtime privilege boundaries remain separate. HQ runtime still has `SELECT,INSERT,UPDATE` only; R8 adds a behavioral DELETE-denial probe in addition to the existing DDL/global/grant escalation denial checks.
4. Workflow calls now declare the profile explicitly: SaaS PMS uses `property`; HQ adapters use `hq`.
5. New `prd-schema-authority-regression.py` executes the real parser against canonical PMS SQL, HQ SQL, HQ control-plane SQL, mixed quoted/unquoted/schema-qualified fixtures, zero-object fail-closed behavior, and profile wiring. Existing regression gates remain intact.

## Next gate

Overlay R8 only on exact commit `85d72c4ad70c8450899f3f92b517dc389c89e4b5`, verify `FILE_CHECKSUMS.sha256`, commit once, and push the same branch. Do not weaken or skip any UAT. If all GitHub jobs are green, freeze that exact commit as **TAMASYA PRD SOFTWARE FINAL**. If red, investigate the first new evidence-backed failure from the same exact commit.
