<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);define('TAMASYA_RECEIPT_COMPACTION_LIBRARY',true);
require dirname(__DIR__).'/api/modules/front_office/043_multi_room_reservations.php';
require dirname(__DIR__).'/api/modules/front_office/044_multi_room_telegram.php';
require dirname(__DIR__).'/receipt_storage_maintenance.php';
$n=0;
function mrCheck(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$n++;}
function mrReject(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException|RuntimeException|DomainException $e){mrCheck(true,$label);return;}throw new RuntimeException($label);}
foreach([['reserved','reserved'],['active','reserved'],['active','active'],['completed','active'],['completed','completed'],['cancelled','cancelled'],['completed','cancelled']] as $i=>$states){$expected=['reserved','partially_checked_in','checked_in','partially_checked_out','completed','cancelled','completed'][$i];$d=tamasyaMultiRoomStatus(array_map(static fn($s)=>['status'=>$s],$states));mrCheck($d['status']===$expected&&array_sum($d['counts'])===2,'Independent child lifecycle '.$expected);}
for($c=1;$c<=300;$c++){foreach([[1,1,1],[0,100],[33.33,66.67],[0.01,0.01,0.02]] as $weights){$parts=tamasyaMultiRoomSplitCents($c/100,$weights);mrCheck(abs(array_sum($parts)-$c/100)<0.00001&&min($parts)>=0,'Exact cents '.$c.' weights '.implode(',',$weights));foreach($weights as $i=>$w)if($w===0)mrCheck($parts[$i]===0,'Zero weight never receives a cent');}}
foreach([NAN,INF,-1,1000000000001] as $v)mrReject(fn()=>tamasyaMultiRoomSplitCents($v,[1,1]),'Invalid amount fails closed');
foreach([[],[0,0],[-1,2],[INF,1],['x',1]] as $w)mrReject(fn()=>tamasyaMultiRoomSplitCents(1,$w),'Invalid weights fail closed');
$actor=['telegram_context'=>json_encode(['flow'=>'multi-room','nonce'=>'good','expiresAt'=>time()+60])];mrCheck(tamasyaMultiRoomTelegramContext($actor,'good')['nonce']==='good','Bound Telegram nonce accepted');mrReject(fn()=>tamasyaMultiRoomTelegramContext($actor,'old'),'Stale nonce denied');$actor['telegram_context']=json_encode(['flow'=>'multi-room','nonce'=>'good','expiresAt'=>time()-1]);mrReject(fn()=>tamasyaMultiRoomTelegramContext($actor,'good'),'Expired Telegram draft denied');
$body=json_encode(['success'=>true,'large'=>str_repeat('Hotel response / exact replay ',3000)]);$next=tamasyaReceiptStorageCandidate($body);mrCheck($next!==null&&strlen($next)<strlen($body)&&tamasyaDecodeReceiptResponseBody($next)===$body,'Receipt lossless byte-equivalent compression');mrCheck(tamasyaReceiptStorageCandidate($next)===null,'Compression is idempotent');mrCheck(tamasyaReceiptStorageCandidate('small')===null,'Small receipt untouched');
$bytes=random_bytes(9000);$next=tamasyaReceiptStorageCandidate($bytes);mrCheck($next===null||tamasyaDecodeReceiptResponseBody($next)===$bytes,'Binary/incompressible data remains lossless');
final class MrNoDb extends PDO{public function __construct(){}}
mrReject(fn()=>tamasyaMultiRoomRequire(new MrNoDb(),['role'=>'owner'],true),'Owner write rejected before any DB access');
$src=file_get_contents(dirname(__DIR__).'/api/modules/front_office/043_multi_room_reservations.php');$cli=file_get_contents(dirname(__DIR__).'/receipt_storage_maintenance.php');
mrCheck(!preg_match('/CREATE\s+(TABLE|ALTER)|ALTER\s+TABLE/i',$src.$cli),'No runtime schema creation');mrCheck(!preg_match('/DELETE\s+FROM/i',$cli),'Compaction deletes no metadata/history');mrCheck(str_contains($cli,'BINARY response_body=BINARY ?'),'Concurrent replay-body update uses byte comparison');
require dirname(__DIR__).'/api/support/017_authorization_policy.php';require dirname(__DIR__).'/api/modules/growth_enterprise_locked/105_growth_suite.php';
putenv('TAMASYA_GROWTH_SUITE_ENABLED=0');putenv('TAMASYA_GROWTH_GROUP_CORPORATE_ENABLED=1');mrReject(fn()=>tamasyaMultiRoomRequire(new MrNoDb(),['role'=>'admin'],false),'Disabled parent blocks group before schema access');
putenv('TAMASYA_GROWTH_SUITE_ENABLED=1');putenv('TAMASYA_GROWTH_GROUP_CORPORATE_ENABLED=0');mrReject(fn()=>tamasyaMultiRoomRequire(new MrNoDb(),['role'=>'admin'],false),'Disabled child blocks group before schema access');
echo "Multi-room and lossless storage unit assertions: $n passed.\n";
