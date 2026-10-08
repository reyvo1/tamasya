from pathlib import Path
import json, sys

base=Path(__file__).resolve().parent
requirements={
    'multi-room-results.json': 80,
    'enterprise-full-workforce-inventory-results.json': 12,
    'enterprise-full-public-journey-results.json': 10,
    'enterprise-full-operational-results.json': 12,
    'enterprise-full-telegram-parity-results.json': 9,
    'enterprise-full-two-node-results.json': 19,
    'enterprise-full-hq-results.json': 14,
    # Locked baseline evidence must still be present; Full Complete adds coverage, never replaces it.
    'post-green-feature-results.json': 30,
    'telegram-results.json': 70,
    'export-results.json': 40,
    'concurrency-results.json': 4,
}
failed=[];summary={}
for name,minimum in requirements.items():
    p=base/'logs'/name
    if not p.exists():
        failed.append(name+': missing evidence');continue
    try:data=json.loads(p.read_text(encoding='utf8'))
    except Exception as e:
        failed.append(name+': invalid JSON '+str(e));continue
    if not isinstance(data,list):
        failed.append(name+': evidence is not assertion list');continue
    passes=sum(1 for x in data if isinstance(x,dict) and x.get('pass') is True)
    bad=[str(x.get('test','unnamed')) for x in data if not isinstance(x,dict) or x.get('pass') is not True]
    test_names=[str(x.get('test','')).strip() for x in data if isinstance(x,dict)]
    unnamed=sum(1 for x in test_names if not x)
    duplicates=sorted({x for x in test_names if x and test_names.count(x)>1})
    summary[name]={'assertions':len(data),'passes':passes,'minimum':minimum,'failures':bad,'unnamed':unnamed,'duplicates':duplicates}
    if len(data)<minimum:failed.append(f'{name}: only {len(data)} assertions, minimum {minimum}')
    if unnamed:failed.append(f'{name}: {unnamed} assertions have no stable test name')
    if duplicates:failed.append(name+': duplicate assertion names: '+', '.join(duplicates[:10]))
    if bad:failed.append(name+': failed assertions: '+', '.join(bad[:10]))

out=base/'logs/full-complete-coverage-summary.json'
out.write_text(json.dumps({'success':not failed,'evidence':summary,'failures':failed},ensure_ascii=False,indent=2),encoding='utf8')
print(json.dumps({'success':not failed,'evidence':summary,'failures':failed},ensure_ascii=False,indent=2))
sys.exit(1 if failed else 0)
