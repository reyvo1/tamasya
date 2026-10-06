#!/usr/bin/env python3
"""Conservative RC1 UAT inventory. Discovery is not a pass claim."""
from pathlib import Path
from html.parser import HTMLParser
import json,re
ROOT=Path(__file__).resolve().parents[2]
OUT=ROOT/'tests/uat_rc1/logs'; OUT.mkdir(parents=True,exist_ok=True)
PAGES=['index.html','property-setup.html','pos.html','internal-memo.html','growth-suite.html','enterprise-suite.html','multi-property-foundation.html','enterprise-suite.html','webpublic/index.html','hq/index.html','hq/control.html']
class Controls(HTMLParser):
    def __init__(self): super().__init__(); self.rows=[]; self.n=0
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag not in {'button','input','select','textarea','a','form'}: return
        if tag=='input' and a.get('type')=='hidden': return
        if tag=='a' and not a.get('href'): return
        self.n+=1
        self.rows.append({'tag':tag,'id':a.get('id'),'type':a.get('type'),'href':a.get('href'),'dataTab':a.get('data-tab'),'dataView':a.get('data-view'),'name':a.get('name')})
pages={}
for rel in PAGES:
    p=ROOT/rel
    if not p.is_file(): raise SystemExit(f'Missing UAT page: {rel}')
    h=Controls(); h.feed(p.read_text(errors='replace')); pages[rel]=h.rows
route_actions={}
for p in sorted((ROOT/'api/routes').glob('*.php')):
    s=p.read_text(errors='replace')
    vals=set(re.findall(r"case\s+['\"]([^'\"]+)['\"]\s*:",s))
    vals.update(re.findall(r"\$action[^;\n]{0,80}!?==?\s*['\"]([^'\"]+)['\"]",s))
    for body in re.findall(r"in_array\(\s*\$action\s*,\s*\[([^]]+)\]",s): vals.update(re.findall(r"['\"]([^'\"]+)['\"]",body))
    route_actions[p.name]=sorted(vals)
telegram=(ROOT/'api/routes/080_telegram_webhook.php').read_text(errors='replace')
callbacks=sorted(set(re.findall(r"callback_data[^\n]{0,220}?['\"]([^'\"]+)['\"]",telegram)))
nav=(ROOT/'assets/navigation-registry.js').read_text(errors='replace')
main_routes=sorted(set(re.findall(r"route:\s*['\"]([^'\"]+)['\"]",nav)))
modules=sorted(set(re.findall(r"elementId:\s*['\"]([^'\"]+)['\"]",nav)))
critical=['login','hotel-data','bookings','transactions','historical-backfill-review','pos-sale-create','pos-sale-void','canonical-report','telegram-bot','telegram-callback','bootstrap','multi-property','internal-memos']
all_actions={x for xs in route_actions.values() for x in xs}
# 'bootstrap' exists in two suite routes; multi-property is route-level action handled via router command.
missing=[x for x in critical if x not in all_actions and x!='multi-property']
if missing: raise SystemExit('Critical API actions missing from source inventory: '+','.join(missing))
thresholds={'property-setup.html':20,'pos.html':45,'growth-suite.html':80,'enterprise-suite.html':85,'multi-property-foundation.html':8,'internal-memo.html':10}
for page,n in thresholds.items():
    if len(pages[page])<n: raise SystemExit(f'{page} static control count regressed: {len(pages[page])} < {n}')
report={'scope':'RC1 source inventory; discovery alone is never PASS evidence','pages':{k:{'controlCount':len(v),'controls':v} for k,v in pages.items()},'routeActions':route_actions,'telegramCallbackTokens':callbacks,'mainNavigationRoutes':main_routes,'moduleDockIds':modules,'semanticEvidenceRequired':True}
(OUT/'coverage-inventory.json').write_text(json.dumps(report,indent=2,ensure_ascii=False))
print('Inventory pages:',len(pages),'static controls:',sum(len(v) for v in pages.values()),'API actions:',sum(len(v) for v in route_actions.values()),'main routes:',len(main_routes),'module dock:',len(modules))
