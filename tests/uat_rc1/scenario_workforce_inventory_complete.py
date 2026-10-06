from client import *
from scenario_core import db
import datetime, json, uuid

results=[]
run='efc_'+uuid.uuid4().hex[:10]

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:900],flush=True)
    (base/'logs/enterprise-full-workforce-inventory-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def call(name,action,data=None,method='POST',expected=200,operation=None):
    op=operation or ('efc_'+name+'_'+run)
    status,body=request(action,method,data,op)
    ok=status==expected and (not isinstance(body,dict) or (body.get('success') is not False if expected<300 else body.get('success') is False))
    check(name,ok,{'status':status,'body':body})
    return status,body

def open_shift_cash_available():
    rows=db("SELECT id,opening_cash FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC LIMIT 1")
    if not rows: return None,None
    shift=rows[0]
    totals=db("""SELECT
      COALESCE(SUM(CASE WHEN type='income' AND COALESCE(transactionKind,'manual')<>'security_deposit_forfeit' THEN
        CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0
                  AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01
             THEN COALESCE(splitCashAmount,0)
             WHEN (bankAccountId IS NULL OR bankAccountId='' OR bankAccountId='cash') THEN amount ELSE 0 END
        ELSE 0 END),0) cash_income,
      COALESCE(SUM(CASE WHEN type='expense' THEN
        CASE WHEN COALESCE(isSplitPayment,0)=1 AND COALESCE(splitCashAmount,0)>0 AND COALESCE(splitTransferAmount,0)>0
                  AND COALESCE(splitTransferBankAccountId,'')<>'' AND ABS(amount-(COALESCE(splitCashAmount,0)+COALESCE(splitTransferAmount,0)))<=0.01
             THEN COALESCE(splitCashAmount,0)
             WHEN (bankAccountId IS NULL OR bankAccountId='' OR bankAccountId='cash') THEN amount ELSE 0 END
        ELSE 0 END),0) cash_expense
      FROM transactions WHERE shiftSessionId=?""",[shift['id']])[0]
    available=round(float(shift['opening_cash'])+float(totals['cash_income'])-float(totals['cash_expense']),2)
    return shift['id'],available

# Finance catalog precondition is provisioned through the canonical setup API, not direct DB mutation.
# Paid payroll and financial asset maintenance must fail closed when these semantic owners are absent.
catalog=db("SELECT system_key,type,is_active FROM categories WHERE system_key IN ('payroll_expense','maintenance_expense') ORDER BY system_key")
check('Full Complete finance catalog owns payroll and maintenance expense semantics',
      len(catalog)==2 and {r['system_key'] for r in catalog}=={'payroll_expense','maintenance_expense'}
      and all(r['type']=='expense' and int(r['is_active'])==1 for r in catalog),catalog)

# Dedicated active employee through canonical Staff API.
username='efc_staff_'+run[-6:]
password='Efc-Staff-UAT!42x'
_,created=call('staff_create','staff',{'name':'EFC Workforce Staff','username':username,'password':password,'role':'receptionist','salary':3250000})
rows=db('SELECT id,name,role,status,salary FROM staff WHERE username=?',[username])
check('Workforce fixture persists active receptionist',len(rows)==1 and rows[0]['role']=='receptionist' and rows[0]['status']=='active',rows)
staff=rows[0] if rows else None

if staff:
    sid=staff['id']; today=datetime.date.today().isoformat()
    # Attendance full manual lifecycle + reason/audit boundary.
    _,att=call('attendance_manual_create','attendance',{
        'staffId':sid,'staffName':'forged browser name must be ignored','date':today,'clockIn':'08:15:00',
        'method':'manual','status':'present','location':'Front Office','notes':'Enterprise Full Complete manual attendance fixture'
    })
    aid=att.get('attendanceId') if isinstance(att,dict) else None
    arows=db('SELECT id,staff_id,staff_name,clock_in,clock_out,method,status,notes FROM attendance WHERE id=?',[aid]) if aid else []
    check('Attendance trusts canonical staff identity and stores manual evidence',bool(arows) and arows[0]['staff_id']==sid and arows[0]['staff_name']=='EFC Workforce Staff' and arows[0]['method']=='manual' and not arows[0]['clock_out'],arows)
    if aid:
        call('attendance_manual_clockout','action=attendance&id='+aid,{'id':aid,'clockOut':'17:05:00','method':'manual','notes':'Admin verified end-of-shift correction'},'PUT')
        updated=db('SELECT clock_out,notes FROM attendance WHERE id=?',[aid])[0]
        check('Attendance clock-out persists with administrative audit reason',updated['clock_out']=='17:05:00' and 'verified' in (updated['notes'] or '').lower(),updated)
        audits=db("SELECT COUNT(*) n FROM audit_logs WHERE entity_type='attendance' AND entity_id=?",[aid])
        check('Attendance lifecycle emits enterprise audit trail',int(audits[0]['n'])>=2,audits)

    # Salary paid is a real accounting mutation. Use the already-open UAT shift.
    slip_id='efc_slip_'+run[-8:]
    period=datetime.date.today().strftime('%Y-%m')
    salary_payload={
        'id':slip_id,'staffId':sid,'period':period,'basicSalary':3000000,
        'detailedAllowances':[{'name':'Tunjangan Makan','amount':250000}],
        'detailedDeductions':[{'name':'Potongan UAT','amount':50000}],
        'bonus':50000,'netSalary':3250000,'notes':'Enterprise Full Complete paid payroll',
        'status':'paid','paymentMethod':'transfer','bankAccountId':'sim_bank','sendTelegram':False
    }
    call('salary_paid_posting','salary-slips',salary_payload,operation='efc_salary_'+run)
    slips=db('SELECT id,status,net_salary,payment_method FROM salary_slips WHERE id=?',[slip_id])
    salary_tx=db("SELECT id,amount,transactionKind,sourceEntity,sourceEntityId,bankAccountId,shiftSessionId FROM transactions WHERE sourceEntity='salary_slip' AND sourceEntityId=?",[slip_id])
    check('Paid salary persists immutable paid slip',len(slips)==1 and slips[0]['status']=='paid' and float(slips[0]['net_salary'])==3250000,slips)
    check('Paid salary creates exactly one canonical bank expense without contaminating the open cash shift',len(salary_tx)==1 and salary_tx[0]['transactionKind']=='salary_payment' and float(salary_tx[0]['amount'])==3250000 and salary_tx[0]['bankAccountId']=='sim_bank' and not salary_tx[0]['shiftSessionId'],salary_tx)
    call('salary_idempotent_retry','salary-slips',salary_payload,operation='efc_salary_'+run)
    check('Paid salary retry does not duplicate financial transaction',int(db("SELECT COUNT(*) n FROM transactions WHERE sourceEntity='salary_slip' AND sourceEntityId=?",[slip_id])[0]['n'])==1)
    check('Paid salary transaction has balanced journal',not db("SELECT j.id FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id=j.id WHERE j.transaction_id=(SELECT id FROM transactions WHERE sourceEntity='salary_slip' AND sourceEntityId=? LIMIT 1) GROUP BY j.id HAVING ABS(SUM(l.debit)-SUM(l.credit))>0.001",[slip_id]))

    # Root-cause guard: a live cash expense may not make the physical drawer negative.
    shift_id,available_cash=open_shift_cash_available()
    check('Payroll overdraw UAT has a real open shift cash balance',bool(shift_id) and available_cash is not None and available_cash>=0,{'shiftId':shift_id,'availableCash':available_cash})
    if shift_id is not None and available_cash is not None:
        overdraw_slip='efc_overdraw_'+run[-8:]
        overdraw=round(max(1000.0,available_cash+1000.0),2)
        call('salary_cash_overdraw_rejected','salary-slips',{
            'id':overdraw_slip,'staffId':sid,'period':period,'basicSalary':overdraw,
            'detailedAllowances':[],'detailedDeductions':[],'bonus':0,'netSalary':overdraw,
            'notes':'EFC must reject cash payroll beyond physical shift cash',
            'status':'paid','paymentMethod':'cash','sendTelegram':False
        },expected=409,operation='efc_salary_cash_overdraw_'+run)
        check('Rejected cash payroll rolls back slip and transaction atomically',
            not db('SELECT id FROM salary_slips WHERE id=?',[overdraw_slip])
            and not db("SELECT id FROM transactions WHERE sourceEntity='salary_slip' AND sourceEntityId=?",[overdraw_slip]))

    # Staff savings lifecycle: deposit -> request -> approve -> pay. Savings must not mutate hotel cash ledger.
    tx_before=int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])
    dep_op='efc_sav_dep_'+run
    call('savings_deposit','staff-savings',{'command':'deposit','operationId':dep_op,'staffId':sid,'amount':500000,'sourceType':'salary_deduction','paymentMethod':'cash','description':'EFC savings deposit'},operation=dep_op)
    call('savings_deposit_retry','staff-savings',{'command':'deposit','operationId':dep_op,'staffId':sid,'amount':500000,'sourceType':'salary_deduction','paymentMethod':'cash','description':'EFC savings deposit'},operation=dep_op)
    check('Savings deposit is idempotent and independent from hotel transactions',int(db("SELECT COUNT(*) n FROM staff_savings_ledger WHERE staff_id=? AND entry_type='deposit'",[sid])[0]['n'])==1 and int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])==tx_before)
    req_op='efc_sav_req_'+run
    _,withdraw=call('savings_withdraw_request','staff-savings',{'command':'request','operationId':req_op,'staffId':sid,'amount':200000,'purpose':'Kebutuhan UAT','payoutMethod':'cash'},operation=req_op)
    request_id=withdraw.get('requestId') if isinstance(withdraw,dict) else None
    if request_id:
        approve_op='efc_sav_app_'+run
        call('savings_approve','staff-savings',{'command':'approve','operationId':approve_op,'requestId':request_id,'notes':'Approved by EFC UAT'},operation=approve_op)
        pay_op='efc_sav_pay_'+run
        call('savings_pay','staff-savings',{'command':'pay','operationId':pay_op,'requestId':request_id,'storageReference':'EFC-CASH'},operation=pay_op)
        reqrow=db('SELECT status,ledger_entry_id FROM staff_savings_requests WHERE id=?',[request_id])[0]
        account=db('SELECT balance FROM staff_savings_accounts WHERE staff_id=?',[sid])[0]
        check('Savings request approval/payment reaches paid state and debits savings only',reqrow['status']=='paid' and bool(reqrow['ledger_entry_id']) and float(account['balance'])==300000 and int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])==tx_before,{'request':reqrow,'account':account})

# Inventory/asset lifecycle with audit-preserving financial maintenance.
asset_code='EFC-ASSET-'+run[-6:].upper()
call('inventory_create','inventory',{'code':asset_code,'name':'EFC Generator','category':'Engineering','location':'Utility Room','quantity':1,'unit':'unit','condition_status':'baik','purchase_date':datetime.date.today().isoformat(),'price':12500000,'notes':'Full lifecycle asset'})
assets=db('SELECT * FROM inventory WHERE code=?',[asset_code]); asset_id=assets[0]['id'] if assets else None
check('Inventory asset persists with initial lifecycle state',bool(assets) and assets[0]['condition_status']=='baik' and int(assets[0]['quantity'])==1,assets)
if asset_id:
    call('inventory_update','action=inventory&id='+asset_id,{'id':asset_id,'location':'Generator Room','condition_status':'perlu_perawatan','notes':'Scheduled EFC service'},'PUT')
    after=db('SELECT location,condition_status FROM inventory WHERE id=?',[asset_id])[0]
    check('Inventory update persists location and condition',after['location']=='Generator Room' and after['condition_status']=='perlu_perawatan',after)
    shift_id,available_cash=open_shift_cash_available()
    if shift_id is not None and available_cash is not None:
        overdraw_cost=round(max(1000.0,available_cash+1000.0),2)
        before_maintenance=int(db('SELECT COUNT(*) n FROM inventory_maintenance WHERE inventory_id=?',[asset_id])[0]['n'])
        call('inventory_maintenance_cash_overdraw_rejected','inventory-maintenance',{
            'inventory_id':asset_id,'maintenance_date':datetime.date.today().isoformat(),'action_taken':'EFC overdraw must rollback',
            'cost':overdraw_cost,'staff_name':'Teknisi EFC','asset_condition_after':'baik','notes':'cash overdraw rejection probe',
            'recordAsExpense':True,'paymentMethod':'cash'
        },expected=409,operation='efc_maint_cash_overdraw_'+run)
        check('Rejected cash maintenance rolls back maintenance row and financial transaction atomically',
            int(db('SELECT COUNT(*) n FROM inventory_maintenance WHERE inventory_id=?',[asset_id])[0]['n'])==before_maintenance
            and not db("SELECT id FROM transactions WHERE sourceEntity='inventory_maintenance' AND description LIKE '%overdraw%'"))

    call('inventory_maintenance_financial','inventory-maintenance',{
        'inventory_id':asset_id,'maintenance_date':datetime.date.today().isoformat(),'action_taken':'Ganti oli dan filter',
        'cost':175000,'staff_name':'Teknisi EFC','asset_condition_after':'baik','notes':'EFC financial maintenance',
        'recordAsExpense':True,'paymentMethod':'transfer','bankAccountId':'sim_bank'
    })
    maint=db('SELECT id,cost,action_taken FROM inventory_maintenance WHERE inventory_id=? ORDER BY id DESC LIMIT 1',[asset_id])
    mid=maint[0]['id'] if maint else None
    tx=db("SELECT id,amount,transactionKind,sourceEntity,sourceEntityId,bankAccountId,shiftSessionId FROM transactions WHERE sourceEntity='inventory_maintenance' AND sourceEntityId=?",[mid]) if mid else []
    check('Inventory maintenance persists and posts exact canonical bank expense without contaminating the open cash shift',bool(maint) and float(maint[0]['cost'])==175000 and len(tx)==1 and float(tx[0]['amount'])==175000 and tx[0]['bankAccountId']=='sim_bank' and not tx[0]['shiftSessionId'],{'maintenance':maint,'transaction':tx})
    if mid:
        s,b=request('action=inventory-maintenance&id='+mid,'DELETE',{'id':mid},'efc_maint_delete_block_'+run)
        check('Financial maintenance audit chain cannot be hard-deleted',s==409 and isinstance(b,dict) and b.get('success') is False,{'status':s,'body':b})
    s,b=request('action=inventory&id='+asset_id,'DELETE',{'id':asset_id},'efc_asset_delete_block_'+run)
    check('Asset with posted maintenance cannot be hard-deleted',s==409 and isinstance(b,dict) and b.get('success') is False,{'status':s,'body':b})

# Draft asset without financial history may be deleted and must emit a tombstone.
draft_code='EFC-DRAFT-'+run[-6:].upper()
call('inventory_draft_create','inventory',{'code':draft_code,'name':'EFC Temporary Asset','category':'UAT','location':'UAT','quantity':1,'unit':'pcs','condition_status':'baik','price':0})
draft=db('SELECT id FROM inventory WHERE code=?',[draft_code]); draft_id=draft[0]['id'] if draft else None
if draft_id:
    call('inventory_draft_delete','action=inventory&id='+draft_id,{'id':draft_id},'DELETE')
    check('Draft asset deletion removes row and records sync tombstone',not db('SELECT id FROM inventory WHERE id=?',[draft_id]) and bool(db("SELECT entity_id FROM sync_tombstones WHERE entity_type='inventory' AND entity_id=?",[draft_id])))

check('Workforce/inventory suite leaves all journals balanced',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
print('ENTERPRISE-FULL-WORKFORCE-INVENTORY',sum(x['pass'] for x in results),'/',len(results))
