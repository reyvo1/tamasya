<?php
// No database or external service is contacted by these regression tests.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
$root=$argv[1]??dirname(__DIR__);
require $root.'/api/modules/pos_inventory/085_pos_minibar.php';
require $root.'/api/support/001_runtime_security.php';
require $root.'/api/support/012_domain_primitives.php';
require $root.'/api/support/017_authorization_policy.php';
if(is_file($root.'/api/modules/front_office/042_operational_lifecycle_invariants.php'))require $root.'/api/modules/front_office/042_operational_lifecycle_invariants.php';
if(is_file($root.'/api/modules/front_office/040_rooms_checkout_housekeeping.php'))require_once $root.'/api/modules/front_office/040_rooms_checkout_housekeeping.php';
$failed=0;$passed=0;
function check($ok,$name){global $failed,$passed;if($ok){$passed++;echo "PASS $name\n";}else{$failed++;echo "FAIL $name\n";}}
function allocate($amounts,$discount){
 if(function_exists('tamasyaPosAllocateDiscount'))return tamasyaPosAllocateDiscount($amounts,$discount);
 // Original route's allocation, retained to reproduce the baseline failure.
 $out=[];$remaining=$discount;$total=array_sum($amounts);
 foreach($amounts as $i=>$amount){$part=$i===count($amounts)-1?$remaining:round($discount*$amount/$total,2);$part=max(0,min($amount,$part));$remaining=round($remaining-$part,2);$out[]=$part;}return $out;
}
foreach([[array_fill(0,100,1),0.5],[[1,1,1,1],0.02],[[100,200,300],59.99],[[0.01,0.01,100],99.99],[[10],0]] as $i=>[$amounts,$discount]){
 $parts=allocate($amounts,$discount);
 check(abs(array_sum($parts)-$discount)<0.00001,"discount sum case $i");
 check(count(array_filter($parts,fn($v,$k)=>$v<0||$v>$amounts[$k],ARRAY_FILTER_USE_BOTH))===0,"discount bounds case $i");
}
mt_srand(143);
$balanced=true;
for($i=0;$i<1000;$i++){$amounts=[];for($j=0,$n=mt_rand(1,100);$j<$n;$j++)$amounts[]=mt_rand(1,100000)/100;$discount=mt_rand(0,(int)round(array_sum($amounts)*100)-1)/100;$parts=allocate($amounts,$discount);if(abs(array_sum($parts)-$discount)>0.00001)$balanced=false;}
check($balanced,'1000 deterministic discount baskets preserve total');
class ShiftTestPDO extends PDO {public function __construct(){}public function inTransaction():bool{return true;}}
function getOpenShiftForStaff($pdo,$id,$lock){$GLOBALS['shiftLocked']=$lock;return ['opened_at'=>date('Y-m-d H:i:s')];}
tamasyaPosRequireOpenShift(new ShiftTestPDO(),['id'=>'test']);
check($GLOBALS['shiftLocked']===true,'POS validates shift under a transaction lock');
$source=file_get_contents($root.'/api/routes/080_telegram_webhook.php');
preg_match('/\$telegramPlainText = static function\(\$value\): string \{.*?\n\s*\};/s',$source,$m);eval($m[0]);
preg_match('/\$replyText="✅ \*INSIDEN OPERASIONAL TERCATAT\*.*?;(?=\s*\$replyMarkup)/s',$source,$m);
$reportText='Pintu *rusak*';$incidentId='test';
try{eval($m[0]);check(str_contains($replyText,'Pintu rusak'),'Telegram incident confirmation uses defined formatter');}catch(Throwable $e){check(false,'Telegram incident confirmation uses defined formatter');}
check(validIsoDate('2028-02-29')&&!validIsoDate('2026-02-29'),'calendar leap-day validation');
check(normalizeHotelDateTime('2026-09-14T14:30')==='2026-09-14 14:30:00','local datetime normalization');
foreach(['capabilities'=>'hasCapability','desktopTabs'=>'hasDesktopTabAccess'] as $group=>$fn){
 foreach([false,'false',0,'0',null,[],true,'true',1,'1'] as $i=>$value){
  $expected=in_array($value,[true,'true',1,'1'],true);
  check($fn(['role'=>'admin','permissions'=>[$group=>['test'=>$value]]],'test',['admin'])===$expected,"$group explicit flag $i");
 }
 try{check(!$fn(['role'=>'admin','permissions'=>[$group=>'invalid']],'test',['admin']),"$group corrupt group denied");}catch(Throwable $e){check(false,"$group corrupt group denied");}
 check(!$fn(['role'=>'admin','permissions'=>'{invalid'],'test',['admin']),"$group corrupt JSON denied");
 check($fn(['role'=>'admin','permissions'=>null],'test',['admin']),"$group unspecified keeps fallback");
}
if(function_exists('tamasyaR3StayWindowsOverlap')){
 check(!tamasyaR3StayWindowsOverlap('2026-09-14 14:00','2026-09-15 12:00','2026-09-15 12:00','2026-09-16 12:00'),'adjacent booking windows do not overlap');
 check(tamasyaR3StayWindowsOverlap('2026-09-14 14:00','2026-09-15 12:00','2026-09-15 11:59','2026-09-16 12:00'),'one minute overlap blocks inventory');
 try{tamasyaR3StayWindowsOverlap('2026-09-14 14:00','2026-09-14 14:00','2026-09-15 12:00','2026-09-16 12:00');check(false,'zero duration rejected');}catch(InvalidArgumentException $e){check(true,'zero duration rejected');}
}

check(tamasyaExceptionHttpStatus(new RuntimeException('Pengiriman campaign dinonaktifkan.'),500)===409,'Disabled feature maps to HTTP 409, never server error');
check(tamasyaExceptionHttpStatus(new RuntimeException('HQ bridge belum diaktifkan.'),500)===409,'Not-enabled feature maps to HTTP 409, never server error');
check(tamasyaPermissionOverride('{invalid','telegramNotifications','finance')===false,'Telegram notification corrupt permissions fail closed');
check(tamasyaPermissionOverride(['telegramNotifications'=>'invalid'],'telegramNotifications','finance')===false,'Telegram notification malformed group fails closed');
check(tamasyaPermissionOverride(['telegramNotifications'=>['finance'=>'false']],'telegramNotifications','finance')===false,'Telegram notification string false denied');
check(tamasyaPermissionOverride(['telegramNotifications'=>['finance'=>true]],'telegramNotifications','finance')===true,'Telegram notification explicit true allowed');
$roomSourceLong='web-room-transfer-transfer-old-room';
$roomSourceNormalized=tamasyaNormalizeRoomUpdateSource($roomSourceLong);
check(strlen($roomSourceNormalized)<=30,'Room updatedSource normalizer respects VARCHAR(30) boundary');
check($roomSourceNormalized===tamasyaNormalizeRoomUpdateSource($roomSourceLong),'Room updatedSource normalizer is deterministic');
check(tamasyaNormalizeRoomUpdateSource('web-active-booking')==='web-active-booking','Room updatedSource normalizer preserves safe source labels');
check(tamasyaNormalizeRoomUpdateSource('web-room-transfer-transfer-new-room')!==$roomSourceNormalized,'Room updatedSource normalizer keeps distinct long lifecycle labels distinct');

$telegramMenuDef=strpos($source,'$buildTelegramShiftCloseMenu = static function');
$telegramMenuUse=strpos($source,'$menu=$buildTelegramShiftCloseMenu($pdo,$loggedInStaff)');
check($telegramMenuDef!==false && $telegramMenuUse!==false && $telegramMenuDef<$telegramMenuUse,'Telegram shift-close menu helper is defined before callback use');
$enterpriseJs=file_get_contents($root.'/assets/enterprise-suite.js');
check(str_contains($enterpriseJs,"const vendorSelect=$('pr-vendor'),vendorId=vendorSelect?vendorSelect.value:''"),'Enterprise PR-to-PO handler tolerates rerendered vendor selector');
$growthJs=file_get_contents($root.'/assets/growth-suite.js');
check(str_contains($growthJs,'endpointState.cache?.activeApiUrl?15000:1000') && str_contains($growthJs,'!probes.some(p=>p.reachable)'),'Growth endpoint discovery retries transient failure and short-caches offline state');
$growthRoute=file_get_contents($root.'/api/routes/110_growth_suite.php');
check(str_contains($growthRoute,'$driverCode===1062') && str_contains($growthRoute,"(string)\$error->getCode()==='23000'"),'Growth duplicate-key detector recognizes MySQL and SQLSTATE duplicate conflicts');
check(str_contains($growthRoute,"throw new DomainException('Channel mapping untuk kombinasi channel/properti/kamar/rate tersebut sudah ada.") && str_contains($growthRoute,'if($isDuplicateKey($e))'),'Growth channel mapping duplicate is fail-closed conflict, not HTTP 500');
$legacySource=file_get_contents($root.'/api/support/080_schema_alignment.php');
$guardPos=strpos($legacySource,"Legacy RC4.4 tax migrator is disabled/fail-closed");
$ddlPos=strpos($legacySource,'CREATE TABLE IF NOT EXISTS schema_migration_progress');
check($guardPos!==false && $ddlPos!==false && $guardPos<$ddlPos,'Legacy RC4.4 migrator fails closed before DDL');


// Post-green Owner role hardening: all reads allowed, every non-safe method denied.
$_SERVER['REQUEST_METHOD']='GET';
check(tamasyaIsOwnerRole(['role'=>'owner']),'Owner role is recognized canonically');
check(!tamasyaOwnerMutationDenied(['role'=>'owner']),'Owner GET is read-only-safe');
check(hasDesktopTabAccess(['role'=>'owner'],'finance',[]),'Owner can view any desktop tab');
check(hasCapability(['role'=>'owner'],'view_audit_log',[]),'Owner can use view-only capability');
check(!hasCapability(['role'=>'owner'],'manage_backup',['admin']),'Owner cannot inherit mutation capability');
$_SERVER['REQUEST_METHOD']='POST';
check(tamasyaOwnerMutationDenied(['role'=>'owner']),'Owner POST is denied globally');
$_SERVER['REQUEST_METHOD']='PATCH';
check(tamasyaOwnerMutationDenied(['role'=>'owner']),'Owner PATCH is denied globally');
$_SERVER['REQUEST_METHOD']='DELETE';
check(tamasyaOwnerMutationDenied(['role'=>'owner']),'Owner DELETE is denied globally');
$_SERVER['REQUEST_METHOD']='GET';

echo "$passed passed; $failed failed\n";exit($failed?1:0);
