#!/usr/bin/env python3
"""Fail closed if required browser UAT scenarios disappear, are duplicated, or are skipped.

This protects coverage monotonically: future releases may add browser tests, but the
known R7 scenarios cannot silently vanish or be converted to skip/fixme/only.
"""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[2]
SPEC = ROOT / 'tests/uat_rc1/browser/rc1-ui.spec.mjs'
source = SPEC.read_text(encoding='utf-8', errors='strict')

required = [
    'Activated Growth stays in native Dashboard and reservation views with exact Rupiah cents',
    'PMS interactive login, session persistence and module dock',
    'PRD shared-hosting shell lazy-loads optional addons only when their capability is opened',
    'Main PMS navigation: every admin route opens and exposes controls without browser crash',
    'Staff management exposes Owner read-only role to Admin',
    'Property setup: save, refresh, finalize and persisted reload',
    'POS UI: tabs, product, cart, sale, receipt, void, stock and category lifecycle',
    'Growth Suite UI: all tabs plus active controls and every business form submits',
    'Internal Memo UI: admin create, search, archive and restore without hard delete',
    'Owner UI is all-read and mutation controls stay unavailable',
    'Enterprise Suite UI: all tabs, refresh, finance views and representative forms',
    'Multi-property buttons: preview, snapshot, queue, manifest, contracts, outbox and disabled bridge',
    'Financial graph reconciles January 28 unknown PBJT with cash, bank and QRIS receipts',
    'Official report defaults use property midnight and recompute on next month reopen',
    'Fresh service worker precaches the exact React module for offline import',
    'Official downloads inherit report period and save actual PDF Excel CSV and JSON files',
    'Finance period is inherited and Operations badges and journal totals explain their scope',
    'Layout: exact large amounts stay inside cards and audit shift stays above navigation',
]

forbidden = re.findall(r'\btest\.(skip|fixme|only)\s*\(', source)
if forbidden:
    raise SystemExit('Browser UAT contains forbidden test modifiers: ' + ', '.join(sorted(set(forbidden))))

names = [m.group(2) for m in re.finditer(r"\btest\(\s*(['\"])(.*?)\1\s*,", source, re.S)]
if len(names) != len(set(names)):
    dup = sorted({n for n in names if names.count(n) > 1})
    raise SystemExit('Duplicate Playwright test names: ' + ' | '.join(dup))

missing = [name for name in required if name not in names]
if missing:
    raise SystemExit('Required browser UAT scenarios missing: ' + ' | '.join(missing))

if len(names) < len(required):
    raise SystemExit(f'Browser UAT count regressed: {len(names)} < {len(required)}')

extras = [name for name in names if name not in required]
print(f'Browser UAT inventory PASS: required={len(required)} discovered={len(names)} extras={len(extras)}')
if extras:
    print('Additional browser scenarios:')
    for name in extras:
        print(' - ' + name)
sys.exit(0)
