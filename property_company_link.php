<?php
declare(strict_types=1);
/**
 * TAMASYA V137 controlled affiliation tool.
 *
 * Purpose: safely link an already-initialized flexible property whose DB has
 * company_id empty to a company/group configured in TAMASYA_COMPANY_ID.
 * It is intentionally NOT a general re-parent/unlink tool.
 *
 * CLI:
 *   php property_company_link.php --apply --confirm=LINK-COMPANY --backup-confirmed=<reference>
 *
 * Browser (only when ADMIN_WEB_TOOLS_ENABLED=1 and protected by the standard
 * web-tool secret): open the page and POST the same confirmation/reference.
 *
 * Two-node cluster: place BOTH nodes in planned maintenance, disable cluster/
 * sync temporarily, update TAMASYA_COMPANY_ID on BOTH nodes, run this tool on
 * EACH node database, then re-enable cluster/sync and verify status.
 */
error_reporting(E_ALL);ini_set('display_errors','0');ini_set('log_errors','1');
require_once __DIR__.'/database_bootstrap.php';

require_once __DIR__.'/admin_web_tool_gate.php';
$isCli=tamasyaAdminToolIsCli();
$input=[];
if($isCli){
    foreach(array_slice($argv??[],1) as $arg){
        if($arg==='--apply')$input['apply']='1';
        elseif(str_starts_with($arg,'--confirm='))$input['confirm']=substr($arg,10);
        elseif(str_starts_with($arg,'--backup-confirmed='))$input['backup']=substr($arg,19);
    }
}else{
    tamasyaAdminToolBeginWeb('TAMASYA Link Property ke Company');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        $fields='<label><input type="checkbox" name="apply" value="1"> Apply link company (jika tidak dicentang = dry run)</label>'
            .'<label for="backup">Referensi backup terverifikasi (wajib saat Apply)</label><input id="backup" name="backup_confirmed" type="text" maxlength="255" placeholder="contoh: tamasya_daily_20260810.sql">'
            .'<label><input type="checkbox" name="confirm" value="LINK-COMPANY"> Saya memahami ENV kedua node harus sudah memakai Company ID yang sama dan cluster/sync harus OFF selama maintenance.</label>';
        tamasyaAdminToolForm('TAMASYA Link Property ke Company','Tool ini hanya menghubungkan company_id yang semula kosong ke TAMASYA_COMPANY_ID. Re-parent atau unlink otomatis ditolak.',$fields,'Periksa / Jalankan');
    }
    tamasyaAdminToolAuthorizeWeb();
    $input=['apply'=>(string)($_POST['apply']??''),'confirm'=>(string)($_POST['confirm']??''),'backup'=>(string)($_POST['backup_confirmed']??'')];
}
$targetCompany=strtolower(trim((string)(getenv('TAMASYA_COMPANY_ID')?:'')));
if($targetCompany===''||!preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$targetCompany)){
    $message='TAMASYA_COMPANY_ID target wajib diisi (2-80 karakter: a-z, 0-9, . _ : -).';
    if($isCli){fwrite(STDERR,$message.PHP_EOL);exit(2);}http_response_code(422);header('Content-Type: application/json');echo json_encode(['success'=>false,'error'=>$message]);exit;
}
if(filter_var(getenv('NODE_CLUSTER_ENABLED')?:'0',FILTER_VALIDATE_BOOLEAN)||filter_var(getenv('NODE_SYNC_ENABLED')?:'0',FILTER_VALIDATE_BOOLEAN)){
    $message='Link company hanya boleh dijalankan saat NODE_CLUSTER_ENABLED=0 dan NODE_SYNC_ENABLED=0 dalam planned maintenance.';
    if($isCli){fwrite(STDERR,$message.PHP_EOL);exit(3);}http_response_code(409);header('Content-Type: application/json');echo json_encode(['success'=>false,'error'=>$message]);exit;
}

$config=tamasyaResolveDatabaseConfig(__DIR__);[$pdo,$error,$stage]=tamasyaConnectDatabase($config);
if(!$pdo instanceof PDO){$message='Database tidak tersedia: '.$stage;if($isCli){fwrite(STDERR,$message.PHP_EOL);exit(4);}http_response_code(503);header('Content-Type: application/json');echo json_encode(['success'=>false,'error'=>$message]);exit;}
try{
    tamasyaAssertDatabaseSafety($pdo,$config);
    $identity=tamasyaDatabasePropertyIdentity($pdo,false);
    if(empty($identity['initialized']))throw new RuntimeException('Property belum diinisialisasi. Jalankan first install terlebih dahulu.');
    $mismatches=(array)($identity['mismatches']??[]);
    $unexpected=array_values(array_filter(array_keys($mismatches),static fn(string $field):bool=>$field!=='company_id'));
    if($unexpected)throw new RuntimeException('Identity field selain company_id tidak cocok: '.implode(', ',$unexpected).'. Link dibatalkan.');
    $row=$pdo->query("SELECT company_id,property_id,property_code FROM property_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    $currentCompany=strtolower(trim((string)($row['company_id']??'')));
    if($currentCompany!==''&&!hash_equals($currentCompany,$targetCompany))throw new RuntimeException('Property sudah terhubung ke company lain. Re-parent otomatis ditolak.');
    $result=['success'=>true,'dryRun'=>true,'propertyId'=>(string)($row['property_id']??''),'propertyCode'=>(string)($row['property_code']??''),'currentCompanyId'=>$currentCompany,'targetCompanyId'=>$targetCompany,'message'=>$currentCompany===$targetCompany?'Company ID sudah sesuai; tidak ada perubahan.':'Siap menghubungkan property ke company target.'];
    $apply=(string)($input['apply']??'')==='1';
    if($apply){
        if(!hash_equals('LINK-COMPANY',trim((string)($input['confirm']??''))))throw new InvalidArgumentException('Konfirmasi wajib persis LINK-COMPANY.');
        $backup=trim((string)($input['backup']??''));if(strlen($backup)<8)throw new InvalidArgumentException('Referensi backup terverifikasi wajib diisi minimal 8 karakter.');
        $backupStmt=$pdo->prepare("SELECT id,file_name,status,size_bytes,checksum,created_at FROM backup_runs WHERE status='completed' AND (id=? OR file_name=?) ORDER BY created_at DESC LIMIT 1");
        $backupStmt->execute([$backup,$backup]);$backupRow=$backupStmt->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$backupRow)throw new RuntimeException('Referensi backup tidak ditemukan sebagai backup completed pada database ini. Jalankan backup restore-grade terlebih dahulu.');
        $backupChecksum=strtolower(trim((string)($backupRow['checksum']??'')));
        $backupSize=(int)($backupRow['size_bytes']??0);
        if(!preg_match('/^[a-f0-9]{64}$/',$backupChecksum) || $backupSize<=0)throw new RuntimeException('Backup referensi belum mempunyai SHA256/ukuran valid. Link company dibatalkan.');
        $backupDir=trim((string)(getenv('BACKUP_DIR')?:''));
        if($backupDir==='' || !tamasyaPathIsAbsolute($backupDir))throw new RuntimeException('BACKUP_DIR wajib path absolut private untuk memverifikasi file backup fisik sebelum link company.');
        if(!is_dir($backupDir) || !is_readable($backupDir))throw new RuntimeException('BACKUP_DIR tidak ada/tidak readable; file backup fisik tidak dapat diverifikasi.');
        if(tamasyaPathIsInsideDocumentRoot($backupDir,__DIR__))throw new RuntimeException('BACKUP_DIR berada di document root; link company dibatalkan demi keamanan backup.');
        $backupFileName=trim((string)($backupRow['file_name']??''));
        if($backupFileName==='' || basename($backupFileName)!==$backupFileName || str_contains($backupFileName,"\0"))throw new RuntimeException('Nama file backup tidak aman/tidak valid.');
        $physicalBackup=rtrim($backupDir,'/\\').DIRECTORY_SEPARATOR.$backupFileName;
        if(!is_file($physicalBackup) || !is_readable($physicalBackup))throw new RuntimeException('File backup fisik tidak ditemukan/readable di BACKUP_DIR. Metadata saja tidak cukup untuk link company.');
        $physicalSize=filesize($physicalBackup);
        if($physicalSize===false || (int)$physicalSize!==$backupSize)throw new RuntimeException('Ukuran file backup fisik tidak cocok dengan metadata backup_runs.');
        $physicalChecksum=hash_file('sha256',$physicalBackup);
        if(!is_string($physicalChecksum) || !hash_equals($backupChecksum,strtolower($physicalChecksum)))throw new RuntimeException('SHA256 file backup fisik tidak cocok dengan metadata backup_runs.');
        $createdAt=strtotime((string)($backupRow['created_at']??''));
        if($createdAt===false)throw new RuntimeException('Timestamp backup referensi tidak valid.');
        $maxAgeHours=max(1,min(168,(int)(getenv('TAMASYA_COMPANY_LINK_BACKUP_MAX_AGE_HOURS')?:24)));
        if(time()-$createdAt>$maxAgeHours*3600)throw new RuntimeException('Backup referensi terlalu lama untuk link company. Buat backup baru maksimal '.$maxAgeHours.' jam sebelum perubahan affiliation.');
        if($currentCompany===''){
            $pdo->beginTransaction();
            try{
                $stmt=$pdo->prepare("UPDATE property_settings SET company_id=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default' AND (company_id IS NULL OR company_id='')");
                $stmt->execute([$targetCompany]);
                if($stmt->rowCount()!==1)throw new RuntimeException('Company ID berubah oleh proses lain; link dibatalkan.');
                try{$pdo->exec("UPDATE config SET server_revision=COALESCE(server_revision,0)+1 WHERE id='system_default'");}catch(Throwable $ignored){}
                $auditId='audit_'.bin2hex(random_bytes(16));
                $oldData=json_encode(['companyId'=>$currentCompany?:null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $newData=json_encode(['companyId'=>$targetCompany,'backupId'=>$backupRow['id'],'backupFile'=>$backupRow['file_name'],'backupSha256'=>$backupChecksum,'backupPhysicalVerified'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $pdo->prepare("INSERT INTO audit_logs(id,staff_id,staff_name,source,action,entity_type,entity_id,old_data,new_data,property_id,node_id,outcome,created_at) VALUES (?,NULL,'Maintenance Tool','maintenance','Menghubungkan property ke company','property_settings','system_default',?,?,?,?,'success',CURRENT_TIMESTAMP)")
                    ->execute([$auditId,$oldData,$newData,(string)($row['property_id']??''),trim((string)(getenv('TAMASYA_NODE_ID')?:''))?:null]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
        $verified=tamasyaDatabasePropertyIdentity($pdo,true);
        $result=['success'=>true,'dryRun'=>false,'propertyId'=>(string)($row['property_id']??''),'propertyCode'=>(string)($row['property_code']??''),'companyId'=>$targetCompany,'backupReference'=>$backup,'backupId'=>$backupRow['id'],'backupSha256'=>$backupChecksum,'backupPhysicalVerified'=>true,'identityOk'=>!empty($verified['ok']),'message'=>'Property berhasil terhubung ke company target. Jalankan/verifikasi pada node pasangan sebelum cluster/sync diaktifkan kembali.'];
    }
    if($isCli)echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    else{header('Content-Type: application/json; charset=UTF-8');echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}catch(Throwable $e){
    error_log('[property_company_link] '.$e->getMessage());
    if($isCli){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(5);}http_response_code($e instanceof InvalidArgumentException?422:409);header('Content-Type: application/json; charset=UTF-8');echo json_encode(['success'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
