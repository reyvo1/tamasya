<?php
// Source/unit tests only: no hotel server, production DB, or Telegram delivery.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_API_ENTRY',true);
require dirname(__DIR__).'/api/modules/finance/030_booking_finance.php';
require dirname(__DIR__).'/api/modules/hr_staff/020_identity_access_audit.php';
require dirname(__DIR__).'/api/support/060_shift_receipts.php';
$passed=0;
function shiftCheck(bool $ok,string $name):void{global $passed;if(!$ok)throw new RuntimeException($name);$passed++;echo "PASS $name\n";}
function shiftReject(callable $fn,string $name):void{$ok=false;try{$fn();}catch(RuntimeException|InvalidArgumentException $e){$ok=true;}shiftCheck($ok,$name);}
$rows=[
 ['type'=>'income','amount'=>100000.30],
 ['type'=>'income','amount'=>200000,'bankAccountId'=>'qris'],
 ['type'=>'income','amount'=>300000,'bankAccountId'=>'bank'],
 ['type'=>'income','amount'=>150000,'isSplitPayment'=>1,'splitCashAmount'=>50000,'splitTransferAmount'=>100000,'splitTransferBankAccountId'=>'bank'],
 ['type'=>'expense','amount'=>40000,'isSplitPayment'=>1,'splitCashAmount'=>10000,'splitTransferAmount'=>30000,'splitTransferBankAccountId'=>'bank'],
 ['type'=>'expense','amount'=>20000,'bankAccountId'=>'none'],
 ['type'=>'expense','amount'=>60000,'bankAccountId'=>'bank'],
 ['type'=>'income','amount'=>10000,'transactionKind'=>'internal_transfer'],
 ['type'=>'income','amount'=>12000,'transactionKind'=>'internal_transfer','bankAccountId'=>'bank'],
 ['type'=>'income','amount'=>45000,'bankAccountId'=>'ota_receivable'],
 ['type'=>'expense','amount'=>65000,'bankAccountId'=>'accounts_payable'],
 ['type'=>'income','amount'=>70000,'transactionKind'=>'security_deposit_forfeit'],
 ['type'=>'income','amount'=>75000,'recordOrigin'=>'historical_import'],
 ['type'=>'income','amount'=>80000,'shiftExempt'=>1],
];
$totals=tamasyaShiftSettlementTotals($rows);
shiftCheck($totals['cashInc']===160000.30,'Physical receipts include only cash legs and internal cash movements');
shiftCheck($totals['cashExp']===30000.0,'Physical expense excludes bank expense and uses split cash leg');
shiftCheck($totals['digitalInc']===600000.0,'QRIS, transfer and split digital leg remain outside the drawer');
shiftCheck(round(50000.30+$totals['cashInc']-$totals['cashExp'],2)===180000.60,'Opening cash plus actual cash movements preserves cents');
foreach(['ota_receivable','inventory_asset','guest_receivable','accounts_payable'] as $account){
 $t=tamasyaShiftSettlementTotals([['type'=>'income','amount'=>10000,'bankAccountId'=>$account],['type'=>'expense','amount'=>10000,'bankAccountId'=>$account]]);
 shiftCheck($t['cashInc']===0.0&&$t['cashExp']===0.0&&$t['digitalInc']===0.0,'Non-liquid account never becomes drawer cash: '.$account);
}
foreach(['receptionist','finance'] as $role){
 foreach([-5000.30,5000.30] as $variance){
  $p=tamasyaShiftVariancePolicy(['role'=>$role],$variance,0,'Selisih nyata saat menghitung laci');
  shiftCheck($p['needsReview']&&$p['overrideReason']===null,'Short/over declaration requires manager review: '.$role.'/'.$variance);
 }
 shiftReject(fn()=>tamasyaShiftVariancePolicy(['role'=>$role],-1,100,'','Forged manager override'),'Cannot bypass discrepancy note using forged override: '.$role);
}
shiftReject(fn()=>tamasyaShiftVariancePolicy(['role'=>'receptionist'],0.30,10,''),'Even an in-tolerance discrepancy requires its explanation');
shiftCheck(!tamasyaShiftVariancePolicy(['role'=>'receptionist'],0.30,10,'Kembalian lebih')['needsReview'],'In-tolerance explained difference is recorded without manager review');
shiftCheck(!tamasyaShiftVariancePolicy(['role'=>'receptionist'],0,0,'')['needsReview'],'Exact reconciliation allows no-notes closure');
foreach(['admin','manager'] as $role){
 shiftReject(fn()=>tamasyaShiftVariancePolicy(['role'=>$role],-5000,0,'Penjelasan petugas'),'Manager discrepancy still requires explicit override reason: '.$role);
 $p=tamasyaShiftVariancePolicy(['role'=>$role],5000,0,'','Hitung ulang telah diperiksa');
 shiftCheck(!$p['needsReview']&&$p['overrideReason']==='Hitung ulang telah diperiksa','Manager records override without fabricating a cash adjustment: '.$role);
}
foreach(['owner','cleaning_service','keamanan'] as $role)shiftReject(fn()=>tamasyaShiftVariancePolicy(['role'=>$role],0,0,''),'Unauthorized role cannot close drawer: '.$role);
shiftReject(fn()=>tamasyaShiftVariancePolicy(['role'=>'admin'],NAN,0,'Audit kas','Audit kas'),'Nonfinite difference is rejected');
final class ShiftReadFixture extends PDO {
 public function __construct(public array $shift,public array $rows,public bool $broken=false){}
 public function prepare(string $sql,array $options=[]):PDOStatement|false{return new ShiftReadStatement($this,$sql);}
}
final class ShiftReadStatement extends PDOStatement {
 public function __construct(private ShiftReadFixture $db,private string $sql){}
 public function execute(?array $params=null):bool{if($this->db->broken&&str_contains($this->sql,'FROM transactions'))throw new RuntimeException('DB read failed');return true;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->db->shift;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->db->rows;}
}
$session=['id'=>'night','staff_id'=>'front','shift_date'=>'2026-10-06','shift_time'=>'malam','opening_cash'=>50000.30];
$db=new ShiftReadFixture($session,$rows);
$f=getShiftFinancials($db,['id'=>'front'],'malam','2026-10-06','none',true,'night');
shiftCheck($f['expectedCash']===180000.60&&$f['digitalInc']===600000.0,'Exact night session includes all assigned legs across midnight');
shiftReject(fn()=>getShiftFinancials($db,['id'=>'front'],'pagi','2026-10-07','none',true,'night'),'Mismatched explicit session never falls back to all transactions for a date');
shiftReject(fn()=>getShiftFinancials(new ShiftReadFixture($session,$rows,true),['id'=>'front'],'malam','2026-10-06','none',true,'night'),'Database failure cannot be reported as zero cash');
echo "$passed passed; 0 failed\n";
