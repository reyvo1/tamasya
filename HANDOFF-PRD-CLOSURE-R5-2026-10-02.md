# TAMASYA PRD Closure R5 — Database Authority Separation Macro Handoff

## Exact parent

- Repository: `reyvo1/testamasya`
- Branch: `rc1/post-green-owner-harness-v2`
- Exact R4 GitHub parent: `e2a3f38a7225da8419a1ed823911bb14a77bdc96`
- Locked pre-PRD source remains `639d811cf6ad4c336b7814f815bb02a76861d6f9`.

## Evidence from R4 GitHub run

- source/syntax/security gate: GREEN;
- full hotel finance/booking/UI/Telegram UAT: GREEN with **731/731 PASS; 0 FAIL**;
- authenticated MySQL readiness: PASS;
- wrong-password rejection: PASS;
- storage-init / parallel API startup root fix from R3: retained.

The first real R4 failure was later during canonical schema import:

`ERROR 1419 (HY000) at line 2686: You do not have the SUPER privilege and binary logging is enabled`

Line 2686 is canonical `CREATE TRIGGER trg_transactions_tax_snapshot_bi`.

## Root cause

R4 conflated two different authorities: application runtime and schema migration. The runtime account was intentionally used to import `database_setup.sql`. That is incompatible with canonical trigger creation under MySQL binary logging and also contradicts TAMASYA's own `provision_property.php`, which already specifies DBA schema import plus a restricted runtime user.

The correct fix is **not** to grant `SUPER` to the application account and **not** to disable binary logging. It is to separate migration authority from runtime authority.

## R5 macro change

New shared CI contract: `tests/uat_prd/mysql-bootstrap-runtime-boundary.sh`.

It:
1. waits for authenticated root/migration readiness;
2. applies schema files only through migration authority;
3. creates runtime identity only after schema bootstrap;
4. grants only an allowlisted data-plane profile;
5. proves runtime authentication and wrong-password rejection;
6. inspects grants for forbidden DDL/admin capabilities;
7. behaviorally proves runtime `CREATE TABLE` and `SET GLOBAL` are denied.

PMS runtime receives `SELECT,INSERT,UPDATE,DELETE`; HQ runtime receives `SELECT,INSERT,UPDATE`. Both PRD simulations now start MySQL without Docker's `MYSQL_USER` auto-grant path. HQ schema/delivery schema use the same separated authority model.

## Retained safety

No hotel transaction/tax/journal/booking logic was changed. Existing Full Complete UAT assertions were not removed or softened. No `SUPER`, `TRIGGER`, DDL, root credential, or global-admin privilege is granted to runtime. R1 performance, R3 storage, R4 authenticated-readiness, two-node fencing, reports, Telegram parity, browser UAT, backup/restore and concurrency suites remain in the workflow.

## Next gate

Overlay R5 only on exact commit `e2a3f38a7225da8419a1ed823911bb14a77bdc96`, commit once and push the existing branch. If every GitHub job is GREEN, freeze that exact commit as **TAMASYA PRD SOFTWARE FINAL**. If RED, continue only from the first new real failure without weakening the gates.
