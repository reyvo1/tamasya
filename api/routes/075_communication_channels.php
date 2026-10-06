<?php
/** Admin API for provider-neutral communication channels. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), [
    'communication-channels','communication-channel-save','communication-channel-toggle',
    'communication-channel-health','communication-bindings','communication-deliveries',
    'communication-outbox','communication-outbox-process','communication-inbox','communication-binding-code','communication-unbind'
], true)) { return; }
$routeHandled=true;

switch((string)$action){
    case 'communication-channels':
        requireRoles($loggedInStaff,['admin']);
        tamasyaCommunicationSyncLegacyChannels($pdo);
        $channels=[];
        foreach(tamasyaCommunicationListChannels($pdo) as $row){
            $public=tamasyaCommunicationPublicChannel($row);
            $adapter=tamasyaCommunicationAdapter((string)$row['provider_key']);
            $public['adapterInstalled']=$adapter!==null;
            $public['capabilities']=$adapter?$adapter->capabilities():[];
            $channels[]=$public;
        }
        echo json_encode(['success'=>true,'adapters'=>tamasyaCommunicationAdapterManifests(),'channels'=>$channels],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-channel-save':
        requireRoles($loggedInStaff,['admin']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $channelId=trim((string)($input['id']??''));
        $providerKey=strtolower(trim((string)($input['providerKey']??'')));
        $displayName=trim((string)($input['displayName']??''));
        if($channelId==='')$channelId='channel_'.$providerKey.'_'.substr(hash('sha256',random_bytes(16)),0,16);
        if(!preg_match('/^[A-Za-z0-9._:-]{3,80}$/',$channelId))throw new InvalidArgumentException('ID channel tidak valid.');
        if($displayName===''||tamasyaStringLength($displayName)>150)throw new InvalidArgumentException('Nama channel wajib diisi maksimal 150 karakter.');
        $adapter=tamasyaCommunicationAdapter($providerKey);
        if(!$adapter)throw new InvalidArgumentException('Adapter belum terpasang pada server. Tambahkan file adapter yang mengikuti kontrak TAMASYA.');
        $existing=tamasyaCommunicationGetChannel($pdo,$channelId,true);
        if($existing&&in_array((string)$existing['managed_by'],['legacy_config','internal'],true))throw new RuntimeException('Channel bawaan dikelola oleh konfigurasi lama/internal dan tidak dapat ditimpa dari endpoint generik.');
        $config=is_array($input['config']??null)?$input['config']:[];
        $validation=$adapter->validateConfiguration($config);
        if(empty($validation['valid']))throw new InvalidArgumentException(implode(' ',(array)($validation['errors']??['Konfigurasi channel tidak valid.'])));
        $credential=(string)($input['credential']??'');
        $webhookSecret=(string)($input['webhookSecret']??'');
        $credentialEncrypted=$existing['credential_encrypted']??null;
        $webhookSecretEncrypted=$existing['webhook_secret_encrypted']??null;
        if($credential!==''&&$credential!=='********')$credentialEncrypted=encryptStoredSecret($credential);
        if($webhookSecret!==''&&$webhookSecret!=='********'){
            if(strlen($webhookSecret)<32)throw new InvalidArgumentException('Secret webhook minimal 32 karakter.');
            $webhookSecretEncrypted=encryptStoredSecret($webhookSecret);
        }
        $pdo->beginTransaction();
        $existing=tamasyaCommunicationGetChannel($pdo,$channelId,true);
        if($existing&&in_array((string)$existing['managed_by'],['legacy_config','internal'],true))throw new RuntimeException('Channel bawaan dikelola oleh konfigurasi lama/internal dan tidak dapat ditimpa dari endpoint generik.');
        $credentialEncrypted=$existing['credential_encrypted']??$credentialEncrypted;
        $webhookSecretEncrypted=$existing['webhook_secret_encrypted']??$webhookSecretEncrypted;
        if($credential!==''&&$credential!=='********')$credentialEncrypted=encryptStoredSecret($credential);
        if($webhookSecret!==''&&$webhookSecret!=='********')$webhookSecretEncrypted=encryptStoredSecret($webhookSecret);
        $pdo->prepare("INSERT INTO communication_channels
            (id,provider_key,display_name,enabled,inbound_enabled,outbound_enabled,managed_by,config_json,credential_encrypted,webhook_secret_encrypted,health_status,created_at,updated_at)
            VALUES (?,?,?,?,?,?,'communication_core',?,?,?,'unknown',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE provider_key=VALUES(provider_key),display_name=VALUES(display_name),enabled=VALUES(enabled),inbound_enabled=VALUES(inbound_enabled),outbound_enabled=VALUES(outbound_enabled),config_json=VALUES(config_json),credential_encrypted=VALUES(credential_encrypted),webhook_secret_encrypted=VALUES(webhook_secret_encrypted),health_status='unknown',last_error=NULL,updated_at=CURRENT_TIMESTAMP")
            ->execute([$channelId,$providerKey,$displayName,!empty($input['enabled'])?1:0,!empty($input['inboundEnabled'])?1:0,!empty($input['outboundEnabled'])?1:0,json_encode($validation['normalizedConfig']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$credentialEncrypted,$webhookSecretEncrypted]);
        $after=tamasyaCommunicationGetChannel($pdo,$channelId)?:[];
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,$existing?'Ubah channel komunikasi':'Tambah channel komunikasi','communication_channel',$channelId,$existing? tamasyaAuditAttemptSnapshot($existing):null,tamasyaAuditAttemptSnapshot($after),'web');
        bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
        echo json_encode(['success'=>true,'channel'=>tamasyaCommunicationPublicChannel(tamasyaCommunicationGetChannel($pdo,$channelId)?:[])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-channel-toggle':
        requireRoles($loggedInStaff,['admin']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $pdo->beginTransaction();
        $channelId=trim((string)($input['id']??''));$row=tamasyaCommunicationGetChannel($pdo,$channelId,true);
        if(!$row)throw new RuntimeException('Channel tidak ditemukan.');
        if(in_array((string)$row['managed_by'],['legacy_config','internal'],true))throw new RuntimeException('Status channel bawaan mengikuti konfigurasi asalnya.');
        $pdo->prepare("UPDATE communication_channels SET enabled=?,inbound_enabled=?,outbound_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([!empty($input['enabled'])?1:0,!empty($input['inboundEnabled'])?1:0,!empty($input['outboundEnabled'])?1:0,$channelId]);
        $after=tamasyaCommunicationGetChannel($pdo,$channelId)?:[];
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Ubah status channel komunikasi','communication_channel',$channelId,tamasyaAuditAttemptSnapshot($row),tamasyaAuditAttemptSnapshot($after),'web');
        bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
        echo json_encode(['success'=>true,'channel'=>tamasyaCommunicationPublicChannel(tamasyaCommunicationGetChannel($pdo,$channelId)?:[])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-channel-health':
        requireRoles($loggedInStaff,['admin']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $channelId=trim((string)($input['id']??''));$row=tamasyaCommunicationGetChannel($pdo,$channelId,false);
        if(!$row)throw new RuntimeException('Channel tidak ditemukan.');
        $adapter=tamasyaCommunicationAdapter((string)$row['provider_key']);if(!$adapter)throw new RuntimeException('Adapter channel tidak terpasang.');
        $health=$adapter->healthCheck($pdo,$row);
        $pdo->beginTransaction();
        $before=tamasyaCommunicationGetChannel($pdo,$channelId,true);
        if(!$before)throw new RuntimeException('Channel tidak ditemukan saat menyimpan hasil pemeriksaan.');
        $pdo->prepare("UPDATE communication_channels SET health_status=?,last_checked_at=CURRENT_TIMESTAMP,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([(string)($health['status']??(!empty($health['healthy'])?'healthy':'error')),!empty($health['healthy'])?null:substr((string)($health['message']??'Health check gagal.'),0,1000),$channelId]);
        $after=tamasyaCommunicationGetChannel($pdo,$channelId)?:[];
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memeriksa kesehatan channel komunikasi','communication_channel',$channelId,tamasyaAuditAttemptSnapshot($before),tamasyaAuditAttemptSnapshot($after),'web');
        tamasyaFinancialCommit($pdo);
        echo json_encode(['success'=>true,'health'=>$health,'channel'=>tamasyaCommunicationPublicChannel(tamasyaCommunicationGetChannel($pdo,$channelId)?:[])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-binding-code':
        requireRoles($loggedInStaff,['admin']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $pdo->beginTransaction();
        $result=tamasyaCommunicationCreateBindingCode($pdo,trim((string)($input['channelId']??'')),trim((string)($input['staffId']??'')),(string)($loggedInStaff['id']??''));
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Buat kode binding channel','communication_binding_code',$result['id'],null,['channelId'=>$result['channelId'],'staffId'=>$result['staffId'],'expiresInMinutes'=>$result['expiresInMinutes']],'web');
        tamasyaFinancialCommit($pdo);
        echo json_encode(['success'=>true,'bindingCode'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-unbind':
        requireRoles($loggedInStaff,['admin']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $identityId=trim((string)($input['identityId']??''));
        $pdo->beginTransaction();
        $beforeStmt=$pdo->prepare("SELECT * FROM communication_identities WHERE id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$identityId]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$before)throw new RuntimeException('Binding tidak ditemukan.');
        tamasyaCommunicationUnbindIdentity($pdo,$identityId,$loggedInStaff);
        $afterStmt=$pdo->prepare("SELECT * FROM communication_identities WHERE id=? LIMIT 1");$afterStmt->execute([$identityId]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Cabut binding channel','communication_identity',$identityId,tamasyaAuditAttemptSnapshot($before),$after? tamasyaAuditAttemptSnapshot($after):null,'web');
        bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
        echo json_encode(['success'=>true,'message'=>'Binding channel dicabut.']);
        break;

    case 'communication-bindings':
        requireRoles($loggedInStaff,['admin']);
        tamasyaCommunicationSyncLegacyChannels($pdo);
        $rows=$pdo->query("SELECT ci.id,ci.channel_id,cc.display_name AS channel_name,cc.provider_key,ci.provider_user_id,ci.provider_conversation_id,ci.staff_id,s.name AS staff_name,s.role,ci.status,ci.verified_at,ci.updated_at
            FROM communication_identities ci JOIN communication_channels cc ON cc.id=ci.channel_id JOIN staff s ON s.id=ci.staff_id ORDER BY cc.display_name,s.name")->fetchAll(PDO::FETCH_ASSOC)?:[];
        // External identifiers are operational identifiers; only Admin receives them.
        echo json_encode(['success'=>true,'bindings'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-deliveries':
        requireRoles($loggedInStaff,['admin']);
        $rows=$pdo->query("SELECT da.id,da.outbox_id,da.channel_id,cc.display_name AS channel_name,da.provider_message_id,da.status,da.attempt_number,da.error_message,da.started_at,da.completed_at
            FROM communication_delivery_attempts da LEFT JOIN communication_channels cc ON cc.id=da.channel_id ORDER BY da.started_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)?:[];
        echo json_encode(['success'=>true,'deliveries'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-outbox':
        requireRoles($loggedInStaff,['admin']);
        $rows=$pdo->query("SELECT id,event_type,recipient_staff_id,preferred_channel_id,fallback_channel_ids,message_json,priority,status,attempts,last_error,scheduled_at,sent_at,created_at,updated_at FROM communication_outbox ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){$row['fallbackChannelIds']=tamasyaCommunicationJsonDecode($row['fallback_channel_ids']??null,[]);$row['message']=tamasyaCommunicationJsonDecode($row['message_json']??null,[]);unset($row['fallback_channel_ids'],$row['message_json']);}
        echo json_encode(['success'=>true,'outbox'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-outbox-process':
        requireRoles($loggedInStaff,['admin']);
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;}
        $result=tamasyaCommunicationProcessOutbox($pdo,max(1,min(50,(int)($input['limit']??20))),$loggedInStaff);
        echo json_encode(['success'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'communication-inbox':
        $staffId=(string)($loggedInStaff['id']??'');
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&!empty($input['markReadId'])){
            $messageId=trim((string)$input['markReadId']);
            $pdo->beginTransaction();
            $beforeStmt=$pdo->prepare("SELECT id,staff_id,read_at FROM communication_inbox WHERE id=? AND staff_id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$messageId,$staffId]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($before){
                $pdo->prepare("UPDATE communication_inbox SET read_at=CURRENT_TIMESTAMP WHERE id=? AND staff_id=?")->execute([$messageId,$staffId]);
                $afterStmt=$pdo->prepare("SELECT id,staff_id,read_at FROM communication_inbox WHERE id=? AND staff_id=? LIMIT 1");$afterStmt->execute([$messageId,$staffId]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menandai pesan komunikasi dibaca','communication_inbox',$messageId,$before,$afterStmt->fetch(PDO::FETCH_ASSOC)?:null,'web');
            }
            tamasyaFinancialCommit($pdo);
        }
        $stmt=$pdo->prepare("SELECT id,channel_id,title,message,action_json,read_at,created_at FROM communication_inbox WHERE staff_id=? ORDER BY created_at DESC LIMIT 100");$stmt->execute([$staffId]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){$row['actions']=tamasyaCommunicationJsonDecode($row['action_json']??null,[]);unset($row['action_json']);}
        echo json_encode(['success'=>true,'messages'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;
}
