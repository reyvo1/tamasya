<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/database_bootstrap.php';
$url=parse_url((string)getenv('APP_URL'));
if (!is_array($url)||empty($url['host'])) throw new RuntimeException('APP_URL wajib untuk worker.');
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['HTTP_HOST']=$url['host'].(isset($url['port'])?':'.$url['port']:'');
$_SERVER['REMOTE_ADDR']='127.0.0.1'; $_SERVER['REQUEST_URI']='/api.php?action=worker-bootstrap'; $_GET=['action'=>'worker-bootstrap'];
define('TAMASYA_SERVICE_BOOTSTRAP',true);
ob_start(); require __DIR__.'/api.php'; ob_end_clean();
$command=$argv[1]??'once';
if (!in_array($command,['once','daemon','health'],true)) throw new InvalidArgumentException('Command worker: once, daemon, health.');
function tamasyaServiceRunOnce(PDO $pdo): array {
    $identity=tamasyaMultiPropertyIdentity();
    $status=['contractVersion'=>'tamasya-worker-v1','companyId'=>$identity['companyId'],'propertyId'=>$identity['propertyId'],'time'=>gmdate('c'),'writer'=>false,'attempted'=>0,'acknowledged'=>0,'deadLetter'=>0];
    if (function_exists('tamasyaClusterRefreshGlobals')) tamasyaClusterRefreshGlobals($pdo);
    if (!tamasyaExternalSideEffectsAllowed()) return $status+['reason'=>'inactive_writer'];
    $status['writer']=true;
    $max=max(1,min(20,(int)(getenv('TAMASYA_WORKER_BATCH')?:5)));
    $status['deviceAttempted']=0;
    if(filter_var(getenv('TAMASYA_DEVICE_WORKER_ENABLED')?:'0',FILTER_VALIDATE_BOOLEAN)) {
        $error=tamasyaClusterMutationGuard($pdo);if($error!==null)throw new RuntimeException($error);
        $jobs=$pdo->query("SELECT id FROM smart_lock_jobs WHERE attempts<5 AND (status='pending' OR (status='processing' AND updated_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 120 SECOND)) OR (status='failed' AND updated_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 60 SECOND))) ORDER BY created_at,id LIMIT ".$max)->fetchAll(PDO::FETCH_COLUMN);
        foreach($jobs as $id){processSmartLockBridgeJobById($pdo,(string)$id,null,'device-worker');$status['deviceAttempted']++;}
    }
    if (!tamasyaHqBridgeEnabled()) return $status+['hqDisabled'=>true];
    for($offset=0;$offset<1000 && $status['attempted']<$max;$offset+=50) {
        $page=tamasyaHybridOutboxList($offset);
        foreach($page['jobs'] as $job) {
            if (!in_array($job['status'],['pending','sending'],true) || ($job['next_attempt_at']??0)>time()) continue;
            $result=tamasyaHybridOutboxDeliver($job['operation_id']);$status['attempted']++;
            if($result['status']==='acknowledged')$status['acknowledged']++;
            if($result['status']==='dead_letter')$status['deadLetter']++;
            if($status['attempted']>=$max)break;
        }
        if($page['nextOffset']===null)break;
    }
    return $status;
}
do {
    try {
        if ($command==='health') { echo tamasyaHybridJson(['success'=>true,'identity'=>tamasyaMultiPropertyIdentity(),'outbox'=>tamasyaHybridOutboxList(),'contractVersion'=>'tamasya-worker-v1'])."\n"; break; }
        $result=tamasyaServiceRunOnce($pdo); echo tamasyaHybridJson(['success'=>true,'data'=>$result])."\n";
    } catch(TamasyaHybridOutboxBusy $busy) { echo json_encode(['success'=>true,'status'=>'busy','retryable'=>true,'contractVersion'=>'tamasya-worker-v1'])."\n"; }
    catch(Throwable $error) { fwrite(STDERR,json_encode(['success'=>false,'code'=>'WORKER_CYCLE_FAILED','retryable'=>true,'message'=>$error->getMessage()])."\n");if($command!=='daemon')exit(1); }
    if($command==='daemon')sleep(max(2,min(60,(int)(getenv('TAMASYA_WORKER_INTERVAL_SECONDS')?:5))));
} while($command==='daemon');
