from client import *
from scenario_core import db,state
import concurrent.futures,datetime
results=[]
def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail}); print('PASS' if ok else 'FAIL',name,detail or '',flush=True)
    (base/'logs/concurrency-results.json').write_text(json.dumps(results,indent=2),encoding='utf8')

future=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()+datetime.timedelta(days=30)
payload={'guestName':'RC1 Concurrent Guest','roomNumber':'103','checkIn':str(future),'checkOut':str(future+datetime.timedelta(days=1)),'totalAmount':220000,'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False}
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    jobs=[pool.submit(request,'bookings','POST',payload,'rc1_concurrent_booking_'+str(i),38184+i) for i in range(2)]
    replies=[j.result() for j in jobs]
check('Concurrent overlap has exactly one winner',sorted(r[0] for r in replies)==[200,409],[r[0] for r in replies])
check('Concurrent overlap writes one booking',db('SELECT COUNT(*) n FROM bookings WHERE guestName=?',['RC1 Concurrent Guest'])[0]['n']==1)
cart={'paymentMethod':'cash','items':[{'productId':state['product_2'],'quantity':1}]}
before=db('SELECT COUNT(*) n FROM pos_sales')[0]['n']
operation='rc1_concurrent_same_sale'
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    jobs=[pool.submit(request,'pos-sale-create','POST',cart,operation,38184+i) for i in range(2)]
    replies=[j.result() for j in jobs]
check('Concurrent same operation succeeds/replays without duplicate money',all(r[0] in (200,202,409) for r in replies),[r[0] for r in replies])
status,body=request('pos-sale-create','POST',cart,operation)
check('Same operation becomes a successful deterministic replay',status==200 and body.get('success') is True,{'status':status,'error':body.get('error')})
check('Concurrent operation writes one sale',db('SELECT COUNT(*) n FROM pos_sales')[0]['n']==before+1)
print('CONCURRENCY',sum(x['pass'] for x in results),'/',len(results))
