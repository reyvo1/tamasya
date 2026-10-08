<?php
/** TAMASYA V137 R3 root lifecycle invariants. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Extract canonical room ownership from maintenance asset_ref without misclassifying equipment IDs. */
function tamasyaR3RoomFromMaintenanceAssetRef(PDO $pdo, string $assetRef): string {
    $assetRef=trim($assetRef);
    if($assetRef==='')return '';
    if(str_starts_with($assetRef,'asset:'))return '';
    $candidate=$assetRef;
    if(str_starts_with($assetRef,'room:'))$candidate=trim(substr($assetRef,5));
    if($candidate===''||!preg_match('/^[A-Za-z0-9._-]{1,40}$/',$candidate))return '';
    // A room prefix is not enough: ownership is valid only when the room exists
    // in the property room master. Legacy bare room numbers remain readable.
    $stmt=$pdo->prepare("SELECT number FROM rooms WHERE number=? LIMIT 1");
    $stmt->execute([$candidate]);
    $room=$stmt->fetchColumn();
    return $room!==false?(string)$room:'';
}

/**
 * Canonicalize one maintenance target. New UI/API callers must explicitly choose
 * room vs general asset. Legacy callers are accepted but normalized safely.
 */
function tamasyaR3CanonicalMaintenanceAssetRef(PDO $pdo, array $input, ?array $before=null): string {
    $kind=strtolower(trim((string)($input['assetKind']??'')));
    if($kind==='room'){
        $roomNumber=trim((string)($input['roomNumber']??''));
        if($roomNumber==='')throw new InvalidArgumentException('Pilih kamar maintenance dari master kamar.');
        $stmt=$pdo->prepare("SELECT number FROM rooms WHERE number=? LIMIT 1");
        $stmt->execute([$roomNumber]);$room=$stmt->fetchColumn();
        if($room===false)throw new RuntimeException('Kamar maintenance tidak ditemukan pada master kamar. Muat ulang daftar kamar lalu pilih kembali.');
        return 'room:'.(string)$room;
    }
    if($kind==='asset'){
        $asset=trim((string)($input['assetRef']??''));
        if($asset==='')throw new InvalidArgumentException('ID/nama aset umum maintenance wajib diisi.');
        if(str_starts_with($asset,'room:')||str_starts_with($asset,'asset:'))$asset=trim(substr($asset,strpos($asset,':')+1));
        if($asset===''||strlen($asset)>180||preg_match('/[\r\n\x00-\x1F\x7F]/',$asset))throw new InvalidArgumentException('ID/nama aset umum maintenance tidak valid.');
        return 'asset:'.$asset;
    }
    if($kind!=='')throw new InvalidArgumentException('Jenis objek maintenance tidak valid. Pilih Kamar atau Aset umum.');

    // Backward compatibility for older API/UI payloads. A prefixed room is still
    // validated against the room master; a bare value equal to a real room becomes
    // canonical room:<number>. Other bare values become explicit asset:<id>.
    $raw=array_key_exists('assetRef',$input)?trim((string)$input['assetRef']):trim((string)($before['asset_ref']??''));
    if($raw==='')throw new InvalidArgumentException('Kamar/aset maintenance wajib diisi.');
    if(str_starts_with($raw,'room:')){
        $room=tamasyaR3RoomFromMaintenanceAssetRef($pdo,$raw);
        if($room==='')throw new RuntimeException('Referensi kamar pada tiket maintenance tidak ditemukan pada master kamar.');
        return 'room:'.$room;
    }
    if(str_starts_with($raw,'asset:')){
        $asset=trim(substr($raw,6));
        if($asset===''||strlen($asset)>180||preg_match('/[\r\n\x00-\x1F\x7F]/',$asset))throw new InvalidArgumentException('Referensi aset maintenance tidak valid.');
        return 'asset:'.$asset;
    }
    $room=tamasyaR3RoomFromMaintenanceAssetRef($pdo,$raw);
    if($room!=='')return 'room:'.$room;
    if(strlen($raw)>180||preg_match('/[\r\n\x00-\x1F\x7F]/',$raw))throw new InvalidArgumentException('Referensi aset maintenance tidak valid.');
    return 'asset:'.$raw;
}


/**
 * Resolve an asset:<identifier> maintenance target against the registered inventory
 * master. Registered inventory assets have one canonical maintenance/history and
 * financial owner: inventory_maintenance. Room-linked tickets and unregistered
 * facilities remain owned by maintenance_tickets.
 */
function tamasyaR7RegisteredInventoryAssetForMaintenance(PDO $pdo, string $assetRef): ?array {
    $assetRef=trim($assetRef);
    if(!str_starts_with($assetRef,'asset:'))return null;
    $identifier=trim(substr($assetRef,6));
    if($identifier==='')return null;
    $stmt=$pdo->prepare("SELECT id,code,name FROM inventory WHERE id=? OR code=? OR name=? ORDER BY CASE WHEN id=? THEN 0 WHEN code=? THEN 1 ELSE 2 END,id LIMIT 2");
    $stmt->execute([$identifier,$identifier,$identifier,$identifier,$identifier]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(!$rows)return null;
    // Exact ID/code is authoritative. A name match is accepted only when unique;
    // ambiguous names must never silently bind a work order to the wrong asset.
    foreach($rows as $row){
        if((string)($row['id']??'')===$identifier || (string)($row['code']??'')===$identifier)return $row;
    }
    return count($rows)===1?$rows[0]:null;
}

function tamasyaR7AssertMaintenanceTargetOwnership(PDO $pdo, string $assetRef, string $context='maintenance'): void {
    $asset=tamasyaR7RegisteredInventoryAssetForMaintenance($pdo,$assetRef);
    if(!$asset)return;
    $label=trim((string)($asset['name']??''));
    $code=trim((string)($asset['code']??''));
    $suffix=$label!==''?' '.$label:'';
    if($code!=='')$suffix.=' ('.$code.')';
    throw new RuntimeException('Aset terdaftar'.$suffix.' dikelola melalui Inventory → Pemeliharaan. Jangan membuat Maintenance Ticket kedua untuk aset yang sama karena histori dan biaya dapat terduplikasi.');
}

/**
 * Keep rooms.status aligned as soon as a room-linked maintenance ticket exists.
 * An occupied room remains booked; an unoccupied room is held in maintenance.
 */
function tamasyaR3ReconcileRoomForActiveMaintenance(PDO $pdo, array $actor, string $assetRef, string $source='maintenance-ticket-save'): ?array {
    if(!$pdo->inTransaction())throw new RuntimeException('Rekonsiliasi kamar maintenance wajib berada di transaksi database.');
    $roomNumber=tamasyaR3RoomFromMaintenanceAssetRef($pdo,$assetRef);
    if($roomNumber==='')return null;
    $activeStmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' ORDER BY id LIMIT 1 FOR UPDATE");
    $activeStmt->execute([$roomNumber]);$activeBookingId=(string)($activeStmt->fetchColumn()?:'');
    $projection=tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,$source);
    return ['roomNumber'=>$roomNumber,'roomStatus'=>(string)$projection['roomStatus'],'activeBookingId'=>$activeBookingId?:null,'blockers'=>$projection['blockers']??[]];
}

/** Room ownership on an existing room-linked maintenance ticket is immutable. */
function tamasyaR3AssertMaintenanceRoomOwnershipImmutable(PDO $pdo, ?array $before, string $requestedAssetRef): void {
    if(!$before)return;
    $oldRoom=tamasyaR3RoomFromMaintenanceAssetRef($pdo,(string)($before['asset_ref']??''));
    if($oldRoom==='')return;
    $newRoom=tamasyaR3RoomFromMaintenanceAssetRef($pdo,$requestedAssetRef);
    if($newRoom===''||!hash_equals($oldRoom,$newRoom)){
        throw new RuntimeException('Kepemilikan kamar pada tiket maintenance aktif tidak boleh dipindahkan melalui edit tiket. Buat tiket baru atau gunakan workflow koreksi audit agar housekeeping dan blocker kamar tidak terputus.');
    }
}

function tamasyaR3ActiveMaintenanceTickets(PDO $pdo, string $roomNumber, bool $forUpdate=true): array {
    $roomNumber=trim($roomNumber);if($roomNumber==='')return [];
    $lock=$forUpdate&&$pdo->inTransaction()?' FOR UPDATE':'';
    $stmt=$pdo->prepare("SELECT id,status,asset_ref FROM maintenance_tickets WHERE asset_ref IN (?,?) AND status NOT IN ('completed','cancelled') ORDER BY created_at,id".$lock);
    $stmt->execute([$roomNumber,'room:'.$roomNumber]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

/**
 * A blocked housekeeping task may leave blocked only when every blocking maintenance
 * ticket for the room is terminal. This is the single resolver used by maintenance
 * completion and Manager/Admin cancellation review.
 */
function tamasyaR3ResolveHousekeepingAfterMaintenance(PDO $pdo, array $actor, string $roomNumber, string $source='maintenance-resolver'): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Resolver maintenance/housekeeping wajib berada di transaksi database.');
    $roomNumber=trim($roomNumber);if($roomNumber==='')throw new InvalidArgumentException('Nomor kamar maintenance wajib diisi.');
    $remaining=tamasyaR3ActiveMaintenanceTickets($pdo,$roomNumber,true);
    $activeBookingStmt=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active' ORDER BY id LIMIT 1 FOR UPDATE");
    $activeBookingStmt->execute([$roomNumber]);$activeBookingId=(string)($activeBookingStmt->fetchColumn()?:'');
    if($remaining){
        $projection=tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,$source.'-remaining');
        return ['resolved'=>false,'roomNumber'=>$roomNumber,'activeBookingId'=>$activeBookingId?:null,'remainingMaintenance'=>$remaining,'inspectionTaskId'=>null,'roomProjection'=>$projection];
    }

    $pdo->prepare("UPDATE housekeeping_tasks SET status='inspection',completed_at=NULL,completed_by_staff_id=NULL,last_action_by_staff_id=?,last_action_source=?,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,'Maintenance blocker selesai; kamar wajib inspeksi housekeeping sebelum dijual.'),updated_at=CURRENT_TIMESTAMP WHERE room_number=? AND status='blocked'")
        ->execute([(string)($actor['id']??''),$source,$roomNumber]);

    $inspectionStmt=$pdo->prepare("SELECT id,status FROM housekeeping_tasks WHERE room_number=? AND status NOT IN ('ready','completed') ORDER BY created_at,id LIMIT 1 FOR UPDATE");
    $inspectionStmt->execute([$roomNumber]);$inspection=$inspectionStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$inspection && $activeBookingId===''){
        $inspectionId=generateServerId('hk_maint_qc');
        $pdo->prepare("INSERT INTO housekeeping_tasks (id,room_number,priority,status,checklist,notes,last_action_by_staff_id,last_action_source,created_by,created_at,updated_at) VALUES (?,?,'high','inspection','Verifikasi kebersihan, fungsi fasilitas, dan kesiapan kamar setelah maintenance','Seluruh blocker maintenance terminal; kamar wajib inspeksi housekeeping sebelum dijual.',?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
            ->execute([$inspectionId,$roomNumber,(string)($actor['id']??''),$source,(string)($actor['id']??'')]);
        $inspection=['id'=>$inspectionId,'status'=>'inspection'];
    }
    $projection=tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,$source.'-inspection');
    return ['resolved'=>true,'roomNumber'=>$roomNumber,'activeBookingId'=>$activeBookingId?:null,'remainingMaintenance'=>[],'inspectionTaskId'=>$inspection['id']??null,'roomProjection'=>$projection];
}

/**
 * Legacy self-heal for checkout-damage tickets that were cancelled before the
 * maintenance-cancellation review lifecycle existed. This function never clears
 * damage automatically: it creates the missing Manager/Admin review alert, which
 * remains a blocker until an explicit operational review is completed.
 */
function tamasyaR3EnsureCancelledCheckoutDamageReviewAlerts(PDO $pdo, array $actor, string $roomNumber, string $source='lifecycle-reconcile'): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Rekonsiliasi incident maintenance wajib berada di transaksi database.');
    $roomNumber=trim($roomNumber);if($roomNumber==='')return [];
    // Read-only terhadap alert/tiket terminal: cancellation transaction memakai lock
    // ticket→room. Mengambil lock alert→ticket di sini akan membalik urutan dan dapat
    // memicu deadlock. Snapshot lama tetap fail-safe karena maintenance aktif masih blocker.
    $damageStmt=$pdo->prepare("SELECT id,source,title,message,acknowledged_at FROM system_alerts WHERE source=? AND acknowledged_at IS NULL AND id LIKE 'alert_room_damage_%' ORDER BY created_at,id");
    $damageStmt->execute(['room-damage:'.$roomNumber]);
    $damageRows=$damageStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(!$damageRows)return [];

    $created=[];
    foreach($damageRows as $damageAlert){
        $damageAlertId=(string)($damageAlert['id']??'');
        $ticketId=checkoutDamageTicketIdFromAlertId($damageAlertId);
        if($ticketId==='')continue;
        $ticketStmt=$pdo->prepare("SELECT id,status,asset_ref,title FROM maintenance_tickets WHERE id=? LIMIT 1");
        $ticketStmt->execute([$ticketId]);$ticket=$ticketStmt->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$ticket||strtolower(trim((string)($ticket['status']??'')))!=='cancelled')continue;
        $assetRef=trim((string)($ticket['asset_ref']??''));
        if(!in_array($assetRef,[$roomNumber,'room:'.$roomNumber],true))continue;

        $reviewAlertId=maintenanceCancellationAlertIdForTicket($ticketId,$roomNumber);
        if($reviewAlertId==='')continue;
        $reviewSource='maintenance-cancelled:'.$roomNumber;
        $reviewMessage='Incident kerusakan checkout Kamar '.$roomNumber.' memiliki tiket '.$ticketId.' yang berstatus cancelled. Manager/Admin wajib melakukan review eksplisit terhadap kondisi fisik: pilih aman/tidak perlu pekerjaan bila kondisi memang aman, atau pekerjaan lanjutan bila masih ada kerusakan. Housekeeping tidak boleh membuat kamar siap jual sebelum keputusan review tersimpan.';
        $insert=$pdo->prepare("INSERT INTO system_alerts(id,severity,source,title,message,created_at) VALUES (?,'warning',?,'Review incident maintenance yang dibatalkan',?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE id=id");
        $insert->execute([$reviewAlertId,$reviewSource,$reviewMessage]);
        $review=tamasyaCreateMaintenanceCancellationReview($pdo,$actor,$ticket,$roomNumber,$reviewAlertId,'legacy-self-heal');
        if($review)$pdo->prepare("UPDATE system_alerts SET entity_type='maintenance_cancellation_review',entity_id=? WHERE id=?")->execute([(string)$review['id'],$reviewAlertId]);
        if($insert->rowCount()===1){
            writeRequiredEnterpriseAudit($pdo,$actor,'Membuat review legacy untuk incident checkout-damage dengan tiket cancelled','system_alert',$reviewAlertId,null,[
                'source'=>$reviewSource,'roomNumber'=>$roomNumber,'cancelledTicketId'=>$ticketId,'damageAlertId'=>$damageAlertId,'reviewId'=>$review['id']??null
            ],$source);
            $created[]=$reviewAlertId;
        }
    }
    return $created;
}

/**
 * Resolve the original checkout-damage alert when a Manager/Admin finishes the
 * deterministic review created by cancelling its paired maintenance ticket.
 * Cancellation does not mean "damage fixed"; the dedicated review is the explicit
 * operational decision that supersedes the cancelled work order.
 */
function tamasyaR3ResolveCheckoutDamageAfterCancellationReview(PDO $pdo, array $actor, array $reviewAlert): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Review pembatalan maintenance wajib berada di transaksi database.');
    $source=(string)($reviewAlert['source']??'');
    $prefix='maintenance-cancelled:';
    if(!str_starts_with($source,$prefix))return ['ticketId'=>null,'damageAlertId'=>null];
    $roomNumber=trim(substr($source,strlen($prefix)));
    $reviewAlertId=trim((string)($reviewAlert['id']??''));
    if($roomNumber===''||$reviewAlertId==='')return ['ticketId'=>null,'damageAlertId'=>null];

    $ticketStmt=$pdo->prepare("SELECT id,status,asset_ref FROM maintenance_tickets WHERE asset_ref IN (?,?) AND status='cancelled' ORDER BY updated_at DESC,created_at DESC,id DESC FOR UPDATE");
    $ticketStmt->execute([$roomNumber,'room:'.$roomNumber]);
    $matchedTicketId='';
    foreach($ticketStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $ticket){
        $ticketId=(string)($ticket['id']??'');
        if($ticketId===''||maintenanceCancellationAlertIdForTicket($ticketId,$roomNumber)!==$reviewAlertId)continue;
        $matchedTicketId=$ticketId;
        break;
    }
    if($matchedTicketId===''||checkoutDamageAlertIdFromTicketId($matchedTicketId)===''){
        return ['ticketId'=>$matchedTicketId?:null,'damageAlertId'=>null];
    }

    $damageAlertId=checkoutDamageAlertIdFromTicketId($matchedTicketId);
    $damageStmt=$pdo->prepare("SELECT * FROM system_alerts WHERE id=? AND source=? LIMIT 1 FOR UPDATE");
    $damageStmt->execute([$damageAlertId,'room-damage:'.$roomNumber]);
    $before=$damageStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$before||!empty($before['acknowledged_at']))return ['ticketId'=>$matchedTicketId,'damageAlertId'=>$damageAlertId,'changed'=>false];

    $staffId=(string)($actor['id']??'');
    $pdo->prepare("UPDATE system_alerts SET acknowledged_by=?,acknowledged_at=CURRENT_TIMESTAMP WHERE id=? AND acknowledged_at IS NULL")
        ->execute([$staffId,$damageAlertId]);
    $after=$before;$after['acknowledged_by']=$staffId;$after['acknowledged_at']=date('Y-m-d H:i:s');
    $after['resolved_via']='maintenance-cancellation-review';$after['review_alert_id']=$reviewAlertId;$after['cancelled_ticket_id']=$matchedTicketId;
    writeRequiredEnterpriseAudit($pdo,$actor,'Menyelesaikan incident kerusakan checkout melalui review tiket maintenance yang dibatalkan','system_alert',$damageAlertId,$before,$after,'maintenance-cancel-review');
    return ['ticketId'=>$matchedTicketId,'damageAlertId'=>$damageAlertId,'changed'=>true];
}

/** Manager/Admin acknowledgement is a domain review only for maintenance-cancelled alerts. */
function tamasyaR3ResolveMaintenanceCancellationReview(PDO $pdo, array $actor, array $alert): ?array {
    $source=(string)($alert['source']??'');
    $prefix='maintenance-cancelled:';
    if(!str_starts_with($source,$prefix))return null;
    $roomNumber=trim(substr($source,strlen($prefix)));
    if($roomNumber==='')return null;
    // One review resolves the whole cancelled incident: the dedicated cancellation
    // alert, its paired checkout-damage alert (when applicable), then HK/QC routing.
    $damageResolution=tamasyaR3ResolveCheckoutDamageAfterCancellationReview($pdo,$actor,$alert);
    $housekeeping=tamasyaR3ResolveHousekeepingAfterMaintenance($pdo,$actor,$roomNumber,'maintenance-cancel-review');
    $housekeeping['cancelledIncident']=$damageResolution;
    return $housekeeping;
}

/** Half-open stay windows: checkout exactly at the next check-in is allowed. */
function tamasyaR3StayWindowsOverlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool {
    $a0=strtotime($aStart);$a1=strtotime($aEnd);$b0=strtotime($bStart);$b1=strtotime($bEnd);
    if($a0===false||$a1===false||$b0===false||$b1===false||$a1<=$a0||$b1<=$b0){
        throw new InvalidArgumentException('Periode menginap tidak valid.');
    }
    return $a0<$b1 && $b0<$a1;
}

/**
 * Resolve the persisted booking row through the same canonical date/time contract
 * used by create/edit. This prevents raw legacy scheduled timestamps from owning
 * a different interval than checkIn/checkOut shown in the UI.
 */
function tamasyaR3StoredBookingInventoryWindow(array $booking, string $checkoutTime, string $checkinTime): array {
    $window=resolveHotelBookingStayWindow($booking,$checkoutTime,$checkinTime);
    $rawScheduledIn=trim((string)($booking['scheduledCheckInAt']??''));
    $rawScheduledOut=trim((string)($booking['scheduledCheckOutAt']??''));
    $actualIn=normalizeHotelDateTime($booking['actualCheckInAt']??null);
    $actualOut=normalizeHotelDateTime($booking['actualCheckOutAt']??null);
    $checkoutDue=normalizeHotelDateTime($booking['checkoutDueAt']??null);
    // Preserve the historical SQL precedence: scheduled boundary wins; actual is
    // a fallback only when no scheduled boundary exists.
    if($rawScheduledIn==='' && $actualIn!==null)$window['startAt']=$actualIn;
    if(!(bool)$window['isOpenEnded'] && $rawScheduledOut===''){
        if($actualOut!==null)$window['endAt']=$actualOut;
        elseif($checkoutDue!==null)$window['endAt']=$checkoutDue;
    }
    return $window;
}

/** Pure comparison shared by locked writes and bulk read-only availability. */
function tamasyaR3StayWindowConflictInRows(array $candidates,string $startAt,string $endAt,string $checkoutTime,string $checkinTime): ?array {
    if(strtotime($startAt)===false||strtotime($endAt)===false||strtotime($endAt)<=strtotime($startAt))throw new InvalidArgumentException('Periode menginap tidak valid.');
    foreach($candidates as $candidate){
        try{$window=tamasyaR3StoredBookingInventoryWindow($candidate,$checkoutTime,$checkinTime);}
        catch(InvalidArgumentException $e){throw new RuntimeException('Booking '.(string)($candidate['id']??'-').' memiliki jadwal tersimpan tidak valid: '.$e->getMessage());}
        if(tamasyaR3StayWindowsOverlap($startAt,$endAt,(string)$window['startAt'],(string)$window['endAt'])){
            $candidate['_resolvedStartAt']=$window['startAt'];$candidate['_resolvedEndAt']=$window['endAt'];return $candidate;
        }
    }
    return null;
}

/** One canonical conflict finder for every stay-window mutation channel. */
function tamasyaR3FindStayWindowConflict(PDO $pdo, string $bookingId, string $roomNumber, string $startAt, string $endAt, bool $forUpdate=true): ?array {
    $bookingId=trim($bookingId);$roomNumber=trim($roomNumber);
    if($roomNumber===''||strtotime($startAt)===false||strtotime($endAt)===false||strtotime($endAt)<=strtotime($startAt))throw new InvalidArgumentException('Periode menginap tidak valid.');
    $ops=$pdo->query("SELECT checkin_time,checkout_time FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    $checkinTime=substr((string)($ops['checkin_time']??'14:00:00'),0,8);
    $checkoutTime=substr((string)($ops['checkout_time']??'12:00:00'),0,8);
    $lock=$forUpdate&&$pdo->inTransaction()?' FOR UPDATE':'';
    $sql="SELECT id,guestName,status,checkIn,checkOut,isOpenEnded,stayMode,scheduledCheckInAt,scheduledCheckOutAt,actualCheckInAt,actualCheckOutAt,checkoutDueAt FROM bookings WHERE roomNumber=? AND status IN ('reserved','active')";
    $params=[$roomNumber];
    if($bookingId!==''){$sql.=" AND id<>?";$params[]=$bookingId;}
    $sql.=" ORDER BY checkIn,scheduledCheckInAt,id".$lock;
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    return tamasyaR3StayWindowConflictInRows($stmt->fetchAll(PDO::FETCH_ASSOC)?:[],$startAt,$endAt,$checkoutTime,$checkinTime);
}

/** One overlap rule for every stay-window mutation channel. */
function tamasyaR3AssertStayWindowNoOverlap(PDO $pdo, string $bookingId, string $roomNumber, string $startAt, string $endAt, bool $forUpdate=true): void {
    $conflict=tamasyaR3FindStayWindowConflict($pdo,$bookingId,$roomNumber,$startAt,$endAt,$forUpdate);
    if($conflict){
        throw new RuntimeException('Periode kamar bertabrakan dengan booking '.(string)($conflict['id']??'-').' yang masih reserved/active.');
    }
}

/**
 * One check-in eligibility gate. It is valid for a reserved booking and for a new
 * same-day booking before INSERT (allowNew=true). Mutation remains caller-owned.
 */
function tamasyaR3EvaluateCheckInEligibility(PDO $pdo, array $actor, array $booking, array $options=[]): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Evaluasi check-in wajib berada di transaksi database.');
    $allowNew=!empty($options['allowNew']);
    $bookingId=trim((string)($booking['id']??''));
    $status=strtolower(trim((string)($booking['status']??($allowNew?'reserved':''))));
    if(!$allowNew && $status!=='reserved')throw new RuntimeException('Hanya reservasi berstatus reserved yang dapat masuk workflow check-in.');
    $roomNumber=trim((string)($booking['roomNumber']??''));if($roomNumber==='')throw new InvalidArgumentException('Nomor kamar check-in wajib diisi.');
    $settings=is_array($options['settings']??null)?$options['settings']:($pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[]);
    // FIX28: physical occupancy is an operational mutation, not a payment side-effect.
    // When the hotel requires an open shift for room sale/check-in, enforce it
    // here so create+check-in, reserved->active, Telegram, and API flows share
    // one invariant. Reserved creation itself never calls this eligibility gate.
    $physicalCheckInShiftSessionId=tamasyaRequireOpenShiftForPhysicalCheckIn($pdo,$actor,$settings);
    $checkinTime=substr((string)($settings['checkin_time']??'14:00:00'),0,8);
    $checkoutTime=substr((string)($settings['checkout_time']??'12:00:00'),0,8);
    try{$stay=resolveHotelBookingStayWindow($booking,$checkoutTime,$checkinTime);}catch(InvalidArgumentException $e){throw new RuntimeException('Jadwal reservasi tidak valid: '.$e->getMessage());}
    if((string)$stay['checkIn']>date('Y-m-d'))throw new RuntimeException('Tanggal check-in belum tiba. Ubah tanggal reservasi melalui workflow edit terlebih dahulu bila diperlukan.');
    if((string)$stay['stayMode']==='short_time' && !(bool)$stay['isOpenEnded']){
        if(time()>=strtotime((string)$stay['endAt']))throw new RuntimeException('Jadwal short time sudah berakhir. Perbarui jam reservasi sebelum melakukan check-in.');
    }elseif(!(bool)$stay['isOpenEnded'] && (string)$stay['checkOut']<=date('Y-m-d')){
        throw new RuntimeException('Periode reservasi sudah berakhir. Perbarui tanggal menginap sebelum melakukan check-in.');
    }
    $depositRequired=!empty($booking['securityDepositRequired']);
    $required=max(0.0,round((float)($booking['securityDepositRequiredAmount']??0),2));
    $received=max(0.0,round((float)($booking['securityDepositReceived']??($booking['securityDepositReceivedAmount']??0)),2));
    if($depositRequired&&$required>0&&$received+0.01<$required)throw new RuntimeException('Deposito jaminan belum lengkap. Terima sisa deposito sebelum check-in.');
    $scheduledStartTs=strtotime((string)$stay['startAt']);
    if($scheduledStartTs!==false && time()<$scheduledStartTs && (int)($settings['allow_early_checkin']??1)!==1){
        throw new RuntimeException('Early check-in belum diizinkan oleh kebijakan hotel.');
    }
    $room=is_array($options['room']??null)?$options['room']:null;
    if(!$room){$roomStmt=$pdo->prepare("SELECT * FROM rooms WHERE number=? LIMIT 1 FOR UPDATE");$roomStmt->execute([$roomNumber]);$room=$roomStmt->fetch(PDO::FETCH_ASSOC)?:null;}
    if(!$room)throw new RuntimeException('Kamar check-in tidak ditemukan.');
    $blockers=getRoomOperationalBlockers($pdo,$roomNumber,true);
    $derived=tamasyaDeriveRoomOperationalStatus($blockers);
    if($derived!=='available')throw new DomainException($blockers?roomOperationalBlockerMessage($roomNumber,$blockers):'Kamar belum tersedia untuk check-in aktual.');
    if(strtolower((string)($room['status']??''))!=='available'){
        $projection=tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,'checkin-projection-reconcile');
        if(($projection['roomStatus']??'')!=='available')throw new DomainException($blockers?roomOperationalBlockerMessage($roomNumber,$blockers):'Kamar belum tersedia untuk check-in aktual.');
        $room['status']='available';
    }
    // Actual occupancy begins now, not at the planned clock. This prevents an
    // early check-in from silently overlapping a different reserved time slot.
    $actualStartAt=date('Y-m-d H:i:s');
    if(!(bool)$stay['isOpenEnded'] && strtotime((string)$stay['endAt'])<=strtotime($actualStartAt))throw new RuntimeException('Waktu checkout terjadwal sudah terlewati. Perbarui jadwal sebelum check-in.');
    tamasyaR3AssertStayWindowNoOverlap($pdo,$bookingId,$roomNumber,$actualStartAt,(string)$stay['endAt'],true);
    $otherActive=$pdo->prepare("SELECT id FROM bookings WHERE roomNumber=? AND status='active'".($bookingId!==''?" AND id<>?":"")." ORDER BY id LIMIT 1 FOR UPDATE");
    $otherActive->execute($bookingId!==''?[$roomNumber,$bookingId]:[$roomNumber]);
    if($otherActive->fetchColumn())throw new RuntimeException('Kamar sedang ditempati booking lain.');
    assertRoomCanBecomeAvailable($pdo,$roomNumber,true);
    return ['stayWindow'=>$stay,'room'=>$room,'settings'=>$settings,'shiftSessionId'=>$physicalCheckInShiftSessionId];
}

/** Derive provider credential expiry from the canonical checkout deadline. */
function tamasyaR3ExpectedSmartLockValidUntil(PDO $pdo, string $checkoutDueAt): string {
    $checkoutDueAt=trim($checkoutDueAt);
    if($checkoutDueAt===''||strtotime($checkoutDueAt)===false)throw new RuntimeException('Batas waktu checkout smart-lock tidak valid.');
    $settings=$pdo->query("SELECT late_grace_minutes FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    return date('Y-m-d H:i:s',strtotime($checkoutDueAt)+max(0,(int)($settings['late_grace_minutes']??60))*60);
}

/** Queue refresh of an already-issued smart-lock credential after a stay extension. */
function tamasyaR3QueueSmartLockValidityRefresh(PDO $pdo, array $actor, array $booking, string $newCheckoutDueAt, string $source): ?string {
    if(!$pdo->inTransaction())throw new RuntimeException('Refresh smart-lock wajib berada di transaksi stay-window.');
    if(strtolower((string)($booking['status']??''))!=='active')return null;
    $bookingId=trim((string)($booking['id']??''));$room=trim((string)($booking['roomNumber']??''));if($bookingId===''||$room==='')return null;
    $mode=strtolower(trim((string)($booking['accessMode']??'')));
    if(!in_array($mode,['smart','hybrid'],true))return null;
    $keyStatus=strtolower(trim((string)($booking['keyControlStatus']??'')));
    if($keyStatus==='pending_smart_issue')throw new RuntimeException('Perpanjangan ditunda karena pemberian smart-lock masih menunggu konfirmasi. Selesaikan job akses terlebih dahulu.');
    $storedHash=trim((string)($booking['smartLockCodeHash']??''));
    if($storedHash===''||$keyStatus!=='issued')return null;
    $desired=tamasyaR3ExpectedSmartLockValidUntil($pdo,$newCheckoutDueAt);
    $current=trim((string)($booking['smartLockValidUntil']??''));
    if($current!==''&&strtotime($current)!==false&&abs(strtotime($current)-strtotime($desired))<=1)return null;
    $jobId='lockrefresh_'.substr(hash('sha256',$bookingId.'|'.$newCheckoutDueAt.'|'.$storedHash),0,42);
    $pendingRefreshStmt=$pdo->prepare("SELECT id,status FROM smart_lock_jobs WHERE booking_id=? AND room_number=? AND action='grant' AND id LIKE 'lockrefresh_%' AND status NOT IN ('completed','cancelled') ORDER BY created_at,id LIMIT 1 FOR UPDATE");
    $pendingRefreshStmt->execute([$bookingId,$room]);
    $pendingRefresh=$pendingRefreshStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if($pendingRefresh){
        if((string)$pendingRefresh['id']===$jobId)return $jobId;
        throw new RuntimeException('Perubahan masa inap ditunda karena refresh smart-lock sebelumnya masih belum terminal. Selesaikan/retry job akses terlebih dahulu agar validitas PIN tidak saling menimpa.');
    }
    $jobStmt=$pdo->prepare("SELECT * FROM smart_lock_jobs WHERE booking_id=? AND room_number=? AND action='grant' AND status='completed' ORDER BY completed_at DESC,created_at DESC,id DESC LIMIT 1 FOR UPDATE");
    $jobStmt->execute([$bookingId,$room]);$grantJob=$jobStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$grantJob)throw new RuntimeException('PIN smart-lock aktif tidak mempunyai grant job yang dapat direfresh. Lakukan workflow akses/kunci sebelum memperpanjang masa inap.');
    $grantPayload=tamasyaDecodeSmartLockPayload($grantJob);$code=(string)($grantPayload['code']??'');
    if($code===''||!hash_equals($storedHash,hash('sha256',$code)))throw new RuntimeException('Credential smart-lock aktif tidak cocok dengan histori grant. Perpanjangan dibatalkan agar masa akses tidak salah.');
    $controlStmt=$pdo->prepare("SELECT * FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");$controlStmt->execute([$room]);$control=$controlStmt->fetch(PDO::FETCH_ASSOC)?:[];
    try{
        return queueSmartLockBridgeJob($pdo,$control,[
            'jobId'=>$jobId,'action'=>'grant','roomNumber'=>$room,'bookingId'=>$bookingId,
            'deviceId'=>$control['smart_lock_device_id']??($grantPayload['deviceId']??null),'code'=>$code,
            'credentialLast4'=>substr($code,-4),'validFrom'=>(string)($booking['smartLockValidFrom']??$grantPayload['validFrom']??date('Y-m-d H:i:s')),
            'validUntil'=>$desired,'accessMode'=>$mode,'physicalKeyRef'=>$control['physical_key_ref']??('KEY-'.$room),
            'bookingStateMode'=>'refresh_validity','reason'=>'Refresh masa berlaku smart-lock mengikuti perpanjangan booking.',
            'source'=>$source,'actor'=>tamasyaSmartLockActorSnapshot($actor)
        ]);
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;
        return $jobId;
    }
}

/** Canonical stay-end mutation. Can be used for extension and audited reversal. */
function tamasyaR3ChangeStayEnd(PDO $pdo, array $actor, string $bookingId, string $newCheckOut, string $source='web', bool $allowShorten=false): array {
    if(!$pdo->inTransaction())throw new RuntimeException('Perubahan masa inap wajib berada di transaksi database.');
    $bookingId=trim($bookingId);$newCheckOut=trim($newCheckOut);if($bookingId===''||!validIsoDate($newCheckOut))throw new InvalidArgumentException('Booking/tanggal perubahan masa inap tidak valid.');
    $stmt=$pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$booking)throw new RuntimeException('Booking perubahan masa inap tidak ditemukan.');
    if(!in_array(strtolower((string)($booking['status']??'')),['reserved','active'],true))throw new RuntimeException('Perubahan masa inap hanya berlaku untuk booking reserved/active. Booking terminal dikoreksi melalui workflow audit.');
    $stayMode=strtolower((string)($booking['stayMode']??'overnight'));
    if($stayMode==='open_ended'||!empty($booking['isOpenEnded']))throw new RuntimeException('Booking open-ended tidak memakai perubahan tanggal checkout biasa.');
    if($stayMode==='short_time')throw new RuntimeException('Perubahan short-time harus melalui edit jadwal jam.');
    $oldCheckOut=(string)($booking['checkOut']??'');
    if(!validIsoDate($oldCheckOut))throw new RuntimeException('Tanggal checkout booking sumber tidak valid.');
    if($newCheckOut===$oldCheckOut)return ['bookingBefore'=>$booking,'previousCheckOut'=>$oldCheckOut,'newCheckOut'=>$newCheckOut,'checkoutDueAt'=>$booking['checkoutDueAt']??null,'smartLockRefreshJobId'=>null,'changed'=>false];
    if(!$allowShorten && $newCheckOut<=$oldCheckOut)throw new InvalidArgumentException('Tanggal check-out baru harus lebih besar dari check-out lama.');
    if($newCheckOut<=(string)($booking['checkIn']??''))throw new InvalidArgumentException('Tanggal check-out harus setelah tanggal check-in.');
    $settings=$pdo->query("SELECT checkin_time,checkout_time FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    $checkinTime=substr((string)($settings['checkin_time']??'14:00:00'),0,8);
    $checkoutTime=substr((string)($settings['checkout_time']??'12:00:00'),0,8);
    $startAt=(string)($booking['scheduledCheckInAt']??'')?:((string)$booking['checkIn'].' '.$checkinTime);
    $scheduledOut=trim((string)($booking['scheduledCheckOutAt']??''));
    $newScheduledOut=$scheduledOut!==''?$newCheckOut.' '.substr($scheduledOut,11,8):null;
    $newDue=$newScheduledOut?:($newCheckOut.' '.$checkoutTime);
    tamasyaR3AssertStayWindowNoOverlap($pdo,$bookingId,(string)$booking['roomNumber'],$startAt,$newDue,true);
    $smartJobId=tamasyaR3QueueSmartLockValidityRefresh($pdo,$actor,$booking,$newDue,$source);
    $pdo->prepare("UPDATE bookings SET checkOut=?,scheduledCheckOutAt=?,checkoutDueAt=?,version=version+1,updatedAt=CURRENT_TIMESTAMP,updatedBy=?,updatedSource=? WHERE id=?")
        ->execute([$newCheckOut,$newScheduledOut,$newDue,(string)($actor['id']??''),$source,$bookingId]);
    return ['bookingBefore'=>$booking,'previousCheckOut'=>$oldCheckOut,'newCheckOut'=>$newCheckOut,'checkoutDueAt'=>$newDue,'smartLockRefreshJobId'=>$smartJobId,'changed'=>true];
}

/** Canonical extension mutation used by Telegram, late-checkout and manual allocation. */
function tamasyaR3ExtendStay(PDO $pdo, array $actor, string $bookingId, string $newCheckOut, string $source='web'): array {
    return tamasyaR3ChangeStayEnd($pdo,$actor,$bookingId,$newCheckOut,$source,false);
}
