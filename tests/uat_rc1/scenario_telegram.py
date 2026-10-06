from client import *
from scenario_core import db
import datetime, json, re

results=[]
CHAT_ADMIN=900001001
CHAT_LIMITED=900001002

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:800],flush=True)
    (base/'logs/telegram-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')

def sim_text(name,text,chat=CHAT_ADMIN,expected=200):
    status,body=request('telegram-bot','POST',{'text':text,'chatId':chat,'role':'admin'},'sim_tg_'+name)
    check(name,status==expected and (body.get('success') is not False if status<300 else body.get('success') is False),{'status':status,'text':body.get('message',{}).get('text'),'identity':body.get('simulationIdentity')})
    return status,body

def sim_cb(name,data,chat=CHAT_ADMIN,message='uat-msg',expected=200):
    status,body=request('telegram-callback','POST',{'callbackData':data,'chatId':chat,'messageId':message,'role':'admin'},'sim_tg_'+name)
    check(name,status==expected and (body.get('success') is not False if status<300 else body.get('success') is False),{'status':status,'text':body.get('message',{}).get('text'),'identity':body.get('simulationIdentity')})
    return status,body

# Bind the bootstrap admin to a synthetic Telegram user. No external Telegram call is made.
admin=db("SELECT id,password,role FROM staff WHERE username=? LIMIT 1",[os.getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin')])[0]
db('UPDATE staff SET telegram_chat_id=?,telegram_state=NULL,telegram_context=NULL WHERE id=?',[str(CHAT_ADMIN),admin['id']])

# Unknown identity may not become admin by forging a browser role field.
status,unknown=request('telegram-bot','POST',{'text':'/menu','chatId':999001999,'role':'admin'},'sim_tg_unknown')
check('Telegram forged role cannot bind an unknown identity',status==200 and unknown.get('success') is True and unknown.get('simulationIdentity',{}).get('bound') is False,unknown.get('simulationIdentity'))

for cmd in ['/start','/menu','/status_kamar','/laporan','/help']:
    _,body=sim_text('command_'+cmd.strip('/').replace('/','_'),cmd)
    check(cmd+' resolves bound admin server-side',body.get('simulationIdentity',{}).get('bound') is True and body.get('simulationIdentity',{}).get('role')=='admin' and bool(body.get('message',{}).get('text')),body.get('simulationIdentity'))

for callback in ['main_menu','account_menu','account_profile','guest_ops_menu','field_ops_menu','cash_shift_menu','housekeeping_menu','booking_checkout_menu','patrol_reports_menu','consistency_guard']:
    _,body=sim_cb('menu_'+callback,callback,message='uat-menu-'+callback)
    check(callback+' renders a non-empty callback response',bool(body.get('message',{}).get('text')),body.get('message',{}).get('text'))

# Role/capability boundary: create a synthetic cleaning-service identity with the same fixture hash.
limited_id='uat_tg_cleaning'
db("DELETE FROM staff WHERE id=? OR username=?",[limited_id,limited_id])
db("INSERT INTO staff(id,name,username,password,role,status,telegram_chat_id) SELECT ?,?,?,?,?,?,? FROM staff WHERE id=?",[limited_id,'UAT Telegram Cleaning',limited_id,admin['password'],'cleaning_service','active',str(CHAT_LIMITED),admin['id']])
before_tx=int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])
_,denied=sim_cb('limited_finance_denied','pemasukan_menu',chat=CHAT_LIMITED,message='uat-limited-finance')
check('Telegram limited role cannot enter finance callback',('AKSES DITOLAK' in (denied.get('message',{}).get('text') or '')) or denied.get('status')=='callback_denied',denied)
check('Denied Telegram finance callback cannot mutate money',int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])==before_tx)

# Public website support -> Telegram one-tap reply state. The binding is server-side
# and one-shot so a later normal Telegram message cannot leak into the prior thread.
support_id='uat_support_direct_reply'
support_code='SUP-UAT-DIRECTREPLY01'
db('DELETE FROM public_support_messages WHERE conversation_id=?',[support_id])
db('DELETE FROM public_support_conversations WHERE id=? OR public_code=?',[support_id,support_code])
db("INSERT INTO public_support_conversations(id,public_code,visitor_token_hash,status,source,last_message_at,created_at,updated_at) VALUES (?,?,?,'open','website',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",[support_id,support_code,'0'*64])
db("INSERT INTO public_support_messages(id,conversation_id,sender,channel,message,created_at) VALUES (?,?, 'guest','website',?,CURRENT_TIMESTAMP)",['uat_support_guest_1',support_id,'Tolong jawab langsung dari Telegram'])

_,reply_mode=sim_cb('support_reply_mode','support_reply:'+support_code,message='uat-support-reply-mode')
ctx_rows=db('SELECT telegram_state,telegram_context FROM staff WHERE id=?',[admin['id']])
ctx=json.loads(ctx_rows[0]['telegram_context'] or '{}') if ctx_rows else {}
check('Telegram support Reply button enters exact one-shot state',bool(ctx_rows) and ctx_rows[0]['telegram_state']=='waiting_for_support_reply' and ctx.get('publicCode')==support_code and int(ctx.get('expiresAt') or 0)>0,{'row':ctx_rows,'ctx':ctx,'message':reply_mode.get('message',{}).get('text')})

direct_reply_text='Jawaban langsung UAT untuk tamu website'
before_support=int(db("SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=? AND sender='staff'",[support_id])[0]['n'])
_,direct_reply=sim_text('support_direct_reply',direct_reply_text)
after_support=int(db("SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=? AND sender='staff'",[support_id])[0]['n'])
rows=db("SELECT sender,channel,message,staff_id,telegram_user_id FROM public_support_messages WHERE conversation_id=? AND sender='staff' AND channel='telegram' AND message=? ORDER BY created_at DESC,id DESC",[support_id,direct_reply_text])
check('Next normal Telegram text lands in the exact website conversation',after_support==before_support+1 and len(rows)==1 and rows[0]['staff_id']==admin['id'] and str(rows[0]['telegram_user_id'] or '')==str(CHAT_ADMIN),{'beforeStaff':before_support,'afterStaff':after_support,'matchingRows':rows})
state_after=db('SELECT telegram_state,telegram_context FROM staff WHERE id=?',[admin['id']])[0]
check('Successful direct reply clears reply state one-shot',not state_after['telegram_state'] and not state_after['telegram_context'],state_after)

# A later plain Telegram message must never be routed to the old support thread.
count_after=int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])
sim_text('support_plain_after_clear','Pesan biasa setelah reply selesai')
check('Plain Telegram text after state clear cannot leak into old website thread',int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])==count_after)

# Cancel must clear state without adding a support message.
sim_cb('support_reply_mode_cancel_setup','support_reply:'+support_code,message='uat-support-cancel-setup')
count_before_cancel=int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])
_,cancelled=sim_cb('support_reply_cancel','support_reply_cancel',message='uat-support-cancel')
cancel_state=db('SELECT telegram_state,telegram_context FROM staff WHERE id=?',[admin['id']])[0]
check('Telegram support reply Cancel clears state with no delivery',not cancel_state['telegram_state'] and not cancel_state['telegram_context'] and int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])==count_before_cancel,cancelled.get('message',{}).get('text'))

# Expired state must fail closed and clear itself without delivery.
db("UPDATE staff SET telegram_state='waiting_for_support_reply',telegram_context=? WHERE id=?",[json.dumps({'publicCode':support_code,'expiresAt':1,'startedAt':1}),admin['id']])
count_before_expiry=int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])
_,expired=sim_text('support_reply_expired','Pesan yang tidak boleh terkirim')
expired_state=db('SELECT telegram_state,telegram_context FROM staff WHERE id=?',[admin['id']])[0]
check('Expired Telegram support state fails closed and clears without delivery',not expired_state['telegram_state'] and int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])==count_before_expiry,expired.get('message',{}).get('text'))

# Oversized reply must not be delivered and state must remain cancellable.
sim_cb('support_reply_long_setup','support_reply:'+support_code,message='uat-support-long-setup')
count_before_long=int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])
_,too_long=sim_text('support_reply_too_long','X'*2001)
long_state=db('SELECT telegram_state FROM staff WHERE id=?',[admin['id']])[0]
check('Oversized Telegram support reply is rejected without delivery',int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])==count_before_long and long_state['telegram_state']=='waiting_for_support_reply','state='+str(long_state)+' message='+str(too_long.get('message',{}).get('text')))
sim_cb('support_reply_long_cancel','support_reply_cancel',message='uat-support-long-cancel')

# Limited role may not enter support-reply callback.
_,limited_support=sim_cb('limited_support_reply_denied','support_reply:'+support_code,chat=CHAT_LIMITED,message='uat-limited-support')
check('Telegram limited role cannot arm website support reply state',('AKSES DITOLAK' in (limited_support.get('message',{}).get('text') or '')) or limited_support.get('status')=='callback_denied',limited_support)
check('Denied support callback does not set limited staff reply state',not (db('SELECT telegram_state FROM staff WHERE id=?',[limited_id])[0].get('telegram_state')))

# Legacy /balas remains compatible during migration to the one-tap UX.
legacy_before=int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])
_,legacy=sim_text('support_legacy_reply','/balas '+support_code+' Balasan fallback lama tetap berfungsi')
legacy_rows=db("SELECT channel,message FROM public_support_messages WHERE conversation_id=? ORDER BY created_at DESC,id DESC LIMIT 1",[support_id])
check('Legacy /balas remains backward-compatible',int(db('SELECT COUNT(*) n FROM public_support_messages WHERE conversation_id=?',[support_id])[0]['n'])==legacy_before+1 and bool(legacy_rows) and legacy_rows[0]['channel']=='telegram',legacy_rows)

# Dedicated Telegram checkout + close-shift use one real SIANG cash shift. This
# preserves the production rule that check-in/POS/money mutations require an active
# shift and proves that the same shift can later be reconciled and closed from Telegram.
status,opened=request('operations-center','POST',{
    'command':'shift-open','openingCash':100000,'shiftTime':'siang',
    'notes':'Dedicated Telegram checkout and close-shift UAT'
},'sim_tg_open_shift')
check('Telegram fixture opens a dedicated server shift before check-in',status==200 and opened.get('success') is True,opened)
open_rows=db("SELECT id,shift_time,status FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC,id DESC")
check('Telegram fixture has exactly one open SIANG shift',len(open_rows)==1 and open_rows[0]['shift_time']=='siang',open_rows)
shift=open_rows[0] if len(open_rows)==1 else None

# Dedicated Telegram checkout on a fresh active booking, validating the same path used from phones.
today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()
status,created=request('bookings','POST',{
    'guestName':'SIM Telegram Checkout Guest','roomNumber':'110','checkIn':str(today),
    'checkOut':str(today+datetime.timedelta(days=1)),'totalAmount':220000,
    'paymentStatus':'unpaid','bookingSource':'Direct','lifecycleIntent':'check_in_now','broadcast':False
},'sim_tg_checkout_booking')
check('Telegram checkout fixture booking created active',status==200 and created.get('success') is True and bool(created.get('bookingId')),created)
booking_id=created.get('bookingId')
if booking_id:
    row=db('SELECT status,totalAmount,amountPaid,balanceDue FROM bookings WHERE id=?',[booking_id])[0]
    check('Telegram checkout fixture starts unpaid',row['status']=='active' and float(row['balanceDue'])==220000,row)
    _,start=sim_cb('checkout_start','r_checkout:110',message='uat-checkout-start')
    text=start.get('message',{}).get('text','')
    check('Telegram checkout asks for settlement from server ledger','Pilih metode pelunasan' in text and '220.000' in text,text)
    before=int(db('SELECT COUNT(*) n FROM transactions WHERE bookingId=?',[booking_id])[0]['n'])
    _,paid=sim_cb('checkout_cash','r_checkout_pay:110:cash:-',message='uat-checkout-pay')
    text=paid.get('message',{}).get('text','')
    check('Telegram cash checkout reports successful balanced close','CHECK-OUT BERHASIL' in text or 'PELUNASAN DAN CHECK-OUT BERHASIL' in text,text)
    row=db('SELECT status,amountPaid,balanceDue FROM bookings WHERE id=?',[booking_id])[0]
    check('Telegram checkout persists completed booking with zero balance',row['status']=='completed' and float(row['balanceDue'])==0 and float(row['amountPaid'])==220000,row)
    check('Telegram checkout creates exactly one settlement transaction',int(db('SELECT COUNT(*) n FROM transactions WHERE bookingId=?',[booking_id])[0]['n'])==before+1)
    if shift:
        payment_rows=db('SELECT shiftSessionId FROM transactions WHERE bookingId=? ORDER BY createdAt DESC',[booking_id])
        check('Telegram checkout cash settlement is attached to the dedicated open shift',bool(payment_rows) and any(str(x.get('shiftSessionId') or '')==str(shift['id']) for x in payment_rows),payment_rows)
    check('Telegram checkout sends room into housekeeping, not sellable',db("SELECT status FROM rooms WHERE number='110'")[0]['status']!='available' and bool(db("SELECT id FROM housekeeping_tasks WHERE room_number='110' AND status NOT IN ('ready','completed')")))
    tx_count=int(db('SELECT COUNT(*) n FROM transactions WHERE bookingId=?',[booking_id])[0]['n'])
    # Same synthetic button update must never duplicate payment even when user taps again.
    _,again=sim_cb('checkout_cash_replay','r_checkout_pay:110:cash:-',message='uat-checkout-pay')
    check('Telegram checkout replay cannot duplicate money',int(db('SELECT COUNT(*) n FROM transactions WHERE bookingId=?',[booking_id])[0]['n'])==tx_count,again.get('message',{}).get('text'))

# Close exactly the same shift that carried the Telegram checkout payment.
if shift:
    sim_cb('close_shift_menu','tutup_shift_menu',message='uat-close-menu')
    sim_cb('close_shift_select','tutup_shift_select:'+shift['id'],message='uat-close-select')
    _,reconcile=sim_cb('close_shift_reconcile','tutup_shift_time:'+shift['shift_time'],message='uat-close-reconcile')
    reply=reconcile.get('message',{}).get('text','')
    match=re.search(r'Tunai seharusnya:\s*\*Rp\s*([0-9.]+)',reply)
    check('Telegram close-shift exposes computed expected cash',bool(match),reply)
    if match:
        expected_cash=int(re.sub(r'\D','',match.group(1)) or '0')
        _,cash=sim_text('close_shift_actual_cash',str(expected_cash))
        check('Telegram close-shift accepts matching physical cash','REKONSILIASI KAS SEMENTARA' in (cash.get('message',{}).get('text') or ''),cash.get('message',{}).get('text'))
        _,done=sim_cb('close_shift_done','tutup_shift_done:no_notes',message='uat-close-done')
        check('Telegram close-shift confirms persisted closure','LAPORAN SHIFT TERSIMPAN' in (done.get('message',{}).get('text') or ''),done.get('message',{}).get('text'))
        check('Telegram close-shift persists CLOSED server session',db('SELECT status FROM shift_sessions WHERE id=?',[shift['id']])[0]['status']=='closed')
        closed_count=int(db("SELECT COUNT(*) n FROM shift_sessions WHERE id=? AND status='closed'",[shift['id']])[0]['n'])
        sim_cb('close_shift_replay','tutup_shift_done:no_notes',message='uat-close-done')
        check('Telegram close-shift replay cannot create a second session',int(db("SELECT COUNT(*) n FROM shift_sessions WHERE id=? AND status='closed'",[shift['id']])[0]['n'])==closed_count)

check('Telegram UAT leaves journals balanced',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
print('TELEGRAM-UAT',sum(x['pass'] for x in results),'/',len(results))
