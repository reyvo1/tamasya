<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
require_once __DIR__.'/reporting.php';
require_once __DIR__.'/realtime.php';
require_once __DIR__.'/delivery.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$operation=null;
try {
    $config=tamasyaHqConfig();
    $action=(string)($_GET['action'] ?? 'overview');
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($action,['report-snapshot','export','realtime-ticket','event-revision','queue-delivery','delivery-jobs','delivery-destinations'],true)) {
        $viewer=tamasyaHqViewer($config,(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
        if($action==='queue-delivery'&&$method==='POST'){
            $raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)throw new InvalidArgumentException('Request terlalu besar.');
            $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException('Request tidak valid.');
            echo tamasyaHybridJson(['success'=>true,'data'=>tamasyaHqQueueDelivery(tamasyaHqPdo($config),$config,$viewer,$input)]);
        } elseif($action==='delivery-destinations'&&$method==='GET'){
            $targets=[];if(in_array($viewer['role']??'viewer',['platform_admin','company_admin','finance'],true))foreach(($config['deliveryDestinations'][$viewer['companyId']]??[]) as $id=>$target)if(($target['enabled']??false)===true)$targets[]=['id'=>$id,'channel'=>$target['channel'],'label'=>$target['label']??$id];
            echo tamasyaHybridJson(['success'=>true,'data'=>$targets]);
        } elseif($action==='delivery-jobs'&&$method==='GET'){
            $pdo=tamasyaHqPdo($config);$offset=max(0,min(100000,(int)($_GET['offset']??0)));$q=$pdo->prepare('SELECT job_id,operation_id,report_id,destination_id,status,attempts,last_error,created_at FROM hq_delivery_jobs WHERE company_id=? ORDER BY created_at DESC,job_id LIMIT 50 OFFSET '.$offset);$q->execute([$viewer['companyId']]);$jobs=[];
            foreach($q->fetchAll() as $row){try{tamasyaHqStoredReport($pdo,$viewer,$row['report_id']);$jobs[]=$row;}catch(TamasyaHqError $denied){if(!in_array($denied->httpStatus,[403,404],true))throw $denied;}}
            echo tamasyaHybridJson(['success'=>true,'data'=>['jobs'=>$jobs,'offset'=>$offset,'limit'=>50]]);
        } elseif ($action==='report-snapshot' && $method==='POST') {
            $input=json_decode(file_get_contents('php://input',false,null,0,16384),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($input)||!is_array($input['properties']??[])) throw new InvalidArgumentException('Scope laporan tidak valid.');
            echo tamasyaHybridJson(['success'=>true,'data'=>tamasyaHqFreezeReport(tamasyaHqPdo($config),$viewer,$input['properties']??[],(string)($input['from']??''),(string)($input['to']??''))]);
        } elseif ($action==='export' && $method==='GET') {
            $data=tamasyaHqStoredReport(tamasyaHqPdo($config),$viewer,(string)($_GET['id']??''));
            $format=(string)($_GET['format']??'json');
            if ($format==='telegram') { header('Content-Type: text/plain; charset=utf-8');echo tamasyaHqReportMessage($data); }
            elseif ($format==='email') { header('Content-Type: text/html; charset=utf-8');echo tamasyaCanonicalReportEmailBody(tamasyaHqCanonicalDocument($data)); }
            elseif ($format==='json') echo tamasyaHybridJson($data);
            else { $render=tamasyaCanonicalReportRender(tamasyaHqCanonicalDocument($data),$format);header('Content-Type: '.$render['mime']);header('Content-Disposition: attachment; filename="'.$render['filename'].'"');echo $render['body']; }
        } elseif ($action==='realtime-ticket' && $method==='GET') echo tamasyaHybridJson(['success'=>true,'data'=>tamasyaHqRealtimeTicket($config,$viewer)]);
        elseif ($action==='event-revision' && $method==='GET') echo tamasyaHybridJson(['success'=>true,'data'=>tamasyaHqEventRevision(tamasyaHqPdo($config),$viewer)]);
        else throw new TamasyaHqError('METHOD_NOT_ALLOWED',405,'Metode tidak sesuai.');
    } else if ($action==='property-snapshot' && $method==='POST') {
        $stream=fopen('php://input','rb'); $body=stream_get_contents($stream,262145); fclose($stream);
        if (strlen($body)>262144) throw new TamasyaHqError('PAYLOAD_TOO_LARGE',413,'Snapshot melebihi 256 KiB.');
        $snapshot=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) throw new InvalidArgumentException('Snapshot harus objek JSON.');
        tamasyaHybridValidate($snapshot);
        $envelope=tamasyaHqAuthenticateSnapshot($config,$snapshot,$body,$_SERVER,time()); $operation=$envelope['operationId'];
        echo tamasyaHybridJson(tamasyaHqReceive(tamasyaHqPdo($config),$snapshot,$envelope));
    } elseif ($method==='GET' && in_array($action,['overview','consolidated'],true)) {
        $viewer=tamasyaHqViewer($config,(string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($action==='overview') { echo tamasyaHybridJson(['success'=>true,'companyId'=>$viewer['companyId'],'propertyIds'=>$viewer['propertyIds'],'contractVersion'=>'tamasya-hq-consolidation-v1']); }
        else {
            $requested=isset($_GET['properties']) ? explode(',',(string)$_GET['properties']) : [];
            $data=tamasyaHqRead(tamasyaHqPdo($config),$viewer,$requested,(string)($_GET['from'] ?? ''),(string)($_GET['to'] ?? ''));
            $etag='"'.hash('sha256',tamasyaHybridJson($data)).'"'; header('ETag: '.$etag);
            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')===$etag) http_response_code(304);
            else echo tamasyaHybridJson(['success'=>true,'data'=>$data]);
        }
    } else throw new TamasyaHqError('METHOD_NOT_ALLOWED',405,'Endpoint atau metode tidak tersedia.');
} catch (Throwable $error) {
    $status=$error instanceof TamasyaHqError ? $error->httpStatus : (($error instanceof InvalidArgumentException || $error instanceof JsonException) ? 422 : 503);
    http_response_code($status);
    if ($status===503) error_log('TAMASYA HQ: '.$error->getMessage());
    echo json_encode(['success'=>false,'code'=>$error instanceof TamasyaHqError ? $error->errorCode : ($status===422 ? 'INVALID_SNAPSHOT' : 'HQ_UNAVAILABLE'),'retryable'=>$status===503,'operation_id'=>$operation,'receipt'=>null,'status'=>'rejected','message'=>$status===503 ? 'HQ belum tersedia; periksa konfigurasi dan database pusat.' : $error->getMessage()],JSON_UNESCAPED_UNICODE);
}
