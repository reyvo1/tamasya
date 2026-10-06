from client import *
import subprocess,datetime
state=json.loads((base/'state.json').read_text());results=[]
def db(sql,params=[]):
 r=subprocess.run(['php',str(base/'db.php')],input=json.dumps({'sql':sql,'params':params}),capture_output=True,text=True,encoding='utf8')
 if r.returncode:raise RuntimeError(r.stderr)
 return json.loads(r.stdout)
def check(name,ok,details=None):
 results.append({'test':name,'pass':bool(ok),'details':details});print('PASS' if ok else 'FAIL',name,str(details or '')[:350])
 (base/'logs/core-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')
def call(name,action,data=None,method='POST',expected=200,operation=None):
 status,body=request(action,method,data,operation or 'sim_core_'+name)
 check(name,status==expected and (body.get('success') is not False if expected<300 else body.get('success') is False),{'status':status,'message':body.get('error',body.get('message',''))})
 return body
if __name__=='__main__':
 cart=[{'productId':state['product_'+str(i)],'quantity':1} for i in range(1,5)]
 data={'paymentMethod':'cash','items':cart,'discountType':'fixed','discountValue':0.02,'discountReason':'Simulasi pembulatan'}
 sale=call('pos_discount','pos-sale-create',data);state['sale']=sale.get('sale',{}).get('id')
 if state['sale']:
  s=db('SELECT gross_amount,discount_amount,base_amount,tax_amount FROM pos_sales WHERE id=?',[state['sale']])[0]
  check('POS exact discounted total',float(s['gross_amount'])==3.98 and float(s['discount_amount'])==0.02,s)
  call('pos_retry','pos-sale-create',data,operation='sim_core_pos_discount')
  check('POS retry creates one sale',db('SELECT COUNT(*) n FROM pos_sales')[0]['n']==1)
  check('POS decrements stock once',all(float(r['stock_quantity'])==99 for r in db('SELECT stock_quantity FROM pos_products')))
  call('pos_stock_reject','pos-sale-create',{'paymentMethod':'cash','items':[{'productId':state['product_1'],'quantity':1000}]},expected=409)
  check('Rejected POS preserves stock and sale count',db('SELECT COUNT(*) n FROM pos_sales')[0]['n']==1 and float(db('SELECT stock_quantity FROM pos_products WHERE id=?',[state['product_1']])[0]['stock_quantity'])==99)
  call('pos_void','pos-sale-void',{'saleId':state['sale'],'reason':'Simulasi pembatalan'})
  check('POS void restores stock',all(float(r['stock_quantity'])==100 for r in db('SELECT stock_quantity FROM pos_products')))
  call('pos_void_again','pos-sale-void',{'saleId':state['sale'],'reason':'Simulasi pembatalan ulang'})
  check('Repeated void does not duplicate refunds',db("SELECT COUNT(*) n FROM transactions WHERE transactionKind='pos_refund'")[0]['n']==1)
 today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()
 future=today+datetime.timedelta(days=2);end=future+datetime.timedelta(days=2)
 payload={'guestName':'SIM Future Guest','roomNumber':'101','checkIn':str(future),'checkOut':str(end),'totalAmount':400000,'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False}
 b=call('reserve','bookings',payload);state['reserved']=b.get('bookingId')
 if state['reserved']:
  check('Reservation does not occupy room',db('SELECT status FROM rooms WHERE number=?',['101'])[0]['status']=='available')
  call('reserve_retry','bookings',payload,operation='sim_core_reserve')
  check('Booking retry creates one booking',db('SELECT COUNT(*) n FROM bookings WHERE roomNumber=?',['101'])[0]['n']==1)
  call('reserve_overlap','bookings',{**payload,'guestName':'SIM Conflict'},expected=409)
  check('Rejected overlap preserves reservation count',db('SELECT COUNT(*) n FROM bookings WHERE roomNumber=?',['101'])[0]['n']==1)
 payload={'guestName':'SIM Current Guest','roomNumber':'102','checkIn':str(today),'checkOut':str(today+datetime.timedelta(days=1)),'totalAmount':200000,'paymentStatus':'paid','paymentMethod':'cash','bookingSource':'Direct','lifecycleIntent':'check_in_now','broadcast':False}
 b=call('checkin','bookings',payload);state['active']=b.get('bookingId')
 if state['active']:
  r=db('SELECT status,amountPaid,balanceDue FROM bookings WHERE id=?',[state['active']])[0]
  check('Paid checkin has settled folio',r['status']=='active' and float(r['amountPaid'])==200000 and float(r['balanceDue'])==0,r)
  call('room_charge','pos-sale-create',{'paymentMethod':'room_charge','bookingId':state['active'],'items':[{'productId':state['product_1'],'quantity':2}]})
  r=db('SELECT totalAmount,balanceDue FROM bookings WHERE id=?',[state['active']])[0]
  check('Room charge increases receivable',float(r['totalAmount'])==200002 and float(r['balanceDue'])==2,r)
 check('No duplicate document numbers',not db("SELECT documentNumber,COUNT(*) n FROM transactions WHERE documentNumber IS NOT NULL AND documentNumber<>'' GROUP BY documentNumber HAVING COUNT(*)>1"))
 (base/'state.json').write_text(json.dumps(state),encoding='utf8')
 print('core results',sum(r['pass'] for r in results),'/',len(results))
