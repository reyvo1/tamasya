<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/hq/core.php';
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/setup_admin/108_hybrid_architecture.php';
$passed=0;
function checkHybrid(bool $ok,string $name): void { global $passed; if (!$ok) throw new RuntimeException('FAIL '.$name); $passed++; echo 'PASS '.$name."\n"; }
function rejectHybrid(callable $fn,string $name): void { try { $fn(); } catch (InvalidArgumentException|TamasyaHqError $e) { checkHybrid(true,$name); return; } checkHybrid(false,$name); }
$s=['contractVersion'=>'tamasya-hq-snapshot-v2','companyId'=>'group-a','propertyId'=>'hotel-a','propertyName'=>'Hotel A','currency'=>'IDR','timezone'=>'Asia/Makassar','period'=>['from'=>'2026-09-01','to'=>'2026-09-15'],'sourceRevision'=>1,'metricsMinor'=>array_fill_keys(tamasyaHybridMetricNames(),'0'),'roomNights'=>['sold'=>2,'available'=>10],'integrity'=>['status'=>'PASS','unresolvedTaxCount'=>0,'pendingHistoricalCount'=>0,'openSyncConflicts'=>0]];
$s['metricsMinor']=array_merge($s['metricsMinor'],['revenue'=>'10000','expense'=>'1000','profit'=>'9000','cashMovement'=>'5000','bankMovement'=>'-1000','liquidMovement'=>'4000','journalDebit'=>'10000','journalCredit'=>'10000','roomRevenueEstimate'=>'8000']);
$s['checksumSha256']=tamasyaHybridChecksum($s); tamasyaHybridValidate($s); checkHybrid(true,'Valid strict snapshot');
$reordered=array_reverse($s,true); checkHybrid(tamasyaHybridChecksum($s)===tamasyaHybridChecksum($reordered),'Object key order does not change checksum');
foreach (['profit'=>'9999','liquidMovement'=>'1','journalDebit'=>'9999','revenue'=>1.5,'expense'=>'1e3','taxAccrued'=>'9000000000000001'] as $key=>$value) {
    $bad=$s; $bad['metricsMinor'][$key]=$value; $bad['checksumSha256']=tamasyaHybridChecksum($bad);
    rejectHybrid(fn()=>tamasyaHybridValidate($bad),'Reject invalid money/invariant '.$key);
}
foreach (['unresolvedTaxCount','pendingHistoricalCount','openSyncConflicts'] as $key) {
    $bad=$s; $bad['integrity'][$key]=1; $bad['checksumSha256']=tamasyaHybridChecksum($bad);
    rejectHybrid(fn()=>tamasyaHybridValidate($bad),'Reject false PASS '.$key);
}
foreach ([['2026-02-29','2026-03-01'],['2026-01-01','2027-02-05'],['2026-2-01','2026-03-01']] as [$from,$to]) rejectHybrid(fn()=>tamasyaHybridRange($from,$to),'Reject calendar or over-limit range '.$from);
tamasyaHybridRange('2028-02-29','2028-02-29'); checkHybrid(true,'Leap day accepted');
$report=tamasyaHybridConsolidate([$s],['hotel-a'],'group-a','2026-09-01','2026-09-15');
checkHybrid($report['currencies']['IDR']['occupancyPct']===20.0 && $report['currencies']['IDR']['metricsMinor']['profit']==='9000','Exact consolidated money and weighted occupancy');
rejectHybrid(fn()=>tamasyaHybridConsolidate([$s,$s],['hotel-a'],'group-a','2026-09-01','2026-09-15'),'Duplicate property is rejected');
rejectHybrid(fn()=>tamasyaHybridConsolidate([$s],['hotel-a'],'other-company','2026-09-01','2026-09-15'),'Foreign company is rejected');
rejectHybrid(fn()=>tamasyaHybridConsolidate([$s],['hotel-b'],'group-a','2026-09-01','2026-09-15'),'Foreign property is rejected');
rejectHybrid(fn()=>tamasyaHybridConsolidate([$s],['hotel-a'],'group-a','2026-09-01','2026-09-14'),'Mixed periods rejected');
$missing=tamasyaHybridConsolidate([],['hotel-a'],'group-a','2026-09-01','2026-09-15'); checkHybrid($missing['integrityStatus']==='INCOMPLETE' && $missing['currencies']===[],'Absent snapshot is not a zero balance');
$token=str_repeat('a',40); $config=['properties'=>['group-a'=>['hotel-a'=>['enabled'=>true]]],'viewers'=>[['enabled'=>true,'companyId'=>'group-a','propertyIds'=>['hotel-a'],'tokenSha256'=>hash('sha256',$token)]]];
checkHybrid(tamasyaHqViewer($config,'Bearer '.$token)['companyId']==='group-a','Scoped viewer grant');
foreach ([false,'true',1,null] as $value) { $bad=$config; $bad['viewers'][0]['enabled']=$value; rejectHybrid(fn()=>tamasyaHqViewer($bad,'Bearer '.$token),'Viewer enable flag fails closed '.json_encode($value)); }
foreach ([null,'*',[],['hotel-b'],['*']] as $value) { $bad=$config; $bad['viewers'][0]['propertyIds']=$value; rejectHybrid(fn()=>tamasyaHqViewer($bad,'Bearer '.$token),'Malformed or foreign grant fails closed '.json_encode($value)); }
tamasyaHybridAssertRequestScope(['companyId'=>'group-a','propertyId'=>'hotel-a'],[]); checkHybrid(true,'Legacy request uses server-derived property scope');
rejectHybrid(fn()=>tamasyaHybridAssertRequestScope(['companyId'=>'group-a','propertyId'=>'hotel-a'],['HTTP_X_TAMASYA_PROPERTY_ID'=>'hotel-b']),'Explicit property mismatch fails closed');
echo "HYBRID UNIT PASSED $passed\n";
