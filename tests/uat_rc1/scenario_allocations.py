import scenario_extended as e
from scenario_extended import *
e.logname='allocation-results.json'
future=today+datetime.timedelta(days=25)
b=call('allocation_booking','bookings',{'guestName':run+' Allocation Guest','roomNumber':'103','checkIn':str(future),'checkOut':str(future+datetime.timedelta(days=1)),'totalAmount':220000,'paymentStatus':'unpaid','broadcast':False})
booking=b.get('bookingId')
r,p=tx('allocation_receipt',amount=490000,date=str(today))
i=r.get('id');beforeCount=db('SELECT COUNT(*) n FROM transactions')[0]['n']
def alloc(name,kind,amount,**kw):
 p={'transactionId':i,'bookingId':booking,'action':kind,'amount':amount,'date':str(today),'serviceDate':str(today),'manualBookingLinkConfirmed':True,'historicalDateMismatchConfirmed':True,'historicalLinkReason':'Bukti arsip simulasi telah dicocokkan','operationId':run+'_alloc_'+name,'description':run+' '+name};p.update(kw)
 b=call('allocate_'+name,'transaction-booking-action',p);return p,b
room,b=alloc('room','room',220000)
extra,b=alloc('extra','extra',50000,categoryId=state['extra_service'],extra={'name':'SIM extra service','price':50000,'qty':1})
ext,b=alloc('extension','extension',220000,newCheckOutDate=str(future+datetime.timedelta(days=2)))
rows=db('SELECT * FROM transaction_allocations WHERE transaction_id=? ORDER BY created_at,id',[i]);check('Room extra extension create three allocations',len(rows)==3)
check('Allocations never duplicate receipt cash',db('SELECT COUNT(*) n FROM transactions')[0]['n']==beforeCount)
r=db('SELECT totalAmount,amountPaid,balanceDue,checkOut FROM bookings WHERE id=?',[booking])[0]
check('Allocated folio reflects room extra and extension',float(r['totalAmount'])==490000 and float(r['amountPaid'])==490000 and float(r['balanceDue'])==0,r)
call('allocation_retry','transaction-booking-action',room,op=run+'_room_retry')
call('allocation_changed_operation','transaction-booking-action',dict(room,amount=110000),expected=409,op=run+'_room_changed')
call('overallocate','transaction-booking-action',dict(room,amount=1,operationId=run+'_overallocate'),expected=409)
call('shrink_allocated_receipt','transactions',{'id':i,'amount':1000,'changeReason':'Simulasi pembatasan receipt'},method='PUT',expected=409)
for kind in ['extension','extra','room']:
 row=next((x for x in rows if x['allocation_type']==kind),None)
 if not row:continue
 p={'allocationId':row['id'],'operationId':run+'_void_'+kind,'reason':'Simulasi pembatalan rincian','confirmation':'BATALKAN ALOKASI'}
 call('void_'+kind,'transaction-allocation-void',p)
 call('void_'+kind+'_retry','transaction-allocation-void',p,op=run+'_retry_void_'+kind)
r=db('SELECT totalAmount,amountPaid,balanceDue,checkOut FROM bookings WHERE id=?',[booking])[0]
check('Void returns original unpaid booking without deleting cash',float(r['totalAmount'])==220000 and float(r['amountPaid'])==0 and r['checkOut']==str(future+datetime.timedelta(days=1)),r)
r=db('SELECT amount,bookingId FROM transactions WHERE id=?',[i])[0];check('Receipt remains standalone after last allocation void',float(r['amount'])==490000 and not r['bookingId'],r)
check('Journal balanced after allocation and reversal',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
(base/'allocation-state.json').write_text(json.dumps({'booking':booking,'receipt':i,'roomPayload':room,'run':run}))
print('RESULT',sum(x['pass'] for x in e.results),'/',len(e.results))
