<?php
/** V137 Multi-Property/HQ route. Read-only locally; optional signed aggregate snapshot push. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'multi-property') return;
$routeHandled=true;
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
requireRoles($loggedInStaff,['admin','manager','finance']);
requireDesktopTabAccess($loggedInStaff,'report',['admin','manager','finance']);
try{
    if($method==='GET'){
        $command=trim((string)($_GET['command']??'overview'));
        switch($command){
            case 'capabilities': echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaHybridCapabilities()]);break;
            case 'outbox': echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaHybridOutboxList((int)($_GET['offset']??0))]);break;
            case 'snapshot-v2':
                $snapshot=tamasyaHybridSnapshot($pdo,(string)($_GET['from']??date('Y-m-01')),(string)($_GET['to']??date('Y-m-d')));
                $etag='"'.$snapshot['checksumSha256'].'"'; header('ETag: '.$etag); header('Cache-Control: private, no-store');
                if (($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag) http_response_code(304);
                else echo tamasyaJsonEncode(['success'=>true,'data'=>$snapshot]);
                break;
            case 'overview': echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaMultiPropertyReadiness()]);break;
            case 'manifest': requireRoles($loggedInStaff,['admin']);echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaMultiPropertyManifest($pdo)]);break;
            case 'summary-preview': $from=trim((string)($_GET['from']??date('Y-m-01')));$to=trim((string)($_GET['to']??date('Y-m-d')));echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaMultiPropertySummaryPreview($pdo,$from,$to)]);break;
            case 'contracts': echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaMultiPropertyContracts()]);break;
            default: throw new InvalidArgumentException('Command Multi-Property tidak dikenal.');
        }
        return;
    }
    if($method!=='POST'){http_response_code(405);echo tamasyaJsonEncode(['success'=>false,'error'=>'Metode Multi-Property tidak didukung.']);return;}
    $input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=[];$command=trim((string)($input['command']??''));
    if(in_array($command,['queue-snapshot','deliver-snapshot','requeue-snapshot'],true)){
        $op=(string)($input['operationId']??'');
        $job=$command==='queue-snapshot'
            ? tamasyaHybridOutboxEnqueue($pdo,(string)($input['from']??''),(string)($input['to']??''),$op)
            : ($command==='requeue-snapshot' ? tamasyaHybridOutboxRequeue($op) : tamasyaHybridOutboxDeliver($op));
        http_response_code($job['status']==='acknowledged'?200:202);
        echo tamasyaJsonEncode(['success'=>true,'status'=>$job['status'],'operation_id'=>$job['operation_id'],'receipt'=>$job['receipt'],'retryable'=>$job['status']==='pending','data'=>$job]); return;
    }
    if($command==='push-summary'){
        $from=trim((string)($input['from']??date('Y-m-01')));$to=trim((string)($input['to']??date('Y-m-d')));$op=trim((string)($input['operationId']??($_SERVER['HTTP_X_TAMASYA_OPERATION_ID']??($GLOBALS['tamasya_request_operation_id']??''))))?:generateServerId('hqpush_op');if(strlen($op)>100)throw new InvalidArgumentException('Operation ID terlalu panjang.');
        echo tamasyaJsonEncode(tamasyaMultiPropertyPushSummaryToHq($pdo,$loggedInStaff,$from,$to,$op));return;
    }
    throw new InvalidArgumentException('Command Multi-Property POST tidak dikenal.');
}catch(Throwable $e){
    $status=tamasyaApplyExceptionHttpStatus($e,$e instanceof InvalidArgumentException?422:500);
    $message=clientExceptionMessage('Multi-Property/HQ gagal',$e);
    echo tamasyaJsonEncode(['success'=>false,'code'=>$e instanceof InvalidArgumentException?'INVALID_HYBRID_REQUEST':'HYBRID_UNAVAILABLE','retryable'=>$status>=500,'operation_id'=>$input['operationId']??null,'receipt'=>null,'status'=>'rejected','message'=>$message,'error'=>$message]);
}
