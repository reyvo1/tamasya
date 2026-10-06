import scenario_extended as e
from scenario_extended import *
e.logname='accounting-report-results.json'
prior=json.loads((base/'extended-state.json').read_text());e.ids.update(prior['ids'])
# Verify amounts against explicit receipt fixtures, not only debit-credit equality.
for key,legs in [('old_cash',[(105000,0),(0,100000),(0,5000)]),('split',[(40000,0),(70000,0),(0,100000),(0,10000)]),('unknown',[(110000,0),(0,100000),(0,10000)])]:
 rows=db('SELECT l.account_code,l.account_name,l.debit,l.credit FROM journal_lines l JOIN journal_entries j ON j.id=l.journal_entry_id WHERE j.transaction_id=?',[ids[key]])
 check(key+' journal has exact expected legs',sorted((float(r['debit']),float(r['credit'])) for r in rows)==sorted(legs),rows)
# Negative/review/reconciliation controls.
call('reject_unknown','historical-backfill-review',{'transactionId':ids['unknown'],'decision':'reject','reviewNote':'Bukti simulasi belum sesuai'})
call('reject_is_terminal','historical-backfill-review',{'transactionId':ids['unknown'],'decision':'approve','reviewNote':'Tidak boleh membalik status terminal'},expected=409)
call('bad_period_month','reporting-periods',{'periodKey':'2026-13','cashStatus':'open','taxStatus':'open'},expected=422)
call('bad_report_date','canonical-report&type=tax_ledger&from=2026-02-30&to=2026-03-05',expected=[400,422],method='GET')
call('reconcile_split','operations-center',{'command':'reconciliation-import','items':[{'date':'2026-07-13','amount':70000,'reference':run+'_bank_receipt'}]})
r=db('SELECT reconciliationStatus FROM transactions WHERE id=?',[ids['split']])[0]
check('Split reconciliation matches only transfer leg',r['reconciliationStatus']=='matched',r)
call('reject_cash_reconciliation','operations-center',{'command':'reconciliation-save','transactionId':ids['old_cash'],'amount':105000,'transactionDate':'2026-06-12'},expected=[400,409,422])
call('reject_gross_split_reconciliation','operations-center',{'command':'reconciliation-save','transactionId':ids['split'],'amount':110000,'transactionDate':'2026-07-13'},expected=[400,409,422])
# Every declared canonical report must render from the same seeded ledger.
_,catalog=request('canonical-report-types')
reports={}
for typ in catalog['types']:
 b=call('report_'+typ,'canonical-report&type='+typ+'&from=2026-01-01&to=2026-12-31',method='GET');reports[typ]=b
 (base/'logs'/('extended-report-'+typ+'.json')).write_text(json.dumps(b,ensure_ascii=False,indent=2),encoding='utf8')
tax=reports['tax_ledger'];fin=reports['financial_summary']
# Fixtures include real booking/POS refund scenarios from the previous integration suite.
expected=db("SELECT SUM(CASE WHEN type='income' THEN COALESCE(taxAmount,0) WHEN transactionKind IN ('refund','pos_refund') THEN -COALESCE(taxAmount,0) ELSE 0 END) tax FROM transactions WHERE date BETWEEN '2026-01-01' AND '2026-12-31'")[0]['tax']
check('Tax ledger subtracts refund tax',abs(float(tax.get('summary',{}).get('Total Pajak',-1))-float(expected))<0.005,{'expected':expected,'actual':tax.get('summary')})
check('Financial summary tax equals tax ledger',fin.get('summary',{}).get('PBJT Terbentuk')==tax.get('summary',{}).get('Total Pajak'))
rows=tax.get('sheets',{}).get('Pajak',[])
check('Resolved historical tax is explicit in report',any(r.get('Keterangan','').endswith('unknown') and r.get('Status')=='confirmed' and float(r.get('Pajak') or 0)==10000 and float(r.get('DPP') or 0)==100000 for r in rows))
check('Refund report has negative tax rows',any(float(r.get('Pajak') or 0)<0 for r in rows))
check('Journal report remains balanced',reports['journal'].get('summary',{}).get('Selisih')==0)
check('Cash-bank report reconciles to financial summary',round(sum(float(x) for x in reports['cash_bank'].get('summary',{}).values()),2)==fin.get('summary',{}).get('Total Likuid Bersih'))
# scenario_tax_edges resolves the previously unresolved receipt before this suite.
# Prove that the audited correction removed suspense and rebuilt exact revenue+PBJT legs.
rows=db('SELECT l.account_code,l.account_name,l.debit,l.credit FROM journal_lines l JOIN journal_entries j ON j.id=l.journal_entry_id WHERE j.transaction_id=? AND l.credit>0 ORDER BY l.account_code',[ids['unknown']])
check('Audited tax resolution replaces suspense with revenue and PBJT',not any(r['account_code']=='2199' for r in rows) and any(r['account_code']=='4101' and abs(float(r['credit'])-100000)<0.01 for r in rows) and any(r['account_code']=='2102' and abs(float(r['credit'])-10000)<0.01 for r in rows),rows)
print('RESULT',sum(x['pass'] for x in e.results),'/',len(e.results))

# Daily operational command must share the canonical PBJT/refund interpretation.
status,daily=request('operations-center','POST',{'command':'daily-summary','date':'2026-07-12'},'uat_daily_consistency')
status_report,period_report=request('action=canonical-report&type=financial_summary&from=2026-07-12&to=2026-07-12')
check('Daily summary succeeds with canonical financial basis',status==200 and daily.get('success') is True and daily.get('financial_basis')=='canonical_liquid_receipts_payments',daily)
check('Daily summary PBJT equals same-day canonical report including refunds',status_report==200 and abs(float(daily.get('pbjt',-1))-float(period_report.get('summary',{}).get('PBJT Terbentuk',-2)))<0.005,{'daily':daily.get('pbjt'),'canonical':period_report.get('summary')})
check('Daily summary labels operational state separately from historical financial date',daily.get('operational_scope')=='current_snapshot' and bool(daily.get('operational_date')),daily)
