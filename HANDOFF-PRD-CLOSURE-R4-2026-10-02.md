# TAMASYA PRD Closure R4 — Authenticated DB Readiness Macro Handoff

## Exact parent

- Repository: `reyvo1/testamasya`
- Branch: `rc1/post-green-owner-harness-v2`
- Exact R3 GitHub parent: `2588681cae91be2823db7067db294545ada2ca30`
- Locked pre-PRD source remains `639d811cf6ad4c336b7814f815bb02a76861d6f9`.

## Evidence from R3 GitHub run

R3 successfully removed the previous named-volume copy-up race. The log proves `storage-init` completed and `api`, `api2`, and `web` all started together on fresh volumes.

The other two jobs remained strong:
- source/syntax/security gate: GREEN;
- full hotel finance/booking/UI/Telegram UAT: GREEN with **731/731 PASS; 0 FAIL**.

The first new failure was in the PRD runtime smoke immediately after the disposable MySQL container started:

`ERROR 1045 (28000): Access denied for user 'root'@'localhost' (using password: YES)`

## Root cause

R3 used `mysqladmin ping` as if it were authenticated database readiness. That command is a server-process liveness probe and can return success while authentication/bootstrap is not yet complete. The loop therefore allowed the following schema-import command to race MySQL image initialization.

R4 removes that class of defect instead of adding sleeps or retries.

## R4 architecture change

A single helper, `tests/uat_prd/mysql-authenticated-ready.sh`, now defines PRD MySQL readiness:

1. container must still exist and must not be dead/exited;
2. readiness is a TCP `SELECT 1` using the runtime application account and expected database;
3. attempts are bounded;
4. timeout/stopped-container paths emit MySQL container logs;
5. wrong credentials are explicitly tested and must be rejected.

The SaaS runtime smoke and HQ external-adapter setup both use this contract. Database schema bootstrap uses the same application/schema-owner account rather than root. The SaaS smoke additionally proves 113 canonical tables and 5 canonical triggers before the application readiness loop begins.

The auxiliary MySQL 8.4 image used by PRD CI is digest-pinned for reproducibility. Controlled image override remains explicit for future upgrade UAT.

## Retained macro-wave safety

R4 does not alter hotel financial/business code, UAT assertions, or canonical writer authority. R3 storage lifecycle protections remain intact: `nocopy`, one-shot storage-init, non-root API/worker, read-only application root, no upstream request replay, shared-state/restart/replica-loss proofs. Realtime/HQ/S3 simulations remain after the SaaS runtime gate.

## Next gate

Overlay R4 only on exact commit `2588681cae91be2823db7067db294545ada2ca30`, commit once, and push the existing branch. Do not create a repo or branch. If GitHub is RED, continue from the first new real failure. If every job is GREEN, freeze the exact GREEN commit as **TAMASYA PRD SOFTWARE FINAL** and generate final source ZIP, SHA-256, As-Built status, HANDOFF and NEXT-CHAT.
