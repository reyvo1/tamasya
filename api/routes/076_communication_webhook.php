<?php
/** Public provider-neutral webhook endpoint for installed inbound adapters. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'communication-webhook') { return; }
$routeHandled=true;
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);return;}

$channelId=trim((string)($_GET['channelId']??($_SERVER['HTTP_X_TAMASYA_CHANNEL_ID']??($GLOBALS['tamasya_forwarded_channel_id']??''))));
if(empty($GLOBALS['tamasya_cluster_forwarded_channel'])&&tamasyaClusterEnabled()&&tamasyaNodeRole()==='local_backup'){
    $forward=tamasyaClusterForwardCommunicationWebhook($channelId,tamasyaCommunicationHeaders(),(string)$rawRequestBody);
    if(empty($forward['ok'])){http_response_code((int)($forward['status']??0)>0?(int)$forward['status']:503);echo json_encode(['success'=>false,'error'=>'Primary aktif tidak dapat memproses channel webhook: '.($forward['json']['error']??$forward['error']??'koneksi gagal')]);}
    else{http_response_code((int)($forward['status']??200));echo (string)($forward['body']??json_encode(['success'=>true]));}
    return;
}
if(!preg_match('/^[A-Za-z0-9._:-]{3,80}$/',$channelId)){http_response_code(400);echo json_encode(['success'=>false,'error'=>'channelId tidak valid.']);return;}
$channel=tamasyaCommunicationGetChannel($pdo,$channelId,false);
if(!$channel||empty($channel['enabled'])||empty($channel['inbound_enabled'])){http_response_code(404);echo json_encode(['success'=>false,'error'=>'Channel inbound tidak aktif atau tidak ditemukan.']);return;}
$adapter=tamasyaCommunicationAdapter((string)$channel['provider_key']);
if(!$adapter){http_response_code(503);echo json_encode(['success'=>false,'error'=>'Adapter channel belum terpasang.']);return;}
$headers=tamasyaCommunicationHeaders();
if(!$adapter->verifyInbound($pdo,$channel,$headers,(string)$rawRequestBody)){http_response_code(401);echo json_encode(['success'=>false,'error'=>'Signature webhook tidak valid.']);return;}
try{
    $normalized=$adapter->normalizeInbound($pdo,$channel,$headers,(string)$rawRequestBody);
    $eventId=trim((string)($normalized['providerEventId']??''));
    $claim=tamasyaCommunicationClaimEvent($pdo,$channelId,$eventId,$normalized['raw']??$normalized);
    if($claim==='completed'){echo json_encode(['success'=>true,'status'=>'duplicate_completed']);return;}
    if($claim==='busy'){http_response_code(503);echo json_encode(['success'=>false,'retryable'=>true,'error'=>'Event sedang diproses.']);return;}
    $identity=tamasyaCommunicationResolveIdentity($pdo,$channelId,(string)($normalized['providerUserId']??''));
    if(!$identity){
        $text=trim((string)($normalized['text']??''));$bindCode='';
        if(preg_match('/^(?:bind|hubungkan|tautkan)\s+([A-Z2-9]{8})$/i',$text,$m))$bindCode=strtoupper($m[1]);
        if(strtolower(trim((string)($normalized['command']??'')))==='identity.bind')$bindCode=strtoupper(trim((string)(($normalized['arguments']['code']??''))));
        $conversationType=strtolower(trim((string)($normalized['conversationType']??'private')));
        if($bindCode!==''&&!in_array($conversationType,['private','direct','dm'],true))throw new RuntimeException('Binding akun hanya boleh dilakukan melalui percakapan privat.');
        if($bindCode!==''){
            $pdo->beginTransaction();
            try{
                $bound=tamasyaCommunicationBindIdentityWithCode($pdo,$channelId,(string)($normalized['providerUserId']??''),(string)($normalized['providerConversationId']??''),$bindCode,['conversationType'=>$conversationType]);
                $complete=$pdo->prepare("UPDATE communication_webhook_events SET status='completed',last_error=NULL,processed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE channel_id=? AND provider_event_id=? AND status='processing'");
                $complete->execute([$channelId,$eventId]);
                if($eventId!==''&&$complete->rowCount()!==1)throw new RuntimeException('Klaim event binding tidak lagi aktif.');
                $bindingActor=['id'=>$bound['staffId'],'name'=>$bound['staffName'],'role'=>$bound['role'],'deviceId'=>'channel:'.$channelId];
                writeRequiredEnterpriseAudit($pdo,$bindingActor,'Menghubungkan identitas channel','communication_identity',(string)$bound['identityId'],!empty($bound['beforeIdentity'])?tamasyaCommunicationIdentityAuditSnapshot($bound['beforeIdentity']):null,tamasyaCommunicationIdentityAuditSnapshot((array)($bound['afterIdentity']??[])),(string)($channel['provider_key']??'communication'));
                tamasyaFinancialCommit($pdo);
            }catch(Throwable $bindingError){if($pdo->inTransaction())$pdo->rollBack();throw $bindingError;}
            echo json_encode(['success'=>true,'contractVersion'=>'1.0','response'=>['success'=>true,'command'=>'identity.bind','title'=>'Akun Terhubung','text'=>'Akun platform berhasil dihubungkan ke '.$bound['staffName'].' ('.$bound['role'].').']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            return;
        }
        tamasyaCommunicationCompleteEvent($pdo,$channelId,$eventId,true,null);
        http_response_code(403);
        echo json_encode(['success'=>false,'code'=>'BINDING_REQUIRED','error'=>'Identitas platform belum terikat. Minta Admin membuat kode, lalu kirim: BIND KODEANDA.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return;
    }
    $parsed=tamasyaCommunicationParseCommand($normalized);
    $result=tamasyaCommunicationExecuteCommand($pdo,$identity,$parsed);
    tamasyaCommunicationCompleteEvent($pdo,$channelId,$eventId,true,null);
    echo json_encode(['success'=>true,'contractVersion'=>'1.0','response'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $eventId=isset($eventId)?$eventId:'';if($eventId!=='')tamasyaCommunicationCompleteEvent($pdo,$channelId,$eventId,false,$e->getMessage());
    tamasyaApplyExceptionHttpStatus($e,500);
    echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Communication webhook gagal',$e)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
