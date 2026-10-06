from client import *
from scenario_core import db
import datetime, json, urllib.parse, uuid

results=[]
run='efc_tg_'+uuid.uuid4().hex[:10]
CHAT_ADMIN=900001001

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:900],flush=True)
    (base/'logs/enterprise-full-telegram-parity-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def sim_cb(name,data,expected=200,message_id=None):
    mid=message_id or ('efc-'+name+'-'+run)
    s,b=request('telegram-callback','POST',{'callbackData':data,'chatId':CHAT_ADMIN,'messageId':mid,'role':'admin'},'efc_tg_cb_'+name+'_'+run)
    check(name,s==expected and isinstance(b,dict) and (b.get('success') is not False if expected<300 else b.get('success') is False),{'status':s,'text':(b.get('message') or {}).get('text'),'body':b})
    return s,b

def sim_text(name,text,expected=200):
    s,b=request('telegram-bot','POST',{'text':text,'chatId':CHAT_ADMIN,'role':'admin'},'efc_tg_text_'+name+'_'+run)
    check(name,s==expected and isinstance(b,dict) and (b.get('success') is not False if expected<300 else b.get('success') is False),{'status':s,'text':(b.get('message') or {}).get('text'),'body':b})
    return s,b

# Re-bind the canonical admin to the deterministic simulated Telegram identity.
admin=db("SELECT id FROM staff WHERE username=? LIMIT 1",[__import__('os').getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin')])[0]
db('UPDATE staff SET telegram_chat_id=?,telegram_state=NULL,telegram_context=NULL WHERE id=?',[str(CHAT_ADMIN),admin['id']])

# Create one real active, unpaid booking through the web/API path. Telegram must mutate the same canonical row.
ping_status,ping=request('ping','GET')
ping_date=str((ping or {}).get('timestamp') or '')[:10] if isinstance(ping,dict) else ''
try: today=datetime.date.fromisoformat(ping_date)
except Exception: today=None
check('Telegram parity derives booking date from property/server clock',ping_status==200 and isinstance(today,datetime.date),{'status':ping_status,'timestamp':(ping or {}).get('timestamp') if isinstance(ping,dict) else None})
if today is None: raise RuntimeError('Property/server business date unavailable for Telegram parity')
tomorrow=today+datetime.timedelta(days=1)

# The preceding canonical Telegram suite deliberately closes its own cash shift.
# This parity suite establishes a fresh real server shift before immediate check-in;
# the production booking shift guard remains mandatory and is never bypassed.
parity_shifts=db("SELECT id,shift_time,status FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC,id DESC")
if not parity_shifts:
    shift_status,shift_body=request('operations-center','POST',{
        'command':'shift-open','openingCash':100000,'shiftTime':'siang',
        'notes':'Enterprise Full Complete Telegram parity dedicated shift'
    },'efc_tg_parity_shift_'+run)
    check('Telegram parity opens a dedicated server shift before immediate check-in',shift_status==200 and isinstance(shift_body,dict) and shift_body.get('success') is True,{'status':shift_status,'body':shift_body})
parity_shifts=db("SELECT id,shift_time,status FROM shift_sessions WHERE status='open' ORDER BY opened_at DESC,id DESC")
check('Telegram parity has exactly one active server shift',len(parity_shifts)==1,parity_shifts)

rooms=db("SELECT r.number,r.type FROM rooms r WHERE r.status='available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active') AND b.checkOut>? AND b.checkIn<?) ORDER BY CAST(r.number AS UNSIGNED),r.number LIMIT 2",[today.isoformat(),(today+datetime.timedelta(days=14)).isoformat()])
check('Telegram parity has source and target rooms available through canonical inventory',len(rooms)>=2,rooms)
if len(rooms)>=2:
    source,target=rooms[0],rooms[1]
    op='efc_tg_booking_'+run
    s,b=request('bookings','POST',{'guestName':'EFC Telegram Parity Guest','roomNumber':source['number'],'checkIn':today.isoformat(),'checkOut':tomorrow.isoformat(),'totalAmount':300000,'paymentStatus':'unpaid','bookingSource':'Direct','lifecycleIntent':'check_in_now','broadcast':False},op)
    check('Web/API creates active booking that Telegram will operate',s==200 and b.get('success') is True and bool(b.get('bookingId')),{'status':s,'body':b})
    booking_id=b.get('bookingId') if isinstance(b,dict) else None
    if booking_id:
        before=db('SELECT checkOut,totalAmount,balanceDue,roomNumber FROM bookings WHERE id=?',[booking_id])[0]
        # Extension from Telegram callback uses canonical booking charge workflow.
        extend_mid='efc-extend-'+run
        sim_cb('telegram_extend_confirm',f"r_extend_confirm:{source['number']}:1:unpaid",message_id=extend_mid)
        extended=db('SELECT checkOut,totalAmount,balanceDue,roomNumber,extras FROM bookings WHERE id=?',[booking_id])[0]
        ext_extras=[x for x in (json.loads(extended.get('extras') or '[]') or []) if str(x.get('id','')).startswith('ex_ext_tg_')]
        check('Telegram unpaid extension updates same booking checkout and folio without fabricating cash receipt',extended['checkOut']>before['checkOut'] and float(extended['totalAmount'])>float(before['totalAmount']) and float(extended['balanceDue'])>float(before['balanceDue']) and len(ext_extras)==1 and not db("SELECT id FROM transactions WHERE bookingId=? AND operationId LIKE 'tg_%' AND description LIKE 'Perpanjangan Kamar%'",[booking_id]),{'before':before,'after':extended,'extras':ext_extras})
        sim_cb('telegram_extend_replay',f"r_extend_confirm:{source['number']}:1:unpaid",message_id=extend_mid)
        extended_retry=db('SELECT checkOut,totalAmount,extras FROM bookings WHERE id=?',[booking_id])[0]
        ext_retry=[x for x in (json.loads(extended_retry.get('extras') or '[]') or []) if str(x.get('id','')).startswith('ex_ext_tg_')]
        check('Telegram extension callback replay cannot duplicate folio charge',extended_retry['checkOut']==extended['checkOut'] and float(extended_retry['totalAmount'])==float(extended['totalAmount']) and len(ext_retry)==1)

        # Extra service direct confirm exercises the same canonical charge path.
        svc_name='EFC Laundry'
        svc_token=urllib.parse.quote(svc_name,safe='')
        pre_service=db('SELECT totalAmount,balanceDue FROM bookings WHERE id=?',[booking_id])[0]
        service_mid='efc-service-'+run
        sim_cb('telegram_service_confirm',f"r_layanan_confirm:{source['number']}:{svc_token}:50000:1:unpaid",message_id=service_mid)
        post_service=db('SELECT totalAmount,balanceDue,extras FROM bookings WHERE id=?',[booking_id])[0]
        svc_extras=[x for x in (json.loads(post_service.get('extras') or '[]') or []) if x.get('name')==svc_name]
        check('Telegram unpaid extra service increases canonical web folio without fabricating payment transaction',float(post_service['totalAmount'])>float(pre_service['totalAmount']) and float(post_service['balanceDue'])>float(pre_service['balanceDue']) and len(svc_extras)==1,{'before':pre_service,'after':post_service,'extras':svc_extras})
        sim_cb('telegram_service_replay',f"r_layanan_confirm:{source['number']}:{svc_token}:50000:1:unpaid",message_id=service_mid)
        service_retry=db('SELECT totalAmount,extras FROM bookings WHERE id=?',[booking_id])[0]
        retry_extras=[x for x in (json.loads(service_retry.get('extras') or '[]') or []) if x.get('name')==svc_name]
        check('Telegram extra service callback replay cannot duplicate folio charge',float(service_retry['totalAmount'])==float(post_service['totalAmount']) and len(retry_extras)==1)

        # Paid extension now starts a draft, then chooses an explicit physical payment leg.
        bank_id='uat_tg_transfer_'+run
        qris_id='uat_tg_qris_'+run
        for account_id,kind in [(bank_id,'bank'),(qris_id,'edc_qris')]:
            db("INSERT INTO bank_accounts(id,name,type,accountNumber,isActive) VALUES (?,?,?,?,1)",[account_id,'TG Payment '+kind,kind,'UAT'])
        def choose_callback(body,prefix,needle=None):
            buttons=[button for row in (body.get('message',{}).get('replyMarkup') or body.get('message',{}).get('reply_markup') or {}).get('inline_keyboard',[]) for button in row]
            for button in buttons:
                raw=str(button.get('callback_data') or '')
                if raw.startswith('tcb_'):
                    saved=db('SELECT callback_data FROM telegram_callback_tokens WHERE token=?',[raw])
                    resolved=saved[0]['callback_data'] if saved else ''
                else: resolved=raw
                if resolved.startswith(prefix) and (needle is None or resolved.endswith(needle)): return raw
            raise RuntimeError('Required Telegram payment button missing: '+prefix+' '+str(needle))
        for method,account_id in [('cash',None),('transfer',bank_id),('qris',qris_id)]:
            old=db('SELECT checkOut,totalAmount FROM bookings WHERE id=?',[booking_id])[0]
            receipts_before=int(db('SELECT COUNT(*) n FROM transactions WHERE bookingId=?',[booking_id])[0]['n'])
            _,choice=sim_cb('paid_extension_select_'+method,f"r_extend_confirm:{source['number']}:1:paid",message_id='paid-start-'+method+'-'+run)
            draft=json.loads(db('SELECT telegram_context FROM staff WHERE id=?',[admin['id']])[0]['telegram_context'] or '{}')
            check('Selecting paid does not extend or post cash before choosing '+method,db('SELECT checkOut,totalAmount FROM bookings WHERE id=?',[booking_id])[0]==old and int(db('SELECT COUNT(*) n FROM transactions WHERE bookingId=?',[booking_id])[0]['n'])==receipts_before)
            _,selected=sim_cb('paid_extension_method_'+method,choose_callback(choice,'r_charge_method:',':'+method))
            if account_id:
                _,selected=sim_cb('paid_extension_account_'+method,choose_callback(selected,'r_charge_account:',':'+account_id))
            save=choose_callback(selected,'r_charge_save:')
            sim_cb('paid_extension_save_'+method,save,message_id='paid-save-'+method+'-'+run)
            after=db('SELECT checkOut,totalAmount,balanceDue FROM bookings WHERE id=?',[booking_id])[0]
            receipts=db("SELECT id,amount,bankAccountId,shiftSessionId,baseAmount,taxAmount FROM transactions WHERE bookingId=? AND operationId=?",[booking_id,'tg_charge_'+__import__('hashlib').sha256((admin['id']+'|'+draft['nonce']).encode()).hexdigest()])
            extras=json.loads(db('SELECT extras FROM bookings WHERE id=?',[booking_id])[0]['extras'] or '[]')
            component=next((x for x in extras if x.get('paymentOperationId')=='tg_charge_'+__import__('hashlib').sha256((admin['id']+'|'+draft['nonce']).encode()).hexdigest()),None)
            check('Telegram paid extension persists '+method+' in receipt, folio and shift',after['checkOut']>old['checkOut'] and len(receipts)==1 and (receipts[0].get('bankAccountId') or None)==account_id and bool(receipts[0].get('shiftSessionId')) and component and component.get('paymentMethod')==method and abs(float(receipts[0]['amount'])-float(receipts[0]['baseAmount'])-float(receipts[0]['taxAmount']))<0.01,{'after':after,'receipts':receipts,'component':component})
            sim_cb('paid_extension_replay_'+method,save,message_id='paid-save-retry-'+method+'-'+run)
            check('Replay cannot duplicate paid extension '+method,db('SELECT checkOut,totalAmount,balanceDue FROM bookings WHERE id=?',[booking_id])[0]==after and int(db('SELECT COUNT(*) n FROM transactions WHERE bookingId=?',[booking_id])[0]['n'])==receipts_before+1)

        # The same explicit payment flow must serve extra services, not just extensions.
        paid_service='UAT Paid QRIS Service'
        _,service_choice=sim_cb('paid_service_select',f"r_layanan_confirm:{source['number']}:{urllib.parse.quote(paid_service,safe='')}:25000:1:paid")
        service_draft=json.loads(db('SELECT telegram_context FROM staff WHERE id=?',[admin['id']])[0]['telegram_context'] or '{}')
        _,service_accounts=sim_cb('paid_service_qris',choose_callback(service_choice,'r_charge_method:',':qris'))
        _,service_confirm=sim_cb('paid_service_account',choose_callback(service_accounts,'r_charge_account:',':'+qris_id))
        sim_cb('paid_service_save',choose_callback(service_confirm,'r_charge_save:'))
        service_op='tg_charge_'+__import__('hashlib').sha256((admin['id']+'|'+service_draft['nonce']).encode()).hexdigest()
        service_receipt=db('SELECT bankAccountId,shiftSessionId FROM transactions WHERE bookingId=? AND operationId=?',[booking_id,service_op])
        service_components=json.loads(db('SELECT extras FROM bookings WHERE id=?',[booking_id])[0]['extras'] or '[]')
        check('Paid extra service records QRIS receipt and exact paid folio component',len(service_receipt)==1 and service_receipt[0]['bankAccountId']==qris_id and bool(service_receipt[0]['shiftSessionId']) and any(x.get('name')==paid_service and x.get('paymentMethod')=='qris' and x.get('paymentOperationId')==service_op for x in service_components))

        # Transfer through Telegram. If physical key confirmation is required, follow the explicit returned-key path.
        sim_cb('telegram_transfer_target',f"r_transfer_target:{source['number']}:{target['number']}")
        sim_cb('telegram_transfer_confirm',f"r_transfer_confirm:{source['number']}:{target['number']}:0:folio")
        staff_state=db('SELECT telegram_state,telegram_context FROM staff WHERE id=?',[admin['id']])[0]
        if staff_state.get('telegram_state')=='waiting_for_transfer_key':
            sim_cb('telegram_transfer_key_returned','r_transfer_key:returned')
        moved=db('SELECT roomNumber,status FROM bookings WHERE id=?',[booking_id])[0]
        source_room=db('SELECT status FROM rooms WHERE number=?',[source['number']])[0]
        target_room=db('SELECT status FROM rooms WHERE number=?',[target['number']])[0]
        hk=db("SELECT id,status FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed') ORDER BY created_at DESC,id DESC LIMIT 1",[source['number']])
        check('Telegram room transfer updates canonical booking, target occupancy and source housekeeping',moved['roomNumber']==target['number'] and moved['status']=='active' and target_room['status']!='available' and bool(hk),{'booking':moved,'source':source_room,'target':target_room,'housekeeping':hk})

        # Old room is now a real housekeeping task. Process it from Telegram and verify web-visible DB state.
        sim_cb('telegram_housekeeping_start',f"hk_set:{source['number']}:cleaning")
        cleaning=db("SELECT id,status FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed') ORDER BY created_at DESC,id DESC LIMIT 1",[source['number']])
        check('Telegram housekeeping start persists active cleaning task',bool(cleaning) and cleaning[0]['status']=='cleaning',cleaning)
        sim_cb('telegram_housekeeping_finish',f"hk_set:{source['number']}:clean_available")
        room_final=db('SELECT status FROM rooms WHERE number=?',[source['number']])[0]
        task_final=db("SELECT status FROM housekeeping_tasks WHERE room_number=? ORDER BY created_at DESC,id DESC LIMIT 1",[source['number']])
        check('Telegram housekeeping completion makes old room sellable and closes task',room_final['status']=='available' and bool(task_final) and task_final[0]['status'] in ('ready','completed'),{'room':room_final,'task':task_final})

        # Open-ended sale DP and repeated booking deposits must use distinct state machines.
        bookings_before=int(db('SELECT COUNT(*) n FROM bookings')[0]['n'])
        sim_cb('open_ended_dp_start',f"r_sell_open_ended_select:{source['number']}:UAT Open DP")
        sale_state=db('SELECT telegram_state,telegram_context FROM staff WHERE id=?',[admin['id']])[0]
        check('Open-ended sale uses its own amount state',sale_state['telegram_state']=='waiting_for_sell_dp_amount',sale_state)
        sim_text('open_ended_dp_invalid','1.5')
        check('Open-ended DP rejects fractional garbage without converting it to 15',db('SELECT telegram_state FROM staff WHERE id=?',[admin['id']])[0]['telegram_state']=='waiting_for_sell_dp_amount')
        _,sale_methods=sim_text('open_ended_dp_amount','250000')
        sale_state=db('SELECT telegram_state,telegram_context FROM staff WHERE id=?',[admin['id']])[0]
        sale_callback=choose_callback(sale_methods,'r_sell_dp_confirm:',':cash:none')
        check('Open-ended sale offers its own cash/DP confirm without entering repeated-panjar JSON handler',sale_state['telegram_state']=='waiting_for_sell_dp_method' and sale_state['telegram_context'].endswith(':250000') and bool(sale_callback),sale_state)
        sim_cb('open_ended_dp_cancel','cancel_booking_process')
        check('Cancelling open-ended DP creates neither booking nor payment',int(db('SELECT COUNT(*) n FROM bookings')[0]['n'])==bookings_before and not db("SELECT id FROM bookings WHERE guestName='UAT Open DP'"))

        # The web hotel-data projection must show the Telegram mutations on the same canonical booking.
        s,hotel=request('hotel-data','GET')
        projected=next((x for x in (hotel.get('bookings') or []) if x.get('id')==booking_id),None) if isinstance(hotel,dict) else None
        check('Web hotel-data immediately sees Telegram extension/service/transfer state',s==200 and projected is not None and projected.get('roomNumber')==target['number'] and float(projected.get('totalAmount') or 0)==float(db('SELECT totalAmount FROM bookings WHERE id=?',[booking_id])[0]['totalAmount']),{'projected':projected})

check('Telegram parity suite leaves journals balanced',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
print('ENTERPRISE-FULL-TELEGRAM-PARITY',sum(x['pass'] for x in results),'/',len(results))
