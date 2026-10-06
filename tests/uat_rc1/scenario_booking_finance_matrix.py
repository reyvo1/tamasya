from client import *
from scenario_core import db
import datetime, json

state=json.loads((base/'state.json').read_text(encoding='utf8'))
results=[]

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:700],flush=True)
    (base/'logs/booking-finance-matrix-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')

def call(name,action,data=None,expected=200,method='POST',operation=None):
    status,body=request(action,method,data,operation or 'sim_matrix_'+name)
    expected_set={expected} if isinstance(expected,int) else set(expected)
    ok=status in expected_set and (body.get('success') is not False if status<300 else body.get('success') is False)
    check(name,ok,{'status':status,'message':body.get('error',body.get('message',''))})
    return status,body

today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()
future=today+datetime.timedelta(days=20)

# Active booking then dedicated room transfer: regression for historical room-move failures.
payload={
    'guestName':'SIM Matrix Transfer Guest','roomNumber':'104','checkIn':str(today),
    'checkOut':str(today+datetime.timedelta(days=2)),'totalAmount':440000,
    'paymentStatus':'paid','paymentMethod':'cash','bookingSource':'Direct',
    'lifecycleIntent':'check_in_now','broadcast':False
}
_,b=call('create_transfer_booking','bookings',payload)
transfer_booking=b.get('bookingId')
if transfer_booking:
    before=db('SELECT id,roomNumber,status,version,totalAmount,amountPaid,balanceDue FROM bookings WHERE id=?',[transfer_booking])[0]
    _,moved=call('dedicated_room_transfer',f'room-transfers&id={transfer_booking}',{
        'targetRoomNumber':'105','rateMode':'keep','surchargeAmount':0,
        'keyDisposition':'returned','keyReason':'UAT room transfer key returned',
        'expectedVersion':int(before['version'])
    },operation='sim_matrix_transfer_104_105')
    after=db('SELECT roomNumber,status,version,totalAmount,amountPaid,balanceDue FROM bookings WHERE id=?',[transfer_booking])[0]
    check('Room transfer moves active booking 104 to 105',after['roomNumber']=='105' and after['status']=='active',after)
    check('Room transfer preserves settled financial ledger',float(after['totalAmount'])==float(before['totalAmount']) and float(after['amountPaid'])==float(before['amountPaid']) and float(after['balanceDue'])==float(before['balanceDue']),{'before':before,'after':after})
    check('Old room enters housekeeping/maintenance instead of instantly available',db("SELECT status FROM rooms WHERE number='104'")[0]['status']!='available')
    target_room=db("SELECT status FROM rooms WHERE number='105'")[0]
    target_active=db("SELECT id,status,roomNumber FROM bookings WHERE id=? AND status='active' AND roomNumber='105'",[transfer_booking])
    check('New room projection is canonical booked with transferred active booking',target_room['status']=='booked' and bool(target_active),{'room':target_room,'activeBooking':target_active})
    check('Transfer creates housekeeping task for old room',bool(db("SELECT id,status FROM housekeeping_tasks WHERE room_number='104' AND status NOT IN ('ready','completed')")))

# Negotiated/direct price must calculate PBJT from the actual negotiated gross, not master-room price.
negotiated_total=198000.0
_,b=call('negotiated_price_booking','bookings',{
    'guestName':'SIM Negotiated Guest','roomNumber':'106','checkIn':str(future),
    'checkOut':str(future+datetime.timedelta(days=1)),'totalAmount':negotiated_total,
    'paymentStatus':'paid','paymentMethod':'cash','bookingSource':'Direct','broadcast':False
})
neg=b.get('bookingId')
if neg:
    row=db("SELECT amount,baseAmount,taxAmount,taxRate,taxSource FROM transactions WHERE bookingId=? AND type='income' ORDER BY createdAt LIMIT 1",[neg])[0]
    check('Negotiated price posts the negotiated gross exactly',abs(float(row['amount'])-negotiated_total)<0.005,row)
    check('Negotiated price PBJT is calculated from actual gross',abs(float(row['baseAmount'])-180000.0)<0.01 and abs(float(row['taxAmount'])-18000.0)<0.01 and abs(float(row['taxRate'])-10.0)<0.001,row)

# Traveloka/OTA must use explicit receivable semantics and still retain tax snapshot.
ota_total=220000.0
_,b=call('traveloka_receivable_booking','bookings',{
    'guestName':'SIM Traveloka Guest','roomNumber':'107','checkIn':str(future+datetime.timedelta(days=2)),
    'checkOut':str(future+datetime.timedelta(days=3)),'totalAmount':ota_total,
    'paymentStatus':'paid','paymentMethod':'ota','bankAccountId':'ota_receivable',
    'bookingSource':'Traveloka','broadcast':False
})
ota=b.get('bookingId')
if ota:
    row=db("SELECT amount,baseAmount,taxAmount,taxRate,bankAccountId,bookingSource,transactionKind FROM transactions WHERE bookingId=? AND type='income' ORDER BY createdAt LIMIT 1",[ota])[0]
    check('Traveloka booking posts to OTA receivable',row['bankAccountId']=='ota_receivable' and row['bookingSource']=='Traveloka',row)
    check('Traveloka booking keeps PBJT snapshot',abs(float(row['baseAmount'])-200000.0)<0.01 and abs(float(row['taxAmount'])-20000.0)<0.01 and abs(float(row['taxRate'])-10.0)<0.001,row)

# Active split payment: one economic booking, split settlement metadata, no duplicate document numbers.
_,b=call('active_split_booking','bookings',{
    'guestName':'SIM Active Split Guest','roomNumber':'108','checkIn':str(today),
    'checkOut':str(today+datetime.timedelta(days=1)),'totalAmount':220000,
    'paymentStatus':'paid','paymentMethod':'cash','isSplitPayment':True,
    'splitCashAmount':70000,'splitTransferAmount':150000,
    'splitTransferBankAccountId':'sim_bank','bookingSource':'Direct',
    'lifecycleIntent':'check_in_now','broadcast':False
})
split=b.get('bookingId')
if split:
    booking=db('SELECT status,totalAmount,amountPaid,balanceDue FROM bookings WHERE id=?',[split])[0]
    rows=db("SELECT amount,isSplitPayment,splitCashAmount,splitTransferAmount,splitTransferBankAccountId,taxAmount FROM transactions WHERE bookingId=? AND type='income'",[split])
    check('Active split booking is fully settled',booking['status']=='active' and float(booking['amountPaid'])==220000 and float(booking['balanceDue'])==0,booking)
    check('Split metadata preserves exact cash and transfer legs',len(rows)==1 and int(rows[0]['isSplitPayment'])==1 and float(rows[0]['splitCashAmount'])==70000 and float(rows[0]['splitTransferAmount'])==150000 and rows[0]['splitTransferBankAccountId']=='sim_bank',rows)
    check('Split booking tax is not duplicated by payment legs',len(rows)==1 and abs(float(rows[0]['taxAmount'])-20000.0)<0.01,rows)

# Global financial invariants after the high-risk matrix.
check('Booking matrix leaves every journal balanced',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
check('Booking matrix leaves no financial transaction without journal',not db('SELECT t.id FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id WHERE j.id IS NULL'))
check('Booking matrix leaves document numbers unique',not db("SELECT documentNumber,COUNT(*) n FROM transactions WHERE documentNumber IS NOT NULL AND documentNumber<>'' GROUP BY documentNumber HAVING COUNT(*)>1"))

state['matrix_transfer_booking']=transfer_booking
state['matrix_ota_booking']=ota
state['matrix_split_booking']=split
(base/'state.json').write_text(json.dumps(state),encoding='utf8')
print('BOOKING-FINANCE-MATRIX',sum(x['pass'] for x in results),'/',len(results))
