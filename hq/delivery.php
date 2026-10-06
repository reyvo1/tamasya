<?php
declare(strict_types=1);
require_once __DIR__.'/reporting.php';
function tamasyaHqQueueDelivery(PDO $pdo,array $config,array $viewer,array $input): array {
    if(!in_array($viewer['role']??'viewer',['platform_admin','company_admin','finance'],true))throw new TamasyaHqError('DELIVERY_ROLE_DENIED',403,'Pengiriman memerlukan akses keuangan atau administrator.');
    $operation=$input['operationId']??null;$destination=$input['destinationId']??null;$reportId=$input['reportId']??'';
    if(!is_string($operation)||!preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$operation)||!tamasyaHybridId($destination)||!is_string($reportId))throw new InvalidArgumentException('Operation/destination/report tidak valid.');
    $target=$config['deliveryDestinations'][$viewer['companyId']][$destination]??null;
    if(!is_array($target)||($target['enabled']??false)!==true||!in_array($target['channel']??'', ['email','telegram'],true))throw new TamasyaHqError('DESTINATION_DENIED',403,'Tujuan tidak aktif dalam company ini.');
    $data=tamasyaHqStoredReport($pdo,$viewer,$reportId);$document=tamasyaHqCanonicalDocument($data);$csv=tamasyaCanonicalReportRender($document,'csv');
    $job=hash('sha256',$viewer['companyId']."\n".$operation);
    $payload=['contractVersion'=>'tamasya-report-delivery-v1','jobId'=>$job,'operationId'=>$operation,'companyId'=>$viewer['companyId'],'reportId'=>$reportId,'destinationId'=>$destination,'channel'=>$target['channel'],'text'=>tamasyaHqReportMessage($data),'html'=>tamasyaCanonicalReportEmailBody($document),'attachment'=>['filename'=>$csv['filename'],'mime'=>$csv['mime'],'base64'=>base64_encode($csv['body'])]];
    $json=tamasyaHybridJson($payload);$hash=hash('sha256',$json);
    $q=$pdo->prepare("INSERT IGNORE INTO hq_delivery_jobs(job_id,company_id,operation_id,report_id,destination_id,payload_hash,payload_json,status) VALUES (?,?,?,?,?,?,?,'pending')");$q->execute([$job,$viewer['companyId'],$operation,$reportId,$destination,$hash,$json]);
    $q=$pdo->prepare('SELECT payload_hash,status FROM hq_delivery_jobs WHERE job_id=?');$q->execute([$job]);$row=$q->fetch();
    if(!$row||!hash_equals($hash,$row['payload_hash']))throw new TamasyaHqError('OPERATION_CONFLICT',409,'Operation ID sudah terikat ke pengiriman lain.');
    return ['jobId'=>$job,'operation_id'=>$operation,'reportId'=>$reportId,'status'=>$row['status']];
}
function tamasyaHqDeliverOnce(PDO $pdo,array $config): array {
    // A crashed sender may already have delivered. Never resend automatically.
    $pdo->exec("UPDATE hq_delivery_jobs SET status='uncertain',last_error='WORKER_INTERRUPTED' WHERE status='sending' AND updated_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 120 SECOND)");
    $pdo->beginTransaction();
    try{
        $job=$pdo->query("SELECT * FROM hq_delivery_jobs WHERE status='pending' ORDER BY created_at,job_id LIMIT 1 FOR UPDATE")->fetch();
        if(!$job){$pdo->commit();return ['status'=>'idle'];}
        $pdo->prepare("UPDATE hq_delivery_jobs SET status='sending',attempts=attempts+1,updated_at=CURRENT_TIMESTAMP WHERE job_id=?")->execute([$job['job_id']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    $status='uncertain';$error='ACK_NOT_VERIFIED';$networkAttempted=false;
    try{
        if(!hash_equals($job['payload_hash'],hash('sha256',$job['payload_json'])))throw new RuntimeException('PAYLOAD_CHECKSUM');
        $target=$config['deliveryDestinations'][$job['company_id']][$job['destination_id']]??[];
        if(($target['enabled']??false)!==true)throw new RuntimeException('DESTINATION_DISABLED');
        if(($config['controlPlane']['enabled']??false)===true){$q=$pdo->prepare('SELECT enabled FROM hq_companies WHERE id=?');$q->execute([$job['company_id']]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('COMPANY_DISABLED');}
        $url=(string)($target['bridgeUrl']??'');$parts=parse_url($url);$secret=$target['secret']??'';
        if(!$parts||($parts['scheme']??'')!=='https'||isset($parts['user'])||!is_string($secret)||strlen($secret)<32)throw new RuntimeException('DELIVERY_CONFIG_INVALID');
        $caFile=tamasyaHqOutboundCaFile($target);

        $ch=curl_init($url);$response='';$timestamp=(string)time();
        $options=[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$job['payload_json'],
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>30,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: application/json',
                'Idempotency-Key: '.$job['job_id'],
                'X-Tamasya-Timestamp: '.$timestamp,
                'X-Tamasya-Signature: '.hash_hmac('sha256',$timestamp."\n".$job['job_id']."\n".$job['payload_hash'],$secret)
            ],
            CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$response){
                if(strlen($response)+strlen($chunk)>65536)return 0;
                $response.=$chunk;
                return strlen($chunk);
            },
        ];
        if($caFile!==null)$options[CURLOPT_CAINFO]=$caFile;
        curl_setopt_array($ch,$options);

        $networkAttempted=true;
        $ok=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $curlErrno=curl_errno($ch);
        unset($ch);

        $decision=tamasyaHqDeliveryAckDecision($job,$ok!==false,$http,$response,$curlErrno);
        $status=$decision['status'];$error=$decision['code'];
    }catch(Throwable $e){
        if($networkAttempted){
            $status='uncertain';
            if($error==='ACK_NOT_VERIFIED')$error='DELIVERY_RUNTIME_ERROR';
        }else{
            $status='blocked';
            $candidate=$e->getMessage();
            $error=in_array($candidate,['TLS_CA_INVALID','TLS_CA_ABSOLUTE_REQUIRED','TLS_CA_UNREADABLE','PAYLOAD_CHECKSUM','DESTINATION_DISABLED','COMPANY_DISABLED','DELIVERY_CONFIG_INVALID'],true)
                ? $candidate
                : 'DELIVERY_CONFIG_OR_SCOPE';
        }
    }
    $pdo->prepare('UPDATE hq_delivery_jobs SET status=?,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE job_id=? AND status=\'sending\'')->execute([$status,$error,$job['job_id']]);
    return ['jobId'=>$job['job_id'],'reportId'=>$job['report_id'],'status'=>$status,'code'=>$error];
}
