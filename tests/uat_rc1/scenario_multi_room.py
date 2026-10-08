"""Real multi-room API/Telegram/DB UAT, disposable GitHub database only."""
from client import *
from scenario_core import db
import datetime,uuid,urllib.parse,concurrent.futures,subprocess
run='mr_'+uuid.uuid4().hex[:10];results=[]
def check(name,ok,detail=None):
 results.append({'test':name,'pass':bool(ok),'details':detail});print('PASS' if ok else 'FAIL',name,str(detail or '')[:700],flush=True)
 (base/'logs/multi-room-results.json').write_text(json.dumps(results,indent=2,ensure_ascii=False,default=str))
def call(name,command,data=None,expected=(200,),query=None,op=None,port=None):
 action='multi-room-bookings';payload=dict(data or {});payload['command']=command
 if query is not None:action='action='+action+'&'+urllib.parse.urlencode({'command':command,**query});payload=None
 s,b=request(action,'GET' if query is not None else 'POST',payload,op or run+'_'+re.sub(r'[^A-Za-z0-9_.:-]','_',name),port)
 check(name,s in expected and b.get('success') is (s<300),{'status':s,'error':b.get('error',b.get('message'))})
 return s,b
_,ping=request('ping');today=datetime.date.fromisoformat(ping['timestamp'][:10]);future=today+datetime.timedelta(days=60)
rooms=[]
for i in range(16):
 number='M'+run[-5:]+str(i).zfill(2);s,b=request('rooms','POST',{'number':number,'type':'SIM Deluxe','price':200000,'floor':2},run+'_room_'+str(i));check('Dedicated room '+str(i),s==200 and b.get('success') is True);rooms.append(number)
def payload(numbers,mode='individual',start=future,price=220000.01,**extra):
 return {'guestName':'UAT Group Primary','guestPhone':'08000000123','guestEmail':'multi@example.invalid','bookingSource':'Direct','checkIn':str(start),'checkOut':str(start+datetime.timedelta(days=1)),'billingMode':mode,'routedPercent':50,'lifecycleIntent':'reserve','rooms':[{'roomNumber':n,'guestName':('UAT Occupant '+str(i)) if i else '','totalAmount':price} for i,n in enumerate(numbers)],**extra}
def must_create(name,p):
 s,b=call(name,'create',p);assert s==200 and b.get('groupId'),b
 return b['groupId'],b['data']
before_transactions=int(db('SELECT COUNT(*) n FROM transactions')[0]['n']);before_groups=int(db('SELECT COUNT(*) n FROM growth_group_reservations')[0]['n'])
# Exercise a real eligible Telegram recipient through the loopback API sink.
# A chat_id on staff alone is not an active telegram_bindings authorization.
recipient=db('SELECT id FROM staff WHERE username=?',[os.getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin')])[0]['id']
db("UPDATE config SET telegram_bot_token=? WHERE id='system_default'",['TAMASYA-UAT-SYNTHETIC-BOT-ONLY'])
db("INSERT INTO telegram_bindings(telegram_user_id,telegram_chat_id,staff_id,status) VALUES (?, ?, ?, 'active') ON DUPLICATE KEY UPDATE telegram_chat_id=VALUES(telegram_chat_id),staff_id=VALUES(staff_id),status='active'",['900001001','900001001',recipient])
gid,d=must_create('Create three-room reservation',payload(rooms[:3]))
check('One parent and exactly three independent reserved children',len(d['bookings'])==3 and all(b['status']=='reserved' for b in d['bookings']) and int(db('SELECT COUNT(*) n FROM growth_group_reservations')[0]['n'])==before_groups+1,d['lifecycle'])
check('Primary remains separate from optional occupants',d['group']['name']=='UAT Group Primary' and [b['guestName'] for b in d['bookings']]==['UAT Group Primary','UAT Occupant 1','UAT Occupant 2'])
notices=db("SELECT recipient_staff_id,COUNT(*) n FROM communication_outbox WHERE message_json LIKE ? GROUP BY recipient_staff_id",['%'+d['group']['group_code']+'%']);check('Group creation emits one summary per eligible notification recipient',bool(notices) and all(int(r['n'])==1 for r in notices),notices)
check('Unpaid group creates no invented cash/journal source',int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])==before_transactions)
_,retry=call('Identical operation replay','create',payload(rooms[:3]),op=run+'_Create_three-room_reservation')
check('Group replay returns same children without double booking',retry.get('groupId')==gid and len(db('SELECT booking_id FROM growth_group_booking_links WHERE group_id=?',[gid]))==3)
call('Changed payload cannot reuse receipt','create',payload(rooms[:3],guestName='Changed'),expected=(409,422),op=run+'_Create_three-room_reservation')
counts=lambda:[int(db('SELECT COUNT(*) n FROM '+t)[0]['n']) for t in ['bookings','growth_group_reservations','growth_group_booking_links','transactions']]
before=counts();call('Second child overlap rolls back whole batch','create',payload([rooms[3],rooms[1]]),expected=(409,422));check('Failed batch leaves no parent child link cash',counts()==before)
before=counts();_,duplicate=call('Duplicate room rejected','create',payload([rooms[4],rooms[4]]),expected=(409,422));check('Duplicate-room rejection explains the invalid input','Pilih kamar berbeda' in duplicate.get('error',''));check('Duplicate-room failure creates nothing',counts()==before)
_,availability=call('Availability uses existing inventory','availability',query={'checkIn':str(future),'checkOut':str(future+datetime.timedelta(days=1))});check('Booked room excluded and unrelated room available',not next(r for r in availability['data']['rooms'] if r['number']==rooms[0])['available'] and next(r for r in availability['data']['rooms'] if r['number']==rooms[4])['available'])
_,invalid_period=call('Invalid period rejected','availability',query={'checkIn':str(future),'checkOut':str(future)},expected=(422,));check('Invalid-period rejection preserves date rule','tanggal keluar setelahnya' in invalid_period.get('error',''))
# The same physical room can be occupied now and reserved for a non-overlapping date.
occupied_gid,occupied=must_create('Today active inventory fixture',payload([rooms[15]],start=today,lifecycleIntent='check_in_now'))
_,future_av=call('Future availability includes occupied room','availability',query={'checkIn':str(today+datetime.timedelta(days=1)),'checkOut':str(today+datetime.timedelta(days=2)),'bookingSource':'Traveloka'})
fr=next(r for r in future_av['data']['rooms'] if r['number']==rooms[15]);check('Occupied room is selectable after checkout with explicit current status',fr['available'] and fr['currentStatus']=='booked',fr)
check('Availability exposes specific booking source choices','Traveloka' in future_av['data']['bookingSources'] and 'Direct' in future_av['data']['bookingSources'] and 'OTA' not in future_av['data']['bookingSources'])
future_occupied,future_detail=must_create('Reserve occupied room after checkout',payload([rooms[15]],start=today+datetime.timedelta(days=1),bookingSource='Traveloka'))
check('Future reservation preserves OTA and does not check guest in',future_detail['bookings'][0]['status']=='reserved' and future_detail['bookings'][0]['bookingSource']=='Traveloka' and db('SELECT status FROM bookings WHERE id=?',[occupied['bookings'][0]['id']])[0]['status']=='active')
_,today_av=call('Today availability still rejects occupied overlap','availability',query={'checkIn':str(today),'checkOut':str(today+datetime.timedelta(days=1))})
tr=next(r for r in today_av['data']['rooms'] if r['number']==rooms[15]);check('Actual overlap shows dates instead of opaque booking id',not tr['available'] and str(today) in tr['reason'] and str(today+datetime.timedelta(days=1)) in tr['reason'] and occupied['bookings'][0]['id'] not in tr['reason'],tr)
# Ambiguous OTA is not allowed to masquerade as a named source.
before=counts();call('Generic OTA source rejected','create',payload([rooms[14]],bookingSource='OTA'),expected=(409,422));check('Rejected ambiguous source leaves no parent children cash',counts()==before)
# DP, all channels and exact cents.
if not db("SELECT id FROM shift_sessions WHERE status='open'"):
 s,b=request('operations-center','POST',{'command':'shift-open','openingCash':100000,'shiftTime':'siang','notes':'Multi-room UAT shift'},run+'_shift');check('Dedicated shift required for cash',s==200 and b.get('success') is True)
accounts={}
for method,kind in [('transfer','bank'),('qris','edc_qris')]:
 accounts[method]='mr_'+method+'_'+run;db('INSERT INTO bank_accounts(id,name,type,accountNumber,isActive) VALUES (?,?,?,?,1)',[accounts[method],'Multi '+method,kind,'UAT'])
for method,amount in [('cash',0.01),('transfer',123456.78),('qris',150000.02)]:
 before=float(db('SELECT COALESCE(SUM(amount),0) n FROM transactions')[0]['n']);op=run+'_pay_'+method
 p={'groupId':gid,'amount':amount,'paymentMethod':method,'bankAccountId':accounts.get(method,''),'date':str(today)}
 _,b=call('Pay group '+method,'payment',p,op=op);check('Exact canonical payment leg '+method,abs(float(db('SELECT COALESCE(SUM(amount),0) n FROM transactions')[0]['n'])-before-amount)<0.005,b.get('data',{}).get('totals'))
 _,rep=call('Payment replay '+method,'payment',p,op=op);check('Payment retry never duplicates cash '+method,abs(float(db('SELECT COALESCE(SUM(amount),0) n FROM transactions')[0]['n'])-before-amount)<0.005)
_,d=call('Read paid detail','detail',query={'id':gid});d=d['data'];check('Group paid and balances reconcile cents',abs(d['totals']['paid']-273456.81)<0.005 and abs(d['totals']['total']-d['totals']['paid']-d['totals']['balance'])<0.005,d['totals'])
before=counts();_,overpayment=call('Overpayment rejected','payment',{'groupId':gid,'amount':1000000,'paymentMethod':'cash'},expected=(409,422));check('Overpayment rejection identifies balance limit','melebihi sisa tagihan grup' in overpayment.get('error',''));check('Overpayment creates no cash',counts()==before)
call('Cannot change billing after booking','edit',{'groupId':gid,'billingMode':'master'},expected=(409,422))
call('Edit primary contact and occupant','edit',{'groupId':gid,'guestName':'Primary Edited','guestPhone':'0800000999','guestEmail':'edited@example.invalid','notes':'Group note','occupants':[{'bookingId':d['bookings'][1]['id'],'guestName':'Occupant Edited'}]})
_,edited=call('Read edited contact','detail',query={'id':gid});check('Contact edits preserve finance and other occupants',edited['data']['totals']==d['totals'] and edited['data']['group']['contact']['phone']=='0800000999' and edited['data']['bookings'][1]['guestName']=='Occupant Edited')
# Cancel only one, preserve refund and route it into folio (same ledger).
bid=d['bookings'][2]['id'];s,b=request('bookings-status&id='+bid,'POST',{'status':'cancelled'},run+'_cancel');check('Cancel one child using existing workflow',s==200 and b.get('success') is True,b.get('message'))
_,cancelled=call('Read cancellation','detail',query={'id':gid});check('One cancelled sibling two reserved',cancelled['data']['lifecycle']['counts']['cancelled']==1 and cancelled['data']['lifecycle']['counts']['reserved']==2)
check('Refund retained source ledger and zero cancelled charge',bool(db("SELECT id FROM transactions WHERE bookingId=? AND transactionKind='refund'",[bid])) and float(db('SELECT COALESCE(SUM(amount),0) n FROM growth_folio_charge_allocations WHERE booking_id=?',[bid])[0]['n'])==0)
# Add one room with different dates; parent bounds contain all children.
add=payload([rooms[3]],groupId=gid);add['rooms'][0]['checkOut']=str(future+datetime.timedelta(days=3));_,added=call('Add one child to existing parent','create',add)
check('Group add preserves previous children and new date bounds',len(added['data']['bookings'])==4 and added['data']['group']['departure_date']==str(future+datetime.timedelta(days=3)))
newbid=next(b['id'] for b in added['data']['bookings'] if b['roomNumber']==rooms[3]);_,detached=call('Detach unpaid individual reservation','detach',{'groupId':gid,'bookingId':newbid});check('Detach preserves standalone booking and ledger',len(detached['data']['bookings'])==3 and db('SELECT status FROM bookings WHERE id=?',[newbid])[0]['status']=='reserved')
# Master/split reuse official Enterprise allocations without journal duplication.
for mode,numbers in [('master',rooms[4:6]),('split',rooms[6:8])]:
 mg,md=must_create('Create '+mode,payload(numbers,mode));folio=md['folios'][0];expected=440000.02 if mode=='master' else 220000.02
 check('Master charges reflect '+mode,abs(float(folio['chargeTotal'])-expected)<=0.011,folio['chargeTotal'])
 _,paid=call('Master/split deposit '+mode,'payment',{'groupId':mg,'amount':100.01,'paymentMethod':'cash','date':str(today)})
 check('Master payment allocated once '+mode,abs(paid['data']['folios'][0]['paymentAllocatedTotal']-(100.01 if mode=='master' else 50.01))<=0.011)
 check('PBJT and journal sources not duplicated '+mode,not db('SELECT transaction_id FROM growth_folio_transaction_allocations GROUP BY transaction_id HAVING SUM(amount)>(SELECT amount FROM transactions WHERE id=transaction_id)+0.009'))
 # Issued snapshot is immutable; cancellation requires audited void first.
 if mode=='master':
  fid=paid['data']['folios'][0]['folio']['id'];s,b=request('enterprise-suite','POST',{'command':'folio-invoice-issue','folioId':fid,'issueDate':str(today)},run+'_invoice');check('Issue master immutable invoice',s==200 and b.get('success') is True,b.get('error'))
  invoice=db('SELECT snapshot_json,source_hash,total_amount FROM growth_folio_invoices WHERE folio_id=?',[fid]);s,b=request('bookings-status&id='+md['bookings'][0]['id'],'POST',{'status':'cancelled'},run+'_issued_cancel');check('Issued invoice blocks cancellation with no cash changes',s>=400 and b.get('success') is False and db('SELECT snapshot_json,source_hash,total_amount FROM growth_folio_invoices WHERE folio_id=?',[fid])==invoice)
# Concurrent overlapping group writes across two real server processes.
p=payload([rooms[8],rooms[9]])
def race(i):return request('multi-room-bookings','POST',{'command':'create',**p},run+'_race_'+str(i),38184+i)
with concurrent.futures.ThreadPoolExecutor(2) as ex:raced=list(ex.map(race,range(2)))
check('Concurrent batch booking has one winner and one rejection',sum(s==200 and b.get('success') is True for s,b in raced)==1 and sum(s>=400 for s,b in raced)==1,[s for s,b in raced])
# Telegram wizard uses existing staff binding, compact tokens and canonical API children.
admin=db('SELECT id,password FROM staff WHERE username=?',[os.getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin')])[0];chat=900001001
db('UPDATE staff SET telegram_chat_id=?,telegram_state=NULL,telegram_context=NULL WHERE id=?',[str(chat),admin['id']])
def tg(name,data,callback=True):
 s,b=request('telegram-callback' if callback else 'telegram-bot','POST',({'callbackData':data,'messageId':run+'_'+name} if callback else {'text':data})|{'chatId':chat,'role':'admin'},run+'_tg_'+name);check('Telegram '+name,s==200 and b.get('success') is True,{'status':s,'text':b.get('message',{}).get('text')});return b
def button(b,prefix,needle=None):
 markup=b.get('message',{}).get('replyMarkup') or b.get('message',{}).get('reply_markup') or {}
 for row in markup.get('inline_keyboard',[]):
  for btn in row:
   raw=btn.get('callback_data','');token=db('SELECT callback_data FROM telegram_callback_tokens WHERE token=?',[raw]) if raw.startswith('tcb_') else []
   cmd=token[0]['callback_data'] if token else raw
   if cmd.startswith(prefix) and (needle is None or needle in cmd):return raw
 raise RuntimeError('Expected Telegram button absent '+prefix+' '+str(needle))
main_menu=tg('discover_main_menu','main_menu');group_entry=button(main_menu,'mr_start:reserve');tg('discover_group_entry',group_entry)
tg('direct_group_command','/reservasi_grup',False);check('Direct group command starts same canonical wizard',db('SELECT telegram_state FROM staff WHERE id=?',[admin['id']])[0]['telegram_state']=='waiting_mr_name')
tg('start','mr_start:check_in_now');tg('primary','TG Multi Primary',False);source_menu=tg('phone','-',False);source_button=button(source_menu,'mr_source:2:');tg('source_choice',source_button);check('Telegram specific OTA button persists channel in wizard',json.loads(db('SELECT telegram_context FROM staff WHERE id=?',[admin['id']])[0]['telegram_context'])['bookingSource']=='Traveloka');tg('stale_source_choice',source_button);check('Old source callback cannot change subsequent wizard state',db('SELECT telegram_state FROM staff WHERE id=?',[admin['id']])[0]['telegram_state']=='waiting_mr_dates');picker=tg('dates',str(today)+' '+str(today+datetime.timedelta(days=1)),False)
for i,n in enumerate(rooms[10:13]):
 for page in range(1,100):
  try:room_button=button(picker,'mr_select:',n);break
  except RuntimeError:picker=tg('pick_page_'+str(i)+'_'+str(page+1),button(picker,'mr_page:'+str(page+1)+':'))
 else:raise RuntimeError('Room unavailable in paginated Telegram picker')
 picker=tg('select'+str(i),room_button)
tg('next',button(picker,'mr_next:'));billing=tg('occupants',rooms[11]+'=TG Second',False);confirm=tg('individual',button(billing,'mr_bill:individual:'));confirm_button=button(confirm,'mr_confirm:');done=tg('confirm',confirm_button)
ctx=json.loads(db('SELECT telegram_context FROM staff WHERE id=?',[admin['id']])[0]['telegram_context']);tggid=ctx['groupId'];_,td=call('Read Telegram created group','detail',query={'id':tggid});td=td['data']
check('Telegram direct group creates three active canonical children',len(td['bookings'])==3 and td['lifecycle']['status']=='checked_in' and td['bookings'][1]['guestName']=='TG Second',td['lifecycle'])
check('Telegram source survives every child and confirmation','Sumber: Traveloka' in confirm.get('message',{}).get('text','') and all(b['bookingSource']=='Traveloka' for b in td['bookings']))
before=counts();tg('replay_confirm',confirm_button);check('Telegram replay cannot duplicate group children/cash',counts()==before)
check('Telegram confirm displays one group summary',td['group']['group_code'] in done.get('message',{}).get('text','') and all(n in done.get('message',{}).get('text','') for n in rooms[10:13]))
# Telegram group payment requires explicit method/account and replays exactly once.
# Indonesian Telegram text uses decimal comma; retain the exact Rp 75,01 ledger assertion.
tg('payment_start','mr_pay:'+tggid);pay_method=tg('payment_amount','75,01',False);pay_account=tg('payment_qris',button(pay_method,'mr_method:qris:'));pay_confirmation=tg('payment_account',button(pay_account,'mr_account:',accounts['qris']));pay_cb=button(pay_confirmation,'mr_pay_confirm:');before_cash=float(db('SELECT COALESCE(SUM(amount),0) n FROM transactions')[0]['n']);tg('payment_confirm',pay_cb);after_cash=float(db('SELECT COALESCE(SUM(amount),0) n FROM transactions')[0]['n']);tg('payment_replay',pay_cb);check('Telegram QRIS payment is exactly 75.01 and replay creates no cash',abs(after_cash-before_cash-75.01)<0.005 and float(db('SELECT COALESCE(SUM(amount),0) n FROM transactions')[0]['n'])==after_cash)
_,td=call('Read Telegram paid group','detail',query={'id':tggid});td=td['data']
# Partial check-in remains child-specific and preserves reserved siblings.
pg,pd=must_create('Today reserved group',payload([rooms[8],rooms[9],rooms[14]],start=today));pdetail=tg('partial_detail','mr_detail:'+pg);tg('partial_checkin',button(pdetail,'mr_checkin:',pd['bookings'][0]['id']));_,pafter=call('Read partial checkin','detail',query={'id':pg});check('Telegram partial check-in activates only one child',pafter['data']['lifecycle']['status']=='partially_checked_in' and pafter['data']['lifecycle']['counts']['active']==1 and pafter['data']['lifecycle']['counts']['reserved']==2)
# Partial checkout and room move stay on individual workflows, all paid once.
_,tdp=call('Pay active group','payment',{'groupId':tggid,'amount':td['totals']['balance'],'paymentMethod':'cash','date':str(today)})
active=tdp['data']['bookings'];first=active[0];s,b=request('bookings-status&id='+first['id'],'POST',{'status':'completed','keyReturned':True,'checkoutNotes':'Multi-room partial checkout','operationId':run+'_partial_checkout'},run+'_partial_checkout');check('Partial checkout canonical workflow',s==200 and b.get('success') is True,b.get('message'))
_,partial=call('Read partial checkout','detail',query={'id':tggid});check('Only selected child completed with two active siblings',partial['data']['lifecycle']['status']=='partially_checked_out' and partial['data']['lifecycle']['counts']['active']==2)
second=active[1];row=db('SELECT version FROM bookings WHERE id=?',[second['id']])[0];s,b=request('room-transfers&id='+second['id'],'POST',{'targetRoomNumber':rooms[13],'rateMode':'keep','surchargeAmount':0,'keyDisposition':'returned','keyReason':'Multi room transfer','expectedVersion':int(row['version'])},run+'_move');check('Move one child preserves membership',s==200 and b.get('success') is True and db('SELECT roomNumber FROM bookings WHERE id=?',[second['id']])[0]['roomNumber']==rooms[13] and bool(db('SELECT id FROM growth_group_booking_links WHERE booking_id=? AND group_id=?',[second['id'],tggid])),b.get('message'))
# Owner authorization via a real session, restore main session regardless of assertion.
owner_id='mr_owner_'+run;username='mr_owner_'+run
db("INSERT INTO staff(id,name,username,password,role,status,salary,password_changed_at,require_password_change) VALUES (?,?,?,?,?,'active',0,CURRENT_TIMESTAMP,0)",[owner_id,'UAT Multi Owner',username,admin['password'],'owner'])
s,owner=request('login','POST',{'username':username,'password':os.getenv('APP_BOOTSTRAP_ADMIN_PASSWORD','Tamasya-UAT-RC1-Only!'),'offlineSessionScopeId':'offline_mr_owner_uat'},run+'_owner_login');check('Owner real authenticated login',s==200 and owner.get('success') is True)
session=(base/'session.json').read_text()
try:
 (base/'session.json').write_text(json.dumps(owner));call('Owner reads all group detail','detail',query={'id':gid});before=counts();call('Owner cannot create','create',payload([rooms[14]]),expected=(403,));call('Owner cannot pay','payment',{'groupId':gid,'amount':1},expected=(403,));call('Owner cannot edit','edit',{'groupId':gid,'guestName':'Denied'},expected=(403,));call('Owner cannot detach','detach',{'groupId':gid,'bookingId':d['bookings'][0]['id']},expected=(403,));check('Owner denied writes preserve DB counts',counts()==before)
finally:(base/'session.json').write_text(session)
check('No orphan child links',not db('SELECT l.id FROM growth_group_booking_links l LEFT JOIN bookings b ON b.id=l.booking_id LEFT JOIN growth_group_reservations g ON g.id=l.group_id WHERE b.id IS NULL OR g.id IS NULL'))
check('Journals remain balanced after all group cash/refund',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
# Lossless CLI dry-run/apply on private disposable receipt fixture.
body=json.dumps({'success':True,'text':'multi room immutable replay '*10000});rid=run+'_storage';db("INSERT INTO request_operation_receipts(operation_id,staff_id,device_id,action,http_method,payload_hash,status,http_status,response_body,completed_at) VALUES (?,?,?,'multi-room-bookings','POST',?,'completed',200,?,CURRENT_TIMESTAMP)",[rid,admin['id'],'uat-mr-storage','a'*64,body])
before_row=db('SELECT * FROM request_operation_receipts WHERE operation_id=?',[rid])[0]
for apply in [False,True]:
 cmd=['php',str(base.parents[1]/'receipt_storage_maintenance.php'),'--limit=1000']+(['--apply','--backup-confirmed=DISPOSABLE-GITHUB-UAT'] if apply else [])
 p=subprocess.run(cmd,capture_output=True,text=True);check('Storage '+('apply' if apply else 'dry-run')+' succeeds',p.returncode==0,{'stdout':p.stdout,'stderr':p.stderr});after=db('SELECT * FROM request_operation_receipts WHERE operation_id=?',[rid])[0]
 if not apply:check('Storage dry-run mutates nothing',after==before_row)
 else:
  decoded=__import__('gzip').decompress(__import__('base64').b64decode(after['response_body'].split(':',2)[2])).decode();check('Compaction keeps exact replay and all semantic metadata',decoded==body and all(after[k]==before_row[k] for k in before_row if k!='response_body'))
# Storage root fix: real API receipts keep operation results, re-read only UI data.
import base64,gzip,hashlib
def decoded_receipt(row):
 body=row['response_body']
 return gzip.decompress(base64.b64decode(body.split(':',2)[2])).decode() if body.startswith('@tamasya:gzip-base64:') else body
def receipt_meta(row):return {key:value for key,value in row.items() if key!='response_body'}
readop=run+'_receipt_read';s,first_read=request('notifications-read','POST',{},readop)
check('Notification read first response retains normal UI contract',s==200 and first_read.get('success') is True and isinstance(first_read.get('db'),dict))
readrow=db('SELECT * FROM request_operation_receipts WHERE operation_id=?',[readop])[0]
readbody=decoded_receipt(readrow)
check('Notification receipt omits full hotel projection in DB',readbody.startswith('@tamasya:projection-v1:') and len(readbody)<2000 and 'notifications' not in readbody)
notice=run+'_receipt_new_notice';db("INSERT INTO notifications(id,message,timestamp,type) VALUES (?,?,CURRENT_TIMESTAMP,'system')",[notice,'Fresh after committed read'])
money_before=int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])
s,replayed_read=request('notifications-read','POST',{},readop)
check('Notification replay reads current projection without repeating read mutation',s==200 and replayed_read.get('success') is True and replayed_read.get('receiptProjection')=='current-role-scoped' and any(n['id']==notice for n in replayed_read.get('db',{}).get('notifications',[])) and not db('SELECT notification_id FROM notification_reads WHERE notification_id=?',[notice]))
check('Projection replay preserves receipt binding status timestamps and cash',db('SELECT * FROM request_operation_receipts WHERE operation_id=?',[readop])==[readrow] and int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])==money_before)
s,changed_read=request('notifications-read','POST',{'id':notice},readop)
check('Compact receipt still rejects changed payload',s==409 and changed_read.get('success') is False and not db('SELECT notification_id FROM notification_reads WHERE notification_id=?',[notice]))
state=json.loads((base/'state.json').read_text());txop=run+'_receipt_tx'
txpayload={'type':'income','categoryId':state['room_rental'],'amount':75.01,'date':'2026-07-15','description':'Receipt projection root fix UAT','recordOrigin':'historical_import','shiftExemptionReason':'Isolated replay test historical evidence','historicalSourceType':'receipt','historicalSourceReference':txop,'bookingSource':'Direct','historicalTaxMode':'rule_by_date','operationId':txop}
s,first_tx=request('transactions','POST',txpayload,txop)
check('Transaction first response retains canonical document and UI data',s==200 and first_tx.get('success') is True and bool(first_tx.get('transactionId')) and isinstance(first_tx.get('db'),dict))
txrow=db('SELECT * FROM request_operation_receipts WHERE operation_id=?',[txop])[0]
check('Transaction receipt stores compact result rather than hotel snapshot',decoded_receipt(txrow).startswith('@tamasya:projection-v1:') and '"db"' not in decoded_receipt(txrow))
txid=first_tx.get('transactionId');posted=db('SELECT * FROM transactions WHERE id=?',[txid]);journals=db('SELECT * FROM journal_entries WHERE transaction_id=?',[txid])
s,replayed_tx=request('transactions','POST',txpayload,txop)
check('Transaction replay returns original ID document warnings without double money',s==200 and replayed_tx.get('transactionId')==txid and all(replayed_tx.get(k)==v for k,v in first_tx.items() if k!='db') and db('SELECT * FROM transactions WHERE id=?',[txid])==posted and db('SELECT * FROM journal_entries WHERE transaction_id=?',[txid])==journals and len(posted)==1)
# Old stored rows can be upgraded explicitly; only transient UI cache changes.
oldrow=run+'_receipt_legacy_ui';oldbody=json.dumps(first_read,separators=(',',':'),ensure_ascii=False)
db("INSERT INTO request_operation_receipts(operation_id,staff_id,device_id,action,http_method,payload_hash,status,http_status,response_body,completed_at) VALUES (?,?,?,'notifications-read','POST',?,'completed',200,?,CURRENT_TIMESTAMP)",[oldrow,admin['id'],'github-uat-rc1',hashlib.sha256(oldrow.encode()).hexdigest(),oldbody])
legacy_before=db('SELECT * FROM request_operation_receipts WHERE operation_id=?',[oldrow])[0]
code='define("TAMASYA_RECEIPT_COMPACTION_LIBRARY",true);require "receipt_storage_maintenance.php";require "database_bootstrap.php";$c=tamasyaResolveDatabaseConfig(__DIR__);[$p,$e,$s]=tamasyaConnectDatabase($c);$p->beginTransaction();$r=tamasyaCompactReceiptStorage($p,true,1000,true);$p->commit();echo json_encode($r);'
compact=subprocess.run(['php','-r',code],cwd=base.parent.parent,capture_output=True,text=True);check('Explicit logical compaction applies only on disposable database',compact.returncode==0,compact.stdout or compact.stderr)
legacy_after=db('SELECT * FROM request_operation_receipts WHERE operation_id=?',[oldrow])[0]
check('Logical compaction preserves receipt identity status and timestamps',receipt_meta(legacy_before)==receipt_meta(legacy_after) and decoded_receipt(legacy_after).startswith('@tamasya:projection-v1:'))

print('Multi-room real assertions',sum(r['pass'] for r in results),'/',len(results))
if any(not r['pass'] for r in results):raise SystemExit(1)
