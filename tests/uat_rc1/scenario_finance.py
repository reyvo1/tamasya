from client import *
from scenario_core import db
import datetime,os
state=json.loads((base/'state.json').read_text());results=[]
def check(name,ok,detail=None):
 results.append({'test':name,'pass':bool(ok),'details':detail});print('PASS' if ok else 'FAIL',name,str(detail or '')[:500]);(base/'logs/finance-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')
def call(name,action,data=None,expected=200,method='POST'):
 status,body=request(action,method,data,'sim_fin_'+name)
 check(name,status==expected and (body.get('success') is not False if status<300 else body.get('success') is False),{'status':status,'message':body.get('error',body.get('message',''))})
 return body
call('stock_conflict_fixed','pos-sale-create',{'paymentMethod':'cash','items':[{'productId':state['product_1'],'quantity':1000}]},409)
_,b=request('hotel-data');check('Database config projects actual nonstandard port',str(b.get('config',{}).get('dbPort'))==os.getenv('TAMASYA_UAT_DB_PORT','3306'))
call('bank_setup','bank-accounts',{'bankAccounts':[{'id':'sim_bank','name':'SIM BANK','accountNumber':'000000','accountHolder':'SIMULATION HOTEL','type':'bank','isActive':True}]})
before=db('SELECT COUNT(*) n FROM transactions')[0]['n']
call('overpayment_rejected','booking-payments',{'bookingId':state['active'],'amount':3,'paymentMethod':'cash','operationId':'sim_fin_overpayment'},409)
check('Rejected overpayment creates no transaction',db('SELECT COUNT(*) n FROM transactions')[0]['n']==before)
call('pay_room_charge','booking-payments',{'bookingId':state['active'],'amount':2,'paymentMethod':'cash','operationId':'sim_fin_pay_charge'})
check('Room folio settled after extra payment',float(db('SELECT balanceDue FROM bookings WHERE id=?',[state['active']])[0]['balanceDue'])==0)
call('pay_deposit','booking-payments',{'bookingId':state['reserved'],'amount':100000,'paymentMethod':'transfer','bankAccountId':'sim_bank','operationId':'sim_fin_deposit'})
check('Deposit reduces reservation receivable',float(db('SELECT balanceDue FROM bookings WHERE id=?',[state['reserved']])[0]['balanceDue'])==300000)
call('cancel_refund','bookings-status',{'id':state['reserved'],'status':'cancelled','operationId':'sim_fin_cancel'})
r=db('SELECT status,amountPaid,balanceDue FROM bookings WHERE id=?',[state['reserved']])[0]
check('Cancellation clears balance',r['status']=='cancelled' and float(r['balanceDue'])==0,r)
rows=db('SELECT type,amount,bankAccountId FROM transactions WHERE bookingId=?',[state['reserved']]);check('Cancellation refunds to original bank',sum(float(r['amount']) if r['type']=='income' else -float(r['amount']) for r in rows)==0 and all(r['bankAccountId']=='sim_bank' for r in rows),rows)
call('checkout','bookings-status',{'id':state['active'],'status':'completed','keyReturned':True,'operationId':'sim_fin_checkout'})
check('Checkout changes booking to completed',db('SELECT status FROM bookings WHERE id=?',[state['active']])[0]['status']=='completed')
check('Checkout does not mark room instantly sellable',db("SELECT status FROM rooms WHERE number='102'")[0]['status']!='available')
check('Checkout creates housekeeping task',bool(db("SELECT id,status FROM housekeeping_tasks WHERE room_number='102' AND status NOT IN ('ready','completed')")))
call('checkout_retry','bookings-status',{'id':state['active'],'status':'completed','keyReturned':True,'operationId':'sim_fin_checkout_retry'})
check('Repeated checkout creates one housekeeping task',db("SELECT COUNT(*) n FROM housekeeping_tasks WHERE room_number='102'")[0]['n']==1)
today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date();future=today+datetime.timedelta(days=5)
payload={'guestName':'SIM Split Guest','roomNumber':'101','checkIn':str(future),'checkOut':str(future+datetime.timedelta(days=1)),'totalAmount':200000,'paymentStatus':'paid','paymentMethod':'cash','isSplitPayment':True,'splitCashAmount':80000,'splitTransferAmount':120000,'splitTransferBankAccountId':'sim_bank','bookingSource':'Direct','broadcast':False}
b=call('split_booking','bookings',payload);state['split']=b.get('bookingId')
if state['split']:
 rows=db('SELECT type,amount,bankAccountId,isSplitPayment,splitCashAmount,splitTransferAmount,splitTransferBankAccountId FROM transactions WHERE bookingId=?',[state['split']]);check('Split payment total equals booking',sum(float(r['amount']) for r in rows if r['type']=='income')==200000,rows)
 call('split_cancel','bookings-status',{'id':state['split'],'status':'cancelled','operationId':'sim_fin_split_cancel'})
 rows=db('SELECT type,amount,bankAccountId FROM transactions WHERE bookingId=?',[state['split']]);check('Split cancellation preserves net zero',sum(float(r['amount']) if r['type']=='income' else -float(r['amount']) for r in rows)==0,rows)
check('All journal entries balance',not db('SELECT journal_entry_id,SUM(debit)-SUM(credit) delta FROM journal_lines GROUP BY journal_entry_id HAVING ABS(delta)>0.001'))
check('Every financial transaction has a journal entry',not db('SELECT t.id FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id WHERE j.id IS NULL'))
(base/'state.json').write_text(json.dumps(state),encoding='utf8')
print('finance results',sum(r['pass'] for r in results),'/',len(results))
