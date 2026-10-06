<?php
/** TAMASYA V137 canonical multi-channel communication foundation. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaCommunicationJsonDecode($value, array $fallback=[]): array {
    if(is_array($value))return $value;
    if(!is_string($value)||trim($value)==='')return $fallback;
    $decoded=json_decode($value,true);
    return is_array($decoded)?$decoded:$fallback;
}

function tamasyaCommunicationHeaders(): array {
    $headers=[];
    if(function_exists('getallheaders')){
        foreach((array)getallheaders() as $key=>$value)$headers[strtolower((string)$key)]=(string)$value;
    }
    foreach((array)($GLOBALS['tamasya_forwarded_channel_headers']??[]) as $key=>$value){$headers[strtolower((string)$key)]=(string)$value;}
    foreach($_SERVER as $key=>$value){
        if(str_starts_with((string)$key,'HTTP_')){
            $name=strtolower(str_replace('_','-',substr((string)$key,5)));
            if(!isset($headers[$name]))$headers[$name]=(string)$value;
        }
    }
    return $headers;
}

/** @return array<string,TamasyaCommunicationChannelAdapter> */
function tamasyaCommunicationAdapters(): array {
    static $registry=null;
    if(is_array($registry))return $registry;
    $registry=[];
    $dir=TAMASYA_APP_ROOT.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'channels';
    $contract=$dir.DIRECTORY_SEPARATOR.'CommunicationChannelAdapter.php';
    if(is_file($contract))require_once $contract;
    $before=get_declared_classes();
    foreach(glob($dir.DIRECTORY_SEPARATOR.'*Adapter.php')?:[] as $file){
        if(basename($file)==='CommunicationChannelAdapter.php')continue;
        require_once $file;
    }
    $candidates=array_unique(array_merge(array_diff(get_declared_classes(),$before),get_declared_classes()));
    foreach($candidates as $class){
        if(!is_subclass_of($class,TamasyaCommunicationChannelAdapter::class))continue;
        try{
            $ref=new ReflectionClass($class);
            if(!$ref->isInstantiable()||$ref->getConstructor()?->getNumberOfRequiredParameters()>0)continue;
            /** @var TamasyaCommunicationChannelAdapter $adapter */
            $adapter=$ref->newInstance();
            $key=strtolower(trim($adapter->key()));
            if($key===''||!preg_match('/^[a-z][a-z0-9_]{1,63}$/',$key))continue;
            if(isset($registry[$key]))throw new RuntimeException("Adapter komunikasi duplikat: {$key}");
            $registry[$key]=$adapter;
        }catch(Throwable $e){error_log(clientExceptionMessage('[communication] adapter load failed',$e));}
    }
    ksort($registry);
    return $registry;
}

function tamasyaCommunicationAdapter(string $key): ?TamasyaCommunicationChannelAdapter {
    $key=strtolower(trim($key));
    $registry=tamasyaCommunicationAdapters();
    return $registry[$key]??null;
}

function tamasyaCommunicationAdapterManifests(): array {
    $out=[];
    foreach(tamasyaCommunicationAdapters() as $key=>$adapter){
        $out[]=['key'=>$key,'name'=>$adapter->name(),'capabilities'=>$adapter->capabilities()];
    }
    return $out;
}

function tamasyaCommunicationChannelConfig(array $channel): array {
    return tamasyaCommunicationJsonDecode($channel['config_json']??$channel['config']??null,[]);
}

function tamasyaCommunicationChannelSecret(array $channel,string $kind='credential'): string {
    $column=$kind==='webhook'?'webhook_secret_encrypted':'credential_encrypted';
    return trim(decryptStoredSecret($channel[$column]??''));
}

function tamasyaCommunicationPublicChannel(array $row): array {
    $config=tamasyaCommunicationChannelConfig($row);
    foreach(array_keys($config) as $key){
        if(preg_match('/password|token|secret|credential|authorization|api[_-]?key/i',(string)$key))$config[$key]='********';
    }
    return [
        'id'=>(string)($row['id']??''),
        'providerKey'=>(string)($row['provider_key']??''),
        'displayName'=>(string)($row['display_name']??''),
        'enabled'=>(bool)($row['enabled']??false),
        'inboundEnabled'=>(bool)($row['inbound_enabled']??false),
        'outboundEnabled'=>(bool)($row['outbound_enabled']??false),
        'managedBy'=>(string)($row['managed_by']??'communication_core'),
        'config'=>$config,
        'hasCredential'=>trim((string)($row['credential_encrypted']??''))!=='',
        'hasWebhookSecret'=>trim((string)($row['webhook_secret_encrypted']??''))!=='',
        'healthStatus'=>(string)($row['health_status']??'unknown'),
        'lastCheckedAt'=>$row['last_checked_at']??null,
        'lastError'=>$row['last_error']??null,
        'createdAt'=>$row['created_at']??null,
        'updatedAt'=>$row['updated_at']??null,
    ];
}

function tamasyaCommunicationGetChannel(PDO $pdo,string $channelId,bool $forUpdate=false): ?array {
    $sql='SELECT * FROM communication_channels WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([trim($channelId)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

function tamasyaCommunicationListChannels(PDO $pdo): array {
    // Empty list is a valid business state, but a SQL failure is not. Do not
    // display a broken communication subsystem as if every channel were OFF.
    return $pdo->query('SELECT * FROM communication_channels ORDER BY display_name,id')->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function tamasyaCommunicationSyncLegacyChannels(PDO $pdo): void {
    try{
        $conf=$pdo->query("SELECT telegram_bot_token,telegram_webhook_active,smtp_host,smtp_from FROM config WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        $telegramEnabled=trim(decryptStoredSecret($conf['telegram_bot_token']??''))!=='';
        $emailEnabled=trim((string)($conf['smtp_host']??''))!==''&&trim((string)($conf['smtp_from']??''))!=='';
        $stmt=$pdo->prepare("INSERT INTO communication_channels
            (id,provider_key,display_name,enabled,inbound_enabled,outbound_enabled,managed_by,config_json,health_status,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,'unknown',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),enabled=VALUES(enabled),inbound_enabled=VALUES(inbound_enabled),outbound_enabled=VALUES(outbound_enabled),managed_by=VALUES(managed_by),config_json=VALUES(config_json),updated_at=CURRENT_TIMESTAMP");
        $stmt->execute(['channel_telegram_main','telegram','Telegram',($telegramEnabled?1:0),((int)($conf['telegram_webhook_active']??0)===1?1:0),($telegramEnabled?1:0),'legacy_config',json_encode(['configurationMode'=>'legacy_config'],JSON_UNESCAPED_SLASHES)]);
        $stmt->execute(['channel_email_main','email','Email SMTP',($emailEnabled?1:0),0,($emailEnabled?1:0),'legacy_config',json_encode(['configurationMode'=>'legacy_config'],JSON_UNESCAPED_SLASHES)]);
        $stmt->execute(['channel_web_app','web_app','Notifikasi Aplikasi',1,0,1,'internal',json_encode(['configurationMode'=>'internal'],JSON_UNESCAPED_SLASHES)]);

        if(function_exists('tamasyaTableExists')&&tamasyaTableExists($pdo,'telegram_bindings')){
            $pdo->exec("INSERT INTO communication_identities
                (id,channel_id,provider_user_id,provider_conversation_id,staff_id,status,verified_at,metadata_json,created_at,updated_at)
                SELECT CONCAT('identity_tg_',SHA2(CONCAT(tb.telegram_user_id,'|',tb.staff_id),256)),'channel_telegram_main',tb.telegram_user_id,tb.telegram_chat_id,tb.staff_id,
                       CASE WHEN tb.status='active' THEN 'active' ELSE tb.status END,tb.verified_at,JSON_OBJECT('source','telegram_bindings'),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
                FROM telegram_bindings tb
                ON DUPLICATE KEY UPDATE provider_conversation_id=VALUES(provider_conversation_id),staff_id=VALUES(staff_id),status=VALUES(status),verified_at=VALUES(verified_at),metadata_json=VALUES(metadata_json),updated_at=CURRENT_TIMESTAMP");
        }
    }catch(Throwable $e){error_log(clientExceptionMessage('[communication] legacy channel sync failed',$e));}
}

function tamasyaCommunicationResolveIdentity(PDO $pdo,string $channelId,string $providerUserId): ?array {
    $stmt=$pdo->prepare("SELECT ci.*,s.name,s.username,s.role,s.status AS staff_status,s.permissions
        FROM communication_identities ci JOIN staff s ON s.id=ci.staff_id
        WHERE ci.channel_id=? AND ci.provider_user_id=? AND ci.status='active' AND s.status='active' LIMIT 1");
    $stmt->execute([trim($channelId),trim($providerUserId)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

function tamasyaCommunicationClaimEvent(PDO $pdo,string $channelId,string $providerEventId,array $payload=[]): string {
    $providerEventId=trim($providerEventId);
    if($providerEventId==='')return 'skip';
    $payloadHash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT id,status,updated_at,payload_hash FROM communication_webhook_events WHERE channel_id=? AND provider_event_id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$channelId,$providerEventId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row){
            $id=function_exists('generateServerId')?generateServerId('ch_evt'):'ch_evt_'.bin2hex(random_bytes(12));
            $pdo->prepare("INSERT INTO communication_webhook_events(id,channel_id,provider_event_id,payload_hash,status,attempts,received_at,updated_at) VALUES (?,?,?,?, 'processing',1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
                ->execute([$id,$channelId,$providerEventId,$payloadHash]);
            if($owns)tamasyaFinancialCommit($pdo);return 'claimed';
        }
        if(!hash_equals((string)($row['payload_hash']??''),$payloadHash))throw new RuntimeException('Event ID provider dipakai ulang dengan payload berbeda.');
        if((string)$row['status']==='completed'){if($owns)tamasyaFinancialCommit($pdo);return 'completed';}
        $updated=strtotime((string)($row['updated_at']??''))?:0;
        if((string)$row['status']==='processing'&&$updated>time()-120){if($owns)tamasyaFinancialCommit($pdo);return 'busy';}
        $pdo->prepare("UPDATE communication_webhook_events SET status='processing',attempts=attempts+1,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$row['id']]);
        if($owns)tamasyaFinancialCommit($pdo);return 'claimed';
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function tamasyaCommunicationCompleteEvent(PDO $pdo,string $channelId,string $providerEventId,bool $completed,?string $error=null): void {
    try{
        $pdo->prepare("UPDATE communication_webhook_events SET status=?,last_error=?,processed_at=?,updated_at=CURRENT_TIMESTAMP WHERE channel_id=? AND provider_event_id=?")
            ->execute([$completed?'completed':'failed',$completed?null:substr((string)$error,0,500),$completed?date('Y-m-d H:i:s'):null,$channelId,$providerEventId]);
    }catch(Throwable $ignored){}
}

function tamasyaCommunicationMirrorTelegramEvent(PDO $pdo,array $update): void {
    $eventId=trim((string)($update['update_id']??''));
    if($eventId==='')return;
    try{tamasyaCommunicationClaimEvent($pdo,'channel_telegram_main',$eventId,$update);}catch(Throwable $e){error_log(clientExceptionMessage('[communication] Telegram mirror event failed',$e));}
}

function tamasyaCommunicationParseCommand(array $normalized): array {
    $command=strtolower(trim((string)($normalized['command']??'')));
    $args=is_array($normalized['arguments']??null)?$normalized['arguments']:[];
    $text=trim((string)($normalized['text']??''));
    if($command!=='')return ['command'=>$command,'arguments'=>$args];
    $lower=strtolower($text);
    if($lower===''||in_array($lower,['help','bantuan','menu','/start','/help'],true))return ['command'=>'system.help','arguments'=>[]];
    if(preg_match('/^(?:cek\s+)?kamar\s+([a-z0-9._-]+)$/i',$text,$m))return ['command'=>'room.status','arguments'=>['roomNumber'=>$m[1]]];
    if(preg_match('/^(?:cek\s+)?(?:kamar\s+)?(?:kosong|tersedia)$/i',$text))return ['command'=>'room.available','arguments'=>[]];
    return ['command'=>'system.unknown','arguments'=>['text'=>$text]];
}

function tamasyaCommunicationExecuteCommand(PDO $pdo,array $identity,array $parsed): array {
    $command=(string)($parsed['command']??'system.unknown');$args=(array)($parsed['arguments']??[]);
    $role=strtolower((string)($identity['role']??''));
    if((string)($identity['staff_status']??'')!=='active')throw new RuntimeException('Akun staf tidak aktif.');
    if($command==='system.help')return [
        'success'=>true,'command'=>$command,'title'=>'Perintah TAMASYA',
        'text'=>"Perintah fondasi multi-channel yang tersedia:\n• kamar 101 — melihat status kamar\n• kamar kosong — daftar kamar tersedia\n\nTelegram tetap memakai seluruh menu operasional lama. Adapter baru ditambahkan bertahap melalui Communication Core.",
        'actions'=>[]
    ];
    if($command==='room.status'){
        $roomNumber=trim((string)($args['roomNumber']??''));if($roomNumber==='')throw new InvalidArgumentException('Nomor kamar wajib diisi.');
        $stmt=$pdo->prepare("SELECT number,type,status,floor FROM rooms WHERE number=? LIMIT 1");$stmt->execute([$roomNumber]);$room=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$room)return ['success'=>false,'command'=>$command,'title'=>'Kamar tidak ditemukan','text'=>"Kamar {$roomNumber} tidak tersedia dalam database.",'actions'=>[]];
        $blockers=getRoomOperationalBlockers($pdo,$roomNumber,false);
        $operationalStatus=tamasyaDeriveRoomOperationalStatus($blockers);
        $room['operationalStatus']=$operationalStatus;$room['operationalBlockers']=$blockers;$room['stateMismatch']=strtolower((string)$room['status'])!==$operationalStatus;
        $detail=$blockers?"\n".roomOperationalBlockerMessage($roomNumber,$blockers):($room['stateMismatch']?"\nStatus cache tersimpan berbeda dan akan direkonsiliasi dari lifecycle domain.":'');
        return ['success'=>true,'command'=>$command,'title'=>'Status Kamar '.$room['number'],'text'=>"Tipe: {$room['type']}\nStatus operasional: {$operationalStatus}\nLantai: {$room['floor']}".$detail,'data'=>['room'=>$room],'actions'=>[]];
    }
    if($command==='room.available'){
        $rows=array_map(static fn(array $room): array => [
            'number'=>(string)($room['number']??''),
            'type'=>(string)($room['type']??''),
            'floor'=>$room['floor']??''
        ],getOperationallySellableRooms($pdo,50));
        $lines=[];foreach($rows as $row)$lines[]=(string)$row['number'].' · '.(string)$row['type'].' · Lantai '.(string)$row['floor'];
        return ['success'=>true,'command'=>$command,'title'=>'Kamar Tersedia','text'=>$lines?implode("\n",$lines):'Tidak ada kamar yang siap dijual secara operasional.','data'=>['rooms'=>$rows],'actions'=>[]];
    }
    return ['success'=>false,'command'=>$command,'title'=>'Perintah belum tersedia','text'=>'Perintah ini belum terdaftar pada Communication Core. Gunakan “bantuan”.','actions'=>[]];
}

function tamasyaCommunicationQueue(PDO $pdo,array $payload,array $actor=[]): string {
    $id=function_exists('generateServerId')?generateServerId('outbox'):'outbox_'.bin2hex(random_bytes(12));
    $eventType=trim((string)($payload['eventType']??'notification'))?:'notification';
    $recipientStaffId=trim((string)($payload['recipientStaffId']??''));
    $preferredChannelId=trim((string)($payload['preferredChannelId']??''));
    $fallback=is_array($payload['fallbackChannelIds']??null)?$payload['fallbackChannelIds']:[];
    $message=is_array($payload['message']??null)?$payload['message']:['text'=>(string)($payload['text']??'')];
    $pdo->prepare("INSERT INTO communication_outbox(id,event_type,recipient_staff_id,preferred_channel_id,fallback_channel_ids,message_json,priority,status,scheduled_at,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,'pending',COALESCE(?,CURRENT_TIMESTAMP),?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
        ->execute([$id,$eventType,$recipientStaffId?:null,$preferredChannelId?:null,json_encode($fallback,JSON_UNESCAPED_UNICODE),json_encode($message,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),max(0,min(9,(int)($payload['priority']??5))),$payload['scheduledAt']??null,$actor['id']??null]);
    return $id;
}

function tamasyaCommunicationFindRecipient(PDO $pdo,string $staffId,string $channelId): ?array {
    $stmt=$pdo->prepare("SELECT ci.provider_user_id,ci.provider_conversation_id,ci.staff_id
        FROM communication_identities ci JOIN staff s ON s.id=ci.staff_id
        WHERE ci.staff_id=? AND ci.channel_id=? AND ci.status='active' AND s.status='active' LIMIT 1");
    $stmt->execute([$staffId,$channelId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if($row)return ['providerUserId'=>$row['provider_user_id'],'providerConversationId'=>$row['provider_conversation_id'],'staffId'=>$row['staff_id']];
    if($channelId==='channel_web_app')return ['staffId'=>$staffId];
    return null;
}

function tamasyaCommunicationIdentityAuditSnapshot(array $row): array {
    return [
        'id'=>(string)($row['id']??''),
        'channelId'=>(string)($row['channel_id']??$row['channelId']??''),
        'staffId'=>(string)($row['staff_id']??$row['staffId']??''),
        'status'=>(string)($row['status']??''),
        'providerUserHash'=>trim((string)($row['provider_user_id']??''))!==''?hash('sha256',(string)$row['provider_user_id']):null,
        'providerConversationHash'=>trim((string)($row['provider_conversation_id']??''))!==''?hash('sha256',(string)$row['provider_conversation_id']):null,
        'verifiedAt'=>$row['verified_at']??null,
        'updatedAt'=>$row['updated_at']??null,
    ];
}

function tamasyaCommunicationProcessOutbox(PDO $pdo,int $limit=20,array $actor=[]): array {
    if(!tamasyaExternalSideEffectsAllowed())return ['processed'=>0,'sent'=>0,'failed'=>0,'skipped'=>'not_primary'];
    // Recover a worker claim that was abandoned before finalization. Ten minutes
    // is deliberately far above the configured provider timeouts. Delivery is
    // at-least-once: a process crash after the provider accepted a message but
    // before local finalization may cause a rare duplicate on retry.
    $pdo->exec("UPDATE communication_outbox
        SET status=CASE WHEN attempts<3 THEN 'retry' ELSE 'failed' END,
            last_error=CASE WHEN attempts<3 THEN 'Recovered stale processing claim after worker interruption.' ELSE 'Stale processing claim exhausted retry limit.' END,
            updated_at=CURRENT_TIMESTAMP
        WHERE status='processing' AND updated_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 10 MINUTE)");
    $limit=max(1,min(100,$limit));$processed=0;$sent=0;$failed=0;$attentionRequired=0;
    $actor=$actor?:['id'=>null,'name'=>'Communication Worker','role'=>'system'];
    $candidateIds=$pdo->query("SELECT id FROM communication_outbox WHERE status IN ('pending','retry') AND (scheduled_at IS NULL OR scheduled_at<=CURRENT_TIMESTAMP) ORDER BY priority DESC,created_at ASC LIMIT {$limit}")->fetchAll(PDO::FETCH_COLUMN)?:[];
    foreach($candidateIds as $candidateId){
        $row=null;$attemptNumber=0;
        $pdo->beginTransaction();
        try{
            $claim=$pdo->prepare("SELECT * FROM communication_outbox WHERE id=? LIMIT 1 FOR UPDATE");
            $claim->execute([(string)$candidateId]);$row=$claim->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$row||!in_array((string)($row['status']??''),['pending','retry'],true)){tamasyaFinancialCommit($pdo);continue;}
            if(!empty($row['scheduled_at'])&&strtotime((string)$row['scheduled_at'])>time()){tamasyaFinancialCommit($pdo);continue;}
            $attemptNumber=(int)($row['attempts']??0)+1;
            $pdo->prepare("UPDATE communication_outbox SET status='processing',attempts=?,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$attemptNumber,$row['id']]);
            tamasyaFinancialCommit($pdo);
        }catch(Throwable $claimError){if($pdo->inTransaction())$pdo->rollBack();throw $claimError;}
        if(!$row)continue;
        $processed++;$staffId=trim((string)($row['recipient_staff_id']??''));
        $channels=[];if(trim((string)($row['preferred_channel_id']??''))!=='')$channels[]=(string)$row['preferred_channel_id'];
        foreach(tamasyaCommunicationJsonDecode($row['fallback_channel_ids']??null,[]) as $fallback){$fallback=trim((string)$fallback);if($fallback!==''&&!in_array($fallback,$channels,true))$channels[]=$fallback;}
        $message=tamasyaCommunicationJsonDecode($row['message_json']??null,[]);
        $message['_delivery']=['outboxId'=>(string)$row['id'],'attemptNumber'=>$attemptNumber,'idempotencyKey'=>'communication:'.(string)$row['id']];
        $directRecipientMarker=(string)($message['_tamasyaDirectRecipient']??'');
        $directConversationId=trim((string)($message['_providerConversationId']??''));
        $directProviderUserId=trim((string)($message['_providerUserId']??$directConversationId));
        $directChannelId=trim((string)($message['_directChannelId']??''));
        $delivered=false;$lastError='Tidak ada channel/recipient yang dapat dipakai.';$attemptRows=[];
        foreach($channels as $channelId){
            $channel=tamasyaCommunicationGetChannel($pdo,$channelId,false);if(!$channel||empty($channel['enabled'])||empty($channel['outbound_enabled']))continue;
            $adapter=tamasyaCommunicationAdapter((string)$channel['provider_key']);if(!$adapter)continue;
            $recipient=null;
            // System-created durable broadcast rows may pin the exact provider
            // conversation so Telegram group/private routing survives a retry.
            // The marker is intentionally internal-only; there is no public API
            // that accepts arbitrary outbox payload creation.
            if($directRecipientMarker==='telegram_broadcast_v1' && $directConversationId!=='' && ($directChannelId===''||$directChannelId===$channelId) && (string)$channel['provider_key']==='telegram'){
                $recipient=['providerUserId'=>$directProviderUserId?:$directConversationId,'providerConversationId'=>$directConversationId,'staffId'=>$staffId?:null];
            }else{
                $recipient=tamasyaCommunicationFindRecipient($pdo,$staffId,$channelId);
            }
            if(!$recipient)continue;
            $attemptId=function_exists('generateServerId')?generateServerId('delivery'):'delivery_'.bin2hex(random_bytes(12));
            try{$result=$adapter->send($pdo,$channel,$recipient,$message);}catch(Throwable $e){$result=['success'=>false,'status'=>'failed','error'=>clientExceptionMessage('Adapter send failed',$e)];}
            $attemptRows[]=['id'=>$attemptId,'channelId'=>$channelId,'providerMessageId'=>$result['providerMessageId']??null,'status'=>$result['status']??(!empty($result['success'])?'sent':'failed'),'error'=>$result['error']??null];
            if(!empty($result['success'])){$delivered=true;$sent++;break;}$lastError=(string)($result['error']??'Pengiriman gagal.');
        }
        if(!$delivered)$failed++;
        $pdo->beginTransaction();
        try{
            $lock=$pdo->prepare("SELECT * FROM communication_outbox WHERE id=? LIMIT 1 FOR UPDATE");$lock->execute([$row['id']]);$before=$lock->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$before||((string)($before['status']??''))!=='processing'||((int)($before['attempts']??0))!==$attemptNumber)throw new RuntimeException('Klaim outbox berubah sebelum finalisasi.');
            foreach($attemptRows as $attemptRow){
                $pdo->prepare("INSERT INTO communication_delivery_attempts(id,outbox_id,channel_id,provider_message_id,status,attempt_number,error_message,started_at,completed_at)
                    VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
                    ->execute([$attemptRow['id'],$row['id'],$attemptRow['channelId'],$attemptRow['providerMessageId'],$attemptRow['status'],$attemptNumber,$attemptRow['error']]);
            }
            $nextStatus=$delivered?'sent':($attemptNumber<3?'retry':'failed');
            $pdo->prepare("UPDATE communication_outbox SET status=?,last_error=?,sent_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$nextStatus,$delivered?null:substr($lastError,0,500),$delivered?date('Y-m-d H:i:s'):null,$row['id']]);
            $afterStmt=$pdo->prepare("SELECT * FROM communication_outbox WHERE id=? LIMIT 1");$afterStmt->execute([$row['id']]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:[];
            writeRequiredEnterpriseAudit($pdo,$actor,$delivered?'Mengirim outbox komunikasi':'Mencatat kegagalan outbox komunikasi','communication_outbox',(string)$row['id'],tamasyaAuditAttemptSnapshot($before),tamasyaAuditAttemptSnapshot($after),'communication_worker');
            tamasyaFinancialCommit($pdo);
        }catch(Throwable $finalizeError){if($pdo->inTransaction())$pdo->rollBack();$attentionRequired++;error_log(clientExceptionMessage('[communication] finalisasi outbox memerlukan perhatian manual',$finalizeError));}
    }
    return compact('processed','sent','failed','attentionRequired');
}

function tamasyaCommunicationCreateBindingCode(PDO $pdo,string $channelId,string $staffId,string $createdBy): array {
    $channel=tamasyaCommunicationGetChannel($pdo,$channelId,false);
    if(!$channel||empty($channel['enabled'])||empty($channel['inbound_enabled']))throw new RuntimeException('Channel inbound belum aktif.');
    $adapter=tamasyaCommunicationAdapter((string)$channel['provider_key']);
    if(!$adapter||empty($adapter->capabilities()['inboundWebhook']))throw new RuntimeException('Adapter tidak mendukung binding melalui webhook.');
    $stmt=$pdo->prepare("SELECT id,name,status FROM staff WHERE id=? LIMIT 1");$stmt->execute([$staffId]);$staff=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$staff||(string)$staff['status']!=='active')throw new RuntimeException('Staf aktif tidak ditemukan.');
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$code='';
    for($i=0;$i<8;$i++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];
    $hash=hash('sha256',strtoupper($code));$id=function_exists('generateServerId')?generateServerId('bindcode'):'bindcode_'.bin2hex(random_bytes(12));
    $pdo->prepare("DELETE FROM communication_binding_codes WHERE channel_id=? AND staff_id=? AND used_at IS NULL")->execute([$channelId,$staffId]);
    $pdo->prepare("INSERT INTO communication_binding_codes(id,channel_id,staff_id,code_hash,expires_at,created_by,created_at) VALUES (?,?,?,?,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 15 MINUTE),?,CURRENT_TIMESTAMP)")
        ->execute([$id,$channelId,$staffId,$hash,$createdBy?:null]);
    return ['id'=>$id,'code'=>$code,'channelId'=>$channelId,'staffId'=>$staffId,'staffName'=>$staff['name'],'expiresInMinutes'=>15];
}

function tamasyaCommunicationBindIdentityWithCode(PDO $pdo,string $channelId,string $providerUserId,string $providerConversationId,string $code,array $metadata=[]): array {
    $providerUserId=trim($providerUserId);$providerConversationId=trim($providerConversationId);$code=strtoupper(trim($code));
    if($providerUserId===''||$providerConversationId===''||!preg_match('/^[A-Z2-9]{8}$/',$code))throw new InvalidArgumentException('Identitas provider atau kode binding tidak valid.');
    $hash=hash('sha256',$code);$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM communication_binding_codes WHERE channel_id=? AND code_hash=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP LIMIT 1 FOR UPDATE");
        $stmt->execute([$channelId,$hash]);$bindingCode=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$bindingCode)throw new RuntimeException('Kode binding tidak ditemukan, sudah digunakan, atau kedaluwarsa.');
        $staffStmt=$pdo->prepare("SELECT id,name,role,status FROM staff WHERE id=? LIMIT 1 FOR UPDATE");$staffStmt->execute([$bindingCode['staff_id']]);$staff=$staffStmt->fetch(PDO::FETCH_ASSOC);
        if(!$staff||(string)$staff['status']!=='active')throw new RuntimeException('Akun staf tidak aktif.');
        $conflict=$pdo->prepare("SELECT * FROM communication_identities WHERE channel_id=? AND (provider_user_id=? OR staff_id=?) LIMIT 1 FOR UPDATE");
        $conflict->execute([$channelId,$providerUserId,$staff['id']]);$existing=$conflict->fetch(PDO::FETCH_ASSOC);
        if($existing&&(string)$existing['staff_id']!==(string)$staff['id'])throw new RuntimeException('Identitas provider sudah terikat ke staf lain.');
        $identityId=$existing['id']??('identity_'.substr(hash('sha256',$channelId.'|'.$providerUserId.'|'.$staff['id']),0,56));
        $pdo->prepare("INSERT INTO communication_identities(id,channel_id,provider_user_id,provider_conversation_id,staff_id,status,verified_at,metadata_json,created_at,updated_at)
            VALUES (?,?,?,?,?,'active',CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE provider_user_id=VALUES(provider_user_id),provider_conversation_id=VALUES(provider_conversation_id),staff_id=VALUES(staff_id),status='active',verified_at=CURRENT_TIMESTAMP,metadata_json=VALUES(metadata_json),updated_at=CURRENT_TIMESTAMP")
            ->execute([$identityId,$channelId,$providerUserId,$providerConversationId,$staff['id'],json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $pdo->prepare("UPDATE communication_binding_codes SET used_at=CURRENT_TIMESTAMP,used_provider_user_id=? WHERE id=?")->execute([$providerUserId,$bindingCode['id']]);
        $afterStmt=$pdo->prepare("SELECT * FROM communication_identities WHERE id=? LIMIT 1");$afterStmt->execute([$identityId]);$afterIdentity=$afterStmt->fetch(PDO::FETCH_ASSOC)?:[];
        if($owns)tamasyaFinancialCommit($pdo);
        return ['identityId'=>$identityId,'staffId'=>$staff['id'],'staffName'=>$staff['name'],'role'=>$staff['role'],'beforeIdentity'=>$existing?:null,'afterIdentity'=>$afterIdentity];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function tamasyaCommunicationUnbindIdentity(PDO $pdo,string $identityId,array $actor): void {
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM communication_identities WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$identityId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Binding tidak ditemukan.');
        if((string)$row['channel_id']==='channel_telegram_main')throw new RuntimeException('Binding Telegram dicabut melalui menu Karyawan agar tabel kompatibilitas tetap konsisten.');
        $pdo->prepare("UPDATE communication_identities SET status='revoked',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$identityId]);
        if($owns)tamasyaFinancialCommit($pdo);
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
