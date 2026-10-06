# TAMASYA PRD Closure R6 — Trigger Authority + Browser Harness Root-Fix Macro

## Exact parent

- Repository: `reyvo1/testamasya`
- Branch: `rc1/post-green-owner-harness-v2`
- Exact R5 GitHub parent: `321e42daa59eb491e3774dfddbce1d650344b689`
- Locked pre-PRD source remains `639d811cf6ad4c336b7814f815bb02a76861d6f9`.

## R5 GitHub root-cause evidence

PRD runtime imported the canonical schema successfully using migration authority and created the restricted runtime account successfully. It failed only when the restricted runtime queried `information_schema.TRIGGERS` and got zero rows. The same run's comprehensive MySQL UAT proved the database has 5 canonical triggers, and restore drill proved 5 source / 5 restored triggers. Root cause is metadata visibility, not trigger loss.

The comprehensive hotel job had a second independent failure: browser UAT 33/34 PASS, with only the generic `internal-memo.html` wiring sweep timing out. The dedicated Internal Memo business test passed. The sweep used a live `button:nth(i)` locator while memo mutations re-render the button list, allowing the nth target to disappear and Playwright to wait until the 90s test timeout. Two-node/HQ evidence was then missing only because those later steps were skipped after the browser failure.

## R6 changes

1. Strict schema verification moved to the correct authority boundary. `mysql-canonical-authority-verify.py` derives exact expected fresh table and trigger signatures from source SQL and compares them to privileged metadata before runtime user creation.
2. Runtime account remains DML-only. CI proves it cannot `SHOW CREATE TRIGGER`; migration authority then proves trigger count remains intact.
3. Runtime-visible trigger count is never used as canonical schema evidence.
4. `baseline_support.php` now reports trigger metadata invisibility separately from actual missing triggers and remains fail-closed for strict verification.
5. `database_verify.php` directs operators to migration/DBA verification rather than privilege-escalating runtime.
6. Browser static wiring sweep now snapshots `ElementHandle`s at page load instead of re-querying mutable `button:nth(i)` indexes. Every initially rendered handler is still invoked; business assertions are unchanged.
7. Added dedicated PRD regressions for trigger metadata boundary and browser-harness stability.

## Safety retained

No transaction/tax/journal/booking logic was weakened. Runtime still receives no `CREATE`, `ALTER`, `DROP`, `TRIGGER`, `SUPER` or grant escalation. `fastcgi_next_upstream` remains off. Existing two-node, HQ, concurrency, report, Telegram, browser business and backup/restore gates remain present.

## Next gate

Overlay R6 only on exact commit `321e42daa59eb491e3774dfddbce1d650344b689`, commit once and push the same branch. If every GitHub job is GREEN, freeze that exact commit as **TAMASYA PRD SOFTWARE FINAL** and produce the final source archive/checksum/As-Built/HANDOFF/NEXT-CHAT. If RED, continue only from the first new real failure without weakening gates.
