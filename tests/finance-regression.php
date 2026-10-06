<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
$root=$argv[1]??dirname(__DIR__);
require $root.'/canonical_report_support.php';
$pass=0;$fail=0;
function verify($ok,$name){global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$name."\n";}
foreach(['2026-02-30','2026-13-01','2026-00-10','2025-02-29'] as $date){
 try{tamasyaCanonicalReportValidateRange($date,'2027-01-01');verify(false,'Invalid calendar date '.$date);}catch(InvalidArgumentException $e){verify(true,'Invalid calendar date '.$date);}
}
try{tamasyaCanonicalReportValidateRange('2024-02-29','2024-03-01');verify(true,'Leap day accepted');}catch(Throwable $e){verify(false,'Leap day accepted');}
$row=tamasyaCanonicalReportProjectTransaction(['id'=>'unknown','type'=>'income','amount'=>110000,'taxSnapshotStatus'=>'unresolved','taxAmount'=>null,'baseAmount'=>null,'taxRate'=>null]);
verify($row['Pajak']===null&&$row['DPP']===null&&$row['Tarif Pajak']===null,'Unknown report tax is not zero');
$row=tamasyaCanonicalReportProjectTransaction(['id'=>'exempt','type'=>'income','amount'=>110000,'taxSnapshotStatus'=>'confirmed','taxAmount'=>0,'baseAmount'=>110000,'taxRate'=>0]);
verify($row['Pajak']===0.0&&$row['DPP']===110000.0,'Confirmed zero tax stays distinct');
$row=tamasyaCanonicalReportProjectTransaction(['id'=>'forfeit','type'=>'income','amount'=>110000,'transactionKind'=>'security_deposit_forfeit']);
verify($row['_cashSigned']===0.0&&$row['_bankSigned']===0.0,'Deposit forfeiture creates no new receipt in official reports');
$row=tamasyaCanonicalReportProjectTransaction(['id'=>'split-technical','type'=>'income','amount'=>110000,'isSplitPayment'=>1,'splitCashAmount'=>10000,'splitTransferAmount'=>100000,'splitTransferBankAccountId'=>'ota_receivable']);
verify($row['_cashSigned']===10000.0&&$row['_bankSigned']===0.0,'Technical receivable is not a bank balance in split reports');
final class ReportReadFixture extends PDO {
 public function __construct() {}
 public function prepare(string $query,array $options=[]): PDOStatement|false {return new ReportReadStatement();}
}
final class ReportReadStatement extends PDOStatement {
 public function __construct() {}
 public function execute(?array $params=null): bool {return true;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return [];}
 public function fetchColumn(int $column=0): mixed {return 0;}
}
try{
 $ownerSnapshot=tamasyaCanonicalReportSnapshot(new ReportReadFixture(),['role'=>'owner','name'=>'Owner read fixture'],'tax_ledger','2026-01-01','2026-01-31');
 verify($ownerSnapshot['success']===true&&$ownerSnapshot['meta']['role']==='owner','Owner can actually generate a read-only official report snapshot');
}catch(Throwable $e){verify(false,'Owner report snapshot: '.$e->getMessage());}
try{
 tamasyaCanonicalReportSnapshot(new ReportReadFixture(),['role'=>'receptionist'],'tax_ledger','2026-01-01','2026-01-31');
 verify(false,'Other restricted report roles remain denied');
}catch(RuntimeException $e){verify(true,'Other restricted report roles remain denied');}
$snapshot=['meta'=>['reportId'=>'SIM','reportLabel'=>'Test','from'=>'2026-01-01','to'=>'2026-01-01','integrityStatus'=>'PASS','checksumSha256'=>str_repeat('a',64)],'summary'=>['Amount'=>123],'sheets'=>['Rows'=>[['Description'=>'Quoted "receipt", path C:\\receipts','Amount'=>123]]]];
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
try{
 $csv=tamasyaCanonicalReportCsv($snapshot);verify(str_starts_with($csv,"\xEF\xBB\xBF"),'CSV UTF-8 BOM');
 $fh=fopen('php://temp','w+');fwrite($fh,$csv);rewind($fh);$found=false;
 while(($r=fgetcsv($fh,0,',','"',''))!==false){if(($r[0]??'')===$snapshot['sheets']['Rows'][0]['Description'])$found=($r[1]??'')==='123';}fclose($fh);
 verify($found,'CSV quotes and backslashes round-trip under strict PHP errors');
}catch(Throwable $e){verify(false,'CSV under strict errors: '.$e->getMessage());}finally{restore_error_handler();}
echo "$pass passed; $fail failed\n";exit($fail?1:0);
