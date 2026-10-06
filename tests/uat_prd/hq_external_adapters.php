<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
define('TAMASYA_CONTROL_CONFIG_RAW',true);
require __DIR__.'/runtime-source-root.php';
$appRoot=tamasyaUatResolveApplicationRoot();
require $appRoot.'/hq/delivery.php';
require $appRoot.'/hq/object_storage.php';

$passed=0;
function pass(bool $ok,string $name,$detail=null):void{global $passed;if(!$ok){fwrite(STDERR,"FAIL $name ".json_encode($detail,JSON_UNESCAPED_SLASHES)."\n");exit(1);} $passed++;echo "PASS $name\n";}
$config=tamasyaHqConfig();$pdo=tamasyaHqPdo($config);
$viewer=['enabled'=>true,'companyId'=>'group-a','propertyIds'=>['hotel-a'],'role'=>'finance'];
$from='2026-10-01';$to='2026-10-02';
$s=['contractVersion'=>'tamasya-hq-snapshot-v2','companyId'=>'group-a','propertyId'=>'hotel-a','propertyName'=>'Hotel A','currency'=>'IDR','timezone'=>'Asia/Makassar','period'=>['from'=>$from,'to'=>$to],'sourceRevision'=>7,'metricsMinor'=>array_fill_keys(tamasyaHybridMetricNames(),'0'),'roomNights'=>['sold'=>2,'available'=>10],'integrity'=>['status'=>'PASS','unresolvedTaxCount'=>0,'pendingHistoricalCount'=>0,'openSyncConflicts'=>0]];
$s['metricsMinor']=array_merge($s['metricsMinor'],['revenue'=>'10000','expense'=>'1000','profit'=>'9000','cashMovement'=>'5000','bankMovement'=>'-1000','liquidMovement'=>'4000','journalDebit'=>'10000','journalCredit'=>'10000','roomRevenueEstimate'=>'8000']);
$s['checksumSha256']=tamasyaHybridChecksum($s);tamasyaHybridValidate($s);
$report=tamasyaHybridConsolidate([$s],['hotel-a'],'group-a',$from,$to);$data=['report'=>$report,'receivedAt'=>['hotel-a'=>'2026-10-02T00:00:00Z']];$reportId=$report['checksumSha256'];
$pdo->prepare('INSERT INTO hq_reports(checksum,company_id,body) VALUES(?,?,?) ON DUPLICATE KEY UPDATE body=VALUES(body)')->execute([$reportId,'group-a',tamasyaHybridJson($data)]);
pass(tamasyaHqStoredReport($pdo,$viewer,$reportId)['report']['checksumSha256']===$reportId,'stored report checksum remains canonical');

$queued=tamasyaHqQueueDelivery($pdo,$config,$viewer,['operationId'=>'prd-r2-delivery-ok','destinationId'=>'ok','reportId'=>$reportId]);
pass(($queued['status']??'')==='pending','delivery success job queued',$queued);
$sent=tamasyaHqDeliverOnce($pdo,$config);
pass(($sent['status']??'')==='delivered'&&($sent['code']??null)===null,'HTTPS delivery bridge exact ACK accepted through configured private CA',$sent);

$queued=tamasyaHqQueueDelivery($pdo,$config,$viewer,['operationId'=>'prd-r2-delivery-202','destinationId'=>'accepted','reportId'=>$reportId]);
$uncertain=tamasyaHqDeliverOnce($pdo,$config);
pass(($uncertain['status']??'')==='uncertain'&&($uncertain['code']??'')==='HTTP_202_NOT_FINAL','HTTP 202 remains uncertain, never success',$uncertain);
$idle=tamasyaHqDeliverOnce($pdo,$config);
$q=$pdo->prepare('SELECT status,attempts FROM hq_delivery_jobs WHERE job_id=?');$q->execute([$uncertain['jobId']]);$row=$q->fetch();
pass(($idle['status']??'')==='idle'&&($row['status']??'')==='uncertain'&&(int)($row['attempts']??0)===1,'uncertain delivery is not auto-retried',$row);

$queued=tamasyaHqQueueDelivery($pdo,$config,$viewer,['operationId'=>'prd-r2-delivery-wrong','destinationId'=>'wrong','reportId'=>$reportId]);
$wrong=tamasyaHqDeliverOnce($pdo,$config);
pass(($wrong['status']??'')==='uncertain'&&($wrong['code']??'')==='ACK_JOB_MISMATCH','wrong delivery ACK is rejected with exact reason',$wrong);

$queued=tamasyaHqQueueDelivery($pdo,$config,$viewer,['operationId'=>'prd-r10-delivery-badca','destinationId'=>'badca','reportId'=>$reportId]);
$badCa=tamasyaHqDeliverOnce($pdo,$config);
pass(($badCa['status']??'')==='blocked'&&($badCa['code']??'')==='TLS_CA_UNREADABLE','missing private CA fails closed before network send',$badCa);

$archive=tamasyaHqArchiveReport($pdo,$config,$viewer,$reportId);
pass(($archive['status']??'')==='verified'&&($archive['duplicate']??true)===false&&str_starts_with((string)$archive['objectKey'],'group-a/reports/'),'immutable object archive PUT+GET verified',$archive);
$again=tamasyaHqArchiveReport($pdo,$config,$viewer,$reportId);
pass(($again['status']??'')==='verified'&&($again['duplicate']??false)===true&&$again['objectSha256']===$archive['objectSha256'],'duplicate object archive is idempotent via 412',$again);

echo "HQ EXTERNAL ADAPTERS PASSED $passed\n";
