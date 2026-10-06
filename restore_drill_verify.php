<?php
declare(strict_types=1);
/**
 * Read-only verifier for an ISOLATED database restored from a TAMASYA SQL backup.
 *
 * This script never switches the live application database and never mutates the
 * restored database. Restore the SQL into a separate DB first, create a separate
 * private credential file, then set:
 *   RESTORE_VERIFY_CREDENTIALS_FILE=/private/tamasya-restore-db.php
 *   RESTORE_VERIFY_EXPECTED_DB_NAME=tamasya_restore_test_YYYYMMDD
 *   RESTORE_VERIFY_BACKUP_FILE=/private/backups/tamasya_daily_YYYY-MM-DD.sql
 * and run this file via CLI or the protected admin-web-tool gate.
 */
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/baseline_support.php';
require_once __DIR__.'/backup_support.php';
require_once __DIR__.'/admin_web_tool_gate.php';
require_once __DIR__.'/restore_evidence_support.php';

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA Restore Drill Verify');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA Restore Drill Verify',
            'Read-only: verifikasi database hasil restore yang TERPISAH dari database aktif. Credential restore wajib berasal dari file private terpisah.',
            '<label><input type="checkbox" name="confirm" value="VERIFY_RESTORE" required> Database restore sudah dibuat terpisah dan tidak sedang dipakai aplikasi live.</label>',
            'Verifikasi Restore'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
    if((string)($_POST['confirm']??'')!=='VERIFY_RESTORE'){
        tamasyaAdminToolEmit(['success'=>false,'error'=>'Konfirmasi VERIFY_RESTORE wajib.'],400,true);exit;
    }
}

$credentialPath=trim((string)(getenv('RESTORE_VERIFY_CREDENTIALS_FILE')?:''));
$expectedRestore=trim((string)(getenv('RESTORE_VERIFY_EXPECTED_DB_NAME')?:''));
$backupFile=trim((string)(getenv('RESTORE_VERIFY_BACKUP_FILE')?:''));
$activeExpected=trim((string)(getenv('APP_EXPECTED_DB_NAME')?:''));
if($credentialPath==='' || $expectedRestore==='' || $backupFile===''){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'RESTORE_VERIFY_CREDENTIALS_FILE, RESTORE_VERIFY_EXPECTED_DB_NAME, dan RESTORE_VERIFY_BACKUP_FILE wajib diisi.'],$isCli?200:400,true);exit(2);
}
if($activeExpected!=='' && strcasecmp($expectedRestore,$activeExpected)===0){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'Database restore test wajib berbeda dari APP_EXPECTED_DB_NAME database aktif.'],$isCli?200:409,true);exit(2);
}

$backupReal=realpath($backupFile);
if($backupReal===false || !is_file($backupReal) || !is_readable($backupReal)){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'RESTORE_VERIFY_BACKUP_FILE tidak ada/tidak dapat dibaca.'],$isCli?200:400,true);exit(2);
}
if(tamasyaPathIsInsideDocumentRoot($backupReal,__DIR__)){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'File backup restore drill harus berada di luar document root.'],$isCli?200:409,true);exit(2);
}
$backupInspection=tamasyaBackupInspectSqlFile($backupReal);
$backupSize=(int)$backupInspection['sizeBytes'];
$backupSha256=(string)$backupInspection['sha256'];
if((int)$backupInspection['completionMarkerCount']!==1){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'File backup harus memiliki tepat satu TAMASYA_BACKUP_COMPLETE marker. Restore evidence ditolak.'],$isCli?200:409,true);exit(2);
}
$backupObjectHeader=is_array($backupInspection['header'])?$backupInspection['header']:null;
$backupManifest=is_array($backupInspection['manifest'])?$backupInspection['manifest']:null;
if($backupObjectHeader===null){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'Header object-count backup tidak ditemukan. Restore evidence ditolak.'],$isCli?200:409,true);exit(2);
}
if($backupManifest!==null){
    foreach(['tables','views','routines','events','triggers'] as $objectKey){
        if((int)($backupManifest[$objectKey]??-1)!==(int)($backupObjectHeader[$objectKey]??-2)){
            tamasyaAdminToolEmit(['success'=>false,'error'=>'Manifest/header backup tidak konsisten pada '.$objectKey.'.'],$isCli?200:409,true);exit(2);
        }
    }
}

$credentialReal=realpath($credentialPath);
if($credentialReal===false || !is_file($credentialReal) || !is_readable($credentialReal)){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'File credential restore tidak ada/tidak dapat dibaca.'],$isCli?200:400,true);exit(2);
}
$documentRoot=tamasyaDocumentRoot(__DIR__);
$rootReal=realpath($documentRoot)?:$documentRoot;
$compareCredential=str_replace(['\\','/'],DIRECTORY_SEPARATOR,$credentialReal);
$compareRoot=rtrim(str_replace(['\\','/'],DIRECTORY_SEPARATOR,$rootReal),DIRECTORY_SEPARATOR);
if(DIRECTORY_SEPARATOR==='\\'){$compareCredential=strtolower($compareCredential);$compareRoot=strtolower($compareRoot);}
if($compareCredential===$compareRoot || str_starts_with($compareCredential,$compareRoot.DIRECTORY_SEPARATOR)){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'Credential restore harus berada di luar document root.'],$isCli?200:409,true);exit(2);
}

$config=tamasyaReadCredentialFile($credentialReal);
if(!is_array($config)){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'Credential restore tidak valid.'],$isCli?200:400,true);exit(2);
}
$config['detected']=true;
$config['driverAvailable']=in_array('mysql',PDO::getAvailableDrivers(),true);
$config['resolutionError']=null;
[$pdo,$error,$stage]=tamasyaConnectDatabase($config);
if(!$pdo instanceof PDO){
    tamasyaAdminToolEmit(['success'=>false,'stage'=>$stage,'error'=>$error?:'Database restore tidak dapat dihubungkan.'],$isCli?200:503,true);exit(2);
}

try{
    $actual=trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    if($actual==='' || strcasecmp($actual,$expectedRestore)!==0)throw new RuntimeException('Database restore aktif tidak sama dengan RESTORE_VERIFY_EXPECTED_DB_NAME.');
    if($activeExpected!=='' && strcasecmp($actual,$activeExpected)===0)throw new RuntimeException('Refuse: verifier terhubung ke database live aktif.');
    $baseline=tamasyaAssertCanonicalBaseline($pdo);
    $identity=tamasyaDatabasePropertyIdentity($pdo,true);
    if(empty($identity['ok']))throw new RuntimeException('Property identity hasil restore tidak cocok dengan ENV deployment: '.json_encode($identity['mismatches']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    $backupRunCount=null;
    try{$backupRunCount=(int)$pdo->query('SELECT COUNT(*) FROM backup_runs')->fetchColumn();}catch(Throwable $ignored){}
    $restoredObjectCounts=[
        'tables'=>(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchColumn(),
        'views'=>(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='VIEW'")->fetchColumn(),
        'routines'=>(int)$pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()")->fetchColumn(),
        'events'=>(int)$pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()")->fetchColumn(),
        'triggers'=>(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")->fetchColumn(),
    ];
    $objectMismatches=[];
    foreach(['tables','views','routines','events','triggers'] as $objectKey){
        $expectedCount=(int)($backupObjectHeader[$objectKey]??-1);
        $actualCount=(int)($restoredObjectCounts[$objectKey]??-2);
        if($expectedCount!==$actualCount)$objectMismatches[$objectKey]=['expected'=>$expectedCount,'actual'=>$actualCount];
    }
    if($objectMismatches) throw new RuntimeException('Jumlah object hasil restore tidak sama dengan backup: '.json_encode($objectMismatches,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    $rowCountComparison=['available'=>false,'checkedTables'=>0,'mismatches'=>[]];
    if(is_array($backupManifest) && is_array($backupManifest['rowCounts']??null)){
        $rowCountComparison['available']=true;
        foreach($backupManifest['rowCounts'] as $tableName=>$expectedRows){
            $safe=tamasyaBackupSafeIdentifier((string)$tableName);
            $actualRows=(int)$pdo->query("SELECT COUNT(*) FROM `{$safe}`")->fetchColumn();
            $rowCountComparison['checkedTables']++;
            if($actualRows!==(int)$expectedRows)$rowCountComparison['mismatches'][(string)$tableName]=['expected'=>(int)$expectedRows,'actual'=>$actualRows];
        }
        if($rowCountComparison['mismatches']) throw new RuntimeException('Row count hasil restore tidak sama dengan manifest backup: '.json_encode(array_slice($rowCountComparison['mismatches'],0,20,true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    $manifestTimezone=is_array($backupManifest)?trim((string)($backupManifest['timeZone']??'')):'';
    if($manifestTimezone!=='' && preg_match('/^[+-](?:0\d|1[0-3]):[0-5]\d$|^\+14:00$/',$manifestTimezone)){
        $pdo->exec('SET time_zone = '.$pdo->quote($manifestTimezone));
    }
    $tableChecksumComparison=['available'=>false,'checkedTables'=>0,'skipped'=>0,'mismatches'=>[]];
    if(is_array($backupManifest) && is_array($backupManifest['tableChecksums']??null)){
        $tableChecksumComparison['available']=true;
        foreach($backupManifest['tableChecksums'] as $checksumTableName=>$expectedChecksum){
            if($expectedChecksum===null){$tableChecksumComparison['skipped']++;continue;}
            $checksumSafe=tamasyaBackupSafeIdentifier((string)$checksumTableName);
            $actualChecksum=tamasyaBackupTableChecksum($pdo,$checksumSafe);
            $tableChecksumComparison['checkedTables']++;
            if((string)$actualChecksum!==(string)$expectedChecksum)$tableChecksumComparison['mismatches'][(string)$checksumTableName]=['expected'=>(string)$expectedChecksum,'actual'=>(string)$actualChecksum];
        }
        if($tableChecksumComparison['mismatches']) throw new RuntimeException('Checksum isi tabel hasil restore tidak sama dengan manifest backup: '.json_encode(array_slice($tableChecksumComparison['mismatches'],0,20,true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    $releaseState=is_array($baseline['releaseState']??null)?$baseline['releaseState']:[];
    $dbIdentity=is_array($identity['database']??null)?$identity['database']:[];
    $evidenceToken=tamasyaRestoreEvidenceIssue([
        'propertyId'=>(string)($dbIdentity['property_id']??tamasyaRestoreEvidencePropertyId()),
        'companyId'=>(string)($dbIdentity['company_id']??''),
        'backupSha256'=>$backupSha256,
        'restoredDatabase'=>$actual,
        'canonicalSqlSha256'=>(string)($baseline['canonicalSqlSha256']??''),
        'expectedCoreTableCount'=>(int)($baseline['expectedCoreTableCount']??0),
        'expectedTriggerCount'=>(int)($baseline['expectedTriggerCount']??0),
        'release'=>(string)($releaseState['current_release']??''),
        'patchLevel'=>(string)($releaseState['patch_level']??''),
    ]);
    tamasyaAdminToolEmit([
        'success'=>true,
        'readOnly'=>true,
        'database'=>$actual,
        'activeExpectedDatabase'=>$activeExpected,
        'backupFile'=>basename($backupReal),
        'backupSizeBytes'=>$backupSize,
        'backupSha256'=>$backupSha256,
        'backupCompletionMarker'=>true,
        'backupManifest'=>$backupManifest,
        'backupObjectHeader'=>$backupObjectHeader,
        'restoredObjectCounts'=>$restoredObjectCounts,
        'objectCountComparison'=>['pass'=>true,'mismatches'=>[]],
        'rowCountComparison'=>$rowCountComparison,
        'tableChecksumComparison'=>$tableChecksumComparison,
        'baseline'=>$baseline,
        'propertyIdentity'=>$identity,
        'backupRunRows'=>$backupRunCount,
        'restoreEvidenceToken'=>$evidenceToken,
        'restoreEvidenceMaxAgeHours'=>48,
        'restoreTestRecorded'=>false,
        'message'=>'Database hasil restore terpisah lolos SHA256 + completion marker + object count table/view/routine/event/trigger + manifest row-count (bila format v2) + checksum isi tabel per-tabel (bila manifest memuat tableChecksums) + baseline canonical + InnoDB + property identity. Gunakan restoreEvidenceToken ini pada workflow backup-restore-tested dalam 48 jam; server live akan memverifikasi HMAC dan kecocokan checksum/database/property.'
    ]);
}catch(Throwable $e){
    tamasyaAdminToolEmit(['success'=>false,'readOnly'=>true,'error'=>$e->getMessage()],$isCli?200:409,true);exit(1);
}
