<?php
/**
 * TAMASYA Communication Outbox Worker.
 * CLI: php communication_worker.php
 * Protected HTTP cron: POST cron_secret or X-Cron-Secret header.
 */
error_reporting(E_ALL);ini_set('display_errors','0');ini_set('log_errors','1');
// Load .env first so CRON_SECRET works on shared hosting without terminal/runtime env injection.
require_once __DIR__.'/database_bootstrap.php';
$isCli=PHP_SAPI==='cli';$cronSecret=(string)(getenv('CRON_SECRET')?:'');
if(!$isCli){
    $httpsActive=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||((string)($_SERVER['SERVER_PORT']??'')==='443')||(strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')))==='https');
    $allowHttp=filter_var((string)(getenv('CRON_ALLOW_HTTP')?:'0'),FILTER_VALIDATE_BOOLEAN);
    if(!$httpsActive&&!$allowHttp){http_response_code(403);header('Content-Type: text/plain; charset=UTF-8');echo "Protected cron requires HTTPS.\n";exit;}
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $incoming=(string)($_POST['cron_secret']??($_SERVER['HTTP_X_CRON_SECRET']??''));
    if($method==='GET'&&$incoming===''){
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TAMASYA Communication Worker</title></head><body style="font-family:system-ui;max-width:720px;margin:40px auto;padding:0 18px"><h1>TAMASYA Communication Worker</h1><p>Proses antrean komunikasi sekali melalui browser. Secret dikirim dengan POST.</p><form method="post" autocomplete="off"><label>CRON_SECRET<br><input type="password" name="cron_secret" required style="width:100%;max-width:520px;padding:10px"></label><br><button type="submit" style="margin-top:16px;padding:10px 16px">Jalankan Worker</button></form></body></html>';
        exit;
    }
    header('Content-Type: application/json; charset=UTF-8');
    if($cronSecret===''||$incoming===''||!hash_equals($cronSecret,$incoming)){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Forbidden']);exit;}
}
if(!defined('TAMASYA_API_ENTRY'))define('TAMASYA_API_ENTRY',true);
if(!defined('TAMASYA_APP_ROOT'))define('TAMASYA_APP_ROOT',__DIR__);
require_once __DIR__.'/runtime_crypto.php';
try{tamasyaAssertSecretEncryptionConfigured();}catch(Throwable $e){$message='Encryption configuration invalid: '.$e->getMessage();if($isCli)fwrite(STDERR,$message.PHP_EOL);else{http_response_code(503);echo json_encode(['success'=>false,'error'=>$message]);}exit(2);}
require_once __DIR__.'/api/support/001_runtime_security.php';
require_once __DIR__.'/node_sync_support.php';
require_once __DIR__.'/node_cluster_support.php';
require_once __DIR__.'/api/modules/comms/045_communication_core.php';
require_once __DIR__.'/api/modules/comms/050_integrations_telegram_mail.php';

$dbConfig=tamasyaResolveDatabaseConfig(__DIR__);
[$pdo,$dbError,$dbStage]=tamasyaConnectDatabase($dbConfig);
if(!$pdo instanceof PDO){$message='Database worker tidak tersedia: '.$dbStage;error_log('[communication_worker] '.(string)$dbError);if($isCli)fwrite(STDERR,$message.PHP_EOL);else{http_response_code(503);echo json_encode(['success'=>false,'error'=>$message]);}exit(1);}
$GLOBALS['tamasya_runtime_pdo']=$pdo;
try{tamasyaAssertDatabaseSafety($pdo,$dbConfig);tamasyaDatabasePropertyIdentity($pdo,true);$nodeEnv=tamasyaNodeSyncEnvironmentCheck();if(empty($nodeEnv['ok']))throw new RuntimeException('Konfigurasi cluster/sync invalid: '.implode(' ',(array)($nodeEnv['errors']??[])));tamasyaInitializeClusterState($pdo);}catch(Throwable $e){if($isCli)fwrite(STDERR,$e->getMessage().PHP_EOL);else{http_response_code(503);echo json_encode(['success'=>false,'error'=>'Database/cluster safety gate menolak worker.']);}exit(2);}
if(tamasyaNodeRole()!=='online_primary'){ $message='Communication worker hanya berjalan pada primary aktif.';if($isCli)fwrite(STDERR,$message.PHP_EOL);else{http_response_code(409);echo json_encode(['success'=>false,'error'=>$message]);}exit(3); }
if(tamasyaClusterEnabled()){
    $lease=tamasyaClusterHeartbeatLeadership($pdo);
    if(empty($lease['valid'])){$message='Leadership lease communication worker tidak valid.';if($isCli)fwrite(STDERR,$message.PHP_EOL);else{http_response_code(423);echo json_encode(['success'=>false,'error'=>$message]);}exit(4);}
}
try{
    tamasyaCommunicationSyncLegacyChannels($pdo);
    $batchSize=max(1,min(100,(int)(getenv('COMMUNICATION_WORKER_BATCH_SIZE')?:20)));
    $result=tamasyaCommunicationProcessOutbox($pdo,$batchSize,['id'=>null,'name'=>'Communication Worker','role'=>'system']);
    $result['batchSize']=$batchSize;
    $pdo->exec("DELETE FROM communication_binding_codes WHERE used_at IS NOT NULL OR expires_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 1 DAY)");
    $pdo->exec("DELETE FROM communication_webhook_events WHERE status='completed' AND processed_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 120 DAY)");
    $output=['success'=>true,'time'=>date(DATE_ATOM),'result'=>$result];
    echo json_encode($output,$isCli?JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE:JSON_UNESCAPED_UNICODE).($isCli?PHP_EOL:'');
}catch(Throwable $e){error_log('[communication_worker] '.$e->getMessage());if($isCli)fwrite(STDERR,$e->getMessage().PHP_EOL);else{http_response_code(500);echo json_encode(['success'=>false,'error'=>'Communication worker gagal.']);}exit(5);}
