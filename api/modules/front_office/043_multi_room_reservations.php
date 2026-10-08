<?php
/** Operational group orchestration. Canonical booking/payment engines own each child. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaMultiRoomRequire(PDO $pdo,array $actor,bool $write=false): void {
    $roles=$write?['admin','manager','receptionist']:['admin','manager','receptionist','finance','owner'];
    if(!in_array(strtolower((string)($actor['role']??'')),$roles,true)||!hasDesktopTabAccess($actor,'rooms',$roles))throw new DomainException('Akses reservasi grup tidak diizinkan.',403);
    tamasyaGrowthRequireReady($pdo,'group');
    if($write)tamasyaRequirePropertyReadyForLiveMutation($pdo,'reservasi grup');
}
function tamasyaMultiRoomStatus(array $bookings): array {
    $counts=['reserved'=>0,'active'=>0,'completed'=>0,'cancelled'=>0];
    foreach($bookings as $b){$s=(string)($b['status']??'');if(isset($counts[$s]))$counts[$s]++;}
    $live=$counts['reserved']+$counts['active']+$counts['completed'];
    $status=$live===0?'cancelled':($counts['completed']===$live?'completed':($counts['completed']>0?'partially_checked_out':($counts['active']===$live?'checked_in':($counts['active']>0?'partially_checked_in':'reserved'))));
    return ['status'=>$status,'counts'=>$counts,'bookingCount'=>count($bookings)];
}
function tamasyaMultiRoomDetail(PDO $pdo,string $id): array {
    $group=tamasyaEnterpriseFetch($pdo,'SELECT * FROM growth_group_reservations WHERE id=?',[$id]);
    if(!$group)throw new InvalidArgumentException('Reservasi grup tidak ditemukan.');
    $meta=json_decode((string)($group['master_notes']??''),true);$group['contact']=is_array($meta)&&($meta['format']??'')==='multi-room-v1'?$meta:['notes'=>$group['master_notes']??''];
    $children=tamasyaEnterpriseFetchAll($pdo,'SELECT l.id linkId,l.billing_mode,l.routed_percent,b.id,b.guestName,b.guestPhone,b.roomNumber,b.roomType,b.checkIn,b.checkOut,b.status,b.totalAmount,b.discountAmount,b.amountPaid,b.balanceDue,b.paymentStatus,b.bookingSource,b.version FROM growth_group_booking_links l JOIN bookings b ON b.id=l.booking_id WHERE l.group_id=? ORDER BY b.roomNumber,b.id',[$id]);
    $total=0;$paid=0;$balance=0;
    foreach($children as $b){$paid+=(float)$b['amountPaid'];$balance+=(float)$b['balanceDue'];if($b['status']!=='cancelled')$total+=max(0,(float)$b['totalAmount']-(float)$b['discountAmount']);}
    $folios=[];if(tamasyaEnterpriseModuleEnabled('folio')&&tamasyaEnterpriseSchemaReady($pdo)){
        $rows=tamasyaEnterpriseFetchAll($pdo,'SELECT id FROM growth_folios WHERE group_id=? AND status<>\'void\' ORDER BY created_at',[$id]);
        foreach($rows as $r)$folios[]=tamasyaEnterpriseFolioDetail($pdo,$r['id']);
    }
    return ['group'=>$group,'bookings'=>$children,'lifecycle'=>tamasyaMultiRoomStatus($children),'totals'=>['total'=>round($total,2),'paid'=>round($paid,2),'balance'=>round($balance,2)],'folios'=>$folios];
}
/** Booking source identifies the sales channel, independently of payment method. */
function tamasyaMultiRoomNormalizeSource(string $source): string {
    $source=trim($source);
    if(!preg_match('/^.{1,100}$/us',$source)||preg_match('/[\x00-\x1F\x7F]/',$source))throw new InvalidArgumentException('Pilih sumber booking atau isi nama sumber lain (maksimal 100 karakter).');
    if(strtolower($source)==='ota')throw new InvalidArgumentException('Pilih nama OTA, misalnya Traveloka/Agoda; OTA bukan nama sumber booking.');
    return $source;
}
function tamasyaMultiRoomSourceOptions(array $rules): array {
    $options=['Direct','Website','Traveloka','Booking.com','Tiket.com','Agoda','Airbnb'];
    $seen=array_fill_keys(array_map('strtolower',$options),true);
    foreach($rules as $rule){
        $source=trim((string)($rule['source_pattern']??''));$key=strtolower($source);
        if($key===''||$key==='*'||$key==='ota'||isset($seen[$key]))continue;
        try{$source=tamasyaMultiRoomNormalizeSource($source);}catch(InvalidArgumentException $e){continue;}
        $options[]=$source;$seen[$key]=true;
    }
    return $options;
}
function tamasyaMultiRoomBookingSources(PDO $pdo): array {
    return tamasyaMultiRoomSourceOptions($pdo->query("SELECT source_pattern FROM tax_rules WHERE is_active=1 AND transaction_kind IN ('room','*') ORDER BY priority DESC,id")->fetchAll(PDO::FETCH_ASSOC)?:[]);
}
function tamasyaMultiRoomConflictLabel(array $conflict): string {
    $from=(string)($conflict['_resolvedStartAt']??'');$to=(string)($conflict['_resolvedEndAt']??'');
    $status=($conflict['status']??'')==='active'?'tamu menginap':'reservasi lain';
    return 'Bentrok dengan '.$status.': '.$from.' → '.($to>='9999-01-01'?'belum ada tanggal checkout':$to).'. Pilih periode di luar jadwal ini.';
}
/** Date-free planning catalog; physical status never disables room selection. */
function tamasyaMultiRoomCatalog(PDO $pdo,array $actor): array {
    tamasyaMultiRoomRequire($pdo,$actor);
    $rows=$pdo->query('SELECT number,type,floor,price FROM rooms ORDER BY floor,number')->fetchAll(PDO::FETCH_ASSOC)?:[];
    $statuses=tamasyaRoomOperationalStatusMap($pdo,array_column($rows,'number'));
    $rooms=[];foreach($rows as $room)$rooms[]=['number'=>$room['number'],'type'=>$room['type'],'floor'=>$room['floor'],'baseRate'=>(float)$room['price'],'totalAmount'=>null,'available'=>null,'currentStatus'=>$statuses[(string)$room['number']]??'maintenance','reason'=>'Pilih periode menginap untuk memeriksa bentrok.'];
    return ['rooms'=>$rooms,'bookingSources'=>tamasyaMultiRoomBookingSources($pdo),'companies'=>tamasyaEnterpriseFetchAll($pdo,"SELECT id,name FROM growth_companies WHERE status='active' ORDER BY name"),'masterBillingAvailable'=>tamasyaEnterpriseModuleEnabled('folio')&&tamasyaEnterpriseSchemaReady($pdo)];
}
function tamasyaMultiRoomAvailability(PDO $pdo,array $actor,array $input): array {
    tamasyaMultiRoomRequire($pdo,$actor);$from=trim((string)($input['checkIn']??''));$to=trim((string)($input['checkOut']??''));
    if(!validIsoDate($from)||!validIsoDate($to)||$to<=$from||$from<date('Y-m-d'))throw new InvalidArgumentException('Pilih tanggal masuk hari ini/mendatang dan tanggal keluar setelahnya.');
    $bookingSource=tamasyaMultiRoomNormalizeSource((string)($input['bookingSource']??'Direct'));
    $intent=(string)($input['lifecycleIntent']??'reserve');if(!in_array($intent,['reserve','check_in_now'],true))throw new InvalidArgumentException('Jenis reservasi tidak valid.');
    $settings=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default'")->fetch(PDO::FETCH_ASSOC)?:[];
    $window=resolveHotelBookingStayWindow(['checkIn'=>$from,'checkOut'=>$to,'stayMode'=>'overnight','isOpenEnded'=>0],(string)($settings['checkout_time']??'12:00:00'),(string)($settings['checkin_time']??'14:00:00'));
    $nights=(int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;$out=[];
    $rooms=$pdo->query('SELECT number,type,floor,price FROM rooms ORDER BY floor,number')->fetchAll(PDO::FETCH_ASSOC)?:[];
    $blockersByRoom=getRoomOperationalBlockersMap($pdo,array_column($rooms,'number'));
    $byRoom=[];
    $candidates=$pdo->query("SELECT id,roomNumber,status,checkIn,checkOut,isOpenEnded,stayMode,scheduledCheckInAt,scheduledCheckOutAt,actualCheckInAt,actualCheckOutAt,checkoutDueAt FROM bookings WHERE status IN ('reserved','active') ORDER BY roomNumber,checkIn,scheduledCheckInAt,id")->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($candidates as $b)$byRoom[(string)$b['roomNumber']][]=$b;
    $rate=resolveConfiguredTaxRate($pdo,$bookingSource,'room',null,$from);
    foreach($rooms as $room){
        $number=(string)$room['number'];$error='';$blockers=$blockersByRoom[$number]??[];$currentStatus=tamasyaDeriveRoomOperationalStatus($blockers);
        try{
            $conflict=tamasyaR3StayWindowConflictInRows($byRoom[$number]??[],$window['startAt'],$window['endAt'],(string)($settings['checkout_time']??'12:00:00'),(string)($settings['checkin_time']??'14:00:00'));
            if($conflict)throw new RuntimeException(tamasyaMultiRoomConflictLabel($conflict));
            if($intent==='check_in_now'){
                if($from!==date('Y-m-d'))throw new RuntimeException('Check-in langsung wajib pada tanggal hotel hari ini.');
                if($currentStatus!=='available')throw new RuntimeException(roomOperationalBlockerMessage($number,$blockers));
            }else{
                $inventoryBlockers=tamasyaReservationInventoryBlockers($blockers);
                if($inventoryBlockers)throw new RuntimeException(roomOperationalBlockerMessage($number,$inventoryBlockers));
            }
        }catch(RuntimeException|InvalidArgumentException $e){$error=clientExceptionMessage('Kamar tidak tersedia',$e);}
        $total=tamasyaPublishedRoomGrossTotal((float)$room['price'],$nights,(float)$rate);
        $out[]=['number'=>$room['number'],'type'=>$room['type'],'floor'=>$room['floor'],'available'=>$error==='','reason'=>$error,'currentStatus'=>$currentStatus,'baseRate'=>(float)$room['price'],'taxRate'=>(float)$rate,'totalAmount'=>$total];
    }
    return ['rooms'=>$out,'bookingSources'=>tamasyaMultiRoomBookingSources($pdo),'companies'=>tamasyaEnterpriseFetchAll($pdo,"SELECT id,name FROM growth_companies WHERE status='active' ORDER BY name"),'masterBillingAvailable'=>tamasyaEnterpriseModuleEnabled('folio')&&tamasyaEnterpriseSchemaReady($pdo)];
}
function tamasyaMultiRoomSplitCents(float $amount,array $weights): array {
    if(!is_finite($amount)||$amount<0||$amount>1000000000000||!$weights)throw new InvalidArgumentException('Nominal pembagian tidak valid.');
    foreach($weights as $w)if(!is_numeric($w)||!is_finite((float)$w)||(float)$w<0)throw new InvalidArgumentException('Bobot pembayaran tidak valid.');
    $weights=array_values($weights);$cents=(int)round($amount*100);$sum=array_sum($weights);if($sum<=0)throw new InvalidArgumentException('Bobot pembayaran harus positif.');
    $parts=[];$remainders=[];$left=$cents;
    foreach($weights as $i=>$w){$share=$cents*((float)$w/$sum);$part=(int)floor($share);$parts[$i]=$part;$left-=$part;$remainders[$i]=$w>0?$share-$part:-1;}
    arsort($remainders,SORT_NUMERIC);foreach(array_keys($remainders) as $i){if($left<=0)break;if($weights[$i]>0){$parts[$i]++;$left--;}}
    if($left!==0)throw new RuntimeException('Pembagian pembayaran tidak dapat direkonsiliasi.');
    ksort($parts);return array_map(static fn($part)=>$part/100,$parts);
}
/** Parent status/dates are a cache derived from independent children, never their authority. */
function tamasyaMultiRoomSyncState(PDO $pdo,string $id): void {
    if(!$pdo->inTransaction())throw new RuntimeException('Group projection requires a transaction.');
    $g=tamasyaEnterpriseFetch($pdo,'SELECT master_notes FROM growth_group_reservations WHERE id=? FOR UPDATE',[$id]);
    if(!$g||(json_decode((string)($g['master_notes']??''),true)['format']??'')!=='multi-room-v1')return;
    $rows=tamasyaEnterpriseFetchAll($pdo,'SELECT b.status,b.checkIn,b.checkOut FROM bookings b JOIN growth_group_booking_links l ON l.booking_id=b.id WHERE l.group_id=?',[$id]);
    $state=tamasyaMultiRoomStatus($rows);$status=match($state['status']){'cancelled'=>$rows?'cancelled':'draft','completed'=>'completed','reserved'=>'confirmed',default=>'in_house'};
    $live=array_values(array_filter($rows,static fn($b)=>$b['status']!=='cancelled'));
    $pdo->prepare('UPDATE growth_group_reservations SET status=?,room_block_qty=?,arrival_date=COALESCE(?,arrival_date),departure_date=COALESCE(?,departure_date) WHERE id=?')
        ->execute([$status,count($rows),$live?min(array_column($live,'checkIn')):null,$live?max(array_column($live,'checkOut')):null,$id]);
}
function tamasyaMultiRoomSyncFolios(PDO $pdo,string $groupId,array $actor,string $operationId): void {
    if(!tamasyaEnterpriseModuleEnabled('folio')||!tamasyaEnterpriseSchemaReady($pdo))return;
    $g=tamasyaEnterpriseFetch($pdo,'SELECT * FROM growth_group_reservations WHERE id=?',[$groupId]);if(!$g)return;
    $meta=json_decode((string)($g['master_notes']??''),true);if(($meta['format']??'')!=='multi-room-v1')return;
    $master=null;if($g['billing_mode']!=='individual'){
        $master=tamasyaEnterpriseFetch($pdo,"SELECT * FROM growth_folios WHERE group_id=? AND folio_type='master' AND status='open' ORDER BY created_at LIMIT 1 FOR UPDATE",[$groupId]);
        if(!$master){if(tamasyaEnterpriseFetch($pdo,"SELECT id FROM growth_folios WHERE group_id=? AND folio_type='master' AND status<>'void' LIMIT 1",[$groupId]))throw new RuntimeException('Master folio sudah ditutup. Admin/Keuangan harus meninjau folio sebelum perubahan grup.');$id=tamasyaGrowthId('folio');$pdo->prepare("INSERT INTO growth_folios(id,folio_number,folio_type,group_id,company_id,name,status,created_by) VALUES (?,?,'master',?,?,?,'open',?)")->execute([$id,tamasyaEnterpriseInvoiceNumber('FOL'),$groupId,$g['company_id'],$g['name'].' / '.$g['group_code'],$actor['id']]);$master=['id'=>$id];}
    }
    foreach(tamasyaEnterpriseFetchAll($pdo,'SELECT * FROM growth_group_booking_links WHERE group_id=? ORDER BY booking_id',[$groupId]) as $link){
        $bid=$link['booking_id'];$b=tamasyaEnterpriseFetch($pdo,'SELECT status FROM bookings WHERE id=?',[$bid]);if(!$b)continue;
        $guest=tamasyaEnterpriseDefaultFolio($pdo,$bid,$actor);$pct=$link['billing_mode']==='individual'?0:($link['billing_mode']==='master'?100:(float)$link['routed_percent']);
        $targets=$master?[[$master['id'],$pct],[$guest['id'],100-$pct]]:[[$guest['id'],100]];
        if($b['status']==='cancelled')tamasyaMultiRoomAssertCancellation($pdo,$bid);
        if($b['status']==='cancelled')$pdo->prepare('UPDATE growth_folio_charge_allocations SET amount=0,source_amount_snapshot=0 WHERE booking_id=?')->execute([$bid]);
        foreach($targets as [$fid,$percent]){
            if($percent<=0||$b['status']==='cancelled')continue;
            $ruleId='mr_rule_'.substr(hash('sha256',$fid.'|'.$bid),0,40);
            // Deterministic cent ownership for 50/50 and other fractional splits:
            // route the master first, then allocate the exact remaining cents to
            // the guest folio. Equal priorities previously left tie ordering to
            // the generated rule ID, producing inconsistent master totals.
            $priority=($master!==null && $fid===$master['id'])?90:100;
            $pdo->prepare("INSERT INTO growth_folio_routing_rules(id,booking_id,target_folio_id,transaction_kind_pattern,category_pattern,route_percent,priority,active,created_by) VALUES (?,?,?,'*','*',?,?,1,?) ON DUPLICATE KEY UPDATE route_percent=VALUES(route_percent),priority=VALUES(priority)")->execute([$ruleId,$bid,$fid,$percent,$priority,$actor['id']]);
        }
        if($b['status']!=='cancelled')tamasyaEnterpriseApplyFolioRouting($pdo,$bid,$actor,$operationId);
        if($b['status']!=='cancelled'){$source=tamasyaEnterpriseBookingChargeSource($pdo,$bid);foreach($source['charges'] as $kind=>$gross){$allocated=tamasyaEnterpriseChargeAllocatedTotal($pdo,$bid,$kind,null,true);if(abs($allocated-$gross)>0.011)throw new RuntimeException('Alokasi folio belum sesuai tagihan kamar. Periksa invoice yang sudah terbit/alokasi manual di Keuangan sebelum mengubah booking.');}}
        // Only genuinely unallocated canonical payments/refunds; security deposits are excluded by the existing engine.
        foreach(tamasyaEnterpriseFolioAvailableTransactions($pdo,$guest['id']) as $tx){
            $parts=tamasyaMultiRoomSplitCents((float)$tx['available'],array_column($targets,1));
            foreach($targets as $i=>[$fid,$percent]){if($parts[$i]<=0)continue;$oid='mr_alloc_'.substr(hash('sha256',$tx['id'].'|'.$fid.'|'.$operationId),0,55);
                $pdo->prepare("INSERT INTO growth_folio_transaction_allocations(id,folio_id,transaction_id,amount,allocation_role,notes,operation_id,allocated_by) VALUES (?,?,?,?,?,'Pembayaran canonical reservasi grup',?,?)")->execute([tamasyaGrowthId('falloc'),$fid,$tx['id'],$parts[$i],$fid===($master['id']??'')?'master':'guest',$oid,$actor['id']]);
            }
        }
    }
}
function tamasyaMultiRoomCreate(PDO $pdo,array $actor,array $input,string $channel,string $op): array {
    tamasyaMultiRoomRequire($pdo,$actor,true);$name=trim((string)($input['guestName']??''));$rows=$input['rooms']??[];
    if($name===''||tamasyaStringLength($name)>150||!is_array($rows)||count($rows)<1||count($rows)>50)throw new InvalidArgumentException('Nama pemesan dan 1–50 kamar wajib diisi.');
    $mode=(string)($input['billingMode']??'individual');if(!in_array($mode,['individual','master','split'],true))throw new InvalidArgumentException('Pilihan tagihan tidak valid.');
    $pct=$mode==='master'?100:($mode==='individual'?0:(float)($input['routedPercent']??50));if(!is_finite($pct)||($mode==='split'&&($pct<=0||$pct>=100)))throw new InvalidArgumentException('Porsi master split harus lebih dari 0 dan kurang dari 100%.');
    if($mode!=='individual')tamasyaEnterpriseRequireReady($pdo,'folio');
    $numbers=[];foreach($rows as $r){if(!is_array($r)||!is_scalar($r['roomNumber']??null)||!is_numeric($r['totalAmount']??null)||!is_finite((float)$r['totalAmount'])||round((float)$r['totalAmount'],2)<=0||(float)$r['totalAmount']>1000000000000)throw new InvalidArgumentException('Kamar dan harga bruto positif wajib valid.');$n=trim((string)($r['roomNumber']??''));if($n===''||isset($numbers[$n]))throw new InvalidArgumentException('Pilih kamar berbeda untuk setiap booking.');$numbers[$n]=true;}
    usort($rows,static fn($a,$b)=>strcmp((string)$a['roomNumber'],(string)$b['roomNumber']));
    $id=trim((string)($input['groupId']??''));if($id==='')$id='grp_op_'.substr(hash('sha256',$op),0,36);
    $receipt=tamasyaClaimCanonicalBookingReceipt($pdo,$actor,$op,$channel,$id,$input,false,'growth_group');if($receipt['state']==='duplicate')return ['duplicate'=>true]+$receipt['result'];
    try{
        $pdo->beginTransaction();$g=tamasyaEnterpriseFetch($pdo,'SELECT * FROM growth_group_reservations WHERE id=? FOR UPDATE',[$id]);
        $from=trim((string)($input['checkIn']??''));$to=trim((string)($input['checkOut']??''));
        if(!validIsoDate($from)||!validIsoDate($to)||$to<=$from)throw new InvalidArgumentException('Tanggal masuk/keluar tidak valid.');
        if($g){$detail=tamasyaMultiRoomDetail($pdo,$id);if($detail['bookings']&&in_array($detail['lifecycle']['status'],['completed','cancelled'],true))throw new RuntimeException('Grup selesai/dibatalkan tidak dapat ditambah kamar.');$mode=$g['billing_mode'];$meta=$detail['group']['contact'];if(($meta['format']??'')!=='multi-room-v1')throw new RuntimeException('Tambahkan booking pada grup lama melalui penautan di Growth.');$name=$g['name'];$pct=(float)($meta['routedPercent']??($mode==='master'?100:0));}
        else{
            $company=trim((string)($input['companyId']??''));if($company!==''&&!tamasyaEnterpriseFetch($pdo,"SELECT id FROM growth_companies WHERE id=? AND status='active' FOR UPDATE",[$company]))throw new InvalidArgumentException('Perusahaan tidak aktif/tidak ditemukan.');
            $meta=['format'=>'multi-room-v1','phone'=>trim((string)($input['guestPhone']??'')),'email'=>trim((string)($input['guestEmail']??'')),'notes'=>trim((string)($input['notes']??'')),'routedPercent'=>$pct,'bookingSource'=>tamasyaMultiRoomNormalizeSource((string)($input['bookingSource']??'Direct'))];
            if($meta['email']!==''&&!filter_var($meta['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Email pemesan tidak valid.');
            $code='GRP-'.date('Ymd').'-'.strtoupper(substr(hash('sha256',$op),0,8));
            $pdo->prepare("INSERT INTO growth_group_reservations(id,group_code,company_id,name,arrival_date,departure_date,room_block_qty,status,billing_mode,master_notes,operation_id,created_by,updated_by) VALUES (?,?,?,?,?,?,?,'confirmed',?,?,?,?,?)")->execute([$id,$code,$company?:null,$name,$from,$to,count($rows),$mode,tamasyaJsonEncode($meta),$op,$actor['id'],$actor['id']]);
        }
        $results=[];$boundsFrom=$from;$boundsTo=$to;
        foreach($rows as $r){
            if($mode!=='individual')tamasyaEnterpriseRequireReady($pdo,'folio');
            $payload=['guestName'=>trim((string)($r['guestName']??''))?:$name,'guestPhone'=>$meta['phone']??'','guestEmail'=>$meta['email']??'','bookingSource'=>$meta['bookingSource']??'Direct','checkIn'=>$r['checkIn']??$from,'checkOut'=>$r['checkOut']??$to,'roomNumber'=>$r['roomNumber'],'totalAmount'=>$r['totalAmount']??0,'paymentStatus'=>'unpaid','lifecycleIntent'=>$input['lifecycleIntent']??'reserve','broadcast'=>false];
            foreach(['downPaymentAmount','downPaymentMethod','downPaymentBankAccountId','downPaymentDate','securityDepositRequired','securityDepositRequiredAmount','securityDepositReceivedAmount','securityDepositPaymentMethod','securityDepositBankAccountId','securityDepositDate'] as $key)if(isset($r[$key]))$payload[$key]=$r[$key];
            $boundsFrom=min($boundsFrom,(string)$payload['checkIn']);$boundsTo=max($boundsTo,(string)$payload['checkOut']);
            $childOp='mr_child_'.substr(hash('sha256',$op.'|'.$r['roomNumber']),0,60);
            $child=createCanonicalBookingWorkflow($pdo,$actor,$payload,$channel,$childOp,true);$results[]=$child;
            $pdo->prepare('INSERT INTO growth_group_booking_links(id,group_id,booking_id,billing_mode,routed_percent,operation_id,linked_by) VALUES (?,?,?,?,?,?,?)')->execute([tamasyaGrowthId('gl'),$id,$child['bookingId'],$mode,$pct,$childOp,$actor['id']]);
            writeRequiredEnterpriseAudit($pdo,$actor,'Menautkan kamar baru ke reservasi grup','growth_group',$id,null,['bookingId'=>$child['bookingId'],'roomNumber'=>$r['roomNumber']],$channel);
        }
        $pdo->prepare('UPDATE growth_group_reservations SET arrival_date=LEAST(arrival_date,?),departure_date=GREATEST(departure_date,?),room_block_qty=(SELECT COUNT(*) FROM growth_group_booking_links WHERE group_id=?),version=version+1,updated_by=? WHERE id=?')->execute([$boundsFrom,$boundsTo,$id,$actor['id'],$id]);
        tamasyaMultiRoomSyncState($pdo,$id);tamasyaMultiRoomSyncFolios($pdo,$id,$actor,$op);$data=tamasyaMultiRoomDetail($pdo,$id);
        writeRequiredEnterpriseAudit($pdo,$actor,$g?'Menambah kamar reservasi grup':'Membuat reservasi/check-in grup','growth_group',$id,$g,['roomCount'=>count($data['bookings']),'billingMode'=>$mode,'lifecycle'=>$data['lifecycle']],$channel);
        $result=['groupId'=>$id,'data'=>$data,'children'=>$results,'operationId'=>$op];tamasyaCompleteCanonicalBookingReceipt($pdo,$op,$result);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
        broadcastTelegramNotification($pdo,tamasyaMultiRoomTelegramSummary($data,$g?'Kamar ditambahkan':'Reservasi grup baru'),false,'bookings');return ['duplicate'=>false]+$result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaFailCanonicalBookingReceipt($pdo,$op,$e);throw $e;}
}
function tamasyaMultiRoomTelegramSummary(array $data,string $event): string {
    $g=$data['group'];$text='🏨 *'.tamasyaTelegramPlainText($event)."*\n\n".tamasyaTelegramPlainText($g['name']).' · '.tamasyaTelegramPlainText($g['group_code'])."\n";
    foreach(array_slice($data['bookings'],0,25) as $b)$text.='• '.tamasyaTelegramPlainText($b['roomNumber']).' · '.tamasyaTelegramPlainText(function_exists('mb_substr')?mb_substr($b['guestName'],0,48):substr($b['guestName'],0,48)).' · '.tamasyaTelegramPlainText($b['status'])."\n";
    if(count($data['bookings'])>25)$text.='… '.(count($data['bookings'])-25)." kamar lain tersedia di detail web.\n";
    $text.='Sumber: '.tamasyaTelegramPlainText($g['contact']['bookingSource']??'Direct')."\n";
    $c=$data['lifecycle']['counts'];return $text."\nMasuk: {$c['active']} · Selesai: {$c['completed']} · Reservasi: {$c['reserved']}\nTotal: Rp ".tamasyaTelegramFormatAmount($data['totals']['total']).' · Dibayar: Rp '.tamasyaTelegramFormatAmount($data['totals']['paid']).' · Sisa: Rp '.tamasyaTelegramFormatAmount($data['totals']['balance']);
}

function tamasyaMultiRoomPayment(PDO $pdo,array $actor,array $input,string $channel,string $op): array {
    tamasyaMultiRoomRequire($pdo,$actor,true);$id=trim((string)($input['groupId']??''));$amount=$input['amount']??null;
    if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0)throw new InvalidArgumentException('Nominal pembayaran grup harus lebih dari nol.');
    $amount=round((float)$amount,2);if($amount<=0)throw new InvalidArgumentException('Pembayaran minimum satu sen.');$receipt=tamasyaClaimCanonicalBookingReceipt($pdo,$actor,$op,$channel,$id,$input,false,'growth_group');if($receipt['state']==='duplicate')return ['duplicate'=>true]+$receipt['result'];
    try{$pdo->beginTransaction();if(!tamasyaEnterpriseFetch($pdo,'SELECT id FROM growth_group_reservations WHERE id=? FOR UPDATE',[$id]))throw new InvalidArgumentException('Grup tidak ditemukan.');
        $children=tamasyaEnterpriseFetchAll($pdo,"SELECT b.id,b.balanceDue FROM bookings b JOIN growth_group_booking_links l ON l.booking_id=b.id WHERE l.group_id=? AND b.status<>'cancelled' AND b.balanceDue>0 ORDER BY b.id FOR UPDATE",[$id]);
        $weights=array_map(static fn($b)=>(float)$b['balanceDue'],$children);if(!$children||$amount>array_sum($weights)+0.001)throw new DomainException('Pembayaran melebihi sisa tagihan grup.',409);
        $parts=tamasyaMultiRoomSplitCents($amount,$weights);$results=[];
        foreach($children as $i=>$b){if($parts[$i]<=0)continue;$results[]=recordBookingDeposit($pdo,$actor,$b['id'],['amount'=>$parts[$i],'paymentMethod'=>$input['paymentMethod']??'cash','bankAccountId'=>$input['bankAccountId']??'','date'=>$input['date']??date('Y-m-d'),'notes'=>'Pembayaran reservasi grup','operationId'=>'mr_pay_'.substr(hash('sha256',$op.'|'.$b['id']),0,60)],$channel);}
        tamasyaMultiRoomSyncState($pdo,$id);tamasyaMultiRoomSyncFolios($pdo,$id,$actor,$op);$result=['data'=>tamasyaMultiRoomDetail($pdo,$id),'payments'=>$results,'operationId'=>$op];
        writeRequiredEnterpriseAudit($pdo,$actor,'Pembayaran/DP reservasi grup','growth_group',$id,null,['amount'=>$amount,'method'=>$input['paymentMethod']??'cash','transactionIds'=>array_column($results,'transactionId')],$channel);
        tamasyaCompleteCanonicalBookingReceipt($pdo,$op,$result);tamasyaFinancialCommit($pdo);$GLOBALS['tamasya_multi_room_events']=[];
        broadcastTelegramNotification($pdo,tamasyaMultiRoomTelegramSummary($result['data'],'Pembayaran grup: Rp '.tamasyaTelegramFormatAmount($amount)),true,'bookings');return ['duplicate'=>false]+$result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaFailCanonicalBookingReceipt($pdo,$op,$e);throw $e;}
}
function tamasyaMultiRoomEdit(PDO $pdo,array $actor,array $input,string $channel,string $op): array {
    tamasyaMultiRoomRequire($pdo,$actor,true);$id=trim((string)($input['groupId']??''));
    $pdo->beginTransaction();try{
        $g=tamasyaEnterpriseFetch($pdo,'SELECT * FROM growth_group_reservations WHERE id=? FOR UPDATE',[$id]);if(!$g)throw new InvalidArgumentException('Grup tidak ditemukan.');
        $meta=json_decode((string)($g['master_notes']??''),true);if(!is_array($meta)||($meta['format']??'')!=='multi-room-v1')throw new RuntimeException('Grup lama tetap dikelola melalui menu Grup Growth; metadata operasional belum tersedia.');
        if(isset($input['billingMode'])&&$input['billingMode']!==$g['billing_mode'])throw new RuntimeException('Pilihan tagihan grup tidak dapat diganti setelah booking dibuat. Gunakan perubahan alokasi folio yang diaudit oleh Admin/Keuangan.');
        $name=trim((string)($input['guestName']??$g['name']));if($name===''||tamasyaStringLength($name)>150)throw new InvalidArgumentException('Nama pemesan tidak valid.');
        foreach(['phone'=>'guestPhone','email'=>'guestEmail','notes'=>'notes'] as $key=>$field)if(isset($input[$field]))$meta[$key]=trim((string)$input[$field]);
        if(($meta['email']??'')!==''&&!filter_var($meta['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Email pemesan tidak valid.');
        $company=$g['company_id'];if(array_key_exists('companyId',$input)){$company=trim((string)$input['companyId'])?:null;if($company&&!tamasyaEnterpriseFetch($pdo,"SELECT id FROM growth_companies WHERE id=? AND status='active' FOR UPDATE",[$company]))throw new InvalidArgumentException('Perusahaan tidak aktif/tidak ditemukan.');}
        $pdo->prepare('UPDATE growth_group_reservations SET company_id=?,name=?,master_notes=?,updated_by=?,version=version+1 WHERE id=?')->execute([$company,$name,tamasyaJsonEncode($meta),$actor['id'],$id]);
        if($company!==$g['company_id']){if(tamasyaEnterpriseModuleEnabled('folio')&&tamasyaEnterpriseSchemaReady($pdo)){if(tamasyaEnterpriseFetch($pdo,"SELECT i.id FROM growth_folio_invoices i JOIN growth_folios f ON f.id=i.folio_id WHERE f.group_id=? AND i.status='issued' LIMIT 1",[$id]))throw new RuntimeException('Corporate tidak dapat diganti saat invoice master masih terbit. Void/koreksi dahulu melalui Keuangan.');$pdo->prepare("UPDATE growth_folios SET company_id=? WHERE group_id=? AND status='open'")->execute([$company,$id]);}}
        foreach((array)($input['occupants']??[]) as $row){$bid=trim((string)($row['bookingId']??''));$guest=trim((string)($row['guestName']??''));if($guest===''||tamasyaStringLength($guest)>150)throw new InvalidArgumentException('Nama penghuni tidak valid.');
            $b=tamasyaEnterpriseFetch($pdo,'SELECT b.* FROM bookings b JOIN growth_group_booking_links l ON l.booking_id=b.id WHERE l.group_id=? AND b.id=? FOR UPDATE',[$id,$bid]);if(!$b||!in_array($b['status'],['reserved','active'],true))throw new RuntimeException('Penghuni hanya dapat diubah pada kamar grup yang reserved/aktif.');
            $pdo->prepare('UPDATE bookings SET guestName=?,updatedBy=?,updatedSource=?,version=version+1 WHERE id=?')->execute([$guest,$actor['id'],$channel,$bid]);writeRequiredEnterpriseAudit($pdo,$actor,'Mengubah penghuni kamar grup','booking',$bid,['guestFingerprint'=>hash('sha256',$b['guestName'])],['guestFingerprint'=>hash('sha256',$guest)],$channel);
        }
        writeRequiredEnterpriseAudit($pdo,$actor,'Mengubah pemesan/catatan reservasi grup','growth_group',$id,['name'=>$g['name']],['name'=>$name],$channel);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);return tamasyaMultiRoomDetail($pdo,$id);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** Collect group context only for audited child mutations; no transport occurs here. */
function tamasyaMultiRoomCollectEvent(PDO $pdo,string $entityType,string $entityId,string $event): void {
    if($entityType!=='booking'||!tamasyaGrowthModuleEnabled('group')||!tamasyaGrowthTableExists($pdo,'growth_group_booking_links'))return;
    foreach(tamasyaEnterpriseFetchAll($pdo,'SELECT group_id FROM growth_group_booking_links WHERE booking_id=?',[$entityId]) as $r)$GLOBALS['tamasya_multi_room_events'][$r['group_id']]=$event;
}
function tamasyaMultiRoomDecorateBroadcast(PDO $pdo,string $message): ?string {
    $events=$GLOBALS['tamasya_multi_room_events']??[];if(!$events)return $message;
    $groups=[];foreach($events as $id=>$event){if(isset($GLOBALS['tamasya_multi_room_sent'][$id]))continue;$d=tamasyaMultiRoomDetail($pdo,$id);
        if($d['lifecycle']['status']==='completed')$event=$d['totals']['balance']<0.01?'Group checkout selesai':'Semua kamar checkout; tagihan masih tersisa';
        elseif($d['lifecycle']['status']==='partially_checked_out')$event='Checkout sebagian';
        elseif($d['lifecycle']['status']==='partially_checked_in')$event='Check-in sebagian';
        $groups[]=tamasyaMultiRoomTelegramSummary($d,$event);$GLOBALS['tamasya_multi_room_sent'][$id]=true;
    }
    return $groups?$message."\n\n".implode("\n\n",$groups):null;
}
function tamasyaMultiRoomCheckIn(PDO $pdo,array $actor,string $groupId,string $bookingId,string $op): array {
    tamasyaMultiRoomRequire($pdo,$actor,true);$receipt=tamasyaClaimCanonicalBookingReceipt($pdo,$actor,$op,'telegram',$bookingId,['groupId'=>$groupId,'bookingId'=>$bookingId,'status'=>'active']);
    if($receipt['state']==='duplicate')return ['duplicate'=>true]+$receipt['result'];
    try{$pdo->beginTransaction();$g=tamasyaEnterpriseFetch($pdo,'SELECT id FROM growth_group_reservations WHERE id=? FOR UPDATE',[$groupId]);
        $b=tamasyaEnterpriseFetch($pdo,'SELECT b.* FROM bookings b JOIN growth_group_booking_links l ON l.booking_id=b.id WHERE l.group_id=? AND b.id=? FOR UPDATE',[$groupId,$bookingId]);if(!$g||!$b)throw new InvalidArgumentException('Booking bukan bagian dari grup ini.');
        if($b['status']!=='active')tamasyaCanonicalReservedCheckInInTransaction($pdo,$actor,$b,'telegram');
        writeRequiredEnterpriseAudit($pdo,$actor,'Check-in kamar reservasi grup','booking',$bookingId,['status'=>$b['status']],['status'=>'active','groupId'=>$groupId],'telegram');
        tamasyaMultiRoomSyncState($pdo,$groupId);$result=['data'=>tamasyaMultiRoomDetail($pdo,$groupId)];tamasyaCompleteCanonicalBookingReceipt($pdo,$op,$result);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
        broadcastTelegramNotification($pdo,'🏨 Check-in kamar '.tamasyaTelegramPlainText($b['roomNumber']).' berhasil.',false,'bookings');return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaFailCanonicalBookingReceipt($pdo,$op,$e);throw $e;}
}
function tamasyaMultiRoomAssertCancellation(PDO $pdo,string $bookingId): void {
    if(!tamasyaGrowthModuleEnabled('group')||!tamasyaEnterpriseModuleEnabled('folio')||!tamasyaEnterpriseSchemaReady($pdo))return;
    $issued=tamasyaEnterpriseFetch($pdo,"SELECT i.id FROM growth_folio_invoices i JOIN growth_folio_charge_allocations a ON a.folio_id=i.folio_id JOIN growth_group_booking_links l ON l.booking_id=a.booking_id WHERE a.booking_id=? AND i.status='issued' LIMIT 1 FOR UPDATE",[$bookingId]);
    if($issued)throw new RuntimeException('Void/koreksi invoice folio melalui Keuangan sebelum membatalkan kamar grup. Transaksi belum diubah.');
}

function tamasyaCanonicalReservedCheckInInTransaction(PDO $pdo,array $actor,array $booking,string $source): void {
    $bookingId=(string)$booking['id'];$roomNumber=(string)$booking['roomNumber'];
                $checkinEligibility=tamasyaR3EvaluateCheckInEligibility($pdo,$actor,$booking);
                $checkinStayWindow=$checkinEligibility['stayWindow'];
                $checkinUpdate=$pdo->prepare("UPDATE bookings SET status='active',actualCheckInAt=CURRENT_TIMESTAMP,checkoutDueAt=?,version=version+1,updatedBy=?,updatedSource=? WHERE id=? AND status='reserved'");
                $checkinUpdate->execute([$checkinStayWindow['checkoutDueAt'],(string)$actor['id'],$source,$bookingId]);
                if($checkinUpdate->rowCount()!==1)throw new RuntimeException('Status reservasi berubah sebelum check-in diselesaikan.');
                tamasyaReconcileRoomOperationalProjection($pdo,$actor,$roomNumber,$source.'-checkin');
                $accessStmt=$pdo->prepare("SELECT access_mode FROM room_access_control WHERE room_number=? LIMIT 1 FOR UPDATE");
                $accessStmt->execute([$roomNumber]);
                $accessMode=(string)($accessStmt->fetchColumn() ?: ($booking['accessMode'] ?? 'physical'));
                $pdo->prepare("UPDATE room_access_control SET current_booking_id=?,physical_key_status='secured',last_event_at=CURRENT_TIMESTAMP,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE room_number=?")
                    ->execute([$bookingId,(string)$actor['id'],$roomNumber]);
                tamasyaSyncGuestServiceBookingLifecycle($pdo,$actor,(string)$bookingId,'active',$roomNumber,$source.'-checkin');
                logActivity($pdo,'booking_checkin','Reservasi '.$bookingId.' check-in ke kamar '.$roomNumber,$actor['id']??null,$actor['name']??null);
}

function tamasyaMultiRoomDetach(PDO $pdo,array $actor,array $input,string $op): array {
    tamasyaMultiRoomRequire($pdo,$actor,true);$id=trim((string)($input['groupId']??''));$bid=trim((string)($input['bookingId']??''));
    $receipt=tamasyaClaimCanonicalBookingReceipt($pdo,$actor,$op,'web',$id,$input,false,'growth_group');if($receipt['state']==='duplicate')return $receipt['result'];
    try{$pdo->beginTransaction();$g=tamasyaEnterpriseFetch($pdo,'SELECT * FROM growth_group_reservations WHERE id=? FOR UPDATE',[$id]);
        $b=tamasyaEnterpriseFetch($pdo,'SELECT b.*,l.billing_mode FROM bookings b JOIN growth_group_booking_links l ON l.booking_id=b.id WHERE l.group_id=? AND b.id=? FOR UPDATE',[$id,$bid]);
        if(!$g||!$b)throw new InvalidArgumentException('Booking bukan anggota grup.');
        if($b['billing_mode']!=='individual'||$b['status']!=='reserved'||(float)$b['amountPaid']>0)throw new RuntimeException('Lepas kamar hanya untuk reservasi individual yang belum dibayar. Untuk Master/Split/aktif, gunakan pembatalan atau koreksi folio yang diaudit.');
        tamasyaMultiRoomAssertCancellation($pdo,$bid);
        $pdo->prepare('DELETE FROM growth_group_booking_links WHERE group_id=? AND booking_id=?')->execute([$id,$bid]);
        $pdo->prepare('UPDATE growth_group_reservations SET room_block_qty=(SELECT COUNT(*) FROM growth_group_booking_links WHERE group_id=?),version=version+1,updated_by=? WHERE id=?')->execute([$id,$actor['id'],$id]);
        writeRequiredEnterpriseAudit($pdo,$actor,'Melepas booking individual dari grup tanpa membatalkan booking','growth_group',$id,['bookingId'=>$bid],null,'web');
        $result=['data'=>tamasyaMultiRoomDetail($pdo,$id)];tamasyaCompleteCanonicalBookingReceipt($pdo,$op,$result);bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);return $result;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaFailCanonicalBookingReceipt($pdo,$op,$e);throw $e;}
}
