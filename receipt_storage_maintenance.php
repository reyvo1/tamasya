<?php
/** CLI-only, lossless storage maintenance; no schema/business/history deletion. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!defined('TAMASYA_API_ENTRY'))define('TAMASYA_API_ENTRY',true);
require_once __DIR__.'/api/modules/hr_staff/020_identity_access_audit.php';
function tamasyaReceiptStorageCandidate(string $body): ?string {
    if(strlen($body)<4096||str_starts_with($body,'@tamasya:gzip-base64:'))return null;
    $encoded=tamasyaEncodeReceiptResponseBody($body);
    if(strlen($encoded)>=strlen($body))return null;
    if(tamasyaDecodeReceiptResponseBody($encoded)!==$body)throw new RuntimeException('Lossless receipt verification failed.');
    return $encoded;
}
function tamasyaCompactReceiptStorage(PDO $pdo,bool $apply=false,int $limit=200): array {
    if($limit<1||$limit>1000)throw new InvalidArgumentException('Limit must be 1–1000.');
    if($apply&&!$pdo->inTransaction())throw new RuntimeException('Apply requires an authority-checked transaction.');
    $rows=$pdo->query("SELECT operation_id,response_body FROM request_operation_receipts WHERE status IN ('completed','failed','rejected') AND OCTET_LENGTH(response_body)>=4096 AND response_body NOT LIKE '@tamasya:gzip-base64:%' ORDER BY operation_id LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
    $result=['success'=>true,'mode'=>$apply?'apply':'dry-run','scanned'=>count($rows),'eligible'=>0,'updated'=>0,'bytesBefore'=>0,'bytesAfter'=>0,'bytesSaved'=>0];
    foreach($rows as $r){$body=(string)$r['response_body'];$next=tamasyaReceiptStorageCandidate($body);if($next===null)continue;$result['eligible']++;$result['bytesBefore']+=strlen($body);$result['bytesAfter']+=strlen($next);
        if($apply){$s=$pdo->prepare("UPDATE request_operation_receipts SET response_body=?,updated_at=updated_at WHERE operation_id=? AND BINARY response_body=BINARY ? AND status IN ('completed','failed','rejected')");$s->execute([$next,$r['operation_id'],$body]);$result['updated']+=$s->rowCount();}
    }
    $result['bytesSaved']=$result['bytesBefore']-$result['bytesAfter'];return $result;
}
if(defined('TAMASYA_RECEIPT_COMPACTION_LIBRARY'))return;
$options=getopt('', ['apply','limit:','backup-confirmed:','help']);
if(isset($options['help'])){echo "php receipt_storage_maintenance.php [--limit=200] [--apply --backup-confirmed=/private/verified-backup.sql]\nDefault: read-only dry-run. Compact existing replay bodies without deleting metadata or business records.\n";exit;}
$apply=isset($options['apply']);$limit=(int)($options['limit']??200);
try{
    if($apply&&trim((string)($options['backup-confirmed']??''))==='')throw new RuntimeException('Apply requires a verified backup reference.');
    require_once __DIR__.'/release_contract.php';require_once __DIR__.'/database_bootstrap.php';require_once __DIR__.'/node_sync_support.php';require_once __DIR__.'/node_cluster_support.php';
    $config=tamasyaResolveDatabaseConfig(__DIR__);[$pdo,$error,$stage]=tamasyaConnectDatabase($config);
    if(!$pdo instanceof PDO)throw new RuntimeException('Database connection unavailable ('.$stage.').');
    tamasyaAssertDatabaseSafety($pdo,$config);tamasyaDatabasePropertyIdentity($pdo,true);
    if($apply){if(!tamasyaClusterEnabled()&&tamasyaNodeRole()!=='online_primary')throw new RuntimeException('Storage maintenance is primary-only.');if(!tamasyaAcquirePrimaryMutationLock($pdo,30))throw new RuntimeException('Primary mutation lock unavailable.');$guard=tamasyaClusterMutationGuard($pdo);if($guard!==null)throw new RuntimeException($guard);$pdo->beginTransaction();}
    $result=tamasyaCompactReceiptStorage($pdo,$apply,$limit);
    if($apply){tamasyaClusterAssertCommitAuthority($pdo);$pdo->commit();}
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,json_encode(['success'=>false,'error'=>$e->getMessage()]).PHP_EOL);exit(1);}
