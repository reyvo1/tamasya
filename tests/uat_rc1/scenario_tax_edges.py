import scenario_extended as e
from scenario_extended import *
e.logname='tax-edge-results.json'
old=json.loads((base/'extended-state.json').read_text());prefix=old['run']
# Overlapping equally ranked rules must be rejected; adjacent dates already tested.
call('ambiguous_rule_rejected','operations-center',{'command':'tax-rule-save','id':run+'_overlap','name':'SIM conflicting tax rule','sourcePattern':'*','transactionKind':'room','rate':12,'priority':200,'effectiveFrom':'2026-02-01','effectiveUntil':'2026-03-01','isActive':1},expected=[400,409,422])
call('inverted_tax_dates','operations-center',{'command':'tax-rule-save','id':run+'_inverted','name':'SIM invalid range','effectiveFrom':'2026-08-01','effectiveUntil':'2026-07-01','rate':10},expected=[400,422])
for name,rate in [('negative_rate',-1),('excess_rate',101)]:call(name,'operations-center',{'command':'tax-rule-save','id':run+'_'+name,'name':'SIM invalid rate','rate':rate},expected=[400,422])
# Editing text must preserve explicit documentary tax even with newer rules present.
i=old['ids']['document'];before=db('SELECT baseAmount,taxAmount,taxRate,taxSource FROM transactions WHERE id=?',[i])[0]
call('edit_document_description','transactions',{'id':i,'description':run+' revised archive description','changeReason':'Koreksi keterangan tanpa nominal'},method='PUT')
after=db('SELECT baseAmount,taxAmount,taxRate,taxSource FROM transactions WHERE id=?',[i])[0]
check('Document tax snapshot survives descriptive edit',before==after,{'before':before,'after':after})
# Explicit unresolved can later be resolved via audited edit, replacing suspense journal.
i=old['ids']['unknown']
call('resolve_unknown_tax','transactions',{'id':i,'historicalTaxMode':'document','baseAmount':100000,'taxAmount':10000,'taxRate':10,'changeReason':'Bukti pajak historis sudah ditemukan'},method='PUT')
r=db('SELECT taxAmount,taxSnapshotStatus FROM transactions WHERE id=?',[i])[0]
check('Audited tax correction resolves previously unknown snapshot',float(r['taxAmount'] or 0)==10000 and r['taxSnapshotStatus']=='confirmed',r)
rows=db('SELECT l.account_code,l.debit,l.credit FROM journal_lines l JOIN journal_entries j ON j.id=l.journal_entry_id WHERE j.transaction_id=?',[i])
check('Resolution replaces suspense with revenue and tax liability',not any(r['account_code']=='2199' for r in rows) and any(r['account_code']=='2102' and float(r['credit'])==10000 for r in rows),rows)
# Absence of rules must be explicit; never silently invent a zero rate.
active=db('SELECT id,source_pattern FROM tax_rules')
try:
 db("UPDATE tax_rules SET source_pattern='SimulationOnly'")
 p={'type':'income','categoryId':state['room_rental'],'amount':110000,'date':'2026-08-20','description':run+' no rule','recordOrigin':'historical_import','shiftExemptionReason':'Simulasi aturan pajak belum tersedia','historicalTaxMode':'rule_by_date','operationId':run+'_no_rule'}
 call('explicit_missing_rule','transactions',p,expected=[400,409,422])
 p.pop('historicalTaxMode');p['operationId']=run+'_fallback_unknown'
 beforeCount=db('SELECT COUNT(*) n FROM transactions')[0]['n']
 call('missing_rule_property_gate','transactions',p,expected=409)
 check('Incomplete property tax setup blocks writes',db('SELECT COUNT(*) n FROM transactions')[0]['n']==beforeCount)
finally:
 for r in active:db('UPDATE tax_rules SET source_pattern=? WHERE id=?',[r['source_pattern'],r['id']])
print('RESULT',sum(x['pass'] for x in e.results),'/',len(e.results))
