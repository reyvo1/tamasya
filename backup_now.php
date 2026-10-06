<?php
declare(strict_types=1);
/**
 * TAMASYA V137 node-local restore-grade backup.
 *
 * Purpose:
 * - safe ad-hoc backup on a single-server deployment, Primary, or Standby;
 * - gives both servers independent physical backup copies;
 * - writes only node-local backup_runs metadata after the read-only snapshot.
 *
 * Unlike maintenance_cron.php this tool never runs business cleanup/alerts and
 * never needs to become the active writer. backup_runs is intentionally excluded
 * from node snapshot replication because its file exists only on this node.
 */
require_once __DIR__.'/release_contract.php';
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/baseline_support.php';
require_once __DIR__.'/backup_support.php';
require_once __DIR__.'/node_sync_support.php';
require_once __DIR__.'/admin_web_tool_gate.php';

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA Node-Local Backup');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA Node-Local Backup',
            'Membuat SQL restore-grade dari database node ini. Boleh dijalankan pada Primary maupun Standby karena tidak mengubah data bisnis; hanya metadata backup lokal yang dicatat.',
            '<label><input type="checkbox" name="confirm" value="BACKUP_THIS_NODE" required> Simpan backup ke BACKUP_DIR private pada node ini.</label>',
            'Buat Backup Sekarang'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
    if((string)($_POST['confirm']??'')!=='BACKUP_THIS_NODE'){
        tamasyaAdminToolEmit(['success'=>false,'error'=>'Konfirmasi BACKUP_THIS_NODE wajib.'],400,true);exit;
    }
}

function tamasyaNodeLocalBackupAbsolutePath(string $path): bool {
    return tamasyaPathIsAbsolute($path);
}
function tamasyaNodeLocalBackupSafeToken(string $value,string $fallback='node'): string {
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9._-]+/','-',$value)??'';
    $value=trim($value,'-._');
    return $value!==''?substr($value,0,64):$fallback;
}
function tamasyaNodeLocalBackupAtomicReplace(string $source,string $destination): void {
    if(@rename($source,$destination)) return;
    if(is_file($destination) && !@unlink($destination)) throw new RuntimeException('File backup tujuan tidak dapat diganti.');
    if(!@rename($source,$destination)) throw new RuntimeException('File temporary backup tidak dapat dipindahkan ke tujuan final.');
}

$config=tamasyaResolveDatabaseConfig(__DIR__);
[$pdo,$error,$stage]=tamasyaConnectDatabase($config);
if(!$pdo instanceof PDO){
    tamasyaAdminToolEmit(['success'=>false,'stage'=>$stage,'error'=>$error?:'Database tidak dapat dihubungkan.'],$isCli?200:503,true);exit(2);
}

$path=null;$tempPath=null;
try{
    $safety=tamasyaAssertDatabaseSafety($pdo,$config);
    $identity=tamasyaDatabasePropertyIdentity($pdo,true);
    $baseline=tamasyaAssertCanonicalBaseline($pdo);

    $backupDir=trim((string)(getenv('BACKUP_DIR')?:''));
    if($backupDir==='' || !tamasyaNodeLocalBackupAbsolutePath($backupDir)) throw new RuntimeException('BACKUP_DIR wajib path absolut private.');
    if(!is_dir($backupDir) && !mkdir($backupDir,0700,true) && !is_dir($backupDir)) throw new RuntimeException('BACKUP_DIR tidak dapat dibuat.');
    $boundary=tamasyaBackupDirectoryOutsideDocumentRoot($backupDir);
    if(!empty($boundary['insideDocumentRoot']) || tamasyaPathIsInsideDocumentRoot($backupDir,__DIR__)) throw new RuntimeException('BACKUP_DIR harus berada di luar document root/aplikasi publik.');
    if(!is_readable($backupDir) || !is_writable($backupDir)) throw new RuntimeException('BACKUP_DIR tidak readable/writable oleh PHP user.');
    @chmod($backupDir,0700);

    $nodeId=tamasyaNodeLocalBackupSafeToken((string)(getenv('TAMASYA_NODE_ID')?:'single-node'));
    $propertyId=tamasyaNodeLocalBackupSafeToken((string)(getenv('TAMASYA_PROPERTY_ID')?:getenv('APP_PROPERTY_ID')?:'property'),'property');
    $stamp=date('Ymd_His');
    $filename="tamasya_node_{$propertyId}_{$nodeId}_{$stamp}.sql";
    $path=rtrim($backupDir,'/\\').DIRECTORY_SEPARATOR.$filename;
    $tempPath=$path.'.tmp-'.bin2hex(random_bytes(6));
    $fh=fopen($tempPath,'xb');
    if($fh===false) throw new RuntimeException('File temporary backup tidak dapat dibuat.');
    @chmod($tempPath,0600);
    $writer=static function(string $chunk) use ($fh): bool {
        $length=strlen($chunk);$offset=0;
        while($offset<$length){$written=fwrite($fh,substr($chunk,$offset));if($written===false||$written===0)return false;$offset+=$written;}
        return true;
    };
    $streamError=null;$meta=[];
    try{
        $meta=tamasyaBackupStreamFullSql($pdo,$writer,[
            'batchSize'=>100,
            'release'=>TAMASYA_SCHEMA_RELEASE,
            'patch'=>TAMASYA_PATCH_LEVEL,
        ]);
        fflush($fh);if(function_exists('fsync'))@fsync($fh);
    }catch(Throwable $e){$streamError=$e;}finally{fclose($fh);}
    if($streamError instanceof Throwable) throw $streamError;

    $selfVerification=tamasyaBackupVerifySqlFile($tempPath,$meta);
    tamasyaNodeLocalBackupAtomicReplace($tempPath,$path);$tempPath=null;@chmod($path,0600);
    $finalVerification=tamasyaBackupVerifySqlFile($path,$meta);
    if(!hash_equals((string)$selfVerification['sha256'],(string)$finalVerification['sha256'])) throw new RuntimeException('Checksum berubah setelah atomic replace backup.');
    $checksum=(string)$finalVerification['sha256'];$size=(int)$finalVerification['sizeBytes'];

    // Local metadata only. Do not bump business server revision and do not put
    // this table in cross-node snapshots: the file exists on this node only.
    $id='backup_node_'.bin2hex(random_bytes(12));
    $createdBy=substr('node:'.$nodeId,0,50);
    $notes='NODE-LOCAL restore-grade SQL; self-verification PASS (SHA256 + single completion marker + object header/markers + manifest rowCounts); all InnoDB base tables + data + views + routines + events + triggers; PHP SHOW CREATE exporter (no mysqldump dependency); consistent snapshot; restore drill NOT yet executed.';
    $stmt=$pdo->prepare("INSERT INTO backup_runs (id,backup_type,file_name,status,size_bytes,checksum,notes,created_by,created_at,restore_tested_at,restore_tested_by) VALUES (?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,NULL,NULL)");
    $stmt->execute([$id,'node-local',$filename,'completed',$size,$checksum,$notes,$createdBy]);

    // Keep node-local files bounded. Metadata for removed files is retained so
    // the audit trail shows that the physical artifact was subject to retention.
    $keep=max(2,min(90,(int)(getenv('TAMASYA_NODE_LOCAL_BACKUP_KEEP')?:14)));
    $files=glob(rtrim($backupDir,'/\\').DIRECTORY_SEPARATOR.'tamasya_node_'.$propertyId.'_'.$nodeId.'_*.sql')?:[];
    usort($files,static fn(string $a,string $b):int=>(filemtime($b)?:0)<=>(filemtime($a)?:0));
    $deleted=[];
    foreach(array_slice($files,$keep) as $old){if(@unlink($old))$deleted[]=basename($old);}

    tamasyaAdminToolEmit([
        'success'=>true,
        'database'=>$safety['actual'],
        'propertyIdentity'=>$identity,
        'nodeId'=>$nodeId,
        'nodeRole'=>(string)(getenv('TAMASYA_NODE_ROLE')?:'single'),
        'backupRunId'=>$id,
        'file'=>$filename,
        'sizeBytes'=>$size,
        'sha256'=>$checksum,
        'tables'=>(int)($meta['tables']??0),
        'views'=>(int)($meta['views']??0),
        'routines'=>(int)($meta['routines']??0),
        'events'=>(int)($meta['events']??0),
        'triggers'=>(int)($meta['triggers']??0),
        'programmableObjectExporter'=>(string)($meta['programmableObjectExporter']??'unknown'),
        'consistency'=>(string)($meta['consistency']??'unknown'),
        'selfVerified'=>true,
        'selfVerification'=>['formatVersion'=>(int)($finalVerification['manifest']['formatVersion']??0),'completionMarkerCount'=>(int)$finalVerification['completionMarkerCount'],'objectCounts'=>$finalVerification['header']],
        'restoreTested'=>false,
        'retentionKeep'=>$keep,
        'retentionDeleted'=>$deleted,
        'baseline'=>$baseline,
        'message'=>'Backup node-local selesai. Salin/offsite-kan file sesuai kebijakan dan lakukan restore drill terisolasi sebelum menandainya restore-tested.'
    ]);
}catch(Throwable $e){
    if(is_string($tempPath) && $tempPath!=='' && is_file($tempPath))@unlink($tempPath);
    tamasyaAdminToolEmit(['success'=>false,'error'=>$e->getMessage(),'partialFileKept'=>is_string($path)&&is_file($path)],$isCli?200:409,true);exit(1);
}
