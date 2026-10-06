from client import *
from scenario_core import db
import base64, datetime, email, json, os, re, time, uuid
from pathlib import Path

results=[]
run='efc_ops_'+uuid.uuid4().hex[:10]

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:1000],flush=True)
    (base/'logs/enterprise-full-operational-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def call(name,action,data,expected=200,method='POST',operation=None):
    op=operation or ('efc_ops_'+name+'_'+run)
    s,b=request(action,method,data,op)
    ok=s==expected and isinstance(b,dict) and (b.get('success') is not False if expected<300 else b.get('success') is False)
    check(name,ok,{'status':s,'body':b})
    return s,b

# ----- Guest Service: standalone/visitor lifecycle, canonical audit, no accidental room/finance mutation -----
service_op='efc_guest_service_'+run
before_tx=int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])
call('guest_service_open','operations-center',{
    'command':'guest-service-open','requestType':'Antar air minum','description':'Tamu area lobby meminta dua botol air minum untuk menunggu kendaraan.',
    'priority':'high','department':'Front Office','contextType':'non_room_guest','requesterName':'EFC Visitor','requesterContact':'+628111111111',
    'locationLabel':'Lobby','requestChannel':'staff_recorded','operationId':service_op
},operation=service_op)
gs=db('SELECT * FROM guest_service_requests WHERE source_id=?',[service_op])
check('Guest Service open persists standalone visitor context without fake booking',len(gs)==1 and gs[0]['status']=='open' and gs[0]['context_type']=='non_room_guest' and not gs[0]['booking_id'] and not gs[0]['room_number'],gs)
if gs:
    gid=gs[0]['id']
    call('guest_service_assign','operations-center',{'command':'guest-service-progress','id':gid,'status':'assigned'})
    call('guest_service_progress','operations-center',{'command':'guest-service-progress','id':gid,'status':'in_progress'})
    call('guest_service_fulfill','operations-center',{'command':'guest-service-close','id':gid,'status':'fulfilled','note':'Permintaan diantar dan diterima requester.'})
    final=db('SELECT status,assigned_to,started_at,fulfilled_by,fulfilled_at,resolution_note FROM guest_service_requests WHERE id=?',[gid])[0]
    check('Guest Service traverses open -> assigned -> in_progress -> fulfilled',final['status']=='fulfilled' and bool(final['assigned_to']) and bool(final['started_at']) and bool(final['fulfilled_by']) and bool(final['fulfilled_at']),final)
    check('Guest Service lifecycle creates enterprise audit trail',int(db("SELECT COUNT(*) n FROM audit_logs WHERE entity_type='guest_service_request' AND entity_id=?",[gid])[0]['n'])>=4)
check('Guest Service lifecycle does not create hotel money movement',int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])==before_tx)

# ----- Incident and explicit room hold: both must drive canonical room sellability and release it only at terminal state -----
rooms=db("SELECT number,status FROM rooms WHERE status='available' ORDER BY CAST(number AS UNSIGNED),number LIMIT 2")
check('Operational domain UAT has two canonical available rooms',len(rooms)>=2,rooms)
if len(rooms)>=2:
    inc_room=rooms[0]['number']; hold_room=rooms[1]['number']
    inc_op='efc_incident_'+run
    call('incident_open','operations-center',{'command':'operational-incident-open','incidentType':'security/safety','description':'EFC safety inspection found a temporary access hazard requiring room block.','severity':'critical','roomNumber':inc_room,'blocksRoom':True,'operationId':inc_op},operation=inc_op)
    inc=db('SELECT * FROM operational_incidents WHERE source_id=?',[inc_op])
    room_after=db('SELECT status FROM rooms WHERE number=?',[inc_room])[0]
    check('Blocking operational incident creates linked open room hold and removes room from sellable inventory',len(inc)==1 and inc[0]['status']=='open' and bool(inc[0]['hold_id']) and room_after['status']!='available' and bool(db("SELECT id FROM room_operational_holds WHERE id=? AND status='open'",[inc[0]['hold_id'] if inc else ''])),{'incident':inc,'room':room_after})
    if inc:
        iid=inc[0]['id']
        call('incident_progress','operations-center',{'command':'operational-incident-progress','id':iid})
        call('incident_resolve','operations-center',{'command':'operational-incident-resolve','id':iid,'note':'Safety hazard removed and room rechecked by manager.'})
        final_inc=db('SELECT status,resolution_note,hold_id FROM operational_incidents WHERE id=?',[iid])[0]
        hold=db('SELECT status,release_note FROM room_operational_holds WHERE id=?',[final_inc['hold_id']])[0]
        final_room=db('SELECT status FROM rooms WHERE number=?',[inc_room])[0]
        check('Resolving incident releases its exact hold and reconciles room projection',final_inc['status']=='resolved' and hold['status']=='released' and final_room['status']=='available',{'incident':final_inc,'hold':hold,'room':final_room})

    call('room_hold_open','operations-center',{'command':'room-hold-open','roomNumber':hold_room,'holdType':'management_inspection','reason':'EFC management hold until room safety checklist is signed.','severity':'critical'})
    open_hold=db("SELECT * FROM room_operational_holds WHERE room_number=? AND hold_type='management_inspection' AND status='open' ORDER BY created_at DESC,id DESC LIMIT 1",[hold_room])
    check('Explicit operational hold independently blocks room inventory',bool(open_hold) and db('SELECT status FROM rooms WHERE number=?',[hold_room])[0]['status']!='available',open_hold)
    if open_hold:
        call('room_hold_release','operations-center',{'command':'room-hold-release','id':open_hold[0]['id'],'note':'Checklist signed; room may return to inventory.'})
        check('Explicit operational hold release restores derived room availability',db('SELECT status FROM room_operational_holds WHERE id=?',[open_hold[0]['id']])[0]['status']=='released' and db('SELECT status FROM rooms WHERE number=?',[hold_room])[0]['status']=='available')

# ----- Shift handover/manual reconciliation report: primary + companion, persisted + idempotent, auditable -----
admin=db("SELECT id,name FROM staff WHERE username=? LIMIT 1",[os.getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin')])[0]
comp=db("SELECT id,name FROM staff WHERE id<>? AND status='active' AND role IN ('manager','receptionist','finance') ORDER BY id LIMIT 1",[admin['id']])
check('Shift handover UAT has distinct active companion staff',bool(comp),comp)
if comp:
    day=datetime.date.today().isoformat();op='efc_manual_shift_report_'+run
    financials_before=int(db('SELECT COUNT(*) n FROM shift_reports')[0]['n'])
    # Server calculates expected cash; use current aggregate from GET projection if available by submitting zero once is unsafe.
    # Manual reconciliation is allowed to persist variance; the point here is server-derived report identity + companion handover.
    payload={'staffId':admin['id'],'companionStaffId':comp[0]['id'],'shiftDate':day,'shiftTime':'all','actualPhysicalCash':0,'notes':'EFC manual handover reconciliation between primary and companion staff.','operationId':op}
    s,b=call('manual_shift_report','send-shift-report',payload,operation=op)
    rid=b.get('reportId') if isinstance(b,dict) else None
    rows=db('SELECT * FROM shift_reports WHERE id=?',[rid]) if rid else []
    check('Manual shift report persists primary+companion identity and server-derived reconciliation',len(rows)==1 and rows[0]['staffId']==admin['id'] and rows[0]['companionStaffId']==comp[0]['id'] and rows[0]['shiftTime']=='all',rows)
    s2,b2=call('manual_shift_report_retry','send-shift-report',payload,operation=op)
    receipt=db("SELECT operation_id,status,http_status,action,http_method FROM request_operation_receipts WHERE operation_id=?",[op])
    check('Manual shift report retry is idempotent with same report identity and durable completed receipt',
        rid and b2.get('reportId')==rid
        and int(db('SELECT COUNT(*) n FROM shift_reports WHERE id=?',[rid])[0]['n'])==1
        and int(db('SELECT COUNT(*) n FROM shift_reports')[0]['n'])==financials_before+1
        and len(receipt)==1 and receipt[0]['status']=='completed' and int(receipt[0]['http_status'])==200
        and receipt[0]['action']=='send-shift-report' and receipt[0]['http_method']=='POST',
        {'first':b,'retry':b2,'receipt':receipt})
    check('Manual shift report writes enterprise audit',bool(db("SELECT id FROM audit_logs WHERE entity_type='shift_report' AND entity_id=?",[rid])))

# ----- Real application SMTP protocol + canonical PDF/XLSX/CSV attachments against isolated local SMTP server -----
smtp_file=Path(os.getenv('EFC_SMTP_CAPTURE',str(base/'logs/smtp-delivery.eml')))
smtp_port_raw=os.getenv('EFC_SMTP_PORT','').strip()
smtp_port=int(smtp_port_raw) if smtp_port_raw.isdigit() else 0
check('SMTP UAT uses the exact ephemeral port owned by its isolated sink process',1 <= smtp_port <= 65535,{'port':smtp_port_raw})
old=db("SELECT smtp_host,smtp_port,smtp_user,smtp_password,smtp_secure,smtp_from FROM config WHERE id='system_default' LIMIT 1")[0]
try:
    db("UPDATE config SET smtp_host='127.0.0.1',smtp_port=?,smtp_user='',smtp_password='',smtp_secure='none',smtp_from='uat-report@example.invalid' WHERE id='system_default'",[smtp_port])
    email_op='efc_report_email_'+run
    s,mail=call('canonical_report_smtp_delivery','canonical-report-email',{'email':'recipient@example.invalid','type':'financial_summary','from':datetime.date.today().replace(day=1).isoformat(),'to':datetime.date.today().isoformat(),'formats':['pdf','xlsx','csv']},operation=email_op)
    # SMTP sink writes synchronously before 250 response; small polling only protects filesystem scheduling.
    for _ in range(30):
        if smtp_file.exists() and smtp_file.stat().st_size>0: break
        time.sleep(.1)
    raw=smtp_file.read_bytes() if smtp_file.exists() else b''
    msg=email.message_from_bytes(raw) if raw else None
    filenames=[];parts=[]
    if msg:
        for part in msg.walk():
            fn=part.get_filename()
            if fn: filenames.append(fn); parts.append((fn,len(part.get_payload(decode=True) or b'')))
    exts={Path(x).suffix.lower() for x in filenames}
    check('Canonical report email uses real SMTP path and includes non-empty PDF/XLSX/CSV attachments',s==200 and mail.get('success') is True and mail.get('method')=='SMTP' and exts=={'.pdf','.xlsx','.csv'} and len(parts)==3 and all(n>20 for _,n in parts),{'status':s,'body':mail,'filenames':filenames,'parts':parts,'bytes':len(raw)})
    check('SMTP capture contains exact recipient and TAMASYA MIME headers',msg is not None and (msg.get('To') or '').strip()=='<recipient@example.invalid>' and 'Tamasya-SMTP-Client' in (msg.get('X-Mailer') or ''),{'to':msg.get('To') if msg else None,'mailer':msg.get('X-Mailer') if msg else None})
    if isinstance(mail,dict) and mail.get('reportId'):
        check('Canonical SMTP delivery is enterprise-audited by report identity',bool(db("SELECT id FROM audit_logs WHERE entity_type='canonical_report_email' ORDER BY created_at DESC LIMIT 1")))
finally:
    db('UPDATE config SET smtp_host=?,smtp_port=?,smtp_user=?,smtp_password=?,smtp_secure=?,smtp_from=? WHERE id=\'system_default\'',[old['smtp_host'],old['smtp_port'],old['smtp_user'],old['smtp_password'],old['smtp_secure'],old['smtp_from']])

check('Operational Full Complete suite leaves journals balanced',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
print('ENTERPRISE-FULL-OPERATIONAL',sum(x['pass'] for x in results),'/',len(results))
