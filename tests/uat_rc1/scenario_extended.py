from client import *
from scenario_core import db,state
import datetime,uuid
run='fin_'+uuid.uuid4().hex[:8]
results=[];ids={};logname='extended-finance-results.json'
today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()
def check(name,ok,detail=None):
 results.append({'test':name,'pass':bool(ok),'details':detail});print('PASS' if ok else 'FAIL',name,str(detail or '')[:500],flush=True)
 (base/'logs'/logname).write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')
def call(name,action,data=None,expected=200,method='POST',op=None):
 status,b=request(action,method,data,op or run+'_'+name)
 check(name,status in ([expected] if isinstance(expected,int) else expected) and (b.get('success') is not False if status<300 else b.get('success') is False),{'status':status,'message':b.get('error',b.get('message',''))})
 return b

def tx(name,**kw):
 p={'type':'income','categoryId':state['room_rental'],'amount':110000,'date':'2026-06-12','description':run+' '+name,'recordOrigin':'historical_import','shiftExemptionReason':'Entri bukti arsip simulasi keuangan','historicalSourceType':'receipt','historicalSourceReference':run+'_'+name,'bookingSource':'Direct','historicalTaxMode':'rule_by_date','operationId':run+'_'+name}
 p.update(kw);b=call(name,'transactions',p);i=b.get('transactionId');ids[name]=i
 if i:return db('SELECT * FROM transactions WHERE id=?',[i])[0],p
 return {},p

def rule(name,**kw):
 p={'command':'tax-rule-save','id':run+'_'+name,'name':'SIM '+name,'sourcePattern':'*','transactionKind':'room','rate':10,'priority':200,'taxable':1,'isActive':1}
 p.update(kw);return call(name,'operations-center',p)

def period(month,cash='reported',tax='reported'):
 return call('period_'+month+'_'+cash,'reporting-periods',{'periodKey':month,'cashStatus':cash,'taxStatus':tax,'reportReference':run,'notes':'Simulasi pemeriksaan periode keuangan'})

def journal(i):return db('SELECT l.*,a.code,a.name FROM journal_lines l JOIN journal_entries e ON e.id=l.journal_entry_id JOIN chart_of_accounts a ON a.id=l.account_id WHERE e.source_id=? ORDER BY l.id',[i])

if __name__=='__main__':
 rule('old_rate',rate=5,effectiveFrom='2026-01-01',effectiveUntil='2026-06-30')
 rule('new_rate',rate=10,effectiveFrom='2026-07-01')
 rule('extra_exempt',transactionKind='extra',rate=0,taxable=0)
 r,p=tx('old_cash',amount=105000)
 check('Backfill uses historical effective date',float(r.get('taxAmount',-1))==5000 and r.get('taxSource')=='historical_rule',{k:r.get(k) for k in ['taxAmount','taxRate','taxSource']})
 check('Historical receipt has no current shift',r.get('shiftSessionId') is None and r.get('shiftExempt')==1)
 check('Historical service/date and entry timestamp stay distinct',r.get('date')=='2026-06-12' and r.get('serviceDate')=='2026-06-12' and str(r.get('createdAt','')).startswith(str(today)),{'date':r.get('date'),'serviceDate':r.get('serviceDate'),'createdAt':r.get('createdAt'),'propertyToday':str(today)})
 before=db('SELECT COUNT(*) n FROM transactions')[0]['n'];call('same_receipt_retry','transactions',p,op=run+'_retry_transport')
 check('Body operation ID retry is idempotent',db('SELECT COUNT(*) n FROM transactions')[0]['n']==before)
 call('changed_receipt_retry','transactions',dict(p,amount=210000),expected=409,op=run+'_changed_retry')
 r,p=tx('new_bank',date='2026-07-12',bankAccountId='sim_bank',paymentMethod='transfer')
 check('Bank backfill tax is 10 percent',float(r.get('taxAmount',-1))==10000)
 r,p=tx('split',date='2026-07-13',paymentMethod='qris',splitCashAmount=40000,splitTransferAmount=70000,splitTransferBankAccountId='sim_bank')
 check('Split is one canonical historical row',r.get('isSplitPayment')==1 and r.get('bankAccountId') is None)
 (base/'extended-state.json').write_text(json.dumps({'run':run,'ids':ids,'splitPayload':p}),encoding='utf8')
 print('JOURNAL SCHEMA',db('SHOW COLUMNS FROM journal_lines'),flush=True)
 r,p=tx('extra_nontax',categoryId=state['extra_service'],categorySystemKey='room_rental',date='2026-07-14')
 check('Canonical extra category overrides stale client tax kind',float(r.get('taxAmount',-1))==0 and float(r.get('baseAmount',-1))==110000)
 r,p=tx('document',amount=107000,historicalTaxMode='document',baseAmount=100000,taxAmount=7000,taxRate=7)
 check('Document snapshot preserved',float(r.get('taxAmount',-1))==7000 and r.get('taxSource')=='historical_document')
 r,p=tx('unknown',historicalTaxMode='unresolved')
 check('Unknown tax remains NULL rather than zero',r.get('taxAmount') is None and r.get('baseAmount') is None and r.get('taxSnapshotStatus')=='unresolved')
 unknown_journal=db('SELECT l.account_code,l.account_name,l.debit,l.credit FROM journal_lines l JOIN journal_entries j ON j.id=l.journal_entry_id WHERE j.transaction_id=? ORDER BY l.id',[r.get('id')])
 check('Unresolved tax posts receipt to suspense before resolution',len(unknown_journal)==2 and any(x.get('account_code')=='2199' and abs(float(x.get('credit') or 0)-110000)<0.01 for x in unknown_journal) and not any(x.get('account_code') in ('4101','2102') and float(x.get('credit') or 0)>0 for x in unknown_journal),unknown_journal)
 report_status,unknown_report=request('action=canonical-report&type=tax_ledger&from=2026-06-12&to=2026-06-12')
 unknown_rows=((unknown_report.get('sheets') or {}).get('Pajak') or []) if report_status==200 else []
 check('Unresolved tax is explicit in canonical report before resolution',report_status==200 and any(x.get('Keterangan')==r.get('description') and x.get('Status')=='unresolved' and x.get('Pajak') is None and x.get('DPP') is None for x in unknown_rows),{'status':report_status,'summary':unknown_report.get('summary'),'rows':[x for x in unknown_rows if x.get('Keterangan')==r.get('description')]})
 b=call('expense_category','categories',{'name':run+' Office costs','type':'expense'});expense=b.get('categoryId')
 r,p=tx('expense',type='expense',categoryId=expense,amount=15000,baseAmount=10000,taxAmount=5000,taxRate=50,historicalTaxMode='document')
 check('Expense discards stale sales tax',float(r.get('taxAmount',-1))==0 and float(r.get('baseAmount',-1))==15000 and r.get('taxSnapshotStatus')=='not_applicable')
 period('2026-05');r,p=tx('reported',date='2026-05-12',amount=105000)
 check('Reported entry requires tax amendment',r.get('requiresTaxAmendment')==1 and r.get('historicalReviewStatus')=='pending_review')
 a=db('SELECT * FROM historical_backfill_adjustments WHERE transaction_id=?',[r.get('id')]);check('Reported adjustment has exact cash and tax',len(a)==1 and float(a[0]['cash_delta'])==105000 and float(a[0]['tax_delta'])==5000)
 call('close_pending_period','reporting-periods',{'periodKey':'2026-05','cashStatus':'closed','taxStatus':'closed'},expected=409)
 call('review_reported','historical-backfill-review',{'transactionId':r.get('id'),'decision':'approve','reviewNote':'Bukti arsip simulasi sudah diperiksa'})
 period('2026-05','closed','closed')
 period('2026-04','closed','closed');r,p=tx('closed',date='2026-04-12',amount=105000)
 check('Closed entry is captured as pending approval',r.get('periodImpactStatus')=='closed_period_amendment' and r.get('historicalReviewStatus')=='pending_approval')
 oldid=r.get('id');call('closed_edit','transactions',{'id':oldid,'amount':210000,'changeReason':'Koreksi nominal bukti arsip','historicalTaxMode':'rule_by_date'},method='PUT')
 a=db('SELECT SUM(cash_delta) cash,SUM(tax_delta) tax FROM historical_backfill_adjustments WHERE transaction_id=?',[oldid])[0]
 check('Closed edit records net delta rather than duplicate gross',float(a['cash'])==210000 and float(a['tax'])==10000,a)
 call('move_period','transactions',{'id':oldid,'date':'2026-05-15','changeReason':'Koreksi tanggal bukti arsip','historicalTaxMode':'rule_by_date'},method='PUT')
 a=db('SELECT period_key,SUM(cash_delta) cash,SUM(tax_delta) tax FROM historical_backfill_adjustments WHERE transaction_id=? GROUP BY period_key ORDER BY period_key',[oldid])
 check('Moving date removes old period and adds new period',len(a)==2 and float(a[0]['cash'])==0 and float(a[1]['cash'])==210000,a)
 for name,updates in [('future',{'date':str(today+datetime.timedelta(days=1))}),('bad_reason',{'shiftExemptionReason':'x'}),('bad_split',{'splitCashAmount':40000,'splitTransferAmount':50000,'splitTransferBankAccountId':'sim_bank'}),('bad_document',{'historicalTaxMode':'document','baseAmount':100000,'taxAmount':9000,'taxRate':10}),('link_booking',{'bookingId':state['active']}),('invalid_date',{'date':'2026-02-30'})]:
  q=dict(p,operationId=run+'_negative_'+name,description=run+' negative '+name,amount=105000);q.update(updates)
  before=db('SELECT COUNT(*) n FROM transactions')[0]['n'];call(name,'transactions',q,expected=[400,409,422]);check(name+' rolls back',db('SELECT COUNT(*) n FROM transactions')[0]['n']==before)
 check('All journals balance after historical mutations',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
 (base/'extended-state.json').write_text(json.dumps({'run':run,'ids':ids,'expenseCategory':expense}),encoding='utf8')
 print('RESULT',sum(x['pass'] for x in results),'/',len(results),flush=True)
