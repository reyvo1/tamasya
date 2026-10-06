from pathlib import Path
import argparse, json, os, subprocess, urllib.request, urllib.error, uuid

BASE=Path(__file__).resolve().parent
LOG=BASE/'logs/enterprise-full-two-node-results.json'
STATE=BASE/'two-node-state.json'
A_URL=os.getenv('EFC_NODE_A_URL','http://127.0.0.1:38186').rstrip('/')
B_URL=os.getenv('EFC_NODE_B_URL','http://127.0.0.1:38187').rstrip('/')
A_DB=os.getenv('EFC_NODE_A_DB_CONFIG','/tmp/tamasya-efc-node-a-db.php')
B_DB=os.getenv('EFC_NODE_B_DB_CONFIG','/tmp/tamasya-efc-node-b-db.php')
A_DB_NAME=os.getenv('EFC_NODE_A_DB_NAME','tamasya_rc1_node_a')
B_DB_NAME=os.getenv('EFC_NODE_B_DB_NAME','tamasya_rc1_node_b')
ADMIN=os.getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin')
PASSWORD=os.getenv('APP_BOOTSTRAP_ADMIN_PASSWORD','Tamasya-UAT-RC1-Only!')

try: results=json.loads(LOG.read_text()) if LOG.exists() else []
except Exception: results=[]

def save(): LOG.write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')
def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:1200],flush=True); save()

def db(config,name,sql,params=None):
    env=os.environ.copy();env.update({'APP_CREDENTIALS_FILE':config,'APP_EXPECTED_DB_NAME':name,'APP_REQUIRE_EXPECTED_DB_NAME':'1','APP_TIMEZONE':'Asia/Makassar'})
    p=subprocess.run(['php',str(BASE/'db.php')],input=json.dumps({'sql':sql,'params':params or []}),capture_output=True,text=True,encoding='utf8',env=env)
    if p.returncode: raise RuntimeError(f'DB {name}: {p.stderr.strip()}')
    return json.loads(p.stdout or '[]')

def http(base,action,method='GET',payload=None,token=None,operation=None,device='efc-two-node'):
    q=action if action.startswith('action=') else 'action='+action
    url=base+'/api.php?'+q
    headers={'Accept':'application/json','Origin':base,'X-Device-ID':device,'X-Tamasya-Offline-Session-Scope':'offline_efc_two_node_001'}
    if token: headers['Authorization']='Bearer '+token
    if operation: headers['X-Tamasya-Operation-ID']=operation
    body=None
    if payload is not None:
        body=json.dumps(payload,separators=(',',':')).encode();headers['Content-Type']='application/json'
    req=urllib.request.Request(url,data=body,headers=headers,method=method)
    try: resp=urllib.request.urlopen(req,timeout=35)
    except urllib.error.HTTPError as e: resp=e
    raw=resp.read().decode('utf8','replace')
    try: parsed=json.loads(raw) if raw else {}
    except Exception: parsed={'success':False,'raw':raw[:3000]}
    return int(resp.status),parsed,dict(resp.headers)

def login(base,device):
    s,b,_=http(base,'login','POST',{'username':ADMIN,'password':PASSWORD,'offlineSessionScopeId':'offline_efc_two_node_'+device},device=device)
    if s!=200 or not b.get('token'): raise RuntimeError(f'Login {base} failed {s}: {b}')
    return b['token']

def status(base,token,device): return http(base,'node-cluster-status','GET',token=token,device=device)
def rows(config,name,code): return db(config,name,'SELECT id,code,name,location,condition_status FROM inventory WHERE code=?',[code])
def read_state(): return json.loads(STATE.read_text()) if STATE.exists() else {}
def write_state(x): STATE.write_text(json.dumps(x,indent=2),encoding='utf8')

ap=argparse.ArgumentParser();ap.add_argument('--phase',required=True,choices=['online-forward','primary-outage','planned-switch','final']);args=ap.parse_args()
state=read_state(); run=state.get('run') or ('efc2_'+uuid.uuid4().hex[:8]); state['run']=run

if args.phase=='online-forward':
    ta=login(A_URL,'nodeA0000000001');tb=login(B_URL,'nodeB0000000001')
    sa,ba,_=status(A_URL,ta,'nodeA0000000001'); sb,bb,_=status(B_URL,tb,'nodeB0000000001')
    ca=(ba.get('cluster') or {});cb=(bb.get('cluster') or {})
    check('Two-node starts with A as exactly one Primary writer',sa==200 and ca.get('isPrimaryWriter') is True and ca.get('primaryNodeId')=='efc-node-a',ca)
    check('Two-node starts with B as fenced Standby',sb==200 and cb.get('isPrimaryWriter') is False and cb.get('primaryNodeId')=='efc-node-a',cb)
    code='EFC2-FWD-'+run[-6:].upper();state['forwardCode']=code
    s,b,h=http(B_URL,'inventory','POST',{'code':code,'name':'Two Node Forward Asset','category':'EFC Hybrid','location':'Primary A','quantity':1,'unit':'unit','condition_status':'baik','price':0},tb,'efc2_forward_'+run,'nodeB0000000001')
    check('Standby write is executed by active Primary through signed forwarding',s==200 and b.get('success') is not False and h.get('X-Tamasya-Execution-Node')=='active-primary',{'status':s,'body':b,'execution':h.get('X-Tamasya-Execution-Node')})
    check('Forwarded write exists on Primary database only before mirror',len(rows(A_DB,A_DB_NAME,code))==1 and len(rows(B_DB,B_DB_NAME,code))==0,{'a':rows(A_DB,A_DB_NAME,code),'b':rows(B_DB,B_DB_NAME,code)})

    memo_payload={'command':'create','title':'Hybrid memo '+run,'body':'Memo must survive both Primary directions','category':'finance','priority':'high'}
    s,b,h=http(B_URL,'internal-memos','POST',memo_payload,tb,'efc2_memo_'+run,'nodeB0000000001')
    state['memoId']=(b.get('data') or {}).get('id')
    check('Standby memo creation forwards to active Primary',s==200 and bool(state['memoId']) and h.get('X-Tamasya-Execution-Node')=='active-primary',{'status':s,'id':state['memoId'],'body':b,'execution':h.get('X-Tamasya-Execution-Node')})
    replay_s,replay_b,replay_h=http(B_URL,'internal-memos','POST',memo_payload,tb,'efc2_memo_'+run,'nodeB0000000001')
    memo_rows=db(A_DB,A_DB_NAME,'SELECT id FROM growth_internal_memos WHERE title=?',[memo_payload['title']])
    check('Forwarded memo retry reuses receipt without duplicate creation',replay_s==200 and bool(state['memoId']) and (replay_b.get('data') or {}).get('id')==state['memoId'] and len(memo_rows)==1 and replay_h.get('X-Tamasya-Execution-Node')=='active-primary',{'status':replay_s,'body':replay_b,'rows':memo_rows})

elif args.phase=='primary-outage':
    tb=login(B_URL,'nodeB0000000002')
    code='EFC2-OFF-'+run[-6:].upper();state['outageCode']=code
    before=int(db(B_DB,B_DB_NAME,'SELECT COUNT(*) n FROM inventory')[0]['n'])
    s,b,_=http(B_URL,'inventory','POST',{'code':code,'name':'MUST NOT WRITE WHILE PRIMARY DOWN','category':'EFC Hybrid','location':'Standby B','quantity':1,'unit':'unit','condition_status':'baik','price':0},tb,'efc2_offline_'+run,'nodeB0000000002')
    after=int(db(B_DB,B_DB_NAME,'SELECT COUNT(*) n FROM inventory')[0]['n'])
    check('Standby refuses business mutation while Primary is unreachable',s==503 and isinstance(b,dict) and b.get('success') is False,{'status':s,'body':b})
    check('Primary outage never falls back to local Standby write',before==after and len(rows(B_DB,B_DB_NAME,code))==0,{'before':before,'after':after,'row':rows(B_DB,B_DB_NAME,code)})
    memo_before=int(db(B_DB,B_DB_NAME,'SELECT COUNT(*) n FROM growth_internal_memos')[0]['n'])
    memo_s,memo_b,_=http(B_URL,'internal-memos','POST',{'command':'create','title':'Must not write memo while Primary is down '+run,'body':'Fenced standby must remain read-only'},tb,'efc2_offline_memo_'+run,'nodeB0000000002')
    memo_after=int(db(B_DB,B_DB_NAME,'SELECT COUNT(*) n FROM growth_internal_memos')[0]['n'])
    check('Primary outage refuses memo forwarding without local fallback',memo_s==503 and memo_b.get('success') is False and memo_before==memo_after,{'status':memo_s,'body':memo_b,'before':memo_before,'after':memo_after})

elif args.phase=='planned-switch':
    ta=login(A_URL,'nodeA0000000003');tb=login(B_URL,'nodeB0000000003')
    fwd=state['forwardCode'];off=state['outageCode']
    check('Recovered Standby mirror contains the prior Primary-forwarded mutation',len(rows(A_DB,A_DB_NAME,fwd))==1 and len(rows(B_DB,B_DB_NAME,fwd))==1,{'a':rows(A_DB,A_DB_NAME,fwd),'b':rows(B_DB,B_DB_NAME,fwd)})
    check('Rejected outage mutation remains absent after recovery mirror',len(rows(A_DB,A_DB_NAME,off))==0 and len(rows(B_DB,B_DB_NAME,off))==0)
    s,b,_=http(A_URL,'node-cluster-switch-primary','POST',{'reason':'Enterprise Full Complete planned switchover verifies two-node parity and fencing','confirmPhrase':'PINDAHKAN PRIMARY'},ta,'efc2_switch_'+run,'nodeA0000000003')
    check('Planned switchover verifies dataset checksum and promotes B',s==200 and b.get('success') is True and (b.get('cluster') or {}).get('primaryNodeId')=='efc-node-b',{'status':s,'body':b})
    sa,ba,_=status(A_URL,ta,'nodeA0000000003');sb,bb,_=status(B_URL,tb,'nodeB0000000003')
    check('After switchover old A is fenced Standby',sa==200 and (ba.get('cluster') or {}).get('isPrimaryWriter') is False and (ba.get('cluster') or {}).get('primaryNodeId')=='efc-node-b',ba)
    check('After switchover B is the only Primary writer',sb==200 and (bb.get('cluster') or {}).get('isPrimaryWriter') is True and (bb.get('cluster') or {}).get('primaryNodeId')=='efc-node-b',bb)
    mid=state.get('memoId')
    memo_a=db(A_DB,A_DB_NAME,'SELECT id,title,body,status FROM growth_internal_memos WHERE id=?',[mid])
    memo_b=db(B_DB,B_DB_NAME,'SELECT id,title,body,status FROM growth_internal_memos WHERE id=?',[mid])
    check('Memo created on A survives mirror and promotion of B',bool(mid) and memo_a==memo_b and len(memo_b)==1,{'A':memo_a,'B':memo_b})
    s,b,h=http(A_URL,'internal-memos','PUT',{'command':'archive','id':mid},ta,'efc2_archive_memo_'+run,'nodeA0000000003')
    check('Old Primary forwards memo archive to new Primary',s==200 and (b.get('data') or {}).get('status')=='archived' and h.get('X-Tamasya-Execution-Node')=='active-primary',{'status':s,'body':b,'execution':h.get('X-Tamasya-Execution-Node')})
    code='EFC2-REV-'+run[-6:].upper();state['reverseCode']=code
    s,b,h=http(A_URL,'inventory','POST',{'code':code,'name':'Reverse Forward Asset','category':'EFC Hybrid','location':'Primary B','quantity':1,'unit':'unit','condition_status':'baik','price':0},ta,'efc2_reverse_'+run,'nodeA0000000003')
    check('Old Primary now forwards mutation to newly promoted B',s==200 and b.get('success') is not False and h.get('X-Tamasya-Execution-Node')=='active-primary',{'status':s,'body':b,'execution':h.get('X-Tamasya-Execution-Node')})
    check('Reverse-forwarded write exists on new Primary only before mirror-back',len(rows(B_DB,B_DB_NAME,code))==1 and len(rows(A_DB,A_DB_NAME,code))==0,{'a':rows(A_DB,A_DB_NAME,code),'b':rows(B_DB,B_DB_NAME,code)})

elif args.phase=='final':
    ta=login(A_URL,'nodeA0000000004');tb=login(B_URL,'nodeB0000000004')
    rev=state['reverseCode'];fwd=state['forwardCode']
    check('Mirror-back converges mutations created under both Primary directions',len(rows(A_DB,A_DB_NAME,fwd))==1 and len(rows(B_DB,B_DB_NAME,fwd))==1 and len(rows(A_DB,A_DB_NAME,rev))==1 and len(rows(B_DB,B_DB_NAME,rev))==1,{'aForward':rows(A_DB,A_DB_NAME,fwd),'bForward':rows(B_DB,B_DB_NAME,fwd),'aReverse':rows(A_DB,A_DB_NAME,rev),'bReverse':rows(B_DB,B_DB_NAME,rev)})
    ra=int(db(A_DB,A_DB_NAME,"SELECT server_revision FROM config WHERE id='system_default'")[0]['server_revision']); rb=int(db(B_DB,B_DB_NAME,"SELECT server_revision FROM config WHERE id='system_default'")[0]['server_revision'])
    check('Two-node final server revision is identical',ra==rb,{'A':ra,'B':rb})
    for table in ['bookings','transactions','journal_entries','journal_lines','inventory','housekeeping_tasks','public_reservation_requests','staff_savings_ledger','growth_internal_memos']:
        a=int(db(A_DB,A_DB_NAME,f'SELECT COUNT(*) n FROM `{table}`')[0]['n']); b=int(db(B_DB,B_DB_NAME,f'SELECT COUNT(*) n FROM `{table}`')[0]['n'])
        check('Two-node canonical row-count parity: '+table,a==b,{'A':a,'B':b})
    mid=state.get('memoId')
    memo_a=db(A_DB,A_DB_NAME,'SELECT id,title,body,status FROM growth_internal_memos WHERE id=?',[mid])
    memo_b=db(B_DB,B_DB_NAME,'SELECT id,title,body,status FROM growth_internal_memos WHERE id=?',[mid])
    check('Archived memo contents converge after reverse mirror',bool(mid) and len(memo_a)==1 and memo_a==memo_b and memo_a[0]['status']=='archived',{'A':memo_a,'B':memo_b})
    sa,ba,_=status(A_URL,ta,'nodeA0000000004');sb,bb,_=status(B_URL,tb,'nodeB0000000004')
    check('Final cluster reports B Primary and A Standby without split-brain',sa==200 and sb==200 and (ba.get('cluster') or {}).get('primaryNodeId')=='efc-node-b' and (bb.get('cluster') or {}).get('primaryNodeId')=='efc-node-b' and not (ba.get('cluster') or {}).get('splitBrainRisk') and not (bb.get('cluster') or {}).get('splitBrainRisk'),{'A':ba.get('cluster'),'B':bb.get('cluster')})

write_state(state)
print('ENTERPRISE-FULL-TWO-NODE',sum(1 for x in results if x['pass']),'/',len(results))
