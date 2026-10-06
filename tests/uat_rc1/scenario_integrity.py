import scenario_extended as e
from scenario_extended import *
e.logname='shift-integrity-results.json'
def metric(code):
 _,b=request('canonical-report&type=financial_summary&from=2026-01-01&to=2026-12-31')
 return next(x for x in b['integrity']['metrics'] if x['code']==code)
m=metric('backfill_tax_review');check('Consistency guard detects canonical backfill review',m['count']>0 and m['status']=='WARNING',m['count'])
m=metric('shift_cash_reconciliation');check('Open shift is not compared to a closed snapshot',m['status']=='PASS',m['count'])
shift=db("SELECT * FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC LIMIT 1")[0]
rows=db('SELECT * FROM transactions WHERE shiftSessionId=?',[shift['id']]);inc=0;exp=0
for r in rows:
 if r['transactionKind']=='security_deposit_forfeit':continue
 cash=float(r['splitCashAmount']) if r['isSplitPayment'] else (float(r['amount']) if not r['bankAccountId'] or r['bankAccountId']=='cash' else 0)
 if r['type']=='income':inc+=cash
 else:exp+=cash
expected=round(float(shift['opening_cash'])+inc-exp,2)
call('night_shift_requires_audit','operations-center',{'command':'shift-close','shiftId':shift['id'],'actualCash':expected},expected=[400,409])
call('night_audit_start','operations-center',{'command':'night-audit-start','shiftSessionId':shift['id']})
audit=db("SELECT id FROM night_audit_runs WHERE shift_session_id=? AND status='open' ORDER BY started_at DESC LIMIT 1",[shift['id']])[0]['id']
call('night_audit_rejects_incomplete','operations-center',{'command':'night-audit-finalize','auditId':audit},expected=[400,409])
rooms=db("""SELECT r.number,
 EXISTS(SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status='active') AS has_active_booking,
 COALESCE(rac.access_mode,'physical') AS access_mode,
 COALESCE(rac.physical_key_status,'secured') AS physical_key_status
 FROM rooms r LEFT JOIN room_access_control rac ON rac.room_number=r.number ORDER BY r.number""")
for room in rooms:
 occupancy='occupied' if int(room.get('has_active_booking') or 0)>0 else 'vacant'
 access=(room.get('access_mode') or 'physical').lower()
 registered=(room.get('physical_key_status') or 'secured').lower()
 observed=registered if access in ('physical','hybrid') and registered in ('issued','secured','missing') else 'unknown'
 call('night_check_'+room['number'],'operations-center',{'command':'night-audit-check-room','auditId':audit,'roomNumber':room['number'],'physicalOccupancy':occupancy,'observedKeyStatus':observed,'notes':'UAT physical inspection follows current booking and key-register state'})
checked=db('SELECT COUNT(*) n FROM night_audit_items WHERE audit_id=? AND checked_at IS NOT NULL',[audit])[0]['n']
check('Night audit records a physical check for every room',int(checked)==len(rooms),{'checked':checked,'rooms':len(rooms)})
# Genuine discrepancies are not bypassed. Exercise the official Manager/Admin resolution path.
unresolved=db("SELECT id,room_number,discrepancy_type FROM night_audit_items WHERE audit_id=? AND discrepancy_type IS NOT NULL AND discrepancy_type<>'' AND resolution_status<>'resolved' ORDER BY room_number,id",[audit])
for item in unresolved:
 call('night_resolve_'+item['id'],'operations-center',{'command':'night-audit-resolve','id':item['id'],'reason':'UAT physical verification completed; discrepancy reviewed by administrator'})
check('Night audit official resolution clears every reviewed discrepancy',not db("SELECT id FROM night_audit_items WHERE audit_id=? AND discrepancy_type IS NOT NULL AND discrepancy_type<>'' AND resolution_status<>'resolved'",[audit]),[x.get('discrepancy_type') for x in unresolved])
call('night_audit_finalize','operations-center',{'command':'night-audit-finalize','auditId':audit,'notes':'Semua kamar diperiksa; setiap discrepancy diselesaikan melalui workflow resmi'})
call('actual_shift_close','operations-center',{'command':'shift-close','shiftId':shift['id'],'actualCash':expected,'notes':'Simulasi tutup shift dan rekonsiliasi setelah Night Audit resmi selesai'})
r=db('SELECT status,cash_income,cash_expense,expected_cash,variance FROM shift_sessions WHERE id=?',[shift['id']])[0]
check('Closed shift matches actual live cash legs',r['status']=='closed' and float(r['expected_cash'])==expected and float(r['variance'])==0,r)
check('Historical backfill never locked into closing shift',not db("SELECT id FROM transactions WHERE recordOrigin='historical_import' AND (shiftSessionId IS NOT NULL OR lockedAt IS NOT NULL)"))
check('Closed live transactions are locked',not db('SELECT id FROM transactions WHERE shiftSessionId=? AND lockedAt IS NULL',[shift['id']]))
m=metric('shift_cash_reconciliation');check('Correct closed shift passes integrity check',m['status']=='PASS',m['count'])
try:
 db('UPDATE shift_sessions SET expected_cash=expected_cash+1 WHERE id=?',[shift['id']])
 m=metric('shift_cash_reconciliation');check('Guard still detects real closed-shift mismatch',m['status']=='FAIL' and m['count']>=1)
finally:db('UPDATE shift_sessions SET expected_cash=? WHERE id=?',[expected,shift['id']])
_,b=request('canonical-report&type=financial_summary&from=2026-01-01&to=2026-12-31');fails=[m for m in b['integrity']['metrics'] if m['status']=='FAIL']
check('No unresolved integrity FAIL after fixture restoration',not fails,[m['code'] for m in fails])
print('RESULT',sum(x['pass'] for x in e.results),'/',len(e.results))
