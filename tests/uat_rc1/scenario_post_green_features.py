from client import *
from scenario_core import db
import json, os, urllib.request, urllib.error, uuid

results=[]
run='postgreen_'+uuid.uuid4().hex[:10]

def evidence_safe(value):
    # Evidence serialization is intentionally strict: normalize only standard
    # container/view types used by assertions. Unknown objects still fail closed.
    if value is None or isinstance(value,(str,int,float,bool)):
        return value
    if isinstance(value,dict):
        return {str(k):evidence_safe(v) for k,v in value.items()}
    if isinstance(value,(list,tuple)):
        return [evidence_safe(v) for v in value]
    if isinstance(value,(set,frozenset)) or type(value).__name__ in {'dict_keys','dict_values','dict_items'}:
        return [evidence_safe(v) for v in value]
    raise TypeError('Unsupported UAT evidence type: '+type(value).__name__)

def check(name,ok,detail=None):
    safe_detail=evidence_safe(detail)
    results.append({'test':name,'pass':bool(ok),'details':safe_detail})
    print('PASS' if ok else 'FAIL',name,str(safe_detail or '')[:900],flush=True)
    (base/'logs/post-green-feature-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')

def raw_login(username,password,scope):
    payload=json.dumps({'username':username,'password':password,'offlineSessionScopeId':scope}).encode('utf8')
    req=urllib.request.Request(BASE_URL+'/api.php?action=login',data=payload,headers={'Origin':ORIGIN,'X-Device-ID':DEVICE_ID,'Content-Type':'application/json'},method='POST')
    try: resp=urllib.request.urlopen(req,timeout=90)
    except urllib.error.HTTPError as e: resp=e
    body=json.loads(resp.read().decode('utf8','replace') or '{}')
    return int(resp.status),body

def as_session(session, fn):
    p=base/'session.json'
    old=p.read_text(encoding='utf8') if p.exists() else None
    p.write_text(json.dumps(session,ensure_ascii=False,indent=2),encoding='utf8')
    try: return fn()
    finally:
        if old is None: p.unlink(missing_ok=True)
        else: p.write_text(old,encoding='utf8')

def validate_owner_read_contract(label,status,body,owner_username):
    if status != 200:
        return False, {'reason':'unexpected HTTP status','status':status,'body':body}

    # Staff GET is a canonical bare-array endpoint. Keep that contract intact and
    # assert both row shape and the just-created Owner instead of normalizing it
    # into a synthetic success/data envelope.
    if label == 'staff':
        valid_rows = isinstance(body,list) and bool(body) and all(
            isinstance(row,dict) and all(key in row for key in ['id','name','role','status'])
            for row in body
        )
        owner_visible = valid_rows and any(
            row.get('username') == owner_username and row.get('role') == 'owner' and row.get('status') == 'active'
            for row in body
        )
        return bool(valid_rows and owner_visible), {
            'contract':'bare-list',
            'count':len(body) if isinstance(body,list) else None,
            'ownerVisible':bool(owner_visible),
            'body':body,
        }

    if not isinstance(body,dict):
        return False, {'reason':'expected object response','status':status,'body':body}

    if label == 'hotel_data':
        required=['rooms','bookings','transactions','currentUser','dataHealth']
        ok=all(key in body for key in required) and isinstance(body.get('rooms'),list) and isinstance(body.get('bookings'),list) and isinstance(body.get('transactions'),list) and isinstance(body.get('currentUser'),dict) and (body.get('currentUser') or {}).get('role') == 'owner' and isinstance(body.get('dataHealth'),dict)
        return bool(ok), {'contract':'hotel-data-object','required':required,'currentUser':body.get('currentUser'),'dataHealth':body.get('dataHealth')}

    if body.get('success') is not True:
        return False, {'reason':'success envelope missing/false','status':status,'body':body}

    if label == 'support':
        ok=isinstance(body.get('conversations'),list) and isinstance(body.get('messages'),list)
        return bool(ok), {'contract':'support-envelope','conversationCount':len(body.get('conversations') or []),'messageCount':len(body.get('messages') or [])}
    if label == 'memo':
        return isinstance(body.get('data'),list), {'contract':'data-list-envelope','count':len(body.get('data') or []) if isinstance(body.get('data'),list) else None}
    if label == 'savings':
        return isinstance(body.get('accounts'),list) and body.get('canManage') is False, {'contract':'accounts-read-only','count':len(body.get('accounts') or [])}
    if label in ['growth','enterprise','multi_property','operations']:
        return isinstance(body.get('data'),dict), {'contract':'data-object-envelope','keys':sorted((body.get('data') or {}).keys()) if isinstance(body.get('data'),dict) else None}
    if label in ['enterprise_accounting','enterprise_forecast','enterprise_aging']:
        return isinstance(body.get('data'),dict), {'contract':'data-object-envelope','keys':sorted((body.get('data') or {}).keys())}
    if label == 'website':
        return all(k in body for k in ['settings','roomTypes','promotions','media']), {'contract':'cms-read-envelope'}
    if label == 'enterprise_adapters':
        return isinstance(body.get('data'),list), {'contract':'data-list-envelope','count':len(body.get('data') or []) if isinstance(body.get('data'),list) else None}
    if label == 'pos_products':
        return isinstance(body.get('products'),list), {'contract':'products-envelope','count':len(body.get('products') or []) if isinstance(body.get('products'),list) else None}
    return False, {'reason':'owner read contract is not declared','label':label,'body':body}

# ----- Internal Memo: persistence, audit, archive/restore, RBAC -----
status,memo=request('internal-memos','POST',{
    'command':'create','title':'UAT Internal Memo '+run,'body':'Catatan audit internal untuk Finance dan Admin. '+run,
    'category':'finance','priority':'high'
},run+'_memo_create')
check('Admin can create an internal memo',status==200 and memo.get('success') is True and bool((memo.get('data') or {}).get('id')),memo)
memo_id=(memo.get('data') or {}).get('id')
if memo_id:
    rows=db('SELECT id,title,status,category,priority,created_by FROM growth_internal_memos WHERE id=?',[memo_id])
    check('Memo persists in official Enterprise table',len(rows)==1 and rows[0]['status']=='active' and rows[0]['category']=='finance' and rows[0]['priority']=='high',rows)
    audits=db("SELECT entity_type,entity_id,action FROM audit_logs WHERE entity_type='growth_internal_memo' AND entity_id=? ORDER BY created_at DESC",[memo_id])
    check('Memo create writes required enterprise audit',len(audits)>=1,audits)
    status,upd=request('internal-memos','PUT',{'command':'update','id':memo_id,'title':'UAT Memo Updated '+run,'priority':'urgent'},run+'_memo_update')
    check('Admin can update memo without replacing row',status==200 and upd.get('success') is True and (upd.get('data') or {}).get('id')==memo_id,upd)
    status,arc=request('internal-memos','PUT',{'command':'archive','id':memo_id},run+'_memo_archive')
    check('Memo archive is non-destructive',status==200 and arc.get('success') is True and db('SELECT status FROM growth_internal_memos WHERE id=?',[memo_id])[0]['status']=='archived',arc)
    check('Archived memo row still exists',int(db('SELECT COUNT(*) n FROM growth_internal_memos WHERE id=?',[memo_id])[0]['n'])==1)
    status,rest=request('internal-memos','PUT',{'command':'restore','id':memo_id},run+'_memo_restore')
    check('Archived memo can be restored',status==200 and rest.get('success') is True and db('SELECT status FROM growth_internal_memos WHERE id=?',[memo_id])[0]['status']=='active',rest)
    check('Memo lifecycle writes audit on every mutation',int(db("SELECT COUNT(*) n FROM audit_logs WHERE entity_type='growth_internal_memo' AND entity_id=?",[memo_id])[0]['n'])>=4,db("SELECT action,created_at FROM audit_logs WHERE entity_type='growth_internal_memo' AND entity_id=? ORDER BY created_at",[memo_id]))
    status,listing=request('action=internal-memos&status=all&q='+run,'GET')
    check('Memo search/status filter returns exact memo',status==200 and any(x.get('id')==memo_id for x in (listing.get('data') or [])),listing)

# Finance role uses a real account cloned from the bootstrap hash to avoid weakening password/auth logic.
admin=db("SELECT id,password FROM staff WHERE username=? LIMIT 1",[os.getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin')])[0]
finance_user='uat_fin_'+run[-6:]
finance_id='uat_finance_'+run[-8:]
db('DELETE FROM staff WHERE id=? OR username=?',[finance_id,finance_user])
db("INSERT INTO staff(id,name,username,password,role,status,salary,password_changed_at,require_password_change) VALUES (?,?,?,?,?,'active',0,CURRENT_TIMESTAMP,0)",[finance_id,'UAT Finance Memo',finance_user,admin['password'],'finance'])
# We intentionally login using the same bootstrap password whose hash was cloned.
finance_password=os.getenv('APP_BOOTSTRAP_ADMIN_PASSWORD','Tamasya-UAT-RC1-Only!')
fs,fb=raw_login(finance_user,finance_password,'offline_finance_uat_'+run)
check('Finance fixture authenticates through canonical login',fs==200 and fb.get('role')=='finance' and bool(fb.get('token')),fb)
if fs==200:
    def finance_ops():
        s1,b1=request('internal-memos','POST',{'command':'create','title':'Finance Memo '+run,'body':'Finance may create internal memo','category':'finance','priority':'normal'},run+'_finance_memo')
        fid=(b1.get('data') or {}).get('id') if isinstance(b1,dict) else None
        s2,b2=request('internal-memos','GET')
        s3,b3=request('internal-memos','PUT',{'command':'update','id':fid,'body':'Finance updated memo through canonical API','priority':'high'},run+'_finance_memo_update') if fid else (0,{})
        return s1,b1,s2,b2,s3,b3
    s1,b1,s2,b2,s3,b3=as_session(fb,finance_ops)
    check('Finance can create memo',s1==200 and b1.get('success') is True,b1)
    check('Finance can read memo board',s2==200 and b2.get('success') is True,b2)
    check('Finance can update memo',s3==200 and b3.get('success') is True and (b3.get('data') or {}).get('priority')=='high',b3)

# ----- Owner: all-read, global server-side no-write -----
owner_username='uat_owner_'+run[-6:]
owner_password='Owner-UAT-RC1-Only!42x'
status,created=request('staff','POST',{'name':'UAT Owner Read Only','username':owner_username,'password':owner_password,'role':'owner','salary':0},run+'_owner_create')
check('Admin can create official Owner role through Staff API',status==200 and created.get('success') is not False,created)
owner_rows=db('SELECT id,role,status FROM staff WHERE username=?',[owner_username])
check('Owner role persists exactly as owner',len(owner_rows)==1 and owner_rows[0]['role']=='owner' and owner_rows[0]['status']=='active',owner_rows)
oscope='offline_owner_uat_'+run
os,ob=raw_login(owner_username,owner_password,oscope)
check('Owner authenticates through canonical login',os==200 and ob.get('role')=='owner' and bool(ob.get('token')),ob)

if os==200:
    def owner_suite():
        reads=[]
        for label,action in [
            ('hotel_data','hotel-data'),('staff','staff'),('support','public-support-inbox'),('operations','operations-center'),('savings','action=staff-savings&scope=all'),
            ('memo','internal-memos'),('growth','action=growth-suite&command=bootstrap'),
            ('enterprise','action=enterprise-suite&command=bootstrap'),('enterprise_adapters','action=enterprise-suite&command=provider-adapters'),('multi_property','action=multi-property&command=overview'),
            ('enterprise_accounting','action=enterprise-suite&command=accounting-summary'),('enterprise_forecast','action=enterprise-suite&command=revenue-forecast'),('enterprise_aging','action=enterprise-suite&command=ap-aging'),('website','website-cms-data'),
            ('pos_products','pos-products')
        ]:
            s,b=request(action,'GET'); reads.append((label,s,b))
        # Confirm real seeded records open independently of their write actions.
        for table,command in [('growth_folios','folio-detail'),('growth_purchase_requests','pr-detail'),('growth_purchase_orders','po-detail'),('growth_goods_receipts','grn-detail'),('growth_supplier_invoices','supplier-invoice-detail')]:
            rows=db('SELECT id FROM '+table+' ORDER BY id LIMIT 1')
            check('Owner detail UAT has seeded record: '+command,bool(rows),rows)
            if rows:
                s,b=request('action=enterprise-suite&command='+command+'&id='+str(rows[0]['id']),'GET')
                check('Owner opens existing Enterprise detail: '+command,s==200 and b.get('success') is True and isinstance(b.get('data'),dict),{'status':s,'dataKeys':list((b.get('data') or {}).keys())})
        # Snapshot canonical state before direct API bypass attempts.
        snap={
            'transactions':int(db('SELECT COUNT(*) n FROM transactions')[0]['n']),
            'bookings':int(db('SELECT COUNT(*) n FROM bookings')[0]['n']),
            'staff':int(db('SELECT COUNT(*) n FROM staff')[0]['n']),
            'memos':int(db('SELECT COUNT(*) n FROM growth_internal_memos')[0]['n']),
            'journals':int(db('SELECT COUNT(*) n FROM journal_entries')[0]['n']),
            'enterpriseState':{t:db('SELECT * FROM '+t+' ORDER BY id') for t in ['growth_folio_charge_allocations','growth_folio_invoices','growth_supplier_invoices','growth_supplier_invoice_payments','growth_crm_campaigns','growth_provider_adapters','public_site_settings']}
        }
        mutations=[]
        probes=[
            ('booking','bookings',{'guestName':'OWNER MUST FAIL','roomNumber':'101','checkIn':'2099-01-01','checkOut':'2099-01-02','totalAmount':1}),
            ('transaction','transactions',{'type':'income','amount':1,'description':'OWNER MUST FAIL'}),
            ('shift','operations-center',{'command':'shift-open','openingCash':0,'shiftTime':'siang'}),
            ('pos','pos-product-save',{'sku':'OWNERFAIL','name':'OWNER MUST FAIL','price':1}),
            ('growth','growth-suite',{'command':'rate-plan-save','code':'OWNERFAIL','name':'OWNER MUST FAIL','baseRate':1}),
            ('enterprise','enterprise-suite',{'command':'pr-save','department':'OWNER','reason':'OWNER MUST FAIL','items':[]}),
            ('staff','staff',{'name':'OWNER FAIL','username':'owner_fail_x','password':'Owner-Fail-Only!123','role':'receptionist'}),
            ('memo','internal-memos',{'command':'create','title':'OWNER FAIL','body':'OWNER MUST NOT CREATE'}),
            ('website','website-cms-settings-save',{'settings':{'hotelName':'OWNER MUST FAIL'}}),
            *[('enterprise_'+cmd,'enterprise-suite',{'command':cmd,'id':'owner-must-fail','amount':1}) for cmd in ['folio-charge-allocation-save','folio-invoice-issue','supplier-invoice-post','ap-payment-create','crm-campaign-send-batch','provider-adapter-save']]
        ]
        for label,action,payload in probes:
            s,b=request(action,'POST',payload,run+'_owner_'+label)
            mutations.append((label,s,b))
        after={
            'transactions':int(db('SELECT COUNT(*) n FROM transactions')[0]['n']),
            'bookings':int(db('SELECT COUNT(*) n FROM bookings')[0]['n']),
            'staff':int(db('SELECT COUNT(*) n FROM staff')[0]['n']),
            'memos':int(db('SELECT COUNT(*) n FROM growth_internal_memos')[0]['n']),
            'journals':int(db('SELECT COUNT(*) n FROM journal_entries')[0]['n']),
            'enterpriseState':{t:db('SELECT * FROM '+t+' ORDER BY id') for t in ['growth_folio_charge_allocations','growth_folio_invoices','growth_supplier_invoices','growth_supplier_invoice_payments','growth_crm_campaigns','growth_provider_adapters','public_site_settings']}
        }
        return reads,mutations,snap,after
    admin_hotel_status,admin_hotel=request('hotel-data','GET')
    admin_ops_status,admin_ops=request('operations-center','GET')
    reads,mutations,before,after=as_session(ob,owner_suite)
    for label,s,b in reads:
        valid,detail=validate_owner_read_contract(label,s,b,owner_username)
        check('Owner can read '+label,valid,detail)
    read_map={label:b for label,s,b in reads if s==200 and isinstance(b,dict)}
    owner_hotel=read_map.get('hotel_data') or {}
    owner_ops=(read_map.get('operations') or {}).get('data') or {}
    for key in ['salarySlips','attendance','telegramMessages','inventory','transactions','bookings']:
        check('Owner has Admin read scope: '+key,admin_hotel_status==200 and owner_hotel.get(key)==admin_hotel.get(key),{'ownerCount':len(owner_hotel.get(key) or []),'adminCount':len(admin_hotel.get(key) or [])})
    for key in ['journalEntries','journalLines','taxRules','sessions','syncDevices','housekeepingTasks','maintenanceTickets','backupRuns']:
        check('Owner has complete Operations read scope: '+key,admin_ops_status==200 and sorted(str(x.get('id',x.get('device_id',''))) for x in owner_ops.get(key) or [])==sorted(str(x.get('id',x.get('device_id',''))) for x in (admin_ops.get('data') or {}).get(key) or []),{'ownerCount':len(owner_ops.get(key) or []),'adminCount':len((admin_ops.get('data') or {}).get(key) or [])})
    owner_permissions=(owner_hotel.get('currentUser') or {}).get('permissions') or {}
    check('Owner preset advertises read-only and rejects write capability overrides',owner_permissions.get('readOnly') is True and owner_permissions.get('desktopTabs',{}).get('staff') is True and owner_permissions.get('capabilities',{}).get('manage_backup') is False,owner_permissions)
    growth_data=(read_map.get('growth') or {}).get('data') or {}
    enterprise_data=(read_map.get('enterprise') or {}).get('data') or {}
    pos_data=read_map.get('pos_products') or {}
    check('Owner Growth bootstrap includes procurement/channel/payment read projections',all(k in growth_data for k in ['vendors','purchaseOrders','channelMappings','paymentIntents']),growth_data.keys())
    check('Owner Enterprise bootstrap includes AP/CRM/health read projections',all(k in enterprise_data for k in ['purchaseRequests','supplierInvoices','loyalty','segments','campaigns','health']),enterprise_data.keys())
    check('Owner POS product projection includes cost data read-only',bool((pos_data.get('products') or [])) and all('costPrice' in x or 'cost' in x or 'cost_price' in x for x in (pos_data.get('products') or [])[:3]),(pos_data.get('products') or [])[:1])
    for label,s,b in mutations:
        check('Owner direct API mutation denied: '+label,s==403 and isinstance(b,dict) and b.get('code')=='OWNER_READ_ONLY',{'status':s,'body':b})
    check('Owner denied mutations leave canonical row counts unchanged',before==after,{'before':before,'after':after})
    check('Owner denied mutations leave journals balanced',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))

print('POST-GREEN-FEATURE-UAT',sum(x['pass'] for x in results),'/',len(results))
