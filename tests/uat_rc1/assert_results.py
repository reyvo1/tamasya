from pathlib import Path
import json,sys
base=Path(__file__).resolve().parent
files=sorted((base/'logs').glob('*-results.json'))
if not files:
    print('FAIL: no UAT result files found'); sys.exit(2)
total=failed=0
for f in files:
    try: rows=json.loads(f.read_text(encoding='utf8'))
    except Exception as e:
        print('FAIL',f.name,'invalid JSON',e); failed+=1; continue
    for row in rows:
        if not isinstance(row,dict) or 'pass' not in row: continue
        total+=1
        if not row.get('pass'):
            failed+=1; print('FAIL',f.name,row.get('test'),row.get('details',row.get('message','')))
print(f'UAT ASSERTIONS: {total-failed}/{total} PASS; {failed} FAIL')
sys.exit(1 if failed else 0)
