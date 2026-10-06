from client import *
from scenario_core import db
import datetime
state=json.loads((base/'state.json').read_text());results=[]
def check(name,ok,detail=None):
 results.append({'test':name,'pass':bool(ok),'details':detail});print('PASS' if ok else 'FAIL',name,str(detail or '')[:500]);(base/'logs/operations-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')
def call(name,data,expected=200,action='operations-center'):
 status,b=request(action,'POST',data,'sim_ops_'+name);check(name,status==expected and (b.get('success') is not False if expected<300 else b.get('success') is False),{'status':status,'message':b.get('error',b.get('message',''))});return b
task=db("SELECT id FROM housekeeping_tasks WHERE room_number='102' AND status NOT IN ('ready','completed') ORDER BY created_at DESC LIMIT 1")[0]['id']
call('hk_cleaning',{'command':'housekeeping-status','id':task,'status':'cleaning'})
call('hk_inspection',{'command':'housekeeping-status','id':task,'status':'inspection'})
call('hk_ready',{'command':'housekeeping-status','id':task,'status':'ready'})
check('Housekeeping ready returns room to inventory',db("SELECT status FROM rooms WHERE number='102'")[0]['status']=='available')
call('maintenance_open',{'command':'maintenance-ticket-save','id':'sim_maintenance_102','assetKind':'room','roomNumber':'102','title':'SIM kerusakan AC','description':'AC kamar simulasi tidak menyala','priority':'high'})
check('Maintenance blocks available room',db("SELECT status FROM rooms WHERE number='102'")[0]['status']!='available')
today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()
payload={'guestName':'SIM Blocked Guest','roomNumber':'102','checkIn':str(today),'checkOut':str(today+datetime.timedelta(days=1)),'totalAmount':200000,'paymentStatus':'unpaid','lifecycleIntent':'check_in_now','broadcast':False}
call('maintenance_blocks_checkin',payload,409,'bookings')
call('maintenance_work',{'command':'maintenance-ticket-status','id':'sim_maintenance_102','status':'in_progress'})
call('maintenance_done',{'command':'maintenance-ticket-status','id':'sim_maintenance_102','status':'completed','notes':'AC diperbaiki dan diuji berfungsi','actualCost':0})
check('Maintenance completion still requires inspection',db("SELECT status FROM rooms WHERE number='102'")[0]['status']!='available')
rows=db("SELECT id,status FROM housekeeping_tasks WHERE room_number='102' AND status NOT IN ('ready','completed') ORDER BY created_at DESC")
check('Maintenance creates inspection task',bool(rows) and rows[0]['status']=='inspection',rows)
if rows:call('maintenance_inspection_ready',{'command':'housekeeping-status','id':rows[0]['id'],'status':'ready'})
check('Inspection releases maintenance room',db("SELECT status FROM rooms WHERE number='102'")[0]['status']=='available')
call('tax_rule',{'command':'tax-rule-save','id':'sim_tax_10','name':'SIM arithmetic 10 percent','sourcePattern':'*','transactionKind':'*','rate':10,'priority':100,'taxable':1,'isActive':1})
call('tax_profile',{'command':'save','propertyName':'SIMULATION HOTEL','address':'Alamat Simulasi','phone':'0000000000','email':'simulation@example.invalid','taxSetupMode':'configured','paymentSetupMode':'configured'},action='property-setup')
call('tax_ready',{'command':'finalize'},action='property-setup')
future=today+datetime.timedelta(days=10)
payload={'guestName':'SIM Tax Guest','roomNumber':'101','checkIn':str(future),'checkOut':str(future+datetime.timedelta(days=1)),'totalAmount':220000,'paymentStatus':'paid','paymentMethod':'cash','bookingSource':'Direct','broadcast':False}
b=call('tax_booking',payload,action='bookings');booking=b.get('bookingId')
if booking:
 r=db('SELECT amount,baseAmount,taxAmount FROM transactions WHERE bookingId=? AND type=\'income\'',[booking])[0]
 check('Inclusive tax snapshot is exact',float(r['amount'])==220000 and float(r['baseAmount'])==200000 and float(r['taxAmount'])==20000,r)
 call('tax_cancel',{'id':booking,'status':'cancelled','operationId':'sim_ops_tax_cancel'},action='bookings-status')
 rows=db('SELECT type,amount,baseAmount,taxAmount FROM transactions WHERE bookingId=?',[booking])
 check('Refund reverses original tax snapshot',len(rows)==2 and all(float(r['taxAmount'])==20000 for r in rows),rows)
check('Journal remains balanced after maintenance and tax refund',not db('SELECT journal_entry_id,SUM(debit)-SUM(credit) delta FROM journal_lines GROUP BY journal_entry_id HAVING ABS(delta)>0.001'))
print('operations results',sum(r['pass'] for r in results),'/',len(results))
