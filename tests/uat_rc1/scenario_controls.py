import scenario_extended as e
from scenario_extended import *
e.logname='finance-controls-results.json'
prior=json.loads((base/'extended-state.json').read_text());expense=prior['expenseCategory']
b=call('general_income_category','categories',{'name':run+' General income','type':'income'});general=b.get('categoryId')
r,p=tx('live_manual_income',categoryId=general,date=str(today),recordOrigin='live_operation',amount=110000)
check('Live manual income attaches current shift',bool(r.get('shiftSessionId')) and not r.get('shiftExempt'))
liveid=r.get('id');r,p=tx('live_manual_expense',type='expense',categoryId=expense,date=str(today),recordOrigin='live_operation',amount=5000)
check('Live expense uses zero sales tax',float(r.get('taxAmount',-1))==0 and bool(r.get('shiftSessionId')))
call('live_room_manual_block','transactions',dict(p,type='income',categoryId=state['room_rental'],operationId=run+'_live_room'),expected=409)
# Simulate the closed-shift guard without running unrelated night-audit cleanup.
shift=db("SELECT id,status FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC LIMIT 1")[0]
try:
 db("UPDATE shift_sessions SET status='closed' WHERE id=?",[shift['id']])
 call('live_without_shift','transactions',dict(p,operationId=run+'_no_shift',description='SIM no shift'),expected=409)
 r,q=tx('historical_without_shift',type='expense',categoryId=expense,amount=1234)
 check('Historical entry works without current shift',bool(r) and not r.get('shiftSessionId'))
finally:db('UPDATE shift_sessions SET status=? WHERE id=?',[shift['status'],shift['id']])
# Explicit locks and source protection must prevent in-place ledger rewriting.
try:
 db('UPDATE transactions SET lockedAt=NOW() WHERE id=?',[liveid])
 call('edit_locked','transactions',{'id':liveid,'amount':220000,'changeReason':'Simulasi edit setelah tutup shift'},method='PUT',expected=423)
finally:db('UPDATE transactions SET lockedAt=NULL WHERE id=?',[liveid])
system=db("SELECT id FROM transactions WHERE transactionKind='booking_payment' LIMIT 1")[0]['id']
call('edit_system_transaction','transactions',{'id':system,'amount':1,'changeReason':'Simulasi proteksi transaksi sistem'},method='PUT',expected=409)
r,p=tx('deletable',type='expense',categoryId=expense,amount=2000,date='2026-08-12');tid=r.get('id')
call('delete_no_confirmation','transactions',{'id':tid,'deleteReason':'Bukti duplikasi simulasi'},method='DELETE',expected=422)
call('delete_manual','transactions',{'id':tid,'deleteReason':'Bukti duplikasi simulasi','confirmation':'HAPUS DATA MANUAL'},method='DELETE')
check('Deletion removes transaction and journal together',not db('SELECT id FROM transactions WHERE id=?',[tid]) and not db('SELECT id FROM journal_entries WHERE transaction_id=?',[tid]))
check('Deletion records tombstone to prevent resurrection',bool(db("SELECT entity_id FROM sync_tombstones WHERE entity_type='transactions' AND entity_id=?",[tid])))
call('delete_closed_period','transactions',{'id':prior['ids']['closed'],'deleteReason':'Simulasi kontrol periode','confirmation':'HAPUS DATA MANUAL'},method='DELETE',expected=409)
# Role/permission is read fresh by API; fixtures are restored even on failure.
admin=json.loads((base/'session.json').read_text())['staffId']
saved=db('SELECT role,permissions FROM staff WHERE id=?',[admin])[0]
try:
 db("UPDATE staff SET role='receptionist',permissions=NULL WHERE id=?",[admin])
 call('receptionist_cannot_post_backfill','transactions',dict(p,operationId=run+'_forbidden'),expected=403)
 call('receptionist_cannot_export_tax','canonical-report&type=tax_ledger&from=2026-01-01&to=2026-12-31',method='GET',expected=403)
 db("UPDATE staff SET role='finance',permissions=NULL WHERE id=?",[admin])
 call('finance_cannot_approve_closed_adjustment','historical-backfill-review',{'transactionId':prior['ids']['closed'],'decision':'approve','reviewNote':'Simulasi otorisasi finance'},expected=403)
 db('UPDATE staff SET role=?,permissions=? WHERE id=?',[saved['role'],json.dumps({'capabilities':{'manage_tax_rules':'false'}}),admin])
 call('explicit_false_blocks_tax_change','operations-center',{'command':'tax-rule-save','id':run+'_forbidden_tax','name':'Forbidden tax','rate':50},expected=403)
finally:db('UPDATE staff SET role=?,permissions=? WHERE id=?',[saved['role'],saved['permissions'],admin])
check('No unbalanced journals after edit/delete and authorization checks',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
print('RESULT',sum(x['pass'] for x in e.results),'/',len(e.results))
