from pathlib import Path
import datetime, hashlib, json, os, ssl, urllib.parse, urllib.request, urllib.error, uuid

BASE=Path(__file__).resolve().parent
LOG=BASE/'logs/enterprise-full-hq-results.json'
P1=os.getenv('EFC_HQ_PROPERTY1_URL','http://127.0.0.1:38190').rstrip('/')
P2=os.getenv('EFC_HQ_PROPERTY2_URL','http://127.0.0.1:38191').rstrip('/')
HQ=os.getenv('EFC_HQ_URL','https://127.0.0.1:38189').rstrip('/')
TOKEN=os.getenv('EFC_HQ_VIEWER_TOKEN','efc-hq-viewer-token-0123456789abcdef')
ADMIN=os.getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin');PASSWORD=os.getenv('APP_BOOTSTRAP_ADMIN_PASSWORD','Tamasya-UAT-RC1-Only!')
PROP1=os.getenv('EFC_HQ_PROPERTY1_ID','hotel-hq-01');PROP2=os.getenv('EFC_HQ_PROPERTY2_ID','hotel-hq-02');COMPANY=os.getenv('TAMASYA_COMPANY_ID','company-rc1-uat')
results=[];run='efchq_'+uuid.uuid4().hex[:8]

def save(): LOG.write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')
def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail});print('PASS' if ok else 'FAIL',name,str(detail or '')[:1200],flush=True);save()

def raw(url,method='GET',payload=None,headers=None,timeout=45):
    body=None; h={'Accept':'application/json'};h.update(headers or {})
    if payload is not None:
        body=json.dumps(payload,separators=(',',':')).encode();h['Content-Type']='application/json'
    req=urllib.request.Request(url,data=body,headers=h,method=method)
    try: resp=urllib.request.urlopen(req,timeout=timeout)
    except urllib.error.HTTPError as e: resp=e
    data=resp.read();ctype=(resp.headers.get('Content-Type') or '')
    parsed=None
    if 'json' in ctype or data[:1] in (b'{',b'['):
        try: parsed=json.loads(data.decode('utf8','replace'))
        except Exception: parsed=None
    return int(resp.status),parsed,data,dict(resp.headers)

def login(base,device):
    s,b,_,_=raw(base+'/api.php?action=login','POST',{'username':ADMIN,'password':PASSWORD,'offlineSessionScopeId':'offline_'+device},{'Origin':base,'X-Device-ID':device})
    if s!=200 or not isinstance(b,dict) or not b.get('token'): raise RuntimeError(f'login {base}: {s} {b}')
    return b['token']

def prop(base,token,command,method='GET',payload=None,operation=None,device='efc-hq-prop'):
    if method=='GET': url=base+'/api.php?action=multi-property&command='+urllib.parse.quote(command)
    else: url=base+'/api.php?action=multi-property'
    h={'Origin':base,'X-Device-ID':device,'X-Tamasya-Offline-Session-Scope':'offline_'+device,'Authorization':'Bearer '+token}
    if operation:h['X-Tamasya-Operation-ID']=operation
    return raw(url,method,payload,h)

def hq(action,method='GET',payload=None,query=None,token=TOKEN):
    q={'action':action};q.update(query or {})
    url=HQ+'/api.php?'+urllib.parse.urlencode(q)
    h={}
    if token is not None:h['Authorization']='Bearer '+token
    return raw(url,method,payload,h)

p1t=login(P1,'efchqprop1000001');p2t=login(P2,'efchqprop2000001')
today=datetime.date.today();from_date=today.replace(day=1).isoformat();to_date=today.isoformat()

# Contract and privacy surface: exact snapshot-v2 only, no row-level guest/booking/transaction data.
s1,b1,_,_=prop(P1,p1t,'snapshot-v2&from='+from_date+'&to='+to_date) if False else (None,None,None,None)
# Build query manually because prop() quotes command by design.
def snapshot(base,token,device):
    url=base+'/api.php?'+urllib.parse.urlencode({'action':'multi-property','command':'snapshot-v2','from':from_date,'to':to_date})
    return raw(url,'GET',headers={'Origin':base,'X-Device-ID':device,'X-Tamasya-Offline-Session-Scope':'offline_'+device,'Authorization':'Bearer '+token})
s1,b1,_,_=snapshot(P1,p1t,'efchqprop1000001');s2,b2,_,_=snapshot(P2,p2t,'efchqprop2000001')
snap1=(b1 or {}).get('data') or {};snap2=(b2 or {}).get('data') or {}
expected_keys={'contractVersion','companyId','propertyId','propertyName','currency','timezone','period','sourceRevision','metricsMinor','roomNights','integrity','checksumSha256'}
check('Property 1 exposes exact privacy-minimized HQ snapshot-v2 contract',s1==200 and set(snap1)==expected_keys and snap1.get('contractVersion')=='tamasya-hq-snapshot-v2' and snap1.get('propertyId')==PROP1,{'status':s1,'keys':sorted(snap1),'property':snap1.get('propertyId')})
check('Property 2 exposes exact privacy-minimized HQ snapshot-v2 contract',s2==200 and set(snap2)==expected_keys and snap2.get('propertyId')==PROP2,{'status':s2,'keys':sorted(snap2),'property':snap2.get('propertyId')})
serialized=json.dumps([snap1,snap2],sort_keys=True).lower()
check('HQ snapshot contract contains no raw guest, booking or transaction rows',all(k not in serialized for k in ['guestname','ktpphoto','bookingid','documentnumber','proofurl','staff_name']),{'bytes':len(serialized)})

# Current release explicitly stays read-model only.
s,c,_,_=prop(P1,p1t,'contracts',device='efchqprop1000001');contracts=(c or {}).get('data') or {}
check('HQ writeback and cross-property booking remain explicitly disabled',s==200 and contracts.get('futureHqWriteback',{}).get('enabled') is False and contracts.get('futureCrossPropertyReservation',{}).get('enabled') is False,contracts)
s,denied,_,_=prop(P1,p1t,'unused','POST',{'command':'writeback','operationId':'efc_forbidden_writeback_'+run},'efc_forbidden_writeback_'+run,'efchqprop1000001')
check('Unsupported HQ writeback command fails closed at property API',s in (400,409,422,500) and isinstance(denied,dict) and denied.get('success') is False,{'status':s,'body':denied})

# Real TLS bridge: direct push P1 + a true HQ-level idempotent replay, then P2.
# The browser/request receipt and the durable HQ operation are different layers:
# use a fresh transport operation ID for each HTTP mutation while keeping the
# immutable HQ operationId stable in the body. This reaches HQ twice instead of
# being short-circuited by the property request receipt.
op1='efc_hq_direct_p1_'+run
op1_transport_a='efc_hq_direct_transport_a_'+run
op1_transport_b='efc_hq_direct_transport_b_'+run
s,push1,_,_=prop(P1,p1t,'unused','POST',{'command':'push-summary','from':from_date,'to':to_date,'operationId':op1},op1_transport_a,'efchqprop1000001')
check('Property 1 direct HTTPS signed push is acknowledged by HQ',s==200 and push1.get('success') is True and push1.get('status')=='acknowledged' and push1.get('operation_id')==op1 and push1.get('receipt')==snap1.get('checksumSha256'),{'status':s,'body':push1})
s,replay,_,_=prop(P1,p1t,'unused','POST',{'command':'push-summary','from':from_date,'to':to_date,'operationId':op1},op1_transport_b,'efchqprop1000001')
check('HQ direct push replay is idempotently acknowledged as duplicate',s==200 and replay.get('success') is True and replay.get('duplicate') is True and replay.get('operation_id')==op1 and replay.get('receipt')==push1.get('receipt'),{'status':s,'body':replay})
op2='efc_hq_direct_p2_'+run
op2_transport='efc_hq_direct_transport_p2_'+run
s,push2,_,_=prop(P2,p2t,'unused','POST',{'command':'push-summary','from':from_date,'to':to_date,'operationId':op2},op2_transport,'efchqprop2000001')
check('Property 2 direct HTTPS signed push is acknowledged by HQ',s==200 and push2.get('success') is True and push2.get('operation_id')==op2 and push2.get('receipt')==snap2.get('checksumSha256'),{'status':s,'body':push2})

# Durable outbox uses the same immutable snapshot operation, but queue/deliver are
# distinct HTTP mutations and therefore must have distinct transport receipts.
outop='efc_hq_outbox_'+run
queue_transport='efc_hq_outbox_queue_'+run
deliver_transport='efc_hq_outbox_deliver_'+run
s,q,_,_=prop(P1,p1t,'unused','POST',{'command':'queue-snapshot','from':from_date,'to':to_date,'operationId':outop},queue_transport,'efchqprop1000001')
check('HQ durable outbox queues immutable snapshot',s in (200,202) and q.get('success') is True and (q.get('data') or {}).get('operation_id')==outop,{'status':s,'body':q})
s,d,_,_=prop(P1,p1t,'unused','POST',{'command':'deliver-snapshot','operationId':outop},deliver_transport,'efchqprop1000001')
check('HQ durable outbox delivers and persists acknowledgement',s==200 and d.get('status')=='acknowledged' and d.get('receipt')==snap1.get('checksumSha256'),{'status':s,'body':d})
s,ol,_,_=prop(P1,p1t,'outbox',device='efchqprop1000001')
jobs=((ol or {}).get('data') or {}).get('jobs') or []
check('HQ outbox read model retains acknowledged delivery evidence',s==200 and any(x.get('operation_id')==outop and x.get('status')=='acknowledged' for x in jobs),jobs[:5])

# Real HQ viewer consolidation across two property IDs.
s,overview,_,_=hq('overview')
check('HQ viewer grant exposes exactly the two commissioned properties',s==200 and overview.get('success') is True and set(overview.get('propertyIds') or [])=={PROP1,PROP2},overview)
s,consolidated,_,_=hq('consolidated',query={'properties':PROP1+','+PROP2,'from':from_date,'to':to_date})
report=((consolidated or {}).get('data') or {}).get('report') or {}
check('HQ consolidation contains both property sources and no missing property',s==200 and consolidated.get('success') is True and set(report.get('propertySet') or [])=={PROP1,PROP2} and len(report.get('sources') or [])==2 and not report.get('missingProperties'),{'status':s,'report':report})
if report:
    currency=snap1.get('currency','IDR');group=(report.get('currencies') or {}).get(currency) or {};m=group.get('metricsMinor') or {}
    expected={k:str(int(snap1['metricsMinor'][k])+int(snap2['metricsMinor'][k])) for k in snap1.get('metricsMinor',{})}
    check('HQ consolidated financial metrics equal exact sum of property snapshots',all(m.get(k)==v for k,v in expected.items()),{'expected':expected,'actual':m})

s,scope_denied,_,_=hq('consolidated',query={'properties':PROP1+',hotel-not-granted','from':from_date,'to':to_date})
check('HQ viewer cannot request property outside grant',s==403 and scope_denied.get('success') is False and scope_denied.get('code')=='PROPERTY_SCOPE_DENIED',{'status':s,'body':scope_denied})
s,badtoken,_,_=hq('overview',token='wrong-token-012345678901234567890123456789')
check('HQ rejects invalid viewer token',s==403 and badtoken.get('success') is False,{'status':s,'body':badtoken})

# Freeze immutable central report and prove canonical export surfaces.
s,frozen,_,_=hq('report-snapshot','POST',{'properties':[PROP1,PROP2],'from':from_date,'to':to_date})
report_id=((frozen or {}).get('data') or {}).get('reportId')
check('HQ freezes immutable consolidated report snapshot',s==200 and frozen.get('success') is True and isinstance(report_id,str) and len(report_id)==64,{'status':s,'body':frozen})
if report_id:
    for fmt in ['json','csv','xlsx','pdf','telegram','email']:
        s,parsed,data,h=hq('export',query={'id':report_id,'format':fmt})
        check('HQ canonical report export '+fmt,s==200 and len(data)>20,{'status':s,'contentType':h.get('Content-Type'),'bytes':len(data)})

# Same operation ID with a changed snapshot must be rejected, never silently overwrite receipt.
mutop='efc_hq_revision_bump_'+run
url=P1+'/api.php?action=inventory';headers={'Origin':P1,'X-Device-ID':'efchqprop1000001','X-Tamasya-Offline-Session-Scope':'offline_efchqprop1000001','Authorization':'Bearer '+p1t,'X-Tamasya-Operation-ID':mutop}
s,mut,_,_=raw(url,'POST',{'code':'EFC-HQ-'+run[-5:].upper(),'name':'HQ Revision Bump','category':'EFC','location':'HQ UAT','quantity':1,'unit':'unit','condition_status':'baik','price':0},headers)
check('Property mutation advances source before operation-payload conflict probe',s==200 and mut.get('success') is not False,{'status':s,'body':mut})
s,conflict,_,_=prop(P1,p1t,'unused','POST',{'command':'push-summary','from':from_date,'to':to_date,'operationId':op1},'efc_hq_conflict_probe_'+run,'efchqprop1000001')
check('HQ rejects reused operation ID when immutable snapshot payload changed',s>=400 and isinstance(conflict,dict) and conflict.get('success') is False,{'status':s,'body':conflict})

print('ENTERPRISE-FULL-HQ',sum(x['pass'] for x in results),'/',len(results))
