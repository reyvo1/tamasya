<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);define('TAMASYA_RECEIPT_COMPACTION_LIBRARY',true);
require dirname(__DIR__).'/api/modules/front_office/043_multi_room_reservations.php';
require dirname(__DIR__).'/api/modules/front_office/044_multi_room_telegram.php';
require dirname(__DIR__).'/receipt_storage_maintenance.php';
require dirname(__DIR__).'/api/support/012_domain_primitives.php';
require dirname(__DIR__).'/api/modules/front_office/040_rooms_checkout_housekeeping.php';
require dirname(__DIR__).'/api/modules/front_office/042_operational_lifecycle_invariants.php';
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
// Source choices and conflict descriptions use real production helpers, no DB startup.
$opts=tamasyaMultiRoomSourceOptions([['source_pattern'=>'*'],['source_pattern'=>'OTA'],['source_pattern'=>'direct'],['source_pattern'=>'traveloka'],['source_pattern'=>'Agen Lokal'],['source_pattern'=>'Agen Lokal'],['source_pattern'=>"bad\nsource"]]);
mrCheck($opts===['Direct','Website','Traveloka','Booking.com','Tiket.com','Agoda','Airbnb','Agen Lokal'],'Specific OTA and configured source options are deduplicated; generic tax patterns are not sources');
mrCheck(tamasyaMultiRoomNormalizeSource(' Traveloka ')==='Traveloka','Source trims whitespace without replacing the OTA identity');
foreach(['','OTA',str_repeat('a',101),"Bad\nSource"] as $value)mrReject(fn()=>tamasyaMultiRoomNormalizeSource($value),'Invalid or generic source rejected instead of silently saving Direct');
mrCheck(tamasyaMultiRoomNormalizeSource(str_repeat('é',100))===str_repeat('é',100),'100 Unicode source characters accepted');
$label=tamasyaMultiRoomConflictLabel(['id'=>'private-id','guestName'=>'Private guest','status'=>'active','_resolvedStartAt'=>'2026-10-08 14:00:00','_resolvedEndAt'=>'2026-10-09 12:00:00']);
mrCheck(str_contains($label,'2026-10-09 12:00:00')&&str_contains($label,'tamu menginap')&&!str_contains($label,'private-id')&&!str_contains($label,'Private guest'),'Unavailable room explains its actual conflicting window without internal IDs or guest PII');
mrCheck(str_contains(tamasyaMultiRoomConflictLabel(['status'=>'active','_resolvedStartAt'=>'2026-10-08 14:00:00','_resolvedEndAt'=>'9999-12-31 23:59:59']),'belum ada tanggal checkout'),'Open-ended stay explains why future inventory remains blocked');
$picker=tamasyaMultiRoomTelegramSourcePicker(['bookingSources'=>$opts,'nonce'=>'bound']);$buttons=array_merge(...$picker['markup']['inline_keyboard']);
mrCheck(count(array_filter($buttons,static fn($b)=>$b['text']==='Traveloka'&&$b['callback_data']==='mr_source:2:bound'))===1,'Telegram OTA source button keeps its bound state nonce and exact name');
mrCheck(count(array_filter($buttons,static fn($b)=>$b['callback_data']==='mr_source:custom:bound'))===1,'Telegram retains explicit custom source text entry');
// Reservation planning and physical check-in have distinct blocker contracts.
$stay=['type'=>'active_booking','id'=>'guest-now','status'=>'active'];
$key=['type'=>'physical_key','status'=>'issued','bookingId'=>'guest-now'];
mrCheck(tamasyaReservationInventoryBlockers([$stay,$key,['type'=>'housekeeping']])===[],'Normal issued key for current guest does not block future reservation');
mrCheck(tamasyaDeriveRoomOperationalStatus([$stay,$key])==='booked','Same issued-key room remains physically occupied for check-in');
foreach([['status'=>'missing','bookingId'=>'guest-now'],['status'=>'override','bookingId'=>'guest-now'],['status'=>'issued','bookingId'=>'other'],['status'=>'issued','bookingId'=>'']] as $exception){$b=array_merge($key,$exception);mrCheck(tamasyaReservationInventoryBlockers([$stay,$b])===[$b],'Exceptional or unbound key still blocks inventory');}
mrCheck(tamasyaReservationInventoryBlockers([$key])===[$key],'Issued orphan key still blocks inventory');
foreach(['maintenance','operational_hold','night_audit','smart_lock','lost_found'] as $type){$b=['type'=>$type];mrCheck(tamasyaReservationInventoryBlockers([$stay,$key,$b])===[$b],'Unresolved domain remains blocked: '.$type);}
$b=['id'=>'guest-now','status'=>'active','checkIn'=>'2026-10-08','checkOut'=>'2026-10-09','stayMode'=>'overnight','isOpenEnded'=>0];
mrCheck(tamasyaR3StayWindowConflictInRows([$b],'2026-10-09 14:00:00','2026-10-10 12:00:00','12:00:00','14:00:00')===null,'Future stay after occupied checkout does not conflict');
$c=tamasyaR3StayWindowConflictInRows([$b],'2026-10-08 14:00:00','2026-10-09 12:00:00','12:00:00','14:00:00');mrCheck($c['id']==='guest-now'&&$c['_resolvedEndAt']==='2026-10-09 12:00:00','True overlap resolves the canonical stored window');
$b['isOpenEnded']=1;mrCheck(tamasyaR3StayWindowConflictInRows([$b],'2026-10-12 14:00:00','2026-10-13 12:00:00','12:00:00','14:00:00')!==null,'Open-ended current guest does not invent future checkout');
$read=substr($src,strpos($src,'function tamasyaMultiRoomAvailability'),strpos($src,'function tamasyaMultiRoomSplitCents')-strpos($src,'function tamasyaMultiRoomAvailability'));
$loop=substr($read,strpos($read,'foreach($rooms as $room)'));
mrCheck(!str_contains($loop,'$pdo->')&&!str_contains($loop,'resolveConfiguredTaxRate(')&&str_contains($loop,'tamasyaR3StayWindowConflictInRows('),'Bulk availability uses shared windows with no per-room queries');
echo "Multi-room and lossless storage unit assertions: $n passed.\n";
