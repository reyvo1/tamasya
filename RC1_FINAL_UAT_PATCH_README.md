# RC1 Final UAT Patch — 2026-10-01

Owner baseline: `TAMASYA-ENTERPRISE-RC1-AUDITED-2026-10-01.zip`

This patch changes the **UAT harness/workflow**, not production business defaults. It fixes the MySQL 8.4 trigger import failure in GitHub Actions, enables official Growth/Enterprise schemas only in the disposable CI database, adds hotel finance/booking edge matrices, Telegram operational simulation, Growth/Enterprise business scenarios, desktop/mobile Playwright UI coverage, coverage inventory, and backup/restore verification.

Do not claim production acceptance from this file alone. Acceptance requires the exact patched commit to complete the GitHub workflow green and its evidence artifact to be reviewed.
