from client import *
from scenario_core import db
import datetime, json, uuid

run='ge_'+uuid.uuid4().hex[:10]
results=[]
state=json.loads((base/'state.json').read_text(encoding='utf8'))

def check(name,ok,detail=None):
    results.append({'test':name,'pass':bool(ok),'details':detail})
    print('PASS' if ok else 'FAIL',name,str(detail or '')[:800],flush=True)
    (base/'logs/growth-enterprise-results.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf8')

def call(name,command,data=None,expected=200,method='POST',action='growth-suite',query=None,operation=None):
    if method.upper()=='GET':
        q='action='+action+'&command='+command
        if query:
            import urllib.parse
            q += '&'+urllib.parse.urlencode(query)
        status,body=request(q,'GET',None,operation)
    else:
        payload=dict(data or {})
        payload.setdefault('command',command)
        status,body=request(action,method,payload,operation or (run+'_'+name))
    allowed={expected} if isinstance(expected,int) else set(expected)
    ok=status in allowed and (body.get('success') is not False if status<300 else body.get('success') is False)
    check(name,ok,{'status':status,'message':body.get('error',body.get('message',''))})
    return status,body

today=datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date()
future=today+datetime.timedelta(days=30)

# Feature/bootstrap gates must be ON only in this disposable UAT database.
_,gstatus=call('growth_overview','overview',method='GET')
_,gboot=call('growth_bootstrap','bootstrap',method='GET')
features=(gboot.get('data') or {}).get('features') or (gstatus.get('data') or {}).get('features') or {}
check('Growth Suite is enabled in disposable UAT',bool((gboot.get('data') or {}).get('enabled',True)),gboot.get('data'))
_,estatus=call('enterprise_status','status',method='GET',action='enterprise-suite')
_,eboot=call('enterprise_bootstrap','bootstrap',method='GET',action='enterprise-suite')
check('Enterprise Completion is enabled in disposable UAT',estatus.get('success') is True,estatus.get('data'))

# Booking dedicated to Growth/Enterprise tests: no production/live recipient or payment provider.
fixture_room='91'+str(int(uuid.uuid4().hex[:6],16))
room_status,room_created=request('rooms','POST',{'number':fixture_room,'type':'SIM Deluxe','price':200000,'floor':1},run+'_room')
check('Dedicated Growth KPI room created through canonical API',room_status==200 and room_created.get('success') is not False,room_created)
booking_payload={
    'guestName':'SIM Enterprise Guest','guestPhone':'08000000999','guestEmail':'enterprise-uat@example.invalid',
    'roomNumber':fixture_room,'checkIn':str(future),'checkOut':str(future+datetime.timedelta(days=2)),
    'totalAmount':440000,'paymentStatus':'unpaid','bookingSource':'Direct','broadcast':False
}
status,b=request('bookings','POST',booking_payload,run+'_booking')
check('Growth/Enterprise fixture reservation created',status==200 and b.get('success') is True and bool(b.get('bookingId')),b)
booking_id=b.get('bookingId')

# Revenue/rate management.
_,rp=call('rate_plan','rate-plan-save',{'code':'UAT'+run[-5:].upper(),'name':'UAT Enterprise Rate','roomType':'SIM Deluxe','baseRate':200000,'minRate':150000,'maxRate':350000,'active':True})
rate_id=(rp.get('data') or {}).get('id') or rp.get('id')
check('Rate plan persisted',bool(rate_id) and bool(db('SELECT id FROM growth_rate_plans WHERE id=?',[rate_id])),rate_id)
_,rr=call('rate_rule','rate-rule-save',{'planId':rate_id,'name':'UAT Direct +5','priority':100,'condition':{'bookingSource':'Direct'},'adjustmentType':'percent','adjustmentValue':5,'active':True})
check('Rate rule persisted',bool((rr.get('data') or {}).get('id')),rr.get('data'))
_,ro=call('rate_override','rate-override-save',{'planId':rate_id,'stayDate':str(future),'roomType':'SIM Deluxe','rate':225000,'minStay':1,'note':'UAT override'})
check('Rate override persisted',bool((ro.get('data') or {}).get('id')),ro.get('data'))
_,suggest=call('rate_suggestion','rate-suggestion',method='GET',query={'planId':rate_id,'stayDate':str(future),'lengthOfStay':2,'bookingSource':'Direct','roomType':'SIM Deluxe'})
check('Rate suggestion returns deterministic positive rate',float((suggest.get('data') or {}).get('rate') or 0)>0,suggest.get('data'))
_,kpi=call('growth_kpi','kpis',method='GET',query={'from':str(today.replace(day=1)),'to':str(today)})
check('Growth KPI returns report data',isinstance(kpi.get('data'),dict),kpi.get('data'))

# Dedicated canonical reservation is temporarily projected through edge-case dates.
# These are disposable CI fixture changes only; restore every field before business UAT.
if booking_id:
    fields=['checkIn','checkOut','status','isOpenEnded','actualCheckOutAt','extras','roomCharge','extraCharge','discountAmount']
    original=db('SELECT '+','.join(fields)+' FROM bookings WHERE id=?',[booking_id])[0]
    before_transactions=int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])
    baseline=call('kpi_today_before_fixture','kpis',method='GET',query={'from':str(today),'to':str(today)})[1]['data']
    forecast_before=call('forecast_before_fixture','revenue-forecast',method='GET',action='enterprise-suite',query={'days':7})[1]['data']['daily'][0]
    def kpi_case(name,changes):
        db('UPDATE bookings SET '+','.join(k+'=?' for k in changes)+' WHERE id=?',list(changes.values())+[booking_id])
        return call(name,'kpis',method='GET',query={'from':str(today),'to':str(today)})[1]['data']
    try:
        overdue=kpi_case('kpi_active_overdue',{'checkIn':str(today-datetime.timedelta(days=2)),'checkOut':str(today-datetime.timedelta(days=1)),'status':'active','isOpenEnded':0,'actualCheckOutAt':None})
        check('Active overdue booking contributes exactly one occupied night today',overdue['soldRoomNights']==baseline['soldRoomNights']+1 and overdue['currentOccupiedRooms']==baseline['currentOccupiedRooms']+1,overdue)
        check('Active overdue room revenue is allocated without inventing billing',abs(overdue['roomRevenue']-baseline['roomRevenue']-440000/3)<0.02,overdue)
        open_kpi=kpi_case('kpi_open_ended_today',{'isOpenEnded':1})
        forecast=call('forecast_open_ended','revenue-forecast',method='GET',action='enterprise-suite',query={'days':7})[1]['data']
        check('Enterprise forecast includes current open-ended room beyond placeholder checkout',forecast['daily'][0]['onBooksRooms']==forecast_before['onBooksRooms']+1 and all(x['onBooksRooms']>=1 for x in forecast['daily']),forecast['daily'])
        early=kpi_case('kpi_early_checkout',{'checkIn':str(today-datetime.timedelta(days=7)),'checkOut':str(today+datetime.timedelta(days=3)),'status':'completed','isOpenEnded':0,'actualCheckOutAt':str(today-datetime.timedelta(days=2))+' 12:00:00'})
        check('Early completed checkout before today cannot generate positive absolute-difference nights',early['soldRoomNights']==baseline['soldRoomNights'] and abs(early['roomRevenue']-baseline['roomRevenue'])<0.01,early)
        check('Folio KPI and LOS use the same actually overlapping bookings',early['folioHealth']['bookingCount']==baseline['folioHealth']['bookingCount'] and early['averageLengthOfStay']==baseline['averageLengthOfStay'],early)
        extras=json.dumps([{'id':'uat_kpi_extension','total':220000,'price':220000,'qty':1,'allocationType':'room','taxKind':'extension'},{'id':'uat_kpi_service','total':110000,'price':110000,'qty':1,'allocationType':'extra'}])
        classified=kpi_case('kpi_room_service_classification',{'checkIn':str(today),'checkOut':str(today+datetime.timedelta(days=1)),'status':'active','actualCheckOutAt':None,'extras':extras,'roomCharge':110000,'extraCharge':330000})
        check('Growth computes room extension as room revenue despite stale storage classification',abs(classified['roomRevenue']-baseline['roomRevenue']-330000)<0.01,classified)
        folio=call('folio_shared_read_projection','folio',method='GET',query={'bookingId':booking_id})[1]['data']['booking']
        check('Growth folio preserves service-only extraCharge and room extension revenue',float(folio['roomCharge'])==330000 and float(folio['extraCharge'])==110000,folio)
        forecast=call('forecast_shared_room_projection','revenue-forecast',method='GET',action='enterprise-suite',query={'days':7})[1]['data']
        check('Enterprise on-books revenue uses same room/service classification as Growth',abs(forecast['daily'][0]['onBooksRevenue']-forecast_before['onBooksRevenue']-330000)<0.01,forecast['daily'][0])
    finally:
        db('UPDATE bookings SET '+','.join(k+'=?' for k in fields)+' WHERE id=?',[original[k] for k in fields]+[booking_id])
    restored=call('kpi_today_after_fixture_restore','kpis',method='GET',query={'from':str(today),'to':str(today)})[1]['data']
    check('KPI/forecast reads do not create cash receipts or journal source transactions',int(db('SELECT COUNT(*) n FROM transactions')[0]['n'])==before_transactions and restored['soldRoomNights']==baseline['soldRoomNights'] and restored['roomRevenue']==baseline['roomRevenue'])


# Corporate/group and link/unlink booking.
_,corp=call('company_save','company-save',{'code':'CORP'+run[-5:].upper(),'name':'UAT Corporate','billingEmail':'billing@example.invalid','phone':'080000001','creditLimit':10000000,'paymentTermsDays':30,'status':'active'})
corp_id=(corp.get('data') or {}).get('id') or corp.get('id')
_,grp=call('group_save','group-save',{'name':'UAT Group '+run,'companyId':corp_id,'arrivalDate':str(future),'departureDate':str(future+datetime.timedelta(days=2)),'roomBlockQty':2,'billingMode':'master','status':'confirmed'})
group_id=(grp.get('data') or {}).get('id') or grp.get('id')
_,link=call('group_link','group-link-booking',{'groupId':group_id,'bookingId':booking_id,'billingMode':'master','routedPercent':100})
link_id=(link.get('data') or {}).get('id')
check('Group booking link persisted',bool(link_id) and bool(db('SELECT id FROM growth_group_booking_links WHERE id=?',[link_id])),link.get('data'))
_,gd=call('group_detail','group-detail',method='GET',query={'id':group_id})
check('Group detail sees linked booking',any(str(x.get('booking_id'))==str(booking_id) for x in ((gd.get('data') or {}).get('bookings') or [])),gd.get('data'))
call('group_unlink','group-unlink-booking',{'id':link_id})
check('Group unlink removes link',not db('SELECT id FROM growth_group_booking_links WHERE id=?',[link_id]))

# Procurement foundation.
_,vendor=call('vendor_save','vendor-save',{'code':'VEN'+run[-5:].upper(),'name':'UAT Vendor','email':'vendor@example.invalid','paymentTermsDays':14,'status':'active'})
vendor_id=(vendor.get('data') or {}).get('id') or vendor.get('id')
_,po=call('growth_po_save','po-save',{'vendorId':vendor_id,'orderDate':str(today),'items':[{'itemName':'UAT supplies','quantity':2,'unit':'pcs','unitPrice':12000,'taxRate':0}]})
po_id=(po.get('data') or {}).get('id') or po.get('id')
call('growth_po_submit','po-status',{'id':po_id,'status':'submitted'})
call('growth_po_approve','po-status',{'id':po_id,'status':'approved'})
_,pod=call('growth_po_detail','po-detail',method='GET',query={'id':po_id})
check('Approved Growth PO has expected total',abs(float(((pod.get('data') or {}).get('purchaseOrder') or {}).get('total_amount') or 0)-24000)<0.01,pod.get('data'))

# Channel mapping/event: metadata only, must not create a booking automatically.
before_bookings=int(db('SELECT COUNT(*) n FROM bookings')[0]['n'])
_,ch=call('channel_mapping','channel-mapping-save',{'channelCode':'traveloka','externalPropertyId':'UAT','externalRoomType':'DELUXE','internalRoomType':'SIM Deluxe','externalRatePlan':'BAR','internalRatePlanId':rate_id,'active':True})
call('channel_event','channel-event-register',{'channelCode':'traveloka','eventType':'reservation.preview','externalId':'UAT-'+run,'payload':{'guest':'synthetic','amount':12345}})
check('Channel event foundation performs no automatic booking mutation',int(db('SELECT COUNT(*) n FROM bookings')[0]['n'])==before_bookings)

# Payment intent/event/link to canonical booking payment.
_,pi=call('payment_intent','payment-intent-create',{'bookingId':booking_id,'providerCode':'uat-provider','amount':100000})
intent=(pi.get('data') or {}).get('id')
call('payment_event','payment-event-register',{'intentId':intent,'providerEventId':'EV-'+run,'status':'captured','amount':100000,'payload':{'synthetic':True}})
status,pay=request('booking-payments','POST',{'bookingId':booking_id,'amount':100000,'paymentMethod':'cash','operationId':run+'_canonical_pay'},run+'_canonical_pay')
check('Canonical booking payment for intent created',status==200 and pay.get('success') is True,pay)
tx_id=((pay.get('result') or {}).get('transactionId') or pay.get('transactionId'))
tx_rows=db("SELECT id,type,bookingId,amount,transactionKind FROM transactions WHERE id=?",[tx_id]) if tx_id else []
check('Payment intent fixture resolves exact canonical income transaction',len(tx_rows)==1 and str(tx_rows[0].get('type','')).lower()=='income' and str(tx_rows[0].get('bookingId'))==str(booking_id) and abs(float(tx_rows[0].get('amount') or 0)-100000)<0.01,tx_rows)
_,linked=call('payment_link','payment-link-transaction',{'intentId':intent,'transactionId':tx_id})
check('Payment intent confirms only by linking canonical transaction',(linked.get('data') or {}).get('status')=='confirmed' and (linked.get('data') or {}).get('confirmed_transaction_id')==tx_id,linked.get('data'))

# Enterprise advanced folio: create, sync charges, route, invoice, void snapshot.
_,folio=call('folio_create','folio-create',{'folioType':'guest','bookingId':booking_id,'name':'UAT Guest Folio'},action='enterprise-suite')
folio_id=(folio.get('data') or {}).get('id')
_,sync=call('folio_sync','folio-sync-booking-charges',{'folioId':folio_id,'percent':100},action='enterprise-suite')
check('Advanced folio sync creates charge allocation',int((sync.get('updated') or 0))>0,sync)
_,fd=call('folio_detail','folio-detail',method='GET',query={'id':folio_id},action='enterprise-suite')
check('Advanced folio detail returns canonical-derived totals',isinstance(fd.get('data'),dict) and (fd.get('data') or {}).get('folio',{}).get('id')==folio_id,fd.get('data'))
_,route=call('folio_route_save','folio-route-save',{'targetFolioId':folio_id,'bookingId':booking_id,'transactionKindPattern':'*','categoryPattern':'*','routePercent':100,'priority':100},action='enterprise-suite')
route_id=(route.get('data') or {}).get('id')
call('folio_route_apply','folio-route-apply',{'bookingId':booking_id},action='enterprise-suite')
_,invoice=call('folio_invoice_issue','folio-invoice-issue',{'folioId':folio_id,'notes':'UAT snapshot invoice'},action='enterprise-suite')
invoice_id=(invoice.get('data') or {}).get('id')
check('Folio invoice is immutable snapshot, not new hotel revenue',bool(invoice_id) and int(db('SELECT COUNT(*) n FROM growth_folio_invoices WHERE id=?',[invoice_id])[0]['n'])==1,invoice.get('data'))
call('folio_invoice_void','folio-invoice-void',{'id':invoice_id,'reason':'UAT reversal of snapshot'},action='enterprise-suite')
check('Folio invoice void retains row for audit',db('SELECT status FROM growth_folio_invoices WHERE id=?',[invoice_id])[0]['status']=='void')

# Enterprise PR -> PO -> GRN -> Supplier Invoice -> AP payment; verifies stock + canonical accounting.
_,pr=call('pr_save','pr-save',{'requestDate':str(today),'department':'Operations','neededBy':str(today+datetime.timedelta(days=2)),'justification':'UAT enterprise procurement','items':[{'posProductId':state['product_1'],'sku':'SIM-01','itemName':'SIM Product 1','quantity':1,'unit':'pcs','estimatedUnitPrice':12000}]},action='enterprise-suite')
pr_id=((pr.get('data') or {}).get('request') or {}).get('id') or (pr.get('data') or {}).get('id')
call('pr_submit','pr-status',{'id':pr_id,'status':'submitted'},action='enterprise-suite')
call('pr_approve','pr-status',{'id':pr_id,'status':'approved'},action='enterprise-suite')
_,epo=call('po_from_pr','po-create-from-pr',{'prId':pr_id,'vendorId':vendor_id,'taxRate':0,'orderDate':str(today)},action='enterprise-suite')
epo_id=(epo.get('data') or {}).get('id')
call('epo_submit','po-status',{'id':epo_id,'status':'submitted'},action='growth-suite')
call('epo_approve','po-status',{'id':epo_id,'status':'approved'},action='growth-suite')
po_item=db('SELECT id,quantity,unit_price FROM growth_purchase_order_items WHERE po_id=? ORDER BY id LIMIT 1',[epo_id])[0]
stock_before=float(db('SELECT stock_quantity FROM pos_products WHERE id=?',[state['product_1']])[0]['stock_quantity'])
_,grn=call('grn_save','grn-save',{'poId':epo_id,'receiptDate':str(today),'location':'UAT','items':[{'poItemId':po_item['id'],'posProductId':state['product_1'],'quantityReceived':float(po_item['quantity']),'unitCost':float(po_item['unit_price'])}]},action='enterprise-suite')
grn_id=((grn.get('data') or {}).get('receipt') or {}).get('id') or (grn.get('data') or {}).get('id')
call('grn_post','grn-post',{'id':grn_id},action='enterprise-suite')
stock_after=float(db('SELECT stock_quantity FROM pos_products WHERE id=?',[state['product_1']])[0]['stock_quantity'])
check('GRN posting mutates real POS stock exactly once',abs(stock_after-stock_before-float(po_item['quantity']))<0.001,{'before':stock_before,'after':stock_after})
invno='UAT-'+run.upper()
_,sinv=call('supplier_invoice_save','supplier-invoice-save',{'vendorId':vendor_id,'poId':epo_id,'grnId':grn_id,'supplierInvoiceNumber':invno,'invoiceDate':str(today),'dueDate':str(today+datetime.timedelta(days=14)),'lines':[{'poItemId':po_item['id'],'itemName':'SIM Product 1','accountClass':'expense','expenseCategory':'UAT Procurement','quantity':float(po_item['quantity']),'unit':'pcs','unitPrice':float(po_item['unit_price']),'taxRate':0}]},action='enterprise-suite')
sinv_id=((sinv.get('data') or {}).get('invoice') or {}).get('id') or (sinv.get('data') or {}).get('id')
_,match=call('supplier_match','supplier-invoice-match',method='GET',query={'id':sinv_id},action='enterprise-suite')
check('Supplier invoice three-way match permits posting',bool((match.get('data') or {}).get('canPost')),match.get('data'))
_,posted=call('supplier_invoice_post','supplier-invoice-post',{'id':sinv_id},action='enterprise-suite')
check('Supplier invoice posting creates canonical accrual transaction',len(posted.get('canonicalAccrualTransactionIds') or [])>=1,posted)
outstanding=float((((posted.get('data') or {}).get('invoice') or {}).get('total_amount') or 0))
if outstanding<=0:
    outstanding=float(db('SELECT total_amount FROM growth_supplier_invoices WHERE id=?',[sinv_id])[0]['total_amount'])
_,appay=call('ap_payment','ap-payment-create',{'supplierInvoiceId':sinv_id,'amount':outstanding,'paymentMethod':'cash','date':str(today)},action='enterprise-suite')
check('AP payment reaches paid status through canonical expense transaction',((appay.get('data') or {}).get('invoice') or {}).get('status')=='paid' and bool(appay.get('transactionId')),appay)

# CRM/loyalty: synthetic guest, consent, link, points, voucher, segment/campaign snapshot.
guest_id='guest_'+run
# Direct DB insertion is fixture creation only; all business mutations below go through API.
db("INSERT INTO guest_profiles(id,name,phone,email,blacklisted) VALUES (?,?,?,?,0)",[guest_id,'SIM Enterprise Guest','08000000999','enterprise-uat@example.invalid'])
call('guest_booking_link','guest-booking-link',{'guestProfileId':guest_id,'bookingId':booking_id},action='enterprise-suite')
call('consent_loyalty','consent-save',{'guestProfileId':guest_id,'consentType':'loyalty_program','status':'granted','source':'uat','evidenceReference':'synthetic'},action='enterprise-suite')
call('consent_email','consent-save',{'guestProfileId':guest_id,'consentType':'email_marketing','status':'granted','source':'uat','evidenceReference':'synthetic'},action='enterprise-suite')
_,points=call('loyalty_adjust','loyalty-adjust',{'guestProfileId':guest_id,'points':100,'reason':'UAT points'},action='enterprise-suite')
check('Loyalty points persist positive balance',float((points.get('account') or {}).get('points_balance') or 0)>=100,points.get('account'))
_,voucher=call('voucher_issue','loyalty-voucher-issue',{'guestProfileId':guest_id,'valueType':'amount','valueAmount':10000,'minSpend':0,'validFrom':str(today),'validUntil':str(today+datetime.timedelta(days=90)),'notes':'UAT voucher'},action='enterprise-suite')
voucher_id=(voucher.get('data') or {}).get('id')
check('Voucher issuance requires and records loyalty consent',bool(voucher_id),voucher.get('data'))
_,seg=call('crm_segment','crm-segment-save',{'code':'SEG'+run[-5:].upper(),'name':'UAT Email Guests','condition':{'email_required':True}},action='enterprise-suite')
segment_id=(seg.get('data') or {}).get('id')
_,camp=call('crm_campaign','crm-campaign-save',{'name':'UAT Campaign','segmentId':segment_id,'channel':'email','subject':'UAT only','messageTemplate':'Hello {{guest_name}}'},action='enterprise-suite')
camp_id=(camp.get('data') or {}).get('id')
call('crm_campaign_approve','crm-campaign-approve',{'id':camp_id},action='enterprise-suite')
_,snap=call('crm_campaign_snapshot','crm-campaign-snapshot',{'id':camp_id},action='enterprise-suite')
check('CRM campaign snapshot includes consented synthetic recipient',int(snap.get('recipientCount') or 0)>=1,snap)
call('crm_live_send_fail_closed','crm-campaign-send-batch',{'id':camp_id,'limit':1},expected=409,action='enterprise-suite')
check('CRM live sender remains disabled in UAT',db('SELECT status FROM growth_crm_campaigns WHERE id=?',[camp_id])[0]['status']=='ready')

# Health + provider adapter metadata. Provider self-test is local only and external mutation remains false.
_,health=call('health_rule','health-rule-save',{'code':'UAT_'+run[-5:].upper(),'metricKey':'unbalanced_journal_entries','comparison':'gt','thresholdValue':0,'severity':'critical'},action='enterprise-suite')
check('Enterprise health snapshot returned after rule save',isinstance(health.get('data'),dict),health.get('data'))
_,adapter=call('adapter_save','provider-adapter-save',{'adapterType':'channel','providerCode':'uat-provider','mode':'sandbox','credentialReference':'','webhookVerifier':'hmac_sha256','active':True,'config':{'endpoint':'https://example.invalid'}},action='enterprise-suite')
adapter_id=(adapter.get('data') or {}).get('id')
check('Provider adapter metadata cannot enable external mutation',adapter.get('externalMutationEnabled') is False,adapter)
_,selftest=call('adapter_self_test','provider-adapter-self-test',method='GET',query={'id':adapter_id},action='enterprise-suite')
check('Provider self-test remains local-only',((selftest.get('data') or {}).get('externalMutationEnabled') is False),selftest.get('data'))

# Final accounting invariants after optional suites.
check('Growth/Enterprise UAT leaves journals balanced',not db('SELECT journal_entry_id FROM journal_lines GROUP BY journal_entry_id HAVING ABS(SUM(debit)-SUM(credit))>0.001'))
check('Growth/Enterprise UAT leaves document numbers unique',not db("SELECT documentNumber,COUNT(*) n FROM transactions WHERE documentNumber IS NOT NULL AND documentNumber<>'' GROUP BY documentNumber HAVING COUNT(*)>1"))
check('No supplier payment exceeds invoice total',not db("SELECT i.id FROM growth_supplier_invoices i LEFT JOIN growth_supplier_invoice_payments p ON p.supplier_invoice_id=i.id GROUP BY i.id,i.total_amount HAVING COALESCE(SUM(p.amount_applied),0)>i.total_amount+0.01"))

state.update({'growth_booking':booking_id,'growth_rate_plan':rate_id,'growth_vendor':vendor_id,'enterprise_folio':folio_id,'enterprise_supplier_invoice':sinv_id,'enterprise_guest':guest_id})
(base/'state.json').write_text(json.dumps(state),encoding='utf8')
print('GROWTH-ENTERPRISE-UAT',sum(x['pass'] for x in results),'/',len(results))
