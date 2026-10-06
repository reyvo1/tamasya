<?php
declare(strict_types=1);

/**
 * TAMASYA V137 first-install bootstrap.
 *
 * CLI or protected browser. Creates exactly one first administrator on an EMPTY V137 fresh
 * canonical database. It never creates tables, changes schema, creates rooms,
 * tax rules, bank accounts, transactions, bookings, or demo data.
 *
 * Required environment:
 *   APP_BOOTSTRAP_ADMIN_PASSWORD (>=12 chars)
 *   TAMASYA_PROPERTY_NAME
 *   TAMASYA_PROPERTY_ID
 *   TAMASYA_PROPERTY_CODE
 *   TAMASYA_PROPERTY_CURRENCY
 *   TAMASYA_PROPERTY_COUNTRY
 *   APP_TIMEZONE (IANA)
 * Optional company affiliation (flexible property model):
 *   TAMASYA_COMPANY_ID          (same value for branches/properties in one company; empty for an unlinked/independent hotel)
 *   TAMASYA_PROPERTY_LOCALE     (default: id-ID)
 *   TAMASYA_INVOICE_PREFIX      (default: property code)
 * Optional:
 *   APP_BOOTSTRAP_ADMIN_USERNAME (default: admin)
 *   APP_BOOTSTRAP_ADMIN_NAME     (default: Administrator Utama)
 */
require_once __DIR__.'/release_contract.php';
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/baseline_support.php';
require_once __DIR__.'/admin_web_tool_gate.php';

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA First Install');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA First Install',
            'Membuat tepat satu Admin pertama dan identitas property dari .env pada database V137 fresh. Hanya jalankan pada database baru/kosong.',
            '<label><input type="checkbox" name="confirm" value="FIRST_INSTALL" required> Saya memahami bootstrap hanya boleh dijalankan sekali pada database fresh.</label>',
            'Jalankan First Install'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
    if((string)($_POST['confirm']??'')!=='FIRST_INSTALL'){
        tamasyaAdminToolEmit(['success'=>false,'error'=>'Konfirmasi FIRST_INSTALL wajib.'],400,true);exit;
    }
}

$config=tamasyaResolveDatabaseConfig(__DIR__);
[$pdo,$connectionError,$connectionStage]=tamasyaConnectDatabase($config);
if(!$pdo instanceof PDO){
    tamasyaAdminToolEmit(['success'=>false,'stage'=>$connectionStage,'error'=>$connectionError ?: 'Database tidak dapat dihubungkan.'],$isCli?200:503,true);
    exit(2);
}

try{
    $dbSafety=tamasyaAssertDatabaseSafety($pdo,$config);
    $db=(string)$dbSafety['actual'];
    if($db==='') throw new RuntimeException('Database belum dipilih.');

    // Verify the COMPLETE canonical core and all canonical triggers,
    // not merely a subset. Extra optional-module tables are allowed.
    $baseline=tamasyaAssertCanonicalBaseline($pdo,true);
    $releaseState=$baseline['releaseState']??null;
    if(!is_array($releaseState) || (string)($releaseState['current_release']??'')!==TAMASYA_SCHEMA_RELEASE){
        throw new RuntimeException('schema_release_state bukan V137 fresh canonical. Jangan bootstrap database legacy dengan script ini.');
    }

    // Fresh means business/operational tables are truly empty, not merely that
    // staff/property happen to be empty. The canonical SQL intentionally seeds
    // only these seven system/bootstrap tables. Separately, a small explicit
    // infrastructure/telemetry set may be populated by health/error/cluster
    // traffic before bootstrap; all other canonical tables remain fail-closed.
    $seedAllowed=['categories','config','hotel_operational_settings','node_sync_settings','schema_migrations','schema_release_state','subcategories'];
    // Infrastructure/telemetry may legitimately be populated before bootstrap by
    // API health/error requests or cluster heartbeat. These rows are not hotel
    // business data and must not make a pristine canonical DB impossible to bootstrap.
    $preBootstrapNoiseAllowed=['runtime_request_events','security_events','rate_limits','request_operation_receipts','data_integrity_issues','system_alerts','node_cluster_events','node_cluster_members','node_cluster_state','node_sync_nonces','node_sync_receipts','node_sync_runs'];
    $unexpectedPopulated=[];
    foreach((array)($baseline['expectedTables']??tamasyaCanonicalBaselineManifest()['tables']) as $table){
        if(in_array($table,$seedAllowed,true)||in_array($table,$preBootstrapNoiseAllowed,true))continue;
        $quoted='`'.str_replace('`','``',(string)$table).'`';
        $count=(int)$pdo->query('SELECT COUNT(*) FROM '.$quoted)->fetchColumn();
        if($count>0)$unexpectedPopulated[(string)$table]=$count;
        if(count($unexpectedPopulated)>=20)break;
    }
    if($unexpectedPopulated){
        $summary=implode(', ',array_map(static fn(string $table,int $count):string=>$table.'='.$count,array_keys($unexpectedPopulated),array_values($unexpectedPopulated)));
        throw new RuntimeException('Bootstrap dihentikan: database bukan fresh/kosong. Ditemukan data aplikasi/bisnis pada canonical table: '.$summary.'. Gunakan database baru atau jalur upgrade existing; jangan menjalankan first_install pada database yang pernah dipakai.');
    }

    $appEnv=strtolower(trim((string)(getenv('APP_ENV')?:'production')));
    $encryptionKeyRaw=trim((string)(getenv('APP_ENCRYPTION_KEY')?:''));
    if(in_array($appEnv,['staging','production','prod'],true) && $encryptionKeyRaw===''){
        throw new RuntimeException('APP_ENCRYPTION_KEY wajib diisi sebelum first_install pada staging/production. Generate key random 32-byte dan simpan hanya di ENV private.');
    }
    if(str_starts_with($encryptionKeyRaw,'base64:')){
        $decodedKey=base64_decode(substr($encryptionKeyRaw,7),true);
        if($decodedKey===false || strlen($decodedKey)<32)throw new RuntimeException('APP_ENCRYPTION_KEY base64 tidak valid/minimal 32-byte.');
    }elseif(in_array($appEnv,['staging','production','prod'],true) && strlen($encryptionKeyRaw)<32){
        throw new RuntimeException('APP_ENCRYPTION_KEY non-base64 minimal 32 karakter pada staging/production.');
    }

    $password=trim((string)(getenv('APP_BOOTSTRAP_ADMIN_PASSWORD') ?: ''));
    if(strlen($password)<12) throw new RuntimeException('APP_BOOTSTRAP_ADMIN_PASSWORD wajib minimal 12 karakter.');
    $username=trim((string)(getenv('APP_BOOTSTRAP_ADMIN_USERNAME') ?: 'admin'));
    $name=trim((string)(getenv('APP_BOOTSTRAP_ADMIN_NAME') ?: 'Administrator Utama'));
    if($username==='' || !preg_match('/^[A-Za-z0-9._-]{3,50}$/',$username)) throw new RuntimeException('APP_BOOTSTRAP_ADMIN_USERNAME tidak valid. Gunakan 3-50 karakter: huruf/angka/._-.');
    if($name==='') throw new RuntimeException('APP_BOOTSTRAP_ADMIN_NAME tidak boleh kosong.');

    $propertyName=trim((string)(getenv('TAMASYA_PROPERTY_NAME') ?: ''));
    if($propertyName==='' || strtoupper($propertyName)==='NAMA HOTEL'){
        throw new RuntimeException('TAMASYA_PROPERTY_NAME wajib diisi dengan nama hotel sebelum first_install.');
    }

    $propertyId=strtolower(trim((string)(getenv('TAMASYA_PROPERTY_ID') ?: '')));
    if($propertyId==='' || $propertyId==='default' || !preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$propertyId)){
        throw new RuntimeException('TAMASYA_PROPERTY_ID wajib unik dan valid (2-80 karakter: a-z, angka, . _ : -). Jangan gunakan default.');
    }
    $propertyCode=strtoupper(trim((string)(getenv('TAMASYA_PROPERTY_CODE') ?: '')));
    if($propertyCode==='' || !preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/',$propertyCode)){
        throw new RuntimeException('TAMASYA_PROPERTY_CODE wajib diisi 2-40 karakter: huruf kapital, angka, _ atau -.');
    }

    $companyId=strtolower(trim((string)(getenv('TAMASYA_COMPANY_ID') ?: '')));
    if($companyId!=='' && !preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$companyId)){
        throw new RuntimeException('TAMASYA_COMPANY_ID tidak valid. Jika hotel adalah cabang/property satu perusahaan, gunakan ID perusahaan stabil 2-80 karakter.');
    }
    $currency=strtoupper(trim((string)(getenv('TAMASYA_PROPERTY_CURRENCY') ?: '')));
    if(!preg_match('/^[A-Z]{3}$/',$currency)) throw new RuntimeException('TAMASYA_PROPERTY_CURRENCY wajib kode 3 huruf, contoh IDR.');
    $countryCode=strtoupper(trim((string)(getenv('TAMASYA_PROPERTY_COUNTRY') ?: '')));
    if(!preg_match('/^[A-Z]{2}$/',$countryCode)) throw new RuntimeException('TAMASYA_PROPERTY_COUNTRY wajib kode negara 2 huruf, contoh ID.');
    $locale=trim((string)(getenv('TAMASYA_PROPERTY_LOCALE') ?: 'id-ID'));
    if(!preg_match('/^[A-Za-z]{2,3}[-_][A-Za-z]{2,4}$/',$locale)) throw new RuntimeException('TAMASYA_PROPERTY_LOCALE tidak valid, contoh id-ID.');
    $invoicePrefix=strtoupper(trim((string)(getenv('TAMASYA_INVOICE_PREFIX') ?: $propertyCode)));
    $invoicePrefix=preg_replace('/[^A-Z0-9_-]+/','',$invoicePrefix) ?? '';
    if($invoicePrefix==='' || strlen($invoicePrefix)>30) throw new RuntimeException('TAMASYA_INVOICE_PREFIX harus 1-30 karakter huruf kapital/angka/_/-.');

    $timezone=trim((string)(getenv('APP_TIMEZONE') ?: ''));
    if($timezone==='' || !in_array($timezone, DateTimeZone::listIdentifiers(), true)){
        throw new RuntimeException('APP_TIMEZONE wajib diisi dengan timezone IANA hotel yang valid, contoh Asia/Jakarta, Asia/Makassar, atau Asia/Jayapura.');
    }

    $id='s_'.bin2hex(random_bytes(12));
    $hash=password_hash($password,PASSWORD_DEFAULT);
    if(!is_string($hash) || $hash==='') throw new RuntimeException('Password admin tidak dapat di-hash.');

    $pdo->beginTransaction();
    $insert=$pdo->prepare("INSERT INTO staff(id,name,username,password,role,status,password_changed_at,require_password_change) VALUES (?,?,?,?, 'admin','active',CURRENT_TIMESTAMP,1)");
    $insert->execute([$id,$name,$username,$hash]);
    $propertyInsert=$pdo->prepare("INSERT INTO property_settings(id,company_id,property_id,property_code,property_name,timezone,currency,country_code,locale,invoice_prefix,accounting_basis,tax_setup_mode,payment_setup_mode,setup_status,setup_version) VALUES ('system_default',?,?,?,?,?,?,?,?,?,'cash','pending','pending','identity_ready',1)");
    $propertyInsert->execute([$companyId!==''?$companyId:null,$propertyId,$propertyCode,$propertyName,$timezone,$currency,$countryCode,$locale,$invoicePrefix]);
    $pdo->prepare("UPDATE config SET is_seeded=1 WHERE id='system_default'")->execute();
    $canonicalSourceChecksum=tamasyaCanonicalDatabaseSourceChecksum();
    $pdo->prepare("UPDATE schema_release_state SET current_release=?,patch_level=?,source_checksum=?,migration_run_id=COALESCE(NULLIF(migration_run_id,''),?),maintenance_required=0,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
        ->execute([TAMASYA_SCHEMA_RELEASE,TAMASYA_PATCH_LEVEL,$canonicalSourceChecksum,'first_install_'.substr($canonicalSourceChecksum,0,16)]);
    $pdo->commit();

    tamasyaAdminToolEmit([
        'success'=>true,
        'database'=>$db,
        'companyId'=>$companyId,
        'propertyRelationship'=>'flexible_property',
        'companyLink'=>$companyId!==''?'linked':'optional_unlinked',
        'propertyName'=>$propertyName,
        'propertyId'=>$propertyId,
        'propertyCode'=>$propertyCode,
        'timezone'=>$timezone,
        'currency'=>$currency,
        'countryCode'=>$countryCode,
        'locale'=>$locale,
        'invoicePrefix'=>$invoicePrefix,
        'propertySetupStatus'=>'identity_ready',
        'adminId'=>$id,
        'username'=>$username,
        'role'=>'admin',
        'requirePasswordChange'=>true,
        'passwordLogged'=>false,
        'message'=>'Administrator pertama dan identitas deployment property dibuat. Login, ganti password bootstrap, lalu selesaikan Wizard Setup Hotel sampai status READY sebelum operasi/live data.'
    ]);
}catch(Throwable $e){
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    tamasyaAdminToolEmit(['success'=>false,'error'=>$e->getMessage()],$isCli?200:400,true);
    exit(1);
}
