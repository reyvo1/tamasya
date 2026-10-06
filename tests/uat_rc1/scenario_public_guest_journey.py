from client import *
from scenario_core import db
import datetime, hashlib, json, urllib.request, urllib.error, uuid
from urllib.parse import urlencode

results=[]
run='efc_public_'+uuid.uuid4().hex[:10]

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:900],flush=True)
    (base/'logs/enterprise-full-public-journey-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2,default=str),encoding='utf8')

def raw_public(action,method='GET',data=None,operation=None,query=None):
    q={'action':action}
    if query:q.update(query)
    url=BASE_URL+'/api.php?'+urlencode(q)
    body=None
    headers={'Accept':'application/json','Origin':ORIGIN,'User-Agent':'TAMASYA-EFC-Public-UAT/1.0','X-Device-ID':'efc-public-browser'}
    if operation: headers['X-Tamasya-Operation-ID']=operation
    if data is not None:
        body=json.dumps(data,separators=(',',':')).encode('utf8');headers['Content-Type']='application/json'
    req=urllib.request.Request(url,data=body,headers=headers,method=method)
    try: resp=urllib.request.urlopen(req,timeout=90)
    except urllib.error.HTTPError as e: resp=e
    raw=resp.read().decode('utf8','replace')
    try: parsed=json.loads(raw) if raw else {}
    except Exception: parsed={'success':False,'raw':raw[:2000]}
    return int(resp.status),parsed

def admin_operation(label):
    # Operation IDs are security/idempotency keys, not human labels. Keep them
    # deterministic for this run, ASCII-only, bounded, and route-safe.
    digest=hashlib.sha256((run+'|'+label).encode('utf8')).hexdigest()[:40]
    return 'efc_public_admin_'+digest

def acall(name,action,data,expected=200,operation=None):
    op=operation or admin_operation(name)
    s,b=request(action,'POST',data,op)
    check(name,s==expected and isinstance(b,dict) and (b.get('success') is True if expected<300 else b.get('success') is False),{'status':s,'body':b,'operationId':op})
    return s,b

# Choose an actually free room/time window and publish a matching website room type.
today=datetime.date.today(); checkin=today+datetime.timedelta(days=30); checkout=checkin+datetime.timedelta(days=2)
room_rows=db("SELECT r.number,r.type FROM rooms r WHERE r.status='available' AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.roomNumber=r.number AND b.status IN ('reserved','active') AND b.checkOut>? AND b.checkIn<?) ORDER BY r.number LIMIT 1",[checkin.isoformat(),checkout.isoformat()])
check('Public journey has an actual canonical room available for conversion',bool(room_rows),room_rows)
room=room_rows[0] if room_rows else None
room_type_id='efc_public_'+run[-8:]
if room:
    cms_op='efc_public_cms_room_'+run
    acall('CMS publishes room type matching canonical inventory','website-cms-room-type-save',{'roomType':{
        'id':room_type_id,'name':room['type'],'slug':'efc-'+run[-8:],'shortDescription':'Enterprise Full Complete room type',
        'description':'Published from the authenticated website CMS during deterministic UAT.','priceFrom':400000,'capacity':2,
        'roomSize':'24 m2','bedType':'Queen','facilities':['WiFi','AC'],'rules':['No smoking'],'images':[],'featured':True,'status':'published','sortOrder':5
    }},operation=cms_op)

    s,boot=raw_public('public-bootstrap','GET',query={'effectiveDate':checkin.isoformat()})
    check('Anonymous public bootstrap exposes newly published room type',s==200 and boot.get('success') is True and any(x.get('id')==room_type_id for x in (boot.get('roomTypes') or [])),{'status':s,'roomTypes':boot.get('roomTypes')})
    s,avail=raw_public('public-room-availability','GET',query={'checkIn':checkin.isoformat(),'checkOut':checkout.isoformat()})
    check('Anonymous availability endpoint returns server-derived inventory',s==200 and avail.get('success') is True and isinstance(avail.get('availability'),list),{'status':s,'body':avail})

    op='public_reservation_'+uuid.uuid4().hex[:20]
    reservation={
        'guestName':'EFC Public Guest','whatsapp':'+6281112345678','email':'efc-public@example.test',
        'checkIn':checkin.isoformat(),'checkOut':checkout.isoformat(),'guestCount':2,'roomTypeId':room_type_id,
        'extraRequest':'Late arrival','notes':'Enterprise Full Complete public reservation','operationId':op
    }
    s,pub=raw_public('public-reservation-request','POST',reservation,op)
    check('Anonymous website creates pending reservation request, not final booking',s==200 and pub.get('success') is True and pub.get('status')=='pending_review' and bool(pub.get('publicRequestId')),{'status':s,'body':pub})
    public_id=pub.get('publicRequestId') if isinstance(pub,dict) else None
    if public_id:
        prow=db('SELECT public_request_id,status,linked_booking_id,guest_name,room_type_id FROM public_reservation_requests WHERE public_request_id=?',[public_id])
        check('Public request persists pending with no booking link',len(prow)==1 and prow[0]['status']=='pending_review' and not prow[0]['linked_booking_id'] and prow[0]['room_type_id']==room_type_id,prow)
        s2,dup=raw_public('public-reservation-request','POST',reservation,op)
        check('Public reservation operation-id retry is idempotent',s2==200 and dup.get('success') is True and dup.get('publicRequestId')==public_id and int(db('SELECT COUNT(*) n FROM public_reservation_requests WHERE operation_id=?',[op])[0]['n'])==1,dup)

        acall('Staff marks public reservation reviewing','public-reservation-review',{'publicRequestId':public_id,'status':'reviewing','reason':''})
        review=db('SELECT status,reviewed_by,linked_booking_id FROM public_reservation_requests WHERE public_request_id=?',[public_id])[0]
        check('Public reservation review persists authenticated staff decision',review['status']=='reviewing' and bool(review['reviewed_by']) and not review['linked_booking_id'],review)

        booking_op=admin_operation('convert_booking')
        s,b=request('bookings','POST',{
            'guestName':'EFC Public Guest','guestPhone':'+6281112345678','guestEmail':'efc-public@example.test',
            'roomNumber':room['number'],'checkIn':checkin.isoformat(),'checkOut':checkout.isoformat(),'totalAmount':800000,
            'paymentStatus':'unpaid','bookingSource':'Website','lifecycleIntent':'reserve','publicRequestId':public_id,'broadcast':False
        },booking_op)
        check('Staff converts reviewed public request through canonical booking workflow',s==200 and b.get('success') is True and bool(b.get('bookingId')),{'status':s,'body':b})
        booking_id=b.get('bookingId') if isinstance(b,dict) else None
        if booking_id:
            converted=db('SELECT status,linked_booking_id FROM public_reservation_requests WHERE public_request_id=?',[public_id])[0]
            bk=db('SELECT id,status,bookingSource,roomNumber,guestName FROM bookings WHERE id=?',[booking_id])[0]
            check('Public request becomes converted and links exactly one reserved Website booking',converted['status']=='converted' and converted['linked_booking_id']==booking_id and bk['status']=='reserved' and bk['bookingSource']=='Website' and bk['roomNumber']==room['number'],{'public':converted,'booking':bk})
            before_reject=db('SELECT status,linked_booking_id,reviewed_by FROM public_reservation_requests WHERE public_request_id=?',[public_id])[0]
            s,bad=request('public-reservation-review','POST',{'publicRequestId':public_id,'status':'rejected','reason':'must not overwrite conversion'},admin_operation('review_after_conversion'))
            after_reject=db('SELECT status,linked_booking_id,reviewed_by FROM public_reservation_requests WHERE public_request_id=?',[public_id])[0]
            check('Converted public request cannot be rejected or rewritten',s==409 and isinstance(bad,dict) and bad.get('success') is False and after_reject['status']=='converted' and after_reject['linked_booking_id']==booking_id and after_reject==before_reject,{'status':s,'body':bad,'before':before_reject,'after':after_reject})

# Real anonymous support chat lifecycle: guest -> system/AI reply -> token-protected sync.
chat_op='public_chat_'+uuid.uuid4().hex[:20]
s,chat=raw_public('public-help-chat','POST',{'message':'Apakah check-in hotel tersedia hari ini?','locale':'id','operationId':chat_op},chat_op)
check('Anonymous public help chat creates token-bound conversation',s==200 and chat.get('success') is True and bool(chat.get('conversationId')) and bool(chat.get('visitorToken')) and bool(chat.get('replyMessage')),{'status':s,'body':chat})
conversation=chat.get('conversationId') if isinstance(chat,dict) else None
token=chat.get('visitorToken') if isinstance(chat,dict) else None
if conversation and token:
    s,sync=raw_public('public-help-chat-sync','POST',{'conversationId':conversation,'visitorToken':token})
    check('Public chat sync returns guest and system/AI messages using visitor token',s==200 and sync.get('success') is True and len(sync.get('messages') or [])>=2 and any(x.get('sender')=='guest' for x in sync.get('messages') or []),{'status':s,'body':sync})
    s,denied=raw_public('public-help-chat-sync','POST',{'conversationId':conversation,'visitorToken':'wrong-token-value'})
    check('Public chat sync rejects wrong visitor token',s==404 and denied.get('success') is False,{'status':s,'body':denied})

check('Public journey writes enterprise audit for website reservation',bool(db("SELECT id FROM audit_logs WHERE entity_type='public_reservation_request' ORDER BY created_at DESC LIMIT 1")))
print('ENTERPRISE-FULL-PUBLIC-JOURNEY',sum(x['pass'] for x in results),'/',len(results))
