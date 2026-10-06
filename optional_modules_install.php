<?php
declare(strict_types=1);
/**
 * TAMASYA V137 optional-module installer.
 *
 * CLI dry run:
 *   php optional_modules_install.php [--target=growth|enterprise|all]
 * CLI apply:
 *   php optional_modules_install.php --apply --target=all --backup-confirmed=<reference>
 *
 * Browser:
 *   enable protected admin web tools in .env, then open optional_modules_install.php.
 *
 * Safety:
 * - browser is OFF by default and protected by ADMIN_WEB_TOOLS_SECRET;
 * - apply requires explicit backup reference and confirmation;
 * - only additive V137 Growth/Enterprise migrations are executed;
 * - no ALTER/DROP/TRUNCATE of core hotel tables.
 */
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/admin_web_tool_gate.php';

$isCli=tamasyaAdminToolIsCli();
$apply=false;$target='all';$backup='';
if($isCli){
    $apply=in_array('--apply',$argv,true);
    foreach($argv as $arg){
        if(str_starts_with($arg,'--target='))$target=strtolower(trim(substr($arg,9)));
        if(str_starts_with($arg,'--backup-confirmed='))$backup=trim(substr($arg,19));
    }
    if(in_array('--help',$argv,true)||in_array('-h',$argv,true)){
        fwrite(STDOUT,"Dry run: php optional_modules_install.php [--target=growth|enterprise|all]\nApply: php optional_modules_install.php --apply --target=all --backup-confirmed=<reference>\n");exit(0);
    }
}else{
    tamasyaAdminToolBeginWeb('TAMASYA Optional Modules');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        $fields='<label for="target">Target</label><select id="target" name="target"><option value="all">Growth + Enterprise</option><option value="growth">Growth</option><option value="enterprise">Enterprise</option></select>'
            .'<label><input type="checkbox" name="apply" value="1"> Apply migration (jika tidak dicentang = dry run)</label>'
            .'<label for="backup">Referensi backup (wajib saat Apply)</label><input id="backup" name="backup_confirmed" type="text" maxlength="255" placeholder="contoh: backup_20260810_1655.sql">'
            .'<label><input type="checkbox" name="confirm" value="APPLY_OPTIONAL_MODULES"> Saya memahami Apply akan membuat tabel modul opsional.</label>';
        tamasyaAdminToolForm('TAMASYA Optional Modules','Dry run aman untuk pemeriksaan. Apply hanya setelah backup terverifikasi dan maintenance window.',$fields,'Jalankan');
    }
    tamasyaAdminToolAuthorizeWeb();
    $target=strtolower(trim((string)($_POST['target']??'all')));
    $apply=(string)($_POST['apply']??'')==='1';
    $backup=trim((string)($_POST['backup_confirmed']??''));
    if($apply && (string)($_POST['confirm']??'')!=='APPLY_OPTIONAL_MODULES'){
        tamasyaAdminToolEmit(['success'=>false,'error'=>'Konfirmasi APPLY_OPTIONAL_MODULES wajib untuk Apply.'],400,true);exit(2);
    }
}

if(!in_array($target,['growth','enterprise','all'],true)){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'INVALID_TARGET','message'=>'Target harus growth, enterprise, atau all.'],$isCli?200:400,true);exit(2);
}
if($apply&&$backup===''){
    tamasyaAdminToolEmit(['success'=>false,'error'=>'BACKUP_CONFIRMATION_REQUIRED','message'=>'Referensi backup wajib untuk mode Apply.'],$isCli?200:400,true);exit(2);
}

$config=tamasyaResolveDatabaseConfig(__DIR__);$migrationUser=trim((string)(getenv('DB_MIGRATION_USER')?:''));
if($migrationUser!==''){$config=['host'=>trim((string)(getenv('DB_MIGRATION_HOST')?:$config['host'])),'port'=>(int)(getenv('DB_MIGRATION_PORT')?:$config['port']),'name'=>trim((string)(getenv('DB_MIGRATION_NAME')?:$config['name'])),'user'=>$migrationUser,'pass'=>(string)(getenv('DB_MIGRATION_PASSWORD')?:''),'source'=>'environment:DB_MIGRATION_*','sourceType'=>'migration-environment','detected'=>true,'driverAvailable'=>in_array('mysql',PDO::getAvailableDrivers(),true)];}
[$pdo,$connectionError,$stage]=tamasyaConnectDatabase($config);
if(!$pdo instanceof PDO){tamasyaAdminToolEmit(['success'=>false,'stage'=>$stage,'error'=>$connectionError?:'Database tidak dapat dihubungkan.'],$isCli?200:503,true);exit(2);}

$tableExists=static function(PDO $db,string $table): bool{$s=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);return (int)$s->fetchColumn()===1;};
$tableCount=static function(PDO $db,array $tables) use($tableExists): int{$n=0;foreach($tables as $t)if($tableExists($db,$t))$n++;return $n;};
$splitSql=static function(string $file): array{if(!is_file($file))throw new RuntimeException('Migration tidak ditemukan: '.$file);$sql=(string)file_get_contents($file);$sql=preg_replace('/^\s*--.*$/m','',$sql)??$sql;return array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[]),static fn($x)=>$x!==''));};
$growthTables=['growth_rate_plans','growth_rate_rules','growth_rate_overrides','growth_companies','growth_group_reservations','growth_group_booking_links','growth_vendors','growth_purchase_orders','growth_purchase_order_items','growth_purchase_order_payments','growth_channel_mappings','growth_channel_events','growth_payment_intents','growth_payment_events'];
$enterpriseTables=['growth_folios','growth_folio_transaction_allocations','growth_folio_charge_allocations','growth_folio_routing_rules','growth_folio_invoices','growth_purchase_requests','growth_purchase_request_items','growth_purchase_request_po_links','growth_goods_receipts','growth_goods_receipt_items','growth_supplier_invoices','growth_supplier_invoice_lines','growth_supplier_invoice_payments','growth_guest_booking_links','growth_loyalty_accounts','growth_loyalty_ledger','growth_loyalty_vouchers','growth_guest_consents','growth_crm_segments','growth_crm_campaigns','growth_crm_campaign_recipients','growth_health_alert_rules','growth_provider_adapters','growth_internal_memos'];
$growthFile=__DIR__.'/migrations/V137_OPTIONAL_GROWTH_SUITE.sql';$enterpriseFile=__DIR__.'/migrations/V137_OPTIONAL_ENTERPRISE_COMPLETION.sql';
try{
    $safety=tamasyaAssertDatabaseSafety($pdo,$config);
    tamasyaDatabasePropertyIdentity($pdo,true);
    $report=['success'=>true,'mode'=>$apply?'apply':'dry-run','target'=>$target,'database'=>$safety['actual'],'databaseSafetyLocked'=>$safety['locked'],'backupReference'=>$backup?:null,'schemaRelease'=>'V137_FRESH_CANONICAL_MULTI_HOTEL','growth'=>['expectedTables'=>count($growthTables),'presentTables'=>$tableCount($pdo,$growthTables),'migrationSha256'=>hash_file('sha256',$growthFile)],'enterprise'=>['expectedTables'=>count($enterpriseTables),'presentTables'=>$tableCount($pdo,$enterpriseTables),'migrationSha256'=>hash_file('sha256',$enterpriseFile)],'warning'=>'DDL MySQL melakukan implicit commit. Migrasi hanya CREATE TABLE IF NOT EXISTS/additive; bila terhenti, feature flag tetap OFF dan runner aman diulang setelah penyebab diperbaiki.'];
    if(!$apply){tamasyaAdminToolEmit($report);exit(0);}
    $lockName='tamasya_optional_modules_install';$q=$pdo->prepare('SELECT GET_LOCK(?,30)');$q->execute([$lockName]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Tidak dapat memperoleh optional-module migration lock.');
    try{
        $applyFile=static function(PDO $db,string $file,array $tables,string $label) use($splitSql,$tableCount): array{
            foreach($splitSql($file) as $statement)$db->exec($statement);
            $present=$tableCount($db,$tables);if($present!==count($tables))throw new RuntimeException($label.' schema tidak lengkap setelah apply: '.$present.'/'.count($tables));
            return ['presentTables'=>$present,'expectedTables'=>count($tables),'sha256'=>hash_file('sha256',$file)];
        };
        $applied=[];
        if(in_array($target,['growth','all'],true))$applied['growth']=$applyFile($pdo,$growthFile,$growthTables,'Growth Suite');
        if(in_array($target,['enterprise','all'],true)){
            if($tableCount($pdo,$growthTables)!==count($growthTables))throw new RuntimeException('Enterprise Completion memerlukan 14 tabel Growth Suite lengkap lebih dahulu. Gunakan target all atau pasang Growth terlebih dahulu.');
            $applied['enterprise']=$applyFile($pdo,$enterpriseFile,$enterpriseTables,'Enterprise Completion');
            if($tableExists($pdo,'schema_migrations'))$pdo->prepare("INSERT INTO schema_migrations(version,description,applied_at) VALUES ('2026.08.10.v137-optional-enterprise-completion.1','Optional additive Enterprise Completion: advanced folio, procurement/AP, CRM/loyalty and health controls',CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
        }
        $report['applied']=$applied;$report['growth']['presentTables']=$tableCount($pdo,$growthTables);$report['enterprise']['presentTables']=$tableCount($pdo,$enterpriseTables);$report['message']='Optional module migration selesai. JANGAN aktifkan feature flag sampai schema pada Primary/Standby sama dan UAT dilakukan.';
        tamasyaAdminToolEmit($report);
    }finally{try{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}catch(Throwable $ignored){}}
}catch(Throwable $e){tamasyaAdminToolEmit(['success'=>false,'mode'=>$apply?'apply':'dry-run','target'=>$target,'error'=>$e->getMessage()],$isCli?200:500,true);exit(1);}
