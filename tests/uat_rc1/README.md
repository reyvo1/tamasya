# TAMASYA Enterprise RC1 — Final Comprehensive GitHub UAT

This harness targets **only a fresh disposable GitHub Actions MySQL database**. It must never point to production. Production feature defaults are not changed by this harness.

## Evidence model

A green result requires layered evidence. Static discovery is not a pass claim and a clickable button is not proof that financial state is correct.

1. **Source gate** — release checksum, PHP/JS/Python syntax, seven existing regression suites, CSP/build/security invariants.
2. **Canonical database gate** — exact `database_setup.sql`, 113+ canonical tables and all five audited triggers on MySQL 8.4.
3. **Official optional-module gate** — `optional_modules_install.php --target=all` installs Growth + Enterprise tables only on the disposable UAT DB. Growth/Enterprise flags are ON only in CI so those screens and commands are testable.
4. **Hotel business API/DB UAT** — booking, check-in, move room, negotiated/OTA tax, room service, cash/bank/split payment, overpayment controls, cancellation/refund, checkout/housekeeping, POS, shift, backfill, reporting-period controls, allocation, settlement, accounting, sync/idempotency/conflict, concurrency and report/export parity.
5. **Enterprise business UAT** — rate/revenue, groups, advanced folio, procurement/AP, GRN/stock, supplier invoice/payment, CRM/loyalty, health and sandbox provider adapter.
6. **Browser UAT (desktop + mobile)** — main PMS navigation, Property Setup, POS, Growth Suite, Enterprise Suite and Multi-property controls. Static controls are inventoried/wiring-exercised; representative state-changing forms additionally assert HTTP success and persisted behavior.
7. **Telegram operational UAT** — bound/unbound role checks, menu/callback authorization, booking checkout/payment, housekeeping effect, shift reconciliation/close and duplicate/replay protection. No real Telegram network is contacted.
8. **Final finance gate** — journals must balance, document numbers remain unique, non-zero transactions must have journal evidence, then backup/restore is drilled into an isolated second DB.

## Sensitive finance focus

The RC1 final gate deliberately stresses the hotel money path: booking total/base/tax snapshots, negotiated price, OTA/Traveloka-style receivable, exact split payments, refund to canonical source, historical tax-by-date, period movement, POS stock/void, AP accrual/payment and final journal balance. A UI click is never used as the sole evidence for these paths.

## Live/environment gates that automation cannot impersonate

External Telegram delivery, SMTP delivery, OTA/payment-provider credentials, physical biometric devices/printers/smart locks, DNS/TLS and the actual production Primary/Standby network still require a controlled staging/production smoke test. The CI simulator must not send to real recipients or providers.


## Enterprise Full Complete extension

The Full Complete phase is additive to the locked green baseline. It does not remove, skip, or relax any prior assertion. It adds deterministic CI coverage for workforce/payroll/savings, asset lifecycle, anonymous public reservation/support, operational guest-service/maintenance/shift handover and SMTP delivery, Web↔Telegram business parity, two application nodes backed by two independent MySQL services, and signed HTTPS multi-property/HQ aggregation.

Two-node UAT deliberately uses independent MySQL listeners (`3306` and `3307`) and independent PHP application processes. Standby outage behavior must fail closed; planned leadership transfer must retain a single writer; final mirror convergence must prove canonical row-count parity and no split-brain risk.

External physical dependencies (a real phone network, real biometric device, production DNS, production SMTP provider, real OTA/payment-provider credentials, physical printer/smart-lock hardware) remain production/staging smoke tests and are not falsely represented by CI simulation.
