<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
function tamasyaMultiRoomTelegramState(PDO $pdo,array $actor,string $state,array $context): void {
    $pdo->prepare('UPDATE staff SET telegram_state=?,telegram_context=? WHERE id=?')->execute([$state,tamasyaJsonEncode($context),$actor['id']]);
}
function tamasyaMultiRoomTelegramContext(array $actor,?string $nonce=null): array {
    $ctx=json_decode((string)($actor['telegram_context']??''),true);
    if(!is_array($ctx)||($ctx['flow']??'')!=='multi-room'||($ctx['expiresAt']??0)<time()||($nonce!==null&&!hash_equals((string)($ctx['nonce']??''),$nonce)))throw new RuntimeException('Langkah grup sudah kedaluwarsa/berubah. Mulai kembali dari menu Kamar & Tamu.');
    return $ctx;
}
function tamasyaMultiRoomTelegramDetail(PDO $pdo,array $actor,string $id): array {
    tamasyaMultiRoomRequire($pdo,$actor);$d=tamasyaMultiRoomDetail($pdo,$id);$buttons=[];$write=in_array($actor['role'],['admin','manager','receptionist'],true);
    foreach(array_slice($d['bookings'],0,20) as $b){
        if($write&&$b['status']==='reserved')$buttons[]=[['text'=>'Check-in kamar '.$b['roomNumber'],'callback_data'=>'mr_checkin:'.$id.':'.$b['id']]];
        if($write&&$b['status']==='active')$buttons[]=[['text'=>'Checkout kamar '.$b['roomNumber'],'callback_data'=>'r_checkout:'.$b['roomNumber']],['text'=>'Perpanjang '.$b['roomNumber'],'callback_data'=>'r_extend:'.$b['roomNumber']]];
    }
    if($write&&!in_array($d['lifecycle']['status'],['completed','cancelled'],true))$buttons[]=[['text'=>'➕ Tambah kamar','callback_data'=>'mr_add:'.$id]];
    if($write&&$d['totals']['balance']>0)$buttons[]=[['text'=>'💰 DP / Pembayaran grup','callback_data'=>'mr_pay:'.$id]];
    $buttons[]=[['text'=>'🔄 Muat ulang','callback_data'=>'mr_detail:'.$id],['text'=>'⬅️ Daftar grup','callback_data'=>'mr_list']];
    return ['text'=>tamasyaMultiRoomTelegramSummary($d,'Detail reservasi grup'),'markup'=>['inline_keyboard'=>$buttons]];
}
function tamasyaMultiRoomTelegramPicker(array $ctx,int $page=1): array {
    $rows=$ctx['availableRooms'];$page=max(1,min($page,max(1,(int)ceil(count($rows)/12))));$buttons=[];
    foreach(array_slice($rows,($page-1)*12,12) as $r){$selected=isset($ctx['selected'][$r['number']]);$buttons[]=[['text'=>($selected?'✅ ':'▫️ ').'Kamar '.$r['number'].' · '.$r['type'],'callback_data'=>'mr_select:'.$r['number'].':'.$ctx['nonce']]];}
    $nav=[];if($page>1)$nav[]=['text'=>'←','callback_data'=>'mr_page:'.($page-1).':'.$ctx['nonce']];if($page*12<count($rows))$nav[]=['text'=>'→','callback_data'=>'mr_page:'.($page+1).':'.$ctx['nonce']];if($nav)$buttons[]=$nav;
    $buttons[]=[['text'=>'Lanjut · '.count($ctx['selected']).' kamar','callback_data'=>'mr_next:'.$ctx['nonce']]];$buttons[]=[['text'=>'Batalkan','callback_data'=>'main_menu']];
    return ['text'=>"🏨 *PILIH BEBERAPA KAMAR*\n\n".tamasyaTelegramPlainText($ctx['guestName'])."\n".$ctx['checkIn'].' → '.$ctx['checkOut']."\n\nTekan kamar untuk memilih/melepasnya. Ketersediaan diperiksa kembali saat simpan.\nDipilih: ".implode(', ',array_keys($ctx['selected'])),'markup'=>['inline_keyboard'=>$buttons]];
}
function tamasyaMultiRoomTelegramConfirm(PDO $pdo,array $actor,array $ctx): array {
    tamasyaMultiRoomTelegramState($pdo,$actor,'waiting_mr_confirm',$ctx);$total=array_sum(array_column($ctx['selected'],'totalAmount'));
    $text="🏨 *KONFIRMASI RESERVASI GRUP*\n\n".tamasyaTelegramPlainText($ctx['guestName'])."\n".$ctx['checkIn'].' → '.$ctx['checkOut']."\n";
    foreach($ctx['selected'] as $r)$text.='• '.$r['roomNumber'].' · '.tamasyaTelegramPlainText($r['guestName']?:$ctx['guestName']).' · Rp '.tamasyaTelegramFormatAmount($r['totalAmount'])."\n";
    $text.="\nTagihan: ".$ctx['billingMode'].($ctx['billingMode']==='split'?' · master '.$ctx['routedPercent'].'%':'')."\nTotal termasuk PBJT: Rp ".tamasyaTelegramFormatAmount($total)."\nPembayaran: BELUM BAYAR. DP/pelunasan diterima lewat tombol Pembayaran grup setelah tersimpan.\n".($ctx['lifecycleIntent']==='check_in_now'?'Kamar akan langsung check-in.':'Kamar masih reservasi; belum dianggap menginap.');
    return ['text'=>$text,'markup'=>['inline_keyboard'=>[[['text'=>$ctx['lifecycleIntent']==='check_in_now'?'✅ Check-in semua':'✅ Simpan reservasi','callback_data'=>'mr_confirm:'.$ctx['nonce']]],[['text'=>'Batalkan','callback_data'=>'main_menu']]]]];
}
function tamasyaMultiRoomTelegramCallback(PDO $pdo,array $actor,string $callback,string $operationId): array {
    $parts=explode(':',$callback);$command=$parts[0];
    tamasyaMultiRoomRequire($pdo,$actor,!in_array($command,['mr_list','mr_detail'],true));
    if($command==='mr_list'){$buttons=[];foreach(tamasyaEnterpriseFetchAll($pdo,'SELECT id,group_code,name FROM growth_group_reservations ORDER BY created_at DESC LIMIT 20') as $g)$buttons[]=[['text'=>$g['group_code'].' · '.(function_exists('mb_substr')?mb_substr($g['name'],0,30):substr($g['name'],0,30)),'callback_data'=>'mr_detail:'.$g['id']]];$buttons[]=[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']];return ['text'=>'🏨 *DAFTAR RESERVASI GRUP*','markup'=>['inline_keyboard'=>$buttons]];}
    if($command==='mr_detail')return tamasyaMultiRoomTelegramDetail($pdo,$actor,$parts[1]??'');
    if($command==='mr_checkin'){$result=tamasyaMultiRoomCheckIn($pdo,$actor,$parts[1]??'',$parts[2]??'',$operationId);return tamasyaMultiRoomTelegramDetail($pdo,$actor,$result['data']['group']['id']);}
    if($command==='mr_start'){
        $intent=$parts[1]??'reserve';if(!in_array($intent,['reserve','check_in_now'],true))throw new InvalidArgumentException('Pilihan grup tidak valid.');
        $ctx=['flow'=>'multi-room','nonce'=>bin2hex(random_bytes(8)),'expiresAt'=>time()+1800,'lifecycleIntent'=>$intent,'selected'=>[]];tamasyaMultiRoomTelegramState($pdo,$actor,'waiting_mr_name',$ctx);
        return ['text'=>'👤 Ketik nama pemesan utama untuk beberapa kamar.','markup'=>['inline_keyboard'=>[[['text'=>'Batalkan','callback_data'=>'main_menu']]]]];
    }
    if($command==='mr_add'){$d=tamasyaMultiRoomDetail($pdo,$parts[1]??'');if(in_array($d['lifecycle']['status'],['completed','cancelled'],true))throw new RuntimeException('Grup sudah selesai.');$g=$d['group'];$ctx=['flow'=>'multi-room','nonce'=>bin2hex(random_bytes(8)),'expiresAt'=>time()+1800,'lifecycleIntent'=>'reserve','selected'=>[],'groupId'=>$g['id'],'guestName'=>$g['name'],'guestPhone'=>$g['contact']['phone']??'','bookingSource'=>$g['contact']['bookingSource']??'Direct','billingMode'=>$g['billing_mode'],'routedPercent'=>$g['contact']['routedPercent']??0];tamasyaMultiRoomTelegramState($pdo,$actor,'waiting_mr_dates',$ctx);return ['text'=>'Ketik tanggal masuk dan keluar untuk kamar tambahan: YYYY-MM-DD YYYY-MM-DD.','markup'=>null];}
    if($command==='mr_pay'){$d=tamasyaMultiRoomDetail($pdo,$parts[1]??'');$ctx=['flow'=>'multi-room','nonce'=>bin2hex(random_bytes(8)),'expiresAt'=>time()+900,'groupId'=>$d['group']['id']];tamasyaMultiRoomTelegramState($pdo,$actor,'waiting_mr_payment_amount',$ctx);return ['text'=>'💰 Ketik nominal uang yang benar-benar diterima. Sisa grup: Rp '.tamasyaTelegramFormatAmount($d['totals']['balance']),'markup'=>['inline_keyboard'=>[[['text'=>'Batalkan','callback_data'=>'main_menu']]]]];}
    $nonce=end($parts);$ctx=tamasyaMultiRoomTelegramContext($actor,$nonce);$state=(string)($actor['telegram_state']??'');
    if(in_array($command,['mr_confirm','mr_pay_confirm'],true)&&$state==='mr_done')return tamasyaMultiRoomTelegramDetail($pdo,$actor,$ctx['groupId']);
    if(in_array($command,['mr_select','mr_page','mr_next'],true)){
        if($state!=='waiting_mr_rooms')throw new RuntimeException('Pemilihan kamar sudah selesai. Mulai ulang untuk mengubah kamar.');
        if($command==='mr_select'){$number=$parts[1]??'';$found=null;foreach($ctx['availableRooms'] as $r)if((string)$r['number']===$number)$found=$r;if(!$found)throw new RuntimeException('Kamar tidak ada pada pilihan yang diperiksa.');if(isset($ctx['selected'][$number]))unset($ctx['selected'][$number]);else $ctx['selected'][$number]=['roomNumber'=>$number,'guestName'=>'','totalAmount'=>$found['totalAmount']];tamasyaMultiRoomTelegramState($pdo,$actor,$state,$ctx);return tamasyaMultiRoomTelegramPicker($ctx);}
        if($command==='mr_page')return tamasyaMultiRoomTelegramPicker($ctx,(int)($parts[1]??1));
        if(!$ctx['selected'])throw new InvalidArgumentException('Pilih minimal satu kamar.');tamasyaMultiRoomTelegramState($pdo,$actor,'waiting_mr_occupants',$ctx);
        return ['text'=>"👥 Penghuni per kamar boleh berbeda. Ketik misalnya:\n`101=Andi; 102=Rudi`\n\nKetik `-` agar semua memakai nama pemesan. Nomor kamar mengikuti pilihan Anda.",'markup'=>null];
    }
    if($command==='mr_bill'){
        if($state!=='waiting_mr_billing')throw new RuntimeException('Pilihan tagihan sudah berubah.');$ctx['billingMode']=$parts[1]??'';if(!in_array($ctx['billingMode'],['individual','master','split'],true))throw new InvalidArgumentException('Pilihan tagihan tidak valid.');
        if($ctx['billingMode']!=='individual')tamasyaEnterpriseRequireReady($pdo,'folio');$ctx['routedPercent']=$ctx['billingMode']==='master'?100:0;
        if($ctx['billingMode']==='split'){tamasyaMultiRoomTelegramState($pdo,$actor,'waiting_mr_percent',$ctx);return ['text'=>'Ketik persentase tagihan untuk master, contoh 50. Sisanya masuk masing-masing kamar.','markup'=>null];}
        return tamasyaMultiRoomTelegramConfirm($pdo,$actor,$ctx);
    }
    if($command==='mr_confirm'){
        if($state!=='waiting_mr_confirm')throw new RuntimeException('Konfirmasi grup sudah tidak berlaku.');
        $op='mr_tg_'.substr(hash('sha256',$actor['id'].'|'.$ctx['nonce']),0,60);$result=tamasyaMultiRoomCreate($pdo,$actor,['guestName'=>$ctx['guestName'],'guestPhone'=>$ctx['guestPhone'],'bookingSource'=>$ctx['bookingSource'],'checkIn'=>$ctx['checkIn'],'checkOut'=>$ctx['checkOut'],'billingMode'=>$ctx['billingMode'],'routedPercent'=>$ctx['routedPercent'],'lifecycleIntent'=>$ctx['lifecycleIntent'],'groupId'=>$ctx['groupId']??'','rooms'=>array_values($ctx['selected'])],'telegram',$op);
        $ctx['groupId']=$result['groupId'];tamasyaMultiRoomTelegramState($pdo,$actor,'mr_done',$ctx);return tamasyaMultiRoomTelegramDetail($pdo,$actor,$ctx['groupId']);
    }
    if($command==='mr_method'){
        if($state!=='waiting_mr_payment_method')throw new RuntimeException('Pilihan pembayaran sudah tidak berlaku.');$method=$parts[1]??'';if(!in_array($method,['cash','transfer','qris'],true))throw new InvalidArgumentException('Metode pembayaran tidak valid.');$ctx['paymentMethod']=$method;
        if($method!=='cash'){$buttons=[];foreach(tamasyaEnterpriseFetchAll($pdo,"SELECT id,name,type FROM bank_accounts WHERE isActive=1 ORDER BY name") as $b){if(($method==='qris'&&$b['type']==='edc_qris')||($method==='transfer'&&$b['type']==='bank'))$buttons[]=[['text'=>$b['name'],'callback_data'=>'mr_account:'.$b['id'].':'.$ctx['nonce']]];}if(!$buttons)throw new RuntimeException('Belum ada akun penerimaan yang aktif untuk metode ini.');tamasyaMultiRoomTelegramState($pdo,$actor,'waiting_mr_payment_account',$ctx);return ['text'=>'Pilih akun penerimaan.','markup'=>['inline_keyboard'=>$buttons]];}
        $ctx['bankAccountId']='';return tamasyaMultiRoomTelegramPaymentConfirm($pdo,$actor,$ctx);
    }
    if($command==='mr_account'){if($state!=='waiting_mr_payment_account')throw new RuntimeException('Pilihan akun sudah tidak berlaku.');$ctx['bankAccountId']=$parts[1]??'';return tamasyaMultiRoomTelegramPaymentConfirm($pdo,$actor,$ctx);}
    if($command==='mr_pay_confirm'){
        if($state!=='waiting_mr_payment_confirm')throw new RuntimeException('Konfirmasi pembayaran sudah tidak berlaku.');
        $result=tamasyaMultiRoomPayment($pdo,$actor,['groupId'=>$ctx['groupId'],'amount'=>$ctx['amount'],'paymentMethod'=>$ctx['paymentMethod'],'bankAccountId'=>$ctx['bankAccountId'],'date'=>date('Y-m-d')],'telegram','mr_tg_pay_'.substr(hash('sha256',$actor['id'].'|'.$ctx['nonce']),0,55));tamasyaMultiRoomTelegramState($pdo,$actor,'mr_done',$ctx);return tamasyaMultiRoomTelegramDetail($pdo,$actor,$result['data']['group']['id']);
    }
    throw new InvalidArgumentException('Tindakan grup tidak dikenal.');
}
function tamasyaMultiRoomTelegramPaymentConfirm(PDO $pdo,array $actor,array $ctx): array {
    tamasyaMultiRoomTelegramState($pdo,$actor,'waiting_mr_payment_confirm',$ctx);return ['text'=>'💰 Konfirmasi uang diterima: Rp '.tamasyaTelegramFormatAmount($ctx['amount']).' · '.$ctx['paymentMethod']."\nPembayaran dibagi proporsional ke sisa tagihan kamar, tidak membuat tagihan baru.",'markup'=>['inline_keyboard'=>[[['text'=>'✅ Simpan pembayaran','callback_data'=>'mr_pay_confirm:'.$ctx['nonce']]],[['text'=>'Batalkan','callback_data'=>'main_menu']]]]];
}
function tamasyaMultiRoomTelegramMessage(PDO $pdo,array $actor,string $message): array {
    tamasyaMultiRoomRequire($pdo,$actor,true);$ctx=tamasyaMultiRoomTelegramContext($actor);$state=(string)$actor['telegram_state'];$text=trim($message);$markup=null;
    if($state==='waiting_mr_name'){if($text===''||tamasyaStringLength($text)>150)throw new InvalidArgumentException('Nama pemesan tidak valid.');$ctx['guestName']=$text;$state='waiting_mr_phone';$reply='Ketik nomor HP pemesan, atau - untuk melewati.';}
    elseif($state==='waiting_mr_phone'){$ctx['guestPhone']=$text==='-'?'':$text;$state='waiting_mr_source';$reply='Ketik sumber booking, contoh Direct atau nama OTA yang sesuai aturan pajak hotel.';}
    elseif($state==='waiting_mr_source'){$ctx['bookingSource']=$text?:'Direct';$state='waiting_mr_dates';$reply="Ketik tanggal check-in dan check-out, contoh:\n`".date('Y-m-d').' '.date('Y-m-d',strtotime('+1 day'))."`\nBisa juga DD/MM/YYYY DD/MM/YYYY. Check-in langsung wajib hari ini.";}
    elseif($state==='waiting_mr_dates'){
        $parts=preg_split('/\s+/',$text);if(count($parts)!==2)throw new InvalidArgumentException('Ketik dua tanggal masuk dan keluar.');
        foreach($parts as &$date)if(preg_match('~^(\d{2})/(\d{2})/(\d{4})$~',$date,$m))$date=$m[3].'-'.$m[2].'-'.$m[1];unset($date);
        $ctx['checkIn']=$parts[0];$ctx['checkOut']=$parts[1];$a=tamasyaMultiRoomAvailability($pdo,$actor,$ctx);$ctx['availableRooms']=array_values(array_filter($a['rooms'],static fn($r)=>$r['available']));if(!$ctx['availableRooms'])throw new RuntimeException('Tidak ada kamar tersedia untuk periode tersebut. Pilih tanggal lain.');$ctx['masterBillingAvailable']=$a['masterBillingAvailable'];$state='waiting_mr_rooms';tamasyaMultiRoomTelegramState($pdo,$actor,$state,$ctx);return tamasyaMultiRoomTelegramPicker($ctx);
    }elseif($state==='waiting_mr_occupants'){
        if($text!=='-')foreach(explode(';',$text) as $part){$pair=explode('=',trim($part),2);if(count($pair)!==2||!isset($ctx['selected'][trim($pair[0])])||trim($pair[1])===''||tamasyaStringLength(trim($pair[1]))>150)throw new InvalidArgumentException('Isi nomor kamar=nama untuk kamar yang sudah dipilih.');$ctx['selected'][trim($pair[0])]['guestName']=trim($pair[1]);}
        if(!empty($ctx['groupId']))return tamasyaMultiRoomTelegramConfirm($pdo,$actor,$ctx);
        $state='waiting_mr_billing';$buttons=[[['text'=>'Masing-masing kamar','callback_data'=>'mr_bill:individual:'.$ctx['nonce']]]];if($ctx['masterBillingAvailable']){$buttons[]=[['text'=>'Master billing','callback_data'=>'mr_bill:master:'.$ctx['nonce']]];$buttons[]=[['text'=>'Split master + kamar','callback_data'=>'mr_bill:split:'.$ctx['nonce']]];}$reply='Pilih pembagian tagihan.';$markup=['inline_keyboard'=>$buttons];
    }elseif($state==='waiting_mr_percent'){if(!is_numeric($text)||(float)$text<=0||(float)$text>=100)throw new InvalidArgumentException('Persentase harus lebih dari 0 dan kurang dari 100.');$ctx['routedPercent']=(float)$text;return tamasyaMultiRoomTelegramConfirm($pdo,$actor,$ctx);}
    elseif($state==='waiting_mr_payment_amount'){$amount=tamasyaTelegramParseMoney($text);$d=tamasyaMultiRoomDetail($pdo,$ctx['groupId']);if($amount===null||$amount<=0||$amount>$d['totals']['balance'])throw new InvalidArgumentException('Nominal harus lebih dari nol dan tidak melebihi sisa grup.');$ctx['amount']=$amount;$state='waiting_mr_payment_method';$reply='Pilih metode uang diterima.';$markup=['inline_keyboard'=>[[['text'=>'Tunai','callback_data'=>'mr_method:cash:'.$ctx['nonce']]],[['text'=>'Transfer','callback_data'=>'mr_method:transfer:'.$ctx['nonce']],['text'=>'QRIS','callback_data'=>'mr_method:qris:'.$ctx['nonce']]]]];}
    else return ['text'=>'Gunakan tombol pada langkah grup terakhir, atau kembali ke Menu Utama.','markup'=>null];
    tamasyaMultiRoomTelegramState($pdo,$actor,$state,$ctx);return ['text'=>$reply,'markup'=>$markup];
}
