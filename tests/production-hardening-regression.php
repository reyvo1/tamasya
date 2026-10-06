<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
error_reporting(E_ALL);
set_error_handler(static function(int $level,string $message,string $file,int $line): bool {
    if(!(error_reporting()&$level))return false;
    throw new ErrorException($message,0,$level,$file,$line);
});
define('TAMASYA_API_ENTRY',true);
$root=dirname(__DIR__);
require $root.'/api/modules/finance/030_booking_finance.php';
require $root.'/api/modules/finance/110_daily_summary.php';
require $root.'/database_tls.php';
require $root.'/growth_activation_profile.php';
require $root.'/node_sync_support.php';
require $root.'/api/modules/growth_enterprise_locked/105_growth_suite.php';
require $root.'/api/modules/growth_enterprise_locked/107_enterprise_completion.php';
require $root.'/api/modules/comms/050_integrations_telegram_mail.php';
$action='categories';require $root.'/api/routes/070_config_telegram_admin.php';
$passed=0;
function hardeningCheck(bool $ok,string $name): void {
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL '.$name);
    $passed++;echo 'PASS '.$name."\n";
}
$receipt=['id'=>'sale','type'=>'income','amount'=>110000,'baseAmount'=>100000,'taxAmount'=>10000,'taxSnapshotStatus'=>'confirmed','categorySystemKey'=>'room_rental'];
$rows=[
    $receipt,
    array_replace($receipt,['id'=>'refund','type'=>'expense','transactionKind'=>'refund','amount'=>55000,'baseAmount'=>50000,'taxAmount'=>5000]),
    array_replace($receipt,['id'=>'unknown','amount'=>250000,'baseAmount'=>null,'taxAmount'=>null,'taxSnapshotStatus'=>'unresolved']),
    ['type'=>'expense','amount'=>30000,'taxSnapshotStatus'=>'not_applicable'],
    ['type'=>'expense','amount'=>10000,'transactionKind'=>'pbjt_payment','taxSnapshotStatus'=>'not_applicable'],
    ['type'=>'income','amount'=>1000000,'transactionKind'=>'opening_balance_cash','taxSnapshotStatus'=>'not_applicable'],
    ['type'=>'income','amount'=>100000,'transactionKind'=>'internal_transfer','taxSnapshotStatus'=>'not_applicable'],
    array_replace($receipt,['id'=>'ota','amount'=>220000,'baseAmount'=>200000,'taxAmount'=>20000,'bankAccountId'=>'ota_receivable']),
];
$summary=tamasyaDailySummaryFinancial($rows);
hardeningCheck($summary['income']===360000.0&&$summary['expense']===95000.0&&$summary['net']===265000.0,'Daily money includes unknown receipts, refunds and paid tax; excludes opening/internal/receivable');
hardeningCheck($summary['pbjt']===25000.0,'Daily PBJT subtracts revenue refund');
hardeningCheck($summary['recognized_income']===250000.0&&$summary['recognized_expense']===30000.0,'Recognized revenue stays distinct from gross money');
hardeningCheck($summary['tax_unresolved_count']===1&&$summary['tax_unresolved_receipts']===250000.0,'Unknown tax is explicit without fabricating a snapshot');
$split=array_replace($receipt,['isSplitPayment'=>1,'splitCashAmount'=>40000,'splitTransferAmount'=>70000,'splitTransferBankAccountId'=>'qris']);
hardeningCheck(tamasyaDailySummaryFinancial([$split])['income']===110000.0,'Split payment counted once');
foreach(['2026-02-30','2026-13-01','not-a-date'] as $date){
    try{tamasyaCanonicalReportValidateRange($date,$date);$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
    hardeningCheck($rejected,'Invalid daily calendar date rejected '.$date);
}
$snapshot=['meta'=>['reportId'=>'test','reportLabel'=>'Report','from'=>'2026-01-01','to'=>'2026-01-31','integrityStatus'=>'PASS','checksumSha256'=>'fixture'],
    'summary'=>['Refund'=>-50000.0],
    'sheets'=>['Rows'=>[['Name'=>' =1+1','Description'=>"\t@SUM(1)",'Numeric'=>-50000.0,'Text'=>'-50000']]]];
$before=$snapshot;$csv=tamasyaCanonicalReportCsv($snapshot);
hardeningCheck(str_contains($csv,"' =1+1")&&str_contains($csv,"'\t@SUM(1)")&&str_contains($csv,"'-50000"),'CSV neutralizes user text including whitespace prefixes');
hardeningCheck(str_contains($csv,'Refund,-50000')&&$snapshot===$before,'CSV keeps numeric refunds and original audit snapshot');
$attr=tamasyaMysqlDriverAttribute('SSL_VERIFY_SERVER_CERT');
hardeningCheck($attr!==null||!in_array('mysql',PDO::getAvailableDrivers(),true),'MySQL TLS driver attribute resolves without deprecation');
if(in_array('mysql',PDO::getAvailableDrivers(),true)){
    $ca=tempnam(sys_get_temp_dir(),'tamasya-unit-ca-');file_put_contents($ca,'unit fixture');
    try{$options=tamasyaDatabaseTlsOptions(['required'=>true,'caFile'=>$ca]);hardeningCheck($options[$attr]===true,'Required database TLS keeps certificate verification');}finally{unlink($ca);}
}
final class HardeningCatalogPdo extends PDO {
    public int $queries=0;public int $prepares=0;
    public function __construct(){}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        $this->queries++;return new HardeningCatalogStmt(str_contains($query,'subcategories')?[['id'=>'sub','system_key'=>'suite']]:[['id'=>'room','system_key'=>'room_rental']]);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $this->prepares++;return new HardeningCatalogStmt(str_contains($query,'subcategories')?[['id'=>'sub','system_key'=>'suite']]:[['id'=>'room','system_key'=>'room_rental']]);
    }
}
final class HardeningCatalogStmt extends PDOStatement {
    public function __construct(private array $rows){}
    public function execute(?array $params=null): bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array{return $this->rows;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed{return $this->rows[0]??false;}
}
$pdo=new HardeningCatalogPdo();$catalog=tamasyaTransactionCatalogSnapshot($pdo);
for($i=0;$i<1000;$i++)$tx=tamasyaEnrichTransactionCatalogIdentity($pdo,['categoryId'=>'room','subcategoryId'=>'sub','categorySystemKey'=>'saved_key'],$catalog);
hardeningCheck($pdo->queries===2&&$pdo->prepares===0&&$tx['categorySystemKey']==='saved_key'&&$tx['subcategorySystemKey']==='suite','1000 ID-based transactions use two catalog reads and preserve saved identity');
for($i=0;$i<1000;$i++)$tx=tamasyaEnrichTransactionCatalogIdentity($pdo,['type'=>'income','category'=>'Legacy Room','subcategory'=>'Suite'],$catalog);
hardeningCheck($pdo->prepares===2&&$tx['categoryId']==='room','Repeated legacy names reuse database-collation lookup');
$map=tamasyaNodeSnapshotTableMap();
hardeningCheck(($map['growth_internal_memos']['pk']??[])===['id'],'Internal memos included in hybrid canonical snapshot');
hardeningCheck(in_array('growth_internal_memos',tamasyaEnterpriseSchemaTables(),true),'Enterprise readiness includes memo schema');
$original="DB_PASSWORD=private\nTAMASYA_NODE_ID=local-node\nTAMASYA_GROWTH_SUITE_ENABLED=0\nexport TAMASYA_GROWTH_SUITE_ENABLED=0\nNODE_CLUSTER_ENABLED=1\n";
$env=tamasyaGrowthProfileEnvironment($original);
hardeningCheck(substr_count($env,'TAMASYA_GROWTH_SUITE_ENABLED=')===1&&str_contains($env,'TAMASYA_GROWTH_SUITE_ENABLED=1')&&str_contains($env,'TAMASYA_ENTERPRISE_COMPLETION_ENABLED=1'),'Explicit activation replaces duplicate flags');
hardeningCheck(str_contains($env,'DB_PASSWORD=private')&&str_contains($env,'TAMASYA_NODE_ID=local-node')&&str_contains($env,'NODE_CLUSTER_ENABLED=1'),'Activation preserves node identity, credentials and fencing');
hardeningCheck(tamasyaGrowthActivationProfile()['TAMASYA_ENTERPRISE_PROVIDER_ADAPTERS_ENABLED']==='0'&&tamasyaGrowthActivationProfile()['TAMASYA_ENTERPRISE_CRM_CAMPAIGN_SEND_ENABLED']==='0','External live sending needs separate configuration');
echo "$passed passed; 0 failed (PHP ".PHP_VERSION.")\n";
