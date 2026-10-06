<?php
/** TAMASYA V137 R4 — operational domain records and cross-domain links. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/**
 * Flexible quality gate for Lost & Found descriptions.
 * We deliberately do NOT whitelist item categories. A one-word real item such as
 * "charger", "dompet", "AirPods", or a future/unfamiliar item remains valid.
 * Only descriptions made entirely of non-informative workflow words are rejected.
 */
function tamasyaRequireLostFoundItemDetail(string $detail, string $context='Barang Lost & Found'): string {
    $detail=trim((string)preg_replace('/\s+/u',' ',trim($detail)));
    $length=tamasyaStringLength($detail);
    if($length<2)throw new InvalidArgumentException($context.' belum jelas. Sebutkan barang yang ditemukan, misalnya “charger hitam”, “dompet”, atau “tas kecil”.');
    if($length>1000)throw new InvalidArgumentException($context.' maksimal 1.000 karakter.');
    $normalized=function_exists('mb_strtolower')?mb_strtolower($detail,'UTF-8'):strtolower($detail);
    $normalized=trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$normalized));
    $tokens=array_values(array_filter(preg_split('/\s+/u',$normalized)?:[],static fn($token)=>$token!==''));
    $generic=array_fill_keys([
        'barang','item','benda','ada','ditemukan','temuan','tertinggal','ketinggalan','milik','tamu','guest',
        'ini','itu','sini','sana','nya','unknown','tidak','tahu','belum','jelas'
    ],true);
    $meaningful=[];
    foreach($tokens as $token){
        if(isset($generic[$token])||preg_match('/^\d+$/u',$token))continue;
        $meaningful[]=$token;
    }
    if(!$meaningful){
        throw new InvalidArgumentException($context.' terlalu umum. Tulis barangnya secara bebas, misalnya “charger hitam”, “dompet cokelat”, atau “obat dalam pouch”.');
    }
    return $detail;
}


/**
 * Free-text quality gate for security/safety/occupancy field incidents.
 * No incident-type, location, asset, or vocabulary whitelist is used. We only
 * reject text that contains no operational information beyond workflow filler.
 */
function tamasyaRequireOperationalIncidentDetail(string $detail, string $context='Detail insiden operasional'): string {
    $detail=trim((string)preg_replace('/\s+/u',' ',trim($detail)));
    $length=tamasyaStringLength($detail);
    if($length<5)throw new InvalidArgumentException($context.' belum jelas. Jelaskan kondisi/lokasi yang benar-benar ditemukan.');
    if($length>2000)throw new InvalidArgumentException($context.' maksimal 2.000 karakter.');
    $normalized=function_exists('mb_strtolower')?mb_strtolower($detail,'UTF-8'):strtolower($detail);
    $normalized=trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$normalized));
    $tokens=array_values(array_filter(preg_split('/\s+/u',$normalized)?:[],static fn($token)=>$token!==''));
    $generic=array_fill_keys([
        'insiden','incident','kejadian','laporan','masalah','problem','issue','ada','ditemukan','temuan','kondisi',
        'bahaya','danger','darurat','emergency','urgent','segera','tolong','mohon','cek','periksa','ini','itu','sini','sana',
        'hotel','area','tempat','sesuatu','tidak','normal','abnormal','bermasalah'
    ],true);
    $meaningful=[];
    foreach($tokens as $token){if(isset($generic[$token])||preg_match('/^\d+$/u',$token))continue;$meaningful[]=$token;}
    if(!$meaningful)throw new InvalidArgumentException($context.' terlalu umum. Tulis kondisi/lokasi nyata, misalnya “asap dari panel listrik lobby” atau “orang tidak dikenal memaksa masuk gudang”.');
    return $detail;
}


/**
 * Guest-service quality gate. Request categories and vocabulary stay free-text:
 * a hotel may introduce a new amenity/service without a deployment. We only
 * reject text that contains no actionable information beyond workflow filler.
 */
function tamasyaRequireGuestServiceDetail(string $detail, string $context='Detail permintaan tamu'): string {
    $detail=trim((string)preg_replace('/\s+/u',' ',trim($detail)));
    $length=tamasyaStringLength($detail);
    if($length<2)throw new InvalidArgumentException($context.' belum jelas. Tulis kebutuhan tamu, misalnya “2 handuk tambahan”, “wake-up call 05:30”, atau “bantu luggage ke lobby”.');
    if($length>2000)throw new InvalidArgumentException($context.' maksimal 2.000 karakter.');
    $normalized=function_exists('mb_strtolower')?mb_strtolower($detail,'UTF-8'):strtolower($detail);
    $normalized=trim((string)preg_replace('/[^\p{L}\p{N}]+/u',' ',$normalized));
    $tokens=array_values(array_filter(preg_split('/\s+/u',$normalized)?:[],static fn($token)=>$token!==''));
    $generic=array_fill_keys([
        'request','permintaan','layanan','service','tolong','mohon','bantu','bantuan','guest','tamu','ini','itu','sini','sana',
        'ada','butuh','perlu','segera','urgent','urgently','please','pls','help','unknown','lain','lainnya','lainnya'
    ],true);
    $meaningful=[];
    foreach($tokens as $token){if(isset($generic[$token])||preg_match('/^\d+$/u',$token))continue;$meaningful[]=$token;}
    if(!$meaningful)throw new InvalidArgumentException($context.' terlalu umum. Tulis kebutuhan nyata secara bebas, misalnya “handuk tambahan”, “air mineral 2 botol”, atau “wake-up call 05:30”.');
    return $detail;
}

/**
 * Resolve who/where a guest-service request belongs to. Vocabulary stays
 * flexible, but ownership context is canonical and cannot be typed as an
 * arbitrary room number for an in-house guest.
 *
 * New records use one of:
 * - in_house_guest: active booking, canonical room required;
 * - pre_arrival_guest: reserved booking required;
 * - non_room_guest: no booking/room; requester or location required.
 *
 * guest_self_service is intentionally reserved for a future authenticated
 * guest-facing endpoint and is not accepted by the staff operations route.
 */
function tamasyaResolveGuestServiceContext(PDO $pdo, array $actor, string $contextType, string $bookingId='', string $roomNumber='', string $requesterName='', string $requesterContact='', string $locationLabel='', string $requestChannel='staff_recorded', string $neededAt=''): array {
    $contextType=strtolower(trim($contextType));$bookingId=trim($bookingId);$roomNumber=trim($roomNumber);
    $requesterName=trim((string)preg_replace('/\s+/u',' ',$requesterName));$requesterContact=trim((string)preg_replace('/\s+/u',' ',$requesterContact));$locationLabel=trim((string)preg_replace('/\s+/u',' ',$locationLabel));
    $requestChannel=strtolower(trim($requestChannel))?:'staff_recorded';
    if(tamasyaStringLength($requesterName)>150)throw new InvalidArgumentException('Nama/identitas peminta maksimal 150 karakter.');
    if(tamasyaStringLength($requesterContact)>190)throw new InvalidArgumentException('Kontak peminta maksimal 190 karakter.');
    if(tamasyaStringLength($locationLabel)>150)throw new InvalidArgumentException('Lokasi/konteks peminta maksimal 150 karakter.');
    $allowedChannels=['staff_recorded','front_desk','phone','whatsapp','in_person','staff_internal','other'];
    if(!in_array($requestChannel,$allowedChannels,true))throw new InvalidArgumentException('Channel permintaan tamu tidak valid.');
    $neededAt=trim($neededAt);$neededAtSql=null;
    if($neededAt!==''){
        $ts=strtotime($neededAt);if($ts===false)throw new InvalidArgumentException('Waktu kebutuhan layanan tidak valid.');
        $neededAtSql=date('Y-m-d H:i:s',$ts);
    }

    // Compatibility for R4 clients: infer only when there is enough canonical
    // context. Blank booking+room is no longer silently accepted.
    if($contextType===''){
        if($bookingId!==''){
            $q=$pdo->prepare("SELECT id,guestName,guestPhone,guestEmail,roomNumber,status,checkIn,checkOut FROM bookings WHERE id=? LIMIT 1");$q->execute([$bookingId]);$b=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$b)throw new InvalidArgumentException('Booking untuk permintaan tamu tidak ditemukan.');
            $bs=strtolower(trim((string)($b['status']??'')));
            $contextType=$bs==='active'?'in_house_guest':($bs==='reserved'?'pre_arrival_guest':'');
            if($contextType==='')throw new RuntimeException('Booking tersebut tidak lagi aktif/reserved untuk permintaan tamu baru.');
        }elseif($roomNumber!==''){
            $q=$pdo->prepare("SELECT id,guestName,guestPhone,guestEmail,roomNumber,status,checkIn,checkOut FROM bookings WHERE roomNumber=? AND status='active' ORDER BY COALESCE(actualCheckInAt,createdAt) DESC LIMIT 2");$q->execute([$roomNumber]);$matches=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
            if(count($matches)!==1)throw new InvalidArgumentException('Kamar harus memiliki tepat satu booking aktif. Pilih booking/tamu secara eksplisit.');
            $bookingId=(string)$matches[0]['id'];$contextType='in_house_guest';
        }else{
            throw new InvalidArgumentException('Pilih konteks permintaan: tamu menginap, pre-arrival, atau non-kamar/visitor.');
        }
    }
    if($contextType==='guest_self_service')throw new RuntimeException('Guest self-service belum diaktifkan. Gunakan endpoint guest-authenticated khusus pada implementasi mendatang.');
    if(!in_array($contextType,['in_house_guest','pre_arrival_guest','non_room_guest'],true))throw new InvalidArgumentException('Konteks permintaan tamu tidak valid.');
    $actorRole=strtolower(trim((string)($actor['role']??'')));
    if($contextType==='pre_arrival_guest'&&!in_array($actorRole,['admin','manager','receptionist'],true))throw new RuntimeException('Permintaan pre-arrival hanya dapat dicatat Front Office/Manager/Admin.');

    $guestNameSnapshot=null;
    if(in_array($contextType,['in_house_guest','pre_arrival_guest'],true)){
        if($bookingId==='')throw new InvalidArgumentException('Pilih booking/tamu untuk konteks permintaan ini.');
        $q=$pdo->prepare("SELECT id,guestName,guestPhone,guestEmail,roomNumber,status,checkIn,checkOut FROM bookings WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([$bookingId]);$booking=$q->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$booking)throw new InvalidArgumentException('Booking untuk permintaan tamu tidak ditemukan.');
        $status=strtolower(trim((string)($booking['status']??'')));
        $expected=$contextType==='in_house_guest'?'active':'reserved';
        if($status!==$expected)throw new RuntimeException($contextType==='in_house_guest'?'Konteks tamu menginap hanya menerima booking berstatus active.':'Konteks pre-arrival hanya menerima booking berstatus reserved.');
        $canonicalRoom=trim((string)($booking['roomNumber']??''));
        if($canonicalRoom==='')throw new RuntimeException('Booking belum memiliki alokasi kamar canonical.');
        if($roomNumber!==''&&$roomNumber!==$canonicalRoom)throw new InvalidArgumentException('Nomor kamar tidak sesuai dengan booking yang dipilih.');
        $roomNumber=$canonicalRoom;$guestNameSnapshot=trim((string)($booking['guestName']??''))?:null;
        if($requesterName==='')$requesterName=(string)($guestNameSnapshot??'');
        if($contextType==='in_house_guest')requireTelegramRoom($pdo,$roomNumber,true);
    }else{
        if($bookingId!==''||$roomNumber!=='')throw new InvalidArgumentException('Konteks non-kamar/visitor tidak boleh membawa booking atau nomor kamar. Pilih konteks tamu menginap/pre-arrival bila terkait booking.');
        if($requesterName===''&&$locationLabel==='')throw new InvalidArgumentException('Untuk non-kamar/visitor, isi nama/identitas peminta atau lokasi/konteks permintaan.');
    }
    return [
        'contextType'=>$contextType,'bookingId'=>$bookingId,'roomNumber'=>$roomNumber,
        'guestNameSnapshot'=>$guestNameSnapshot,'requesterName'=>$requesterName?:null,'requesterContact'=>$requesterContact?:null,
        'locationLabel'=>$locationLabel?:null,'requestChannel'=>$requestChannel,'neededAt'=>$neededAtSql,
        'contextVerifiedAt'=>date('Y-m-d H:i:s'),'contextVerifiedBy'=>trim((string)($actor['id']??''))?:null,
    ];
}

function tamasyaGuestServiceRequestByIdentifier(PDO $pdo, string $identifier, bool $forUpdate=true): ?array {
    $identifier=trim($identifier);if($identifier===''||!tamasyaTableExists($pdo,'guest_service_requests'))return null;
    $lock=$forUpdate&&$pdo->inTransaction()?' FOR UPDATE':'';
    $stmt=$pdo->prepare("SELECT * FROM guest_service_requests WHERE id=? OR (source_id=? AND source_id IS NOT NULL) ORDER BY CASE WHEN id=? THEN 0 ELSE 1 END LIMIT 1".$lock);
    $stmt->execute([$identifier,$identifier,$identifier]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

/**
 * Non-technical guest request domain. This function intentionally has no room
 * projection and no financial posting: service fulfillment, room readiness,
 * and guest folio charging are separate questions.
 */
function tamasyaCreateGuestServiceRequest(PDO $pdo, array $actor, string $requestType, string $description, string $priority='normal', string $department='', string $bookingId='', string $roomNumber='', string $sourceType='operations_center', string $sourceId='', string $source='guest-service-open', array $context=[]): array {
    if(!tamasyaTableExists($pdo,'guest_service_requests'))throw new RuntimeException('Schema guest_service_requests belum tersedia.');
    $requestType=trim((string)preg_replace('/\s+/u',' ',$requestType));
    if(tamasyaStringLength($requestType)<2||tamasyaStringLength($requestType)>120)throw new InvalidArgumentException('Jenis permintaan tamu wajib 2–120 karakter dan boleh ditulis bebas.');
    $description=tamasyaRequireGuestServiceDetail($description);
    $priority=strtolower(trim($priority));if(!in_array($priority,['normal','high','urgent'],true))throw new InvalidArgumentException('Prioritas permintaan tamu harus normal, high, atau urgent.');
    $department=trim((string)preg_replace('/\s+/u',' ',$department));if(tamasyaStringLength($department)>80)throw new InvalidArgumentException('Nama department maksimal 80 karakter.');
    $sourceType=trim($sourceType)?:'operations_center';$sourceId=trim($sourceId);
    if(tamasyaStringLength($sourceType)>50||tamasyaStringLength($sourceId)>100)throw new InvalidArgumentException('Identitas sumber permintaan tamu terlalu panjang.');
    $resolved=tamasyaResolveGuestServiceContext(
        $pdo,$actor,(string)($context['contextType']??''),$bookingId,$roomNumber,
        (string)($context['requesterName']??''),(string)($context['requesterContact']??''),(string)($context['locationLabel']??''),
        (string)($context['requestChannel']??'staff_recorded'),(string)($context['neededAt']??'')
    );
    $bookingId=(string)$resolved['bookingId'];$roomNumber=(string)$resolved['roomNumber'];
    $id=$sourceId!==''?'gsr_'.substr(hash('sha256',$sourceType.'|'.$sourceId),0,48):generateServerId('gsr');
    $existing=tamasyaGuestServiceRequestByIdentifier($pdo,$id,true);
    if($existing)return $existing+['idempotent'=>true];
    $staffId=trim((string)($actor['id']??''));$staffName=currentStaffLabel($actor);
    $pdo->prepare("INSERT INTO guest_service_requests(id,booking_id,room_number,context_type,guest_name_snapshot,requester_name,requester_contact,location_label,request_channel,needed_at,context_verified_at,context_verified_by,request_type,description,priority,department,status,source_type,source_id,created_by,created_by_name,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'open',?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
        ->execute([$id,$bookingId?:null,$roomNumber?:null,$resolved['contextType'],$resolved['guestNameSnapshot'],$resolved['requesterName'],$resolved['requesterContact'],$resolved['locationLabel'],$resolved['requestChannel'],$resolved['neededAt'],$resolved['contextVerifiedAt'],$resolved['contextVerifiedBy'],$requestType,$description,$priority,$department?:null,$sourceType,$sourceId?:null,$staffId?:null,$staffName]);
    if($bookingId!=='')tamasyaOperationalLink($pdo,'guest_service_request',$id,'booking',$bookingId,'requested_for_booking',$actor);
    if($roomNumber!=='')tamasyaOperationalLink($pdo,'guest_service_request',$id,'room',$roomNumber,'requested_for_room',$actor);
    $stmt=$pdo->prepare("SELECT * FROM guest_service_requests WHERE id=? LIMIT 1");$stmt->execute([$id]);$after=$stmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'status'=>'open'];
    writeRequiredEnterpriseAudit($pdo,$actor,'Membuat permintaan layanan tamu','guest_service_request',$id,null,$after,$source);
    return $after+['idempotent'=>false];
}

/** Build a concise Telegram mirror of the canonical Guest Service lifecycle.
 *  Telegram is alert/coordination only; state mutation remains in the app/API.
 */
function tamasyaGuestServiceTelegramMessage(array $request, string $event, array $actor=[]): string {
    $event=strtolower(trim($event));
    $priority=strtolower(trim((string)($request['priority']??'normal')));
    $priorityLabel=strtoupper($priority?:'normal');
    $icon=$priority==='urgent'?'🚨':($priority==='high'?'⚠️':'🔔');
    $title=$event==='fulfilled'?'PERMINTAAN TAMU SELESAI':($event==='cancelled'?'PERMINTAAN TAMU DIBATALKAN':'PERMINTAAN TAMU BARU');
    $safe=static function($value,$max=300): string {
        if(function_exists('tamasyaTelegramPlainText'))return tamasyaTelegramPlainText($value,$max);
        $text=trim(strip_tags((string)$value));
        if(function_exists('mb_substr'))return mb_substr($text,0,$max);
        return substr($text,0,$max);
    };
    $requestType=$safe($request['request_type']??'Layanan tamu',120);
    $detail=$safe($request['description']??'',420);
    $guest=$safe($request['guest_name_snapshot']??($request['requester_name']??''),120);
    $room=$safe($request['room_number']??'',40);
    $location=$safe($request['location_label']??'',120);
    $department=$safe($request['department']??'',80);
    $needed=$safe($request['needed_at']??'',40);
    $resolution=$safe($request['resolution_note']??'',300);
    $actorName=$safe(currentStaffLabel($actor),120);
    $context=[];
    if($guest!=='')$context[]=$guest;
    if($room!=='')$context[]='Kamar '.$room;
    elseif($location!=='')$context[]=$location;
    $lines=[
        $icon.' *'.$title.'*',
        '',
        'Prioritas: *'.$priorityLabel.'*',
        'Jenis: *'.$requestType.'*',
    ];
    if($context)$lines[]='Konteks: *'.implode(' · ',$context).'*';
    if($department!=='')$lines[]='Tujuan: *'.$department.'*';
    if($needed!=='')$lines[]='Dibutuhkan: `'.$needed.'`';
    if($detail!=='')$lines[]='Detail: '.$detail;
    if($resolution!==''&&in_array($event,['fulfilled','cancelled'],true))$lines[]='Catatan: '.$resolution;
    if($actorName!=='')$lines[]='Petugas: *'.$actorName.'*';
    if($event==='open')$lines[]='Gunakan tombol Telegram untuk *Ambil → Mulai → Selesai*, atau buka *Permintaan Tamu* di aplikasi.';
    return implode("\n",$lines);
}

/** Inline actions for a committed Guest Service request. Callback payloads are
 * deliberately short (<=64 bytes) so they need no token-table write and remain
 * safe across the existing Telegram/standby replication path.
 */
function tamasyaGuestServiceTelegramReplyMarkup(array $request, string $event='open'): ?array {
    $id=trim((string)($request['id']??''));
    if($id===''||strlen($id)>56)return null;
    $event=strtolower(trim($event));
    if(in_array($event,['fulfilled','cancelled'],true))return null;
    return ['inline_keyboard'=>[
        [
            ['text'=>'📥 Ambil','callback_data'=>'gsa:'.$id],
            ['text'=>'📋 Detail','callback_data'=>'gsd:'.$id],
        ],
        [['text'=>'🔔 Antrean Permintaan Tamu','callback_data'=>'gsm:p:1']],
    ]];
}

/**
 * Keep non-terminal Guest Service ownership aligned with canonical booking
 * lifecycle. This never creates a charge or room-readiness blocker.
 */
function tamasyaSyncGuestServiceBookingLifecycle(PDO $pdo, array $actor, string $bookingId, string $bookingStatus, string $roomNumber, string $source='booking-lifecycle'): array {
    $bookingId=trim($bookingId);$bookingStatus=strtolower(trim($bookingStatus));$roomNumber=trim($roomNumber);
    if($bookingId===''||!tamasyaTableExists($pdo,'guest_service_requests'))return ['updated'=>0,'cancelled'=>0];
    if(!in_array($bookingStatus,['reserved','active','completed','cancelled'],true))return ['updated'=>0,'cancelled'=>0];
    $stmt=$pdo->prepare("SELECT * FROM guest_service_requests WHERE booking_id=? AND status IN ('open','assigned','in_progress') ORDER BY created_at,id FOR UPDATE");$stmt->execute([$bookingId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $updated=0;$cancelled=0;$staffId=trim((string)($actor['id']??''));
    foreach($rows as $before){
        $id=(string)($before['id']??'');if($id==='')continue;
        if($bookingStatus==='cancelled'){
            $note='Booking dibatalkan; permintaan layanan yang belum selesai ditutup otomatis agar tidak tertinggal sebagai antrean aktif.';
            $u=$pdo->prepare("UPDATE guest_service_requests SET status='cancelled',cancelled_by=?,cancelled_at=CURRENT_TIMESTAMP,resolution_note=?,context_verified_at=CURRENT_TIMESTAMP,context_verified_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('open','assigned','in_progress')");
            $u->execute([$staffId?:null,$note,$staffId?:null,$id]);
            if($u->rowCount()>0)$cancelled++;
        }else{
            $targetContext=$bookingStatus==='active'?'in_house_guest':($bookingStatus==='reserved'?'pre_arrival_guest':'post_stay_guest');
            $u=$pdo->prepare("UPDATE guest_service_requests SET context_type=?,room_number=COALESCE(NULLIF(?,''),room_number),context_verified_at=CURRENT_TIMESTAMP,context_verified_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('open','assigned','in_progress')");
            $u->execute([$targetContext,$roomNumber,$staffId?:null,$id]);
            if($u->rowCount()>0)$updated++;
            if($roomNumber!==''&&$bookingStatus!=='completed')tamasyaOperationalLink($pdo,'guest_service_request',$id,'room',$roomNumber,'current_service_room',$actor);
        }
        $afterStmt=$pdo->prepare("SELECT * FROM guest_service_requests WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$before;
        if($after!=$before)writeRequiredEnterpriseAudit($pdo,$actor,'Sinkronisasi konteks permintaan tamu dengan lifecycle booking','guest_service_request',$id,$before,$after,$source);
    }
    return ['updated'=>$updated,'cancelled'=>$cancelled];
}

function tamasyaProgressGuestServiceRequest(PDO $pdo, array $actor, string $identifier, string $targetStatus, string $assignedTo='', string $source='guest-service-progress'): array {
    $targetStatus=strtolower(trim($targetStatus));if(!in_array($targetStatus,['assigned','in_progress'],true))throw new InvalidArgumentException('Status proses permintaan harus assigned atau in_progress.');
    $request=tamasyaGuestServiceRequestByIdentifier($pdo,$identifier,true);if(!$request)throw new RuntimeException('Permintaan tamu tidak ditemukan.');
    $state=strtolower(trim((string)($request['status']??'')));$id=(string)$request['id'];
    if(in_array($state,['fulfilled','cancelled'],true))return ['request'=>$request,'idempotent'=>true];
    if($state===$targetStatus)return ['request'=>$request,'idempotent'=>true];
    if($state==='in_progress'&&$targetStatus==='assigned')throw new RuntimeException('Permintaan yang sudah dikerjakan tidak dapat dikembalikan ke assigned.');
    $staffId=trim((string)($actor['id']??''));$assignedTo=trim($assignedTo)?:$staffId;
    if($targetStatus==='assigned'){
        $pdo->prepare("UPDATE guest_service_requests SET status='assigned',assigned_to=?,assigned_at=COALESCE(assigned_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='open'")->execute([$assignedTo?:null,$id]);
    }else{
        $pdo->prepare("UPDATE guest_service_requests SET status='in_progress',assigned_to=COALESCE(NULLIF(?,''),assigned_to),assigned_at=COALESCE(assigned_at,CURRENT_TIMESTAMP),started_at=COALESCE(started_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('open','assigned','in_progress')")->execute([$assignedTo,$id]);
    }
    $stmt=$pdo->prepare("SELECT * FROM guest_service_requests WHERE id=? LIMIT 1");$stmt->execute([$id]);$after=$stmt->fetch(PDO::FETCH_ASSOC)?:$request;
    writeRequiredEnterpriseAudit($pdo,$actor,'Memproses permintaan layanan tamu: '.$targetStatus,'guest_service_request',$id,$request,$after,$source);
    return ['request'=>$after,'idempotent'=>false];
}

function tamasyaCloseGuestServiceRequest(PDO $pdo, array $actor, string $identifier, string $targetStatus, string $resolutionNote, string $source='guest-service-close'): array {
    $targetStatus=strtolower(trim($targetStatus));if(!in_array($targetStatus,['fulfilled','cancelled'],true))throw new InvalidArgumentException('Status terminal permintaan harus fulfilled atau cancelled.');
    $resolutionNote=trim((string)preg_replace('/\s+/u',' ',trim($resolutionNote)));if(tamasyaStringLength($resolutionNote)<3)throw new InvalidArgumentException('Catatan penyelesaian/pembatalan minimal 3 karakter.');
    if(tamasyaStringLength($resolutionNote)>2000)throw new InvalidArgumentException('Catatan penyelesaian maksimal 2.000 karakter.');
    $request=tamasyaGuestServiceRequestByIdentifier($pdo,$identifier,true);if(!$request)throw new RuntimeException('Permintaan tamu tidak ditemukan.');
    $state=strtolower(trim((string)($request['status']??'')));$id=(string)$request['id'];
    if($state===$targetStatus)return ['request'=>$request,'idempotent'=>true];
    if(in_array($state,['fulfilled','cancelled'],true))throw new RuntimeException('Permintaan tamu sudah terminal dengan status '.$state.'.');
    $staffId=trim((string)($actor['id']??''));
    if($targetStatus==='fulfilled'){
        $pdo->prepare("UPDATE guest_service_requests SET status='fulfilled',fulfilled_by=?,fulfilled_at=CURRENT_TIMESTAMP,resolution_note=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('open','assigned','in_progress')")->execute([$staffId?:null,$resolutionNote,$id]);
    }else{
        $pdo->prepare("UPDATE guest_service_requests SET status='cancelled',cancelled_by=?,cancelled_at=CURRENT_TIMESTAMP,resolution_note=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('open','assigned','in_progress')")->execute([$staffId?:null,$resolutionNote,$id]);
    }
    $stmt=$pdo->prepare("SELECT * FROM guest_service_requests WHERE id=? LIMIT 1");$stmt->execute([$id]);$after=$stmt->fetch(PDO::FETCH_ASSOC)?:$request;
    writeRequiredEnterpriseAudit($pdo,$actor,$targetStatus==='fulfilled'?'Menyelesaikan permintaan layanan tamu':'Membatalkan permintaan layanan tamu','guest_service_request',$id,$request,$after,$source);
    return ['request'=>$after,'idempotent'=>false];
}

function tamasyaOperationalIncidentByIdentifier(PDO $pdo, string $identifier, bool $forUpdate=true): ?array {
    $identifier=trim($identifier);if($identifier===''||!tamasyaTableExists($pdo,'operational_incidents'))return null;
    $lock=$forUpdate&&$pdo->inTransaction()?' FOR UPDATE':'';
    $stmt=$pdo->prepare("SELECT * FROM operational_incidents WHERE id=? OR (source_id=? AND source_id IS NOT NULL) ORDER BY CASE WHEN id=? THEN 0 ELSE 1 END LIMIT 1".$lock);
    $stmt->execute([$identifier,$identifier,$identifier]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

/** Security/safety/occupancy exception domain. Never use this for technical repair, Lost & Found, or Key Control. */
function tamasyaCreateOperationalIncident(PDO $pdo, array $actor, string $incidentType, string $description, string $severity='warning', string $roomNumber='', string $areaRef='', bool $blocksRoom=false, string $sourceType='manual', string $sourceId='', string $source='operational-incident'): array {
    if(!tamasyaTableExists($pdo,'operational_incidents'))throw new RuntimeException('Schema operational_incidents belum tersedia.');
    $incidentType=trim((string)preg_replace('/\s+/u',' ',$incidentType));$description=tamasyaRequireOperationalIncidentDetail($description);
    $roomNumber=trim($roomNumber);$areaRef=trim($areaRef);$sourceType=trim($sourceType)?:'manual';$sourceId=trim($sourceId);
    if(tamasyaStringLength($incidentType)<2||tamasyaStringLength($incidentType)>120)throw new InvalidArgumentException('Jenis insiden wajib 2–120 karakter dan boleh ditulis bebas.');
    $severity=strtolower(trim($severity));if(!in_array($severity,['info','warning','critical','emergency'],true))throw new InvalidArgumentException('Severity harus info, warning, critical, atau emergency.');
    if($blocksRoom&&$roomNumber==='')throw new InvalidArgumentException('Insiden yang memblokir kamar wajib memiliki nomor kamar.');
    if($roomNumber===''&&$areaRef==='')$areaRef='area umum';
    $id=$sourceId!==''?'opinc_'.substr(hash('sha256',$sourceType.'|'.$sourceId),0,48):generateServerId('opinc');
    $existing=tamasyaOperationalIncidentByIdentifier($pdo,$id,true);
    if($existing)return $existing+['idempotent'=>true];
    $staffId=trim((string)($actor['id']??''));$staffName=currentStaffLabel($actor);
    $pdo->prepare("INSERT INTO operational_incidents(id,incident_type,description,severity,status,room_number,area_ref,blocks_room,source_type,source_id,reported_by,reported_by_name,reported_at,created_at,updated_at) VALUES (?,?,?,?,'open',?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
        ->execute([$id,$incidentType,$description,$severity,$roomNumber?:null,$areaRef?:null,$blocksRoom?1:0,$sourceType,$sourceId?:null,$staffId?:null,$staffName]);
    $stmt=$pdo->prepare("SELECT * FROM operational_incidents WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$after=$stmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'status'=>'open','room_number'=>$roomNumber];
    if($blocksRoom){
        $hold=tamasyaOpenRoomOperationalHold($pdo,$actor,$roomNumber,'incident:'.$incidentType,$description,'operational_incident',$id,$severity==='emergency'?'critical':$severity,$source);
        $pdo->prepare("UPDATE operational_incidents SET hold_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(string)$hold['id'],$id]);
        tamasyaOperationalLink($pdo,'operational_incident',$id,'room_operational_hold',(string)$hold['id'],'blocks_room_via_hold',$actor);
        writeRequiredEnterpriseAudit($pdo,$actor,'Membuka room hold dari insiden operasional','room_operational_hold',(string)$hold['id'],null,$hold,$source);
        $after['hold_id']=(string)$hold['id'];
    }
    writeRequiredEnterpriseAudit($pdo,$actor,'Membuat insiden operasional '.$incidentType,'operational_incident',$id,null,$after,$source);
    return $after+['idempotent'=>false];
}

function tamasyaProgressOperationalIncident(PDO $pdo, array $actor, string $identifier, string $assignedTo='', string $source='operational-incident-progress'): array {
    $incident=tamasyaOperationalIncidentByIdentifier($pdo,$identifier,true);if(!$incident)throw new RuntimeException('Insiden operasional tidak ditemukan.');
    $state=strtolower(trim((string)($incident['status']??'')));if($state==='resolved')return ['incident'=>$incident,'idempotent'=>true];
    $id=(string)$incident['id'];$staffId=trim((string)($actor['id']??''));$assignedTo=trim($assignedTo)?:$staffId;
    $pdo->prepare("UPDATE operational_incidents SET status='in_progress',assigned_to=?,acknowledged_by=COALESCE(acknowledged_by,?),acknowledged_at=COALESCE(acknowledged_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('open','in_progress')")
        ->execute([$assignedTo?:null,$staffId?:null,$id]);
    $stmt=$pdo->prepare("SELECT * FROM operational_incidents WHERE id=? LIMIT 1");$stmt->execute([$id]);$after=$stmt->fetch(PDO::FETCH_ASSOC)?:$incident;
    writeRequiredEnterpriseAudit($pdo,$actor,'Mengambil tindak lanjut insiden operasional','operational_incident',$id,$incident,$after,$source);
    return ['incident'=>$after,'idempotent'=>$state==='in_progress'];
}

function tamasyaResolveOperationalIncident(PDO $pdo, array $actor, string $identifier, string $resolutionNote, string $source='operational-incident-resolve'): array {
    $resolutionNote=trim($resolutionNote);if(tamasyaStringLength($resolutionNote)<5)throw new InvalidArgumentException('Catatan penyelesaian insiden minimal 5 karakter.');
    $incident=tamasyaOperationalIncidentByIdentifier($pdo,$identifier,true);if(!$incident)throw new RuntimeException('Insiden operasional tidak ditemukan.');
    $id=(string)$incident['id'];$roomNumber=trim((string)($incident['room_number']??''));$state=strtolower(trim((string)($incident['status']??'')));
    if($state==='resolved'){
        if($roomNumber!=='')tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,'operational-incident-resolve-idempotent');
        return ['incident'=>$incident,'idempotent'=>true];
    }
    $staffId=trim((string)($actor['id']??''));$holdId=trim((string)($incident['hold_id']??''));
    if($holdId!==''&&tamasyaTableExists($pdo,'room_operational_holds')){
        $h=$pdo->prepare("SELECT * FROM room_operational_holds WHERE id=? LIMIT 1 FOR UPDATE");$h->execute([$holdId]);$hold=$h->fetch(PDO::FETCH_ASSOC)?:null;
        if($hold&&strtolower(trim((string)($hold['status']??'')))==='open'){
            $pdo->prepare("UPDATE room_operational_holds SET status='released',released_by=?,released_at=CURRENT_TIMESTAMP,release_note=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='open'")->execute([$staffId?:null,$resolutionNote,$holdId]);
            $holdAfter=$hold;$holdAfter['status']='released';$holdAfter['release_note']=$resolutionNote;
            writeRequiredEnterpriseAudit($pdo,$actor,'Melepas room hold melalui penyelesaian insiden','room_operational_hold',$holdId,$hold,$holdAfter,$source);
        }
    }
    $pdo->prepare("UPDATE operational_incidents SET status='resolved',resolution_note=?,resolved_by=?,resolved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ('open','in_progress')")
        ->execute([$resolutionNote,$staffId?:null,$id]);
    $stmt=$pdo->prepare("SELECT * FROM operational_incidents WHERE id=? LIMIT 1");$stmt->execute([$id]);$after=$stmt->fetch(PDO::FETCH_ASSOC)?:$incident;
    $projection=$roomNumber!==''?tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,$source):null;
    writeRequiredEnterpriseAudit($pdo,$actor,'Menyelesaikan insiden operasional','operational_incident',$id,$incident,$projection?array_merge($after,['roomProjection'=>$projection]):$after,$source);
    return ['incident'=>$after,'roomProjection'=>$projection,'idempotent'=>false];
}

/** Cross-domain relation only. It never becomes lifecycle source-of-truth. */
function tamasyaOperationalLink(PDO $pdo, string $fromType, string $fromId, string $toType, string $toId, string $relation, array $actor=[]): ?string {
    foreach([&$fromType,&$fromId,&$toType,&$toId,&$relation] as &$value)$value=trim((string)$value);unset($value);
    if($fromType===''||$fromId===''||$toType===''||$toId===''||$relation==='')return null;
    if(!tamasyaTableExists($pdo,'operational_entity_links'))return null;
    $id='oplink_'.substr(hash('sha256',$fromType.'|'.$fromId.'|'.$toType.'|'.$toId.'|'.$relation),0,48);
    $pdo->prepare("INSERT INTO operational_entity_links(id,from_entity_type,from_entity_id,to_entity_type,to_entity_id,relation,created_by,created_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE id=id")
        ->execute([$id,$fromType,$fromId,$toType,$toId,$relation,trim((string)($actor['id']??''))?:null]);
    return $id;
}

/** Create/update one Lost & Found custody case for an operational finding. */
function tamasyaUpsertLostFoundCase(PDO $pdo, array $actor, string $caseId, string $alertId, string $bookingId, string $roomNumber, string $itemDescription, string $sourceType, string $sourceId='', string $source='web'): array {
    $caseId=trim($caseId);$alertId=trim($alertId);$bookingId=trim($bookingId);$roomNumber=trim($roomNumber);
    $itemDescription=tamasyaRequireLostFoundItemDetail($itemDescription);
    if($caseId===''||$roomNumber==='')throw new InvalidArgumentException('Identitas Lost & Found/kamar tidak valid.');
    if(!tamasyaTableExists($pdo,'lost_found_items')){
        return ['id'=>$caseId,'alert_id'=>$alertId,'room_number'=>$roomNumber,'custody_status'=>'legacy_alert_only','item_description'=>$itemDescription];
    }
    $stmt=$pdo->prepare("SELECT * FROM lost_found_items WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$caseId]);$before=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if($before && !in_array(strtolower(trim((string)($before['custody_status']??''))),['found','secured','guest_notified'],true)){
        // Terminal custody is immutable. A late/new finding gets a distinct case id from caller.
        throw new RuntimeException('Kasus Lost & Found ini sudah terminal. Buat incident baru untuk temuan berikutnya.');
    }
    $foundBy=trim((string)($actor['id']??''));$foundByName=currentStaffLabel($actor);
    $pdo->prepare("INSERT INTO lost_found_items
        (id,alert_id,booking_id,room_number,item_description,source_type,source_id,custody_status,found_at,found_by,found_by_name,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,'found',CURRENT_TIMESTAMP,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE item_description=VALUES(item_description),source_type=VALUES(source_type),source_id=VALUES(source_id),updated_at=CURRENT_TIMESTAMP")
        ->execute([$caseId,$alertId?:null,$bookingId?:null,$roomNumber,$itemDescription,trim($sourceType)?:'operational',trim($sourceId)?:null,$foundBy?:null,$foundByName]);
    $afterStmt=$pdo->prepare("SELECT * FROM lost_found_items WHERE id=? LIMIT 1");$afterStmt->execute([$caseId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$caseId,'room_number'=>$roomNumber,'custody_status'=>'found'];
    writeRequiredEnterpriseAudit($pdo,$actor,$before?'Memperbarui detail Lost & Found':'Membuat kasus Lost & Found','lost_found_item',$caseId,$before,$after,$source);
    return $after;
}

function tamasyaLostFoundByIdentifier(PDO $pdo, string $identifier, bool $forUpdate=true): ?array {
    $identifier=trim($identifier);if($identifier===''||!tamasyaTableExists($pdo,'lost_found_items'))return null;
    $lock=$forUpdate&&$pdo->inTransaction()?' FOR UPDATE':'';
    $stmt=$pdo->prepare("SELECT * FROM lost_found_items WHERE id=? OR alert_id=? ORDER BY CASE WHEN id=? THEN 0 ELSE 1 END LIMIT 1".$lock);
    $stmt->execute([$identifier,$identifier,$identifier]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

/**
 * Canonical projection of a Lost & Found custody row into its system-alert
 * representation. The alert is a workflow projection only; custody_status in
 * lost_found_items remains the lifecycle source of truth.
 */
function tamasyaLostFoundAlertProjection(array $item): array {
    $room=trim((string)($item['room_number']??''));
    $state=strtolower(trim((string)($item['custody_status']??'found')));
    $storage=trim((string)($item['storage_location']??''));
    $resolutionNote=trim((string)($item['resolution_note']??''));
    $terminal=in_array($state,['returned','disposed','donated','authority'],true);
    if($state==='found'){
        return [
            'source'=>'lost-found:'.$room,
            'title'=>'Barang tertinggal - blocker kamar',
            'message'=>'Kamar '.$room.' masih diblokir karena barang tamu belum diamankan dari kamar. Amankan barang dan catat lokasi penyimpanan untuk melepas blocker kamar.',
            'terminal'=>false,
        ];
    }
    if($state==='secured'){
        return [
            'source'=>'lost-found-custody:'.$room,
            'title'=>'Barang tamu diamankan - custody masih terbuka',
            'message'=>'Barang kamar '.$room.' sudah diamankan'.($storage!==''?' di '.$storage:'').'. Blocker kamar sudah dilepas, tetapi custody belum selesai. Beri tahu tamu atau selesaikan custody sebagai returned/disposed/donated/authority.',
            'terminal'=>false,
        ];
    }
    if($state==='guest_notified'){
        return [
            'source'=>'lost-found-custody:'.$room,
            'title'=>'Tamu sudah diberi tahu - custody masih terbuka',
            'message'=>'Tamu untuk barang Lost & Found kamar '.$room.' sudah diberi tahu. Peringatan tetap aktif sampai custody diselesaikan sebagai returned/disposed/donated/authority.',
            'terminal'=>false,
        ];
    }
    if($terminal){
        $labels=['returned'=>'dikembalikan','disposed'=>'dimusnahkan/dibuang','donated'=>'didonasikan','authority'=>'diserahkan ke pihak berwenang'];
        return [
            'source'=>'lost-found-custody:'.$room,
            'title'=>'Custody Lost & Found selesai - '.($labels[$state]??$state),
            'message'=>'Custody barang Lost & Found kamar '.$room.' sudah terminal'.($resolutionNote!==''?': '.$resolutionNote:'.'),
            'terminal'=>true,
        ];
    }
    return [
        'source'=>'lost-found-custody:'.$room,
        'title'=>'Custody Lost & Found - '.$state,
        'message'=>'Custody Lost & Found kamar '.$room.' berada pada status '.$state.'. Tinjau kasus pada menu Lost & Found.',
        'terminal'=>false,
    ];
}

/** Keep persisted system alert synchronized with canonical Lost & Found state. */
function tamasyaSyncLostFoundAlertProjection(PDO $pdo, array $item, string $actorId=''): void {
    $alertId=trim((string)($item['alert_id']??''));
    $itemId=trim((string)($item['id']??''));
    if($alertId===''||$itemId===''||!tamasyaTableExists($pdo,'system_alerts'))return;
    $projection=tamasyaLostFoundAlertProjection($item);
    if(!empty($projection['terminal'])){
        $pdo->prepare("UPDATE system_alerts SET source=?,title=?,message=?,entity_type='lost_found_item',entity_id=?,acknowledged_by=COALESCE(acknowledged_by,?),acknowledged_at=COALESCE(acknowledged_at,CURRENT_TIMESTAMP) WHERE id=?")
            ->execute([(string)$projection['source'],(string)$projection['title'],(string)$projection['message'],$itemId,$actorId!==''?$actorId:null,$alertId]);
        return;
    }
    $pdo->prepare("UPDATE system_alerts SET source=?,title=?,message=?,entity_type='lost_found_item',entity_id=?,acknowledged_by=NULL,acknowledged_at=NULL WHERE id=?")
        ->execute([(string)$projection['source'],(string)$projection['title'],(string)$projection['message'],$itemId,$alertId]);
}

function tamasyaCreateMaintenanceCancellationReview(PDO $pdo, array $actor, array $ticket, string $roomNumber, string $alertId, string $source='maintenance-cancelled'): ?array {
    if(!tamasyaTableExists($pdo,'maintenance_cancellation_reviews'))return null;
    $ticketId=trim((string)($ticket['id']??''));$roomNumber=trim($roomNumber);$alertId=trim($alertId);
    if($ticketId===''||$roomNumber==='')return null;
    $id='mcr_'.substr(hash('sha256',$ticketId.'|'.$roomNumber),0,48);
    $stmt=$pdo->prepare("SELECT * FROM maintenance_cancellation_reviews WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$before=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    $pdo->prepare("INSERT INTO maintenance_cancellation_reviews
        (id,alert_id,ticket_id,room_number,status,created_by,source,created_at,updated_at)
        VALUES (?,?,?,?,'open',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE alert_id=COALESCE(maintenance_cancellation_reviews.alert_id,VALUES(alert_id)),ticket_id=VALUES(ticket_id),room_number=VALUES(room_number),updated_at=CURRENT_TIMESTAMP")
        ->execute([$id,$alertId?:null,$ticketId,$roomNumber,trim((string)($actor['id']??''))?:null,$source]);
    $afterStmt=$pdo->prepare("SELECT * FROM maintenance_cancellation_reviews WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$before&&$after)writeRequiredEnterpriseAudit($pdo,$actor,'Membuat review pembatalan maintenance','maintenance_cancellation_review',$id,null,$after,$source);
    return $after;
}

function tamasyaMaintenanceCancellationReviewByIdentifier(PDO $pdo, string $identifier, bool $forUpdate=true): ?array {
    $identifier=trim($identifier);if($identifier===''||!tamasyaTableExists($pdo,'maintenance_cancellation_reviews'))return null;
    $lock=$forUpdate&&$pdo->inTransaction()?' FOR UPDATE':'';
    $stmt=$pdo->prepare("SELECT * FROM maintenance_cancellation_reviews WHERE id=? OR alert_id=? OR ticket_id=? ORDER BY CASE WHEN id=? THEN 0 WHEN alert_id=? THEN 1 ELSE 2 END LIMIT 1".$lock);
    $stmt->execute([$identifier,$identifier,$identifier,$identifier,$identifier]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function tamasyaResolveMaintenanceCancellationReviewRecord(PDO $pdo, array $actor, string $identifier, string $decision, string $note, string $source='maintenance-cancellation-review'): array {
    $decision=strtolower(trim($decision));$note=trim($note);
    $allowed=['safe_no_work'=>'Kondisi aman / tidak perlu pekerjaan lanjutan','followup_required'=>'Masih perlu pekerjaan lanjutan'];
    if(!isset($allowed[$decision]))throw new InvalidArgumentException('Keputusan review harus safe_no_work atau followup_required.');
    if(tamasyaStringLength($note)<5)throw new InvalidArgumentException('Catatan hasil review minimal 5 karakter.');
    $review=tamasyaMaintenanceCancellationReviewByIdentifier($pdo,$identifier,true);
    if(!$review)throw new RuntimeException('Review pembatalan maintenance tidak ditemukan pada domain record.');
    if(strtolower(trim((string)($review['status']??'')))==='resolved')return ['review'=>$review,'idempotent'=>true];
    $id=(string)$review['id'];$staffId=trim((string)($actor['id']??''));
    $pdo->prepare("UPDATE maintenance_cancellation_reviews SET status='resolved',decision=?,resolution_note=?,resolved_by=?,resolved_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status='open'")
        ->execute([$decision,$note,$staffId?:null,$id]);
    $afterStmt=$pdo->prepare("SELECT * FROM maintenance_cancellation_reviews WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:$review;
    writeRequiredEnterpriseAudit($pdo,$actor,'Menyelesaikan review pembatalan maintenance: '.$allowed[$decision],'maintenance_cancellation_review',$id,$review,$after,$source);
    return ['review'=>$after,'idempotent'=>false];
}

function tamasyaOpenRoomOperationalHold(PDO $pdo, array $actor, string $roomNumber, string $holdType, string $reason, string $sourceEntityType='', string $sourceEntityId='', string $severity='critical', string $source='operational-hold'): array {
    if(!tamasyaTableExists($pdo,'room_operational_holds'))throw new RuntimeException('Schema room_operational_holds belum tersedia.');
    $roomNumber=trim($roomNumber);$holdType=trim($holdType);$reason=trim($reason);
    if($roomNumber===''||$holdType===''||tamasyaStringLength($reason)<5)throw new InvalidArgumentException('Hold kamar membutuhkan tipe dan alasan yang jelas.');
    $baseId='hold_'.substr(hash('sha256',$roomNumber.'|'.$holdType.'|'.$sourceEntityType.'|'.$sourceEntityId.'|'.$reason),0,48);
    $existingStmt=$pdo->prepare("SELECT * FROM room_operational_holds WHERE id=? LIMIT 1 FOR UPDATE");
    $existingStmt->execute([$baseId]);$existing=$existingStmt->fetch(PDO::FETCH_ASSOC)?:null;
    // Same open hold is an idempotent retry. A hold that was already released is historical
    // and must never be silently re-opened; a genuine new occurrence gets a new identity.
    if($existing && strtolower(trim((string)($existing['status']??'')))==='open')return $existing;
    $id=$existing?generateServerId('hold'):$baseId;
    $pdo->prepare("INSERT INTO room_operational_holds(id,room_number,hold_type,severity,reason,source_entity_type,source_entity_id,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'open',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE id=id")
        ->execute([$id,$roomNumber,$holdType,$severity,$reason,trim($sourceEntityType)?:null,trim($sourceEntityId)?:null,trim((string)($actor['id']??''))?:null]);
    tamasyaOperationalLink($pdo,$sourceEntityType,$sourceEntityId,'room_operational_hold',$id,'creates_hold',$actor);
    $stmt=$pdo->prepare("SELECT * FROM room_operational_holds WHERE id=? LIMIT 1");$stmt->execute([$id]);return $stmt->fetch(PDO::FETCH_ASSOC)?:['id'=>$id,'room_number'=>$roomNumber,'status'=>'open'];
}
