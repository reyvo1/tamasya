<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
define('TAMASYA_RECEIPT_COMPACTION_LIBRARY',true);
require dirname(__DIR__).'/receipt_storage_maintenance.php';
$passed=0;
function receiptCheck(bool $ok,string $name):void{global $passed;if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;}
$snapshot=['rooms'=>[['number'=>'101']], 'bookings'=>[], 'transactions'=>[], 'staff'=>[], 'notifications'=>array_map(static fn($n)=>['id'=>hash('sha256',(string)$n),'message'=>hash('sha512','notification'.$n)],range(1,500))];
$result=(object)['success'=>true,'transactionId'=>'tx_exact','documentNumber'=>'DOC-1','amount'=>75.01,'taxAmount'=>6.82,'warning'=>'Review historical evidence','empty'=>(object)[],'details'=>[(object)['splitCash'=>0.01,'splitTransfer'=>75.0]],'db'=>$snapshot];
$body=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
$prepared=tamasyaPrepareDurableReceiptBody($body,'transactions');$stored=tamasyaEncodeReceiptResponseBody($prepared);
receiptCheck(str_starts_with($prepared,'@tamasya:projection-v1:'),'Versioned compact receipt for actual PMS projection');
receiptCheck(strlen($stored)<strlen(tamasyaEncodeReceiptResponseBody($body))/20,'Bulk UI snapshot does not accumulate in each receipt');
$calls=0;$replayed=json_decode(tamasyaReplayDurableReceiptBody($stored,static function()use(&$calls){$calls++;return ['rooms'=>[],'bookings'=>[],'transactions'=>[],'staff'=>[],'marker'=>'current-permission-projection'];}));
$expected=clone $result;unset($expected->db);$actual=clone $replayed;unset($actual->db,$actual->receiptProjection);
receiptCheck($actual==$expected,'Exact business IDs amount split tax warnings and empty object preserved');
receiptCheck($calls===1&&$replayed->db->marker==='current-permission-projection','Only re-read current role-scoped projection; no mutation replay');
$legacy=tamasyaEncodeReceiptResponseBody($body);
receiptCheck(tamasyaReplayDurableReceiptBody($legacy,static function(){throw new RuntimeException('must not run');})===$body,'Legacy gzip receipt stays byte-identical');
receiptCheck(tamasyaReplayDurableReceiptBody('{"success":false,"error":"Denied"}',static function(){throw new RuntimeException('must not run');})==='{"success":false,"error":"Denied"}','Legacy raw and rejected receipt stays byte-identical');
foreach(['bookings','multi-room-bookings','sync','public-help-chat','unknown'] as $action)receiptCheck(tamasyaPrepareDurableReceiptBody($body,$action)===$body,'Unreviewed action keeps full legacy contract: '.$action);
$denied=json_encode(['success'=>false,'error'=>'Denied','db'=>$snapshot]);receiptCheck(tamasyaPrepareDurableReceiptBody($denied,'transactions')===$denied,'Rejected response is never converted');
foreach(['{"success":true,"db":{}}','{"success":true,"db":[]}','{"success":true,"db":{"rooms":[],"bookings":[],"transactions":"not-array"}}','not-json'] as $raw)receiptCheck(tamasyaPrepareDurableReceiptBody($raw,'transactions')===$raw,'Arbitrary db field or invalid JSON stays unchanged');
receiptCheck(str_starts_with(tamasyaPrepareDurableReceiptBody($body,'notifications-read'),'@tamasya:projection-v1:'),'Read-notification receipts omit the same transient projection');
$fallback=json_decode(tamasyaReplayDurableReceiptBody($stored,static function(){throw new RuntimeException('private credentials must not leak');}));
receiptCheck($fallback->success===true&&$fallback->transactionId==='tx_exact'&&$fallback->refreshRequired===true&&!property_exists($fallback,'db')&&!str_contains(json_encode($fallback),'credentials'),'Projection failure preserves committed result and requests refresh');
foreach(['@tamasya:projection-v1:bad','@tamasya:projection-v1:{"format":"wrong"}','@tamasya:projection-v1:{"format":"tamasya-receipt-projection-v1","projection":"role-scoped-hotel-data","response":{"success":true,"db":{}}}'] as $bad){
 try{tamasyaReplayDurableReceiptBody($bad,static fn()=>[]);throw new LogicException('Corrupt envelope accepted');}catch(JsonException|RuntimeException $e){receiptCheck(true,'Corrupt envelope fails closed without rerun');}
}
$candidate=tamasyaReceiptProjectionCandidate($legacy,'transactions');
receiptCheck($candidate!==null&&strlen($candidate)<strlen($legacy)/20,'Explicit backlog mode removes old compressed projection at logical storage layer');
receiptCheck(tamasyaReceiptProjectionCandidate($candidate,'transactions')===null,'Logical backlog compaction is idempotent');
receiptCheck(tamasyaReceiptProjectionCandidate($legacy,'bookings')===null,'Backlog conversion limited to reviewed actions');
$backlog=json_decode(tamasyaReplayDurableReceiptBody($candidate,static fn()=>[]));unset($backlog->db,$backlog->receiptProjection);
receiptCheck($backlog==$expected,'Backlog mode preserves IDs amount PBJT split and original business warnings');
try{tamasyaReceiptProjectionCandidate('@tamasya:gzip-base64:invalid','transactions');throw new LogicException('Corrupt codec accepted');}catch(RuntimeException $e){receiptCheck(true,'Corrupt old codec cannot be compacted');}
$root=dirname(__DIR__);$entry=file_get_contents($root.'/api.php');$auth=file_get_contents($root.'/api/modules/hr_staff/020_identity_access_audit.php');
receiptCheck(str_contains($entry,'static fn()=>getRoleScopedHotelData($pdo,$loggedInStaff)')&&str_contains($auth,'static fn()=>getRoleScopedHotelData($pdo,$user)'),'Browser and forwarded-node operation receipts share role-scoped replay');
receiptCheck(str_contains($auth,'$responseBody,$classification,$hasFatal ? $fatalError : null,(string)$action)')&&str_contains($entry,'$responseBody,$classification,$fatalError,(string)$auditActionSnapshot)'),'Both writers explicitly supply the original action');
echo "Receipt projection: $passed passed; 0 failed.".PHP_EOL;
