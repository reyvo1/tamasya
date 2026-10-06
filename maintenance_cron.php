<?php
/**
 * OPTIONAL scheduled full-maintenance job for TAMASYA Hotel System.
 *
 * Consistency Guard does NOT depend on cron. R2 can run opportunistically from
 * normal Web activity, System Health/Telegram diagnostics, and the node sync
 * agent. If this hosting/VPS supports cron, this file remains useful for a
 * predictable full maintenance window: backup + retention cleanup + deep Guard.
 *
 * Run from CLI: php maintenance_cron.php
 * Or protected HTTP trigger: POST cron_secret or X-Cron-Secret header.
 * BACKUP_DIR is mandatory only when THIS full-maintenance job is executed and
 * must stay outside the public web root.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Load .env before reading CRON_SECRET so shared hosting without process-level
// environment variables can still use the protected HTTP mode.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'release_contract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'database_bootstrap.php';

$isCli = PHP_SAPI === 'cli';
$cronSecret = (string)(getenv('CRON_SECRET') ?: '');
if (!$isCli) {
    $httpsActive = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https');
    $allowHttp = filter_var((string)(getenv('CRON_ALLOW_HTTP') ?: '0'), FILTER_VALIDATE_BOOLEAN);
    if (!$httpsActive && !$allowHttp) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Protected cron requires HTTPS.\n";
        exit;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');

    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $incoming=(string)($_POST['cron_secret']??($_SERVER['HTTP_X_CRON_SECRET']??''));
    if($method==='GET' && $incoming===''){
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TAMASYA Maintenance</title></head><body style="font-family:system-ui;max-width:720px;margin:40px auto;padding:0 18px"><h1>TAMASYA Maintenance Opsional</h1><p>Consistency Guard tetap aktif tanpa cron melalui aktivitas aplikasi. Halaman ini hanya menjalankan full maintenance opsional (backup, cleanup, dan deep Guard) bila hosting Anda mendukungnya. Secret dikirim dengan POST.</p><form method="post" autocomplete="off"><label>CRON_SECRET<br><input type="password" name="cron_secret" required style="width:100%;max-width:520px;padding:10px"></label><br><button type="submit" style="margin-top:16px;padding:10px 16px">Jalankan Maintenance</button></form></body></html>';
        exit;
    }
    header('Content-Type: application/json; charset=UTF-8');
    if ($cronSecret === '' || $incoming==='' || !hash_equals($cronSecret, $incoming)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'Forbidden']);
        exit;
    }
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'node_sync_support.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'node_cluster_support.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'consistency_guard_support.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'backup_support.php';
$dbConfig = tamasyaResolveDatabaseConfig(__DIR__);
[$pdo, $dbConnectError, $dbConnectionStage] = tamasyaConnectDatabase($dbConfig);
if (!$pdo instanceof PDO) {
    $stageMessages = [
        'missing_credentials' => 'Konfigurasi database belum ditemukan.',
        'missing_driver' => 'Driver PHP pdo_mysql belum aktif pada server.',
        'connection_failed' => 'Koneksi database ditolak. Periksa host, port, nama database, user, password, dan izin user.',
        'credential_file_required' => 'APP_CREDENTIALS_FILE tidak valid. Perbaiki path/file credential; fallback otomatis tidak digunakan.',
    ];
    $message = $stageMessages[$dbConnectionStage] ?? 'Database belum dapat digunakan.';
    error_log('[maintenance_cron][' . $dbConnectionStage . '] ' . (string)$dbConnectError);
    if ($isCli) {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        http_response_code(503);
        echo json_encode(['success'=>false,'error'=>$message,'connectionStage'=>$dbConnectionStage], JSON_UNESCAPED_UNICODE);
    }
    exit(1);
}
try {
    $dbSafety=tamasyaAssertDatabaseSafety($pdo,$dbConfig);
    tamasyaDatabasePropertyIdentity($pdo,true);
    $db_name=(string)$dbSafety['actual'];
} catch (Throwable $dbSafetyError) {
    $message='Database safety gate menolak maintenance: '.$dbSafetyError->getMessage();
    if ($isCli) fwrite(STDERR,$message.PHP_EOL); else { http_response_code(409); echo json_encode(['success'=>false,'error'=>$message]); }
    exit(2);
}
try {
    $nodeEnv=tamasyaNodeSyncEnvironmentCheck();
    if(empty($nodeEnv['ok'])) throw new RuntimeException('Konfigurasi cluster/sync invalid: '.implode(' ',(array)($nodeEnv['errors']??[])));
    tamasyaInitializeClusterState($pdo);
} catch (Throwable $clusterInitError) {
    $message='Cluster init gagal: '.$clusterInitError->getMessage();
    if ($isCli) fwrite(STDERR,$message.PHP_EOL); else { http_response_code(503); echo json_encode(['success'=>false,'error'=>$message]); }
    exit(2);
}

if (tamasyaNodeRole() === 'online_primary' && tamasyaClusterEnabled()) {
    $lease=tamasyaClusterHeartbeatLeadership($pdo);
    if (empty($lease['valid'])) {
        $message='maintenance_cron.php diblokir karena leadership lease tidak valid: '.(string)($lease['reason']??'unknown');
        if ($isCli) fwrite(STDERR,$message.PHP_EOL); else { http_response_code(423); echo json_encode(['success'=>false,'error'=>$message]); }
        exit(4);
    }
}

if (tamasyaNodeRole() === 'local_backup') {
    $message = 'maintenance_cron.php operasional hanya boleh berjalan pada primary aktif. Standby hanya menjalankan node_sync_agent.php dan backup node-local melalui backup_now.php; cleanup/alert operasional tetap hanya pada Primary.';
    if ($isCli) fwrite(STDERR, $message . PHP_EOL); else { http_response_code(409); echo json_encode(['success'=>false,'error'=>$message]); }
    exit(3);
}

function cronAcquirePrimaryLock(PDO $pdo, int $timeout = 30): void {
    if (!tamasyaAcquirePrimaryMutationLock($pdo, $timeout)) throw new RuntimeException('Cron tidak memperoleh lock transaksi online. Ulangi setelah trafik transaksi mereda.');
}
function cronBumpRevision(PDO $pdo): void {
    $pdo->exec("UPDATE config SET server_revision=COALESCE(server_revision,0)+1 WHERE id='system_default'");
}

$backupDir=trim((string)(getenv('BACKUP_DIR')?:''));
if($backupDir==='') throw new RuntimeException('BACKUP_DIR wajib diisi dengan path absolut di luar public web root.');
if(!tamasyaPathIsAbsolute($backupDir)) throw new RuntimeException('BACKUP_DIR wajib path absolut.');
if(!is_dir($backupDir) && !mkdir($backupDir,0700,true) && !is_dir($backupDir)) throw new RuntimeException('Backup directory tidak dapat dibuat.');
$backupBoundary=tamasyaBackupDirectoryOutsideDocumentRoot($backupDir);
$appEnv=strtolower(trim((string)(getenv('APP_ENV')?:'production')));
$allowPublicBackup=filter_var(getenv('ALLOW_PUBLIC_BACKUP_DIR')?:'0',FILTER_VALIDATE_BOOLEAN);
if(!empty($backupBoundary['insideDocumentRoot'])){
    if(in_array($appEnv,['staging','production','prod'],true)){
        throw new RuntimeException('BACKUP_DIR berada di dalam document root. Staging/production selalu mewajibkan backup di private directory; override publik tidak berlaku.');
    }
    if(!$allowPublicBackup) throw new RuntimeException('BACKUP_DIR berada di dalam document root. Pindahkan ke private directory; override lokal dinonaktifkan secara default.');
}
@chmod($backupDir,0700);

$results = [
    'guard_scheduling_mode'=>'flexible',
    'guard_cron_required'=>false,
    'guard_cron_optional'=>true,
];
cronAcquirePrimaryLock($pdo);
try {
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(),INTERVAL 30 DAY)) OR expires_at < DATE_SUB(NOW(),INTERVAL 30 DAY)");
    $results['sessions_cleaned'] = true;
    $pdo->exec("DELETE FROM rate_limits WHERE updated_at < DATE_SUB(NOW(),INTERVAL 7 DAY) AND (blocked_until IS NULL OR blocked_until < NOW())");
    $results['rate_limits_cleaned'] = true;
    $pdo->exec("DELETE FROM sync_operations WHERE created_at < DATE_SUB(NOW(),INTERVAL 120 DAY)");
    // Durable request receipts keep idempotency metadata longer than the replay body.
    // Large mutation responses can contain a full projected DB snapshot; expire only
    // the body first so duplicate operation IDs remain blocked without retaining MBs
    // of redundant JSON for months.
    $receiptBodyRetentionDays=max(7,min(120,(int)(getenv('REQUEST_RECEIPT_BODY_RETENTION_DAYS')?:30)));
    $receiptMetadataRetentionDays=max($receiptBodyRetentionDays,min(365,(int)(getenv('REQUEST_RECEIPT_RETENTION_DAYS')?:120)));
    $pdo->exec("UPDATE request_operation_receipts SET response_body=NULL WHERE response_body IS NOT NULL AND status IN ('completed','failed','rejected') AND completed_at IS NOT NULL AND completed_at < DATE_SUB(NOW(),INTERVAL {$receiptBodyRetentionDays} DAY)");
    $pdo->exec("DELETE FROM request_operation_receipts WHERE (action='sync' AND status IN ('failed','rejected') AND created_at < DATE_SUB(NOW(),INTERVAL 7 DAY)) OR created_at < DATE_SUB(NOW(),INTERVAL {$receiptMetadataRetentionDays} DAY)");
    $results['request_receipt_body_retention_days']=$receiptBodyRetentionDays;
    $results['request_receipt_metadata_retention_days']=$receiptMetadataRetentionDays;
    try {
        $pdo->exec("DELETE FROM runtime_request_events WHERE created_at < DATE_SUB(NOW(),INTERVAL 30 DAY)");
        $results['runtime_request_events_cleaned'] = true;
    } catch (Throwable $e) {
        $results['runtime_request_events_cleaned'] = false;
    }
    try {
        $securityRetentionDays=max(30,min(730,(int)(getenv('SECURITY_EVENT_RETENTION_DAYS')?:180)));
        $pdo->exec("DELETE FROM security_events WHERE created_at < DATE_SUB(NOW(),INTERVAL {$securityRetentionDays} DAY)");
        $results['security_events_cleaned'] = true;
        $results['security_event_retention_days'] = $securityRetentionDays;
    } catch (Throwable $e) {
        $results['security_events_cleaned'] = false;
    }
    $results['sync_operations_cleaned'] = true;
    try {
        $pdo->exec("DELETE FROM telegram_binding_codes WHERE used_at IS NOT NULL OR expires_at < DATE_SUB(NOW(),INTERVAL 1 DAY)");
        $results['telegram_binding_codes_cleaned'] = true;
    } catch (Throwable $e) {
        $results['telegram_binding_codes_cleaned'] = false;
    }
    try {
        $pdo->exec("DELETE FROM communication_binding_codes WHERE used_at IS NOT NULL OR expires_at < DATE_SUB(NOW(),INTERVAL 1 DAY)");
        $results['communication_binding_codes_cleaned'] = true;
    } catch (Throwable $e) {
        $results['communication_binding_codes_cleaned'] = false;
    }
    try {
        $pdo->exec("DELETE FROM telegram_callback_tokens WHERE expires_at < DATE_SUB(NOW(),INTERVAL 1 DAY)");
        $results['telegram_callback_tokens_cleaned']=true;
    } catch (Throwable $e) {
        $results['telegram_callback_tokens_cleaned']=false;
    }
    try {
        // Update log is an idempotency/diagnostic table, not business chat history.
        // Keep enough history for incident review without allowing webhook traffic
        // to grow this hot table forever.
        $pdo->exec("DELETE FROM telegram_update_log WHERE (status='completed' AND created_at < DATE_SUB(NOW(),INTERVAL 45 DAY)) OR (status='failed' AND created_at < DATE_SUB(NOW(),INTERVAL 180 DAY))");
        $results['telegram_update_log_cleaned']=true;
    } catch (Throwable $e) {
        $results['telegram_update_log_cleaned']=false;
    }
    $pdo->exec("DELETE nr FROM notification_reads nr LEFT JOIN notifications n ON n.id=nr.notification_id LEFT JOIN staff s ON s.id=nr.staff_id WHERE n.id IS NULL OR s.id IS NULL");
    $results['notification_reads_cleaned'] = true;
    $pdo->exec("UPDATE guest_profiles SET identity_number=NULL,preferences=NULL,notes='Data dianonimkan sesuai retensi',phone='',email='' WHERE retention_until IS NOT NULL AND retention_until < CURDATE()");
    $results['guest_retention_applied'] = true;
    cronBumpRevision($pdo);
    $pdo->commit();
} catch (Throwable $cleanupError) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $cleanupError;
} finally {
    tamasyaReleasePrimaryMutationLock($pdo);
}


function cronAtomicReplace(string $source,string $destination): void {
    if(@rename($source,$destination)) return;
    if(is_file($destination) && !@unlink($destination)) throw new RuntimeException('Backup lama tidak dapat diganti: '.basename($destination));
    if(!@rename($source,$destination)) throw new RuntimeException('Backup tidak dapat dipindahkan ke file final: '.basename($destination));
}

$backupRunRows=[];
$periods=['daily'=>date('Y-m-d')];
if ((int)date('N')===1) $periods['weekly']=date('o-\\WW');
if ((int)date('j')===1) $periods['monthly']=date('Y-m');

// Produce ONE restore-grade SQL image, then copy that exact byte stream to any
// weekly/monthly retention slot. This avoids reading a moving database multiple
// times and guarantees all period labels from this run share one snapshot point.
$dailyFilename='tamasya_daily_'.date('Y-m-d').'.sql';
$dailyPath=rtrim($backupDir,'/\\').DIRECTORY_SEPARATOR.$dailyFilename;
$tempPath=$dailyPath.'.tmp-'.bin2hex(random_bytes(6));
$fh=fopen($tempPath,'xb');
if($fh===false) throw new RuntimeException('File temporary backup tidak dapat dibuat.');
@chmod($tempPath,0600);
$writer=static function(string $chunk) use ($fh): bool {
    $length=strlen($chunk);$written=0;
    while($written<$length){
        $n=fwrite($fh,substr($chunk,$written));
        if($n===false||$n===0)return false;
        $written+=$n;
    }
    return true;
};
$backupError=null;
try{
    $backupMeta=tamasyaBackupStreamFullSql($pdo,$writer,[
        'batchSize'=>100,
        'release'=>TAMASYA_SCHEMA_RELEASE,
        'patch'=>TAMASYA_PATCH_LEVEL,
    ]);
    fflush($fh);
    if(function_exists('fsync'))@fsync($fh);
}catch(Throwable $e){$backupError=$e;}finally{
    fclose($fh);
}
if($backupError instanceof Throwable){@unlink($tempPath);throw $backupError;}
$dailyTempVerification=tamasyaBackupVerifySqlFile($tempPath,$backupMeta);
cronAtomicReplace($tempPath,$dailyPath);
@chmod($dailyPath,0600);
$dailyFinalVerification=tamasyaBackupVerifySqlFile($dailyPath,$backupMeta);
if(!hash_equals((string)$dailyTempVerification['sha256'],(string)$dailyFinalVerification['sha256'])) throw new RuntimeException('Checksum backup daily berubah setelah atomic replace.');

foreach($periods as $kind=>$period){
    $filename="tamasya_{$kind}_{$period}.sql";
    $path=rtrim($backupDir,'/\\').DIRECTORY_SEPARATOR.$filename;
    if($kind!=='daily'){
        $copyTmp=$path.'.tmp-'.bin2hex(random_bytes(6));
        if(!copy($dailyPath,$copyTmp)) throw new RuntimeException('Gagal membuat salinan backup '.$kind.'.');
        @chmod($copyTmp,0600);
        cronAtomicReplace($copyTmp,$path);
    }
    $fileVerification=tamasyaBackupVerifySqlFile($path,$backupMeta);
    $checksum=(string)$fileVerification['sha256'];
    $size=(int)$fileVerification['sizeBytes'];
    $notes='Full SQL restore-grade: all InnoDB base tables + data + views + routines + events + triggers, PHP SHOW CREATE exporter tanpa ketergantungan mysqldump, one consistent snapshot. Self-verification PASS: SHA256 + single completion marker + object header/markers + manifest rowCounts. Restore ke database terisolasi BELUM diuji otomatis.';
    // restore_tested_* intentionally NULL. A readable SQL file is not the same
    // as a successful restore drill.
    $backupRunRows[]=['backup_'.bin2hex(random_bytes(12)),$kind,$filename,'completed',$size,$checksum,$notes,null,null];
    $results['backup_'.$kind]=[
        'file'=>$filename,'size'=>$size,'sha256'=>$checksum,
        'integrityReadback'=>true,'selfVerified'=>true,'manifestFormatVersion'=>(int)($fileVerification['manifest']['formatVersion']??0),'restoreTested'=>false,
        'tables'=>(int)($backupMeta['tables']??0),'views'=>(int)($backupMeta['views']??0),
        'routines'=>(int)($backupMeta['routines']??0),'events'=>(int)($backupMeta['events']??0),'triggers'=>(int)($backupMeta['triggers']??0),
        'programmableObjectExporter'=>(string)($backupMeta['programmableObjectExporter']??'unknown'),
        'consistency'=>(string)($backupMeta['consistency']??'unknown')
    ];
}

// Retention: daily 7, weekly 4, monthly 12.
foreach (['daily'=>7,'weekly'=>4,'monthly'=>12] as $kind=>$keep) {
    $files=glob(rtrim($backupDir,'/\\').DIRECTORY_SEPARATOR.'tamasya_'.$kind.'_*.sql') ?: [];
    usort($files,static function(string $a,string $b):int{return (filemtime($b)?:0)<=>(filemtime($a)?:0);});
    foreach(array_slice($files,$keep) as $old)@unlink($old);
}

// Operational alerts.
$alerts=[];
$openShift=(int)$pdo->query("SELECT COUNT(*) FROM shift_sessions WHERE status='open' AND opened_at<DATE_SUB(NOW(),INTERVAL 16 HOUR)")->fetchColumn();
if($openShift)$alerts[]=['alert_shift_stale','warning','finance','Shift terlalu lama terbuka',"$openShift shift belum ditutup lebih dari 16 jam."];
$staleSync=(int)$pdo->query("SELECT COUNT(*) FROM sync_devices WHERE pending_count>0 AND last_seen<DATE_SUB(NOW(),INTERVAL 1 HOUR) AND status<>'disabled'")->fetchColumn();
if($staleSync)$alerts[]=['alert_sync_stale','warning','sync','Antrean sync tertunda',"$staleSync perangkat memiliki antrean tertunda."];
try {
    $settings=$pdo->query("SELECT checkout_reminder_minutes,late_grace_minutes,require_key_control,require_night_audit_for_night_shift FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch() ?: [];
    $reminder=max(0,(int)($settings['checkout_reminder_minutes']??30));
    $grace=max(0,(int)($settings['late_grace_minutes']??60));
    $dueRows=$pdo->query("SELECT id,roomNumber,guestName,checkoutDueAt FROM bookings WHERE status='active' AND checkoutDueAt IS NOT NULL AND NOW()>=DATE_SUB(checkoutDueAt,INTERVAL {$reminder} MINUTE)")->fetchAll() ?: [];
    foreach($dueRows as $row){
        $due=strtotime((string)$row['checkoutDueAt']);
        if(!$due)continue;
        $lateSeconds=time()-$due;
        $severity=$lateSeconds>$grace*60?'critical':($lateSeconds>0?'warning':'info');
        $title=$lateSeconds>$grace*60?'Check-out lewat masa toleransi':($lateSeconds>0?'Check-out terlambat':'Check-out segera jatuh tempo');
        $alerts[]=['alert_checkout_'.hash('sha256',(string)$row['id']),$severity,'checkout',$title,"Kamar {$row['roomNumber']} · {$row['guestName']} · jatuh tempo ".date('d-m-Y H:i',$due).'.'];
    }
    if((int)($settings['require_key_control']??1)===1){
        $keyOrphans=$pdo->query("SELECT COUNT(*) FROM room_access_control rac JOIN rooms r ON r.number=rac.room_number LEFT JOIN bookings b ON b.roomNumber=rac.room_number AND b.status='active' WHERE b.id IS NULL AND rac.physical_key_status='issued'")->fetchColumn();
        if((int)$keyOrphans>0)$alerts[]=['alert_key_orphan_cron','critical','room-security','Kamar/kunci tanpa booking aktif',"{$keyOrphans} kamar atau kunci tidak cocok dengan booking aktif."];
    }
    if((int)($settings['require_night_audit_for_night_shift']??1)===1){
        $nightMissing=$pdo->query("SELECT COUNT(*) FROM shift_sessions ss LEFT JOIN night_audit_runs nar ON nar.id=ss.night_audit_id AND nar.status='completed' WHERE ss.status='open' AND (HOUR(ss.opened_at)>=18 OR HOUR(ss.opened_at)<=5) AND nar.id IS NULL AND ss.opened_at<DATE_SUB(NOW(),INTERVAL 2 HOUR)")->fetchColumn();
        if((int)$nightMissing>0)$alerts[]=['alert_night_audit_missing','warning','night-audit','Night audit belum dilakukan',"{$nightMissing} shift malam terbuka belum mempunyai night audit selesai."];
    }
} catch (Throwable $e) {
    $results['room_security_alerts'] = 'not_ready';
}
$guardSnapshot=null;
try {
    $guardSnapshot=tamasyaConsistencyGuardSnapshot($pdo,['sampleLimit'=>10,'deepCluster'=>true]);
    $results['consistency_guard']=[
        'overall'=>$guardSnapshot['overall']??'UNKNOWN',
        'summary'=>$guardSnapshot['summary']??[],
        'checkedAt'=>$guardSnapshot['checkedAt']??null,
        'buildId'=>$guardSnapshot['buildId']??null,
        'autoFix'=>false,
    ];
} catch (Throwable $guardError) {
    $results['consistency_guard']=['overall'=>'FAIL','error'=>'Guard scan gagal: '.$guardError->getMessage(),'autoFix'=>false];
    error_log('[maintenance_cron][consistency_guard] '.$guardError->getMessage());
}
cronAcquirePrimaryLock($pdo);
try {
    $pdo->beginTransaction();
    $stmtBackup=$pdo->prepare("INSERT INTO backup_runs (id,backup_type,file_name,status,size_bytes,checksum,notes,created_by,created_at,restore_tested_at,restore_tested_by) VALUES (?,?,?,?,?,?,?,'cron',CURRENT_TIMESTAMP,?,?)");
    foreach($backupRunRows as $backupRunRow)$stmtBackup->execute($backupRunRow);
    $stmtAlert=$pdo->prepare("INSERT INTO system_alerts (id,severity,source,title,message,created_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE severity=VALUES(severity),title=VALUES(title),message=VALUES(message),acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP");
    foreach($alerts as $alert)$stmtAlert->execute($alert);
    if(is_array($guardSnapshot)){
        $results['consistency_guard']['persistence']=tamasyaConsistencyGuardPersist($pdo,$guardSnapshot);
    }
    cronBumpRevision($pdo);
    $pdo->commit();
} catch (Throwable $finalizeError) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $finalizeError;
} finally {
    tamasyaReleasePrimaryMutationLock($pdo);
}
$results['alerts_refreshed']=count($alerts);

$output=['success'=>true,'time'=>date(DATE_ATOM),'results'=>$results];
if($isCli) echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL; else echo json_encode($output,JSON_UNESCAPED_UNICODE);
