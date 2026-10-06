import scenario_extended as e
from scenario_extended import *
e.logname='settlement-sync-results.json'
expense=json.loads((base/'extended-state.json').read_text())['expenseCategory']
# PBJT settlement is liability repayment, not a second operating expense.
b=call('pbjt_category','categories',{'name':run+' PBJT settlement','type':'expense'});cat=b.get('categoryId')
call('pbjt_binding','categories-semantic-bind',{'categoryId':cat,'systemKey':'pbjt_settlement'})
_,before=request('canonical-report&type=financial_summary&from=2026-01-01&to=2026-12-31')
r,p=tx('pbjt_settlement',type='expense',categoryId=cat,amount=10000,date='2026-07-20',bankAccountId='sim_bank',paymentMethod='transfer')
_,after=request('canonical-report&type=financial_summary&from=2026-01-01&to=2026-12-31')
check('PBJT payment does not increase operating expense',before['summary']['Beban Operasional Diakui']==after['summary']['Beban Operasional Diakui'])
check('PBJT payment appears as liability settlement',after['summary']['PBJT Dibayar']-before['summary']['PBJT Dibayar']==10000)
rows=db('SELECT l.account_code,l.debit,l.credit FROM journal_lines l JOIN journal_entries j ON j.id=l.journal_entry_id WHERE j.transaction_id=?',[r.get('id')])
check('PBJT settlement debits tax payable and credits bank',any(x['account_code']=='2102' and float(x['debit'])==10000 for x in rows) and any(x['account_code']=='1102' and float(x['credit'])==10000 for x in rows),rows)
# OTA accrual receipt creates receivable, settlement moves it to a real bank once.
r,p=tx('ota_receivable',date='2026-07-21',bookingSource='Traveloka',bankAccountId='ota_receivable',amount=110000)
check('OTA backfill preserves receivable account',r.get('bankAccountId')=='ota_receivable')
_,before=request('canonical-report&type=financial_summary&from=2026-01-01&to=2026-12-31')
pay={'date':str(today),'amount':110000,'bankAccountId':'sim_bank','notes':run+' OTA settlement','operationId':run+'_ota_settle'}
b=call('ota_settle','ota-disbursements',pay)
_,after=request('canonical-report&type=financial_summary&from=2026-01-01&to=2026-12-31')
check('OTA disbursement never duplicates revenue or tax',before['summary']['Pendapatan Operasional Diakui']==after['summary']['Pendapatan Operasional Diakui'] and before['summary']['PBJT Terbentuk']==after['summary']['PBJT Terbentuk'])
check('OTA disbursement increases liquid bank only',round(after['summary']['Bank/QRIS Bersih']-before['summary']['Bank/QRIS Bersih'],2)==110000)
n=db('SELECT COUNT(*) n FROM transactions')[0]['n'];call('ota_retry','ota-disbursements',pay,op=run+'_ota_retry')
check('OTA settlement retry creates no entries',db('SELECT COUNT(*) n FROM transactions')[0]['n']==n)
call('ota_changed_retry','ota-disbursements',dict(pay,amount=100000),expected=409,op=run+'_ota_changed')
# Offline historical sync is a separate ingestion route.
p={'id':run+'_offline_receipt','type':'income','categoryId':state['room_rental'],'category':'SIM room_rental','amount':110000,'date':'2026-07-22','description':run+' offline','recordOrigin':'historical_import','shiftExempt':1,'shiftExemptionReason':'Entri arsip offline simulasi','historicalSourceType':'receipt','historicalTaxMode':'rule_by_date','bookingSource':'Direct','operationId':run+'_offline_op','version':1,'isSplitPayment':True,'splitCashAmount':30000,'splitTransferAmount':80000,'splitTransferBankAccountId':'sim_bank'}
b=call('offline_create','sync',{'transactions':[p]})
print('SYNC RESULT', {k:v for k,v in b.items() if k not in ['db','data']},flush=True)
r=db('SELECT * FROM transactions WHERE id=?',[p['id']]);check('Offline historical split persisted',len(r)==1 and r[0]['isSplitPayment']==1 and float(r[0]['taxAmount'])==10000,r and {k:r[0][k] for k in ['amount','taxAmount','isSplitPayment','shiftSessionId']})
n=db('SELECT COUNT(*) n FROM transactions')[0]['n'];call('offline_retry','sync',{'transactions':[p]},op=run+'_offline_retry');check('Offline retry does not duplicate money',db('SELECT COUNT(*) n FROM transactions')[0]['n']==n)
b=call('offline_mismatch','sync',{'transactions':[dict(p,amount=220000)]},op=run+'_offline_changed',expected=409)
check('Offline payload mismatch is reported as conflict',bool(b.get('conflicts')))
check('All settlement journals balance',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
(base/'settlement-state.json').write_text(json.dumps({'pay':pay,'offline':p,'run':run}))
print('RESULT',sum(x['pass'] for x in e.results),'/',len(e.results))
