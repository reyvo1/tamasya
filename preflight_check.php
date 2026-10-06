<?php
declare(strict_types=1);
/**
 * TAMASYA V137 read-only deployment preflight.
 *
 * Checks environment shape, secret presence/length (never values), private DB
 * credentials, expected database safety, canonical baseline, property identity,
 * backup directory, and flexible cluster configuration. It does not alter DB or
 * filesystem state.
 */
require_once __DIR__.'/release_contract.php';
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/baseline_support.php';
require_once __DIR__.'/node_cluster_support.php';
require_once __DIR__.'/node_sync_support.php';
require_once __DIR__.'/admin_web_tool_gate.php';
if(!defined('TAMASYA_MAINTENANCE_ENTRY')) define('TAMASYA_MAINTENANCE_ENTRY',true);
require_once __DIR__.'/runtime_crypto.php';

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA Preflight Check');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA Preflight Check',
            'Read-only: audit ENV, target DB, baseline, property identity, backup path, dan cluster sebelum UAT/production.',
            '',
            'Jalankan Preflight'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
}

$checks=[];
$add=function(string $id,string $status,string $message,array $meta=[]) use (&$checks):void{
    $checks[]=['id'=>$id,'status'=>$status,'message'=>$message]+($meta?['meta'=>$meta]:[]);
};
$env=function(string $key,string $default=''):string{return trim((string)(getenv($key)?:$default));};
$isTruthy=function(string $key,bool $default=false):bool{
    $raw=getenv($key);if($raw===false||trim((string)$raw)==='')return $default;return filter_var($raw,FILTER_VALIDATE_BOOLEAN);
};
$isAbs=static fn(string $path):bool=>tamasyaPathIsAbsolute($path);
$isPrivateOrLocalHost=static function(string $host):bool{
    $host=strtolower(trim($host,'[] '));
    if($host==='localhost' || str_ends_with($host,'.local'))return true;
    if(filter_var($host,FILTER_VALIDATE_IP)){
        return filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false;
    }
    return false;
};

$appEnv=strtolower($env('APP_ENV','production'));
$productionLike=in_array($appEnv,['staging','production','prod'],true);
$add('app.env',in_array($appEnv,['local','development','dev','test','staging','production','prod'],true)?'pass':'warn','APP_ENV='.$appEnv);
$debug=$isTruthy('APP_DEBUG',false);
$add('app.debug',($productionLike&&!$debug)||(!$productionLike)?'pass':'fail',$productionLike?($debug?'APP_DEBUG harus 0 pada staging/production.':'APP_DEBUG nonaktif.'):'APP_DEBUG sesuai kebijakan environment.');

$urlKeys=['APP_URL','PUBLIC_SITE_URL','VITE_PUBLIC_API_URL','VITE_TAMASYA_ONLINE_API_URL'];
foreach($urlKeys as $key){
    $value=$env($key);
    if($value===''){ $add('url.'.strtolower($key),'warn',$key.' kosong.'); continue; }
    if(str_contains($value,'](')||str_starts_with($value,'[')){$add('url.'.strtolower($key),'fail',$key.' terlihat seperti Markdown, bukan URL polos.');continue;}
    $parts=parse_url($value);$ok=is_array($parts)&&!empty($parts['scheme'])&&!empty($parts['host']);
    if(!$ok){$add('url.'.strtolower($key),'fail',$key.' bukan URL absolut valid.');continue;}
    $scheme=strtolower((string)$parts['scheme']);
    $host=(string)$parts['host'];
    $localHttpAllowed=$productionLike && $scheme==='http' && $isTruthy('NODE_SYNC_ALLOW_HTTP_LOCAL',false) && $isPrivateOrLocalHost($host);
    $status=($productionLike&&$scheme!=='https'&&!$localHttpAllowed)?'fail':($localHttpAllowed?'warn':'pass');
    $message=$key.' valid.';
    if($productionLike&&$scheme!=='https'&&!$localHttpAllowed)$message=$key.' valid tetapi staging/production wajib HTTPS; HTTP hanya boleh untuk host private/local dengan NODE_SYNC_ALLOW_HTTP_LOCAL=1.';
    elseif($localHttpAllowed)$message=$key.' memakai HTTP private/local yang diizinkan eksplisit. Gunakan HTTPS/VPN gateway bila memungkinkan; browser HTTPS dapat memblokir mixed content.';
    $add('url.'.strtolower($key),$status,$message,['scheme'=>$scheme,'host'=>$host,'localHttpException'=>$localHttpAllowed]);
}

$timezone=$env('APP_TIMEZONE');
try{if($timezone==='')throw new RuntimeException('empty');new DateTimeZone($timezone);$add('app.timezone','pass','Timezone valid: '.$timezone);}catch(Throwable $e){$add('app.timezone','fail','APP_TIMEZONE kosong/tidak valid.');}

$propertyId=strtolower($env('TAMASYA_PROPERTY_ID',$env('APP_PROPERTY_ID')));
$propertyCode=strtoupper($env('TAMASYA_PROPERTY_CODE'));
$companyId=strtolower($env('TAMASYA_COMPANY_ID'));
$propertyName=$env('TAMASYA_PROPERTY_NAME');
$propertyValid=(bool)preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$propertyId);
$add('property.id',$propertyValid?'pass':'fail',$propertyValid?'Property ID valid: '.$propertyId:'TAMASYA_PROPERTY_ID wajib 2-80 karakter dan stabil per hotel/property.');
$add('property.code',$propertyCode!==''?'pass':'fail',$propertyCode!==''?'Property code terisi: '.$propertyCode:'TAMASYA_PROPERTY_CODE wajib diisi.');
$add('property.name',$propertyName!==''?'pass':'fail',$propertyName!==''?'Property name terisi.':'TAMASYA_PROPERTY_NAME wajib diisi.');
$add('property.relationship','pass',$companyId!==''?'Flexible property terhubung ke company: '.$companyId:'Flexible property belum dikaitkan ke company; valid untuk hotel independen/unlinked.');

$crypto=tamasyaSecretEncryptionConfigurationStatus();
$add('security.encryption',!empty($crypto['ok'])?'pass':'fail',!empty($crypto['ok'])?'APP_ENCRYPTION_KEY format valid untuk environment.':(string)($crypto['error']??'APP_ENCRYPTION_KEY invalid.'));
foreach(['SECURITY_EVENT_HASH_KEY'=>32,'CRON_SECRET'=>32] as $key=>$min){$length=strlen($env($key));$add('security.'.strtolower($key),$length>=$min?'pass':($productionLike?'fail':'warn'),$length>=$min?$key.' tersedia dengan panjang memadai.':$key.' minimal '.$min.' karakter direkomendasikan/wajib staging-production.');}
$adminWeb=$isTruthy('ADMIN_WEB_TOOLS_ENABLED',false);
$adminSecretLen=strlen($env('ADMIN_WEB_TOOLS_SECRET'));
if($adminWeb)$add('security.admin_web_tools',$adminSecretLen>=32?'warn':'fail',$adminSecretLen>=32?'ADMIN_WEB_TOOLS_ENABLED=1. Matikan kembali setelah maintenance/UAT browser selesai.':'Admin web tools aktif tetapi secret <32 karakter.');
else $add('security.admin_web_tools','pass','Admin browser maintenance tools nonaktif.');

$config=tamasyaResolveDatabaseConfig(__DIR__);
$sourceType=(string)($config['sourceType']??'none');
$resolutionError=trim((string)($config['resolutionError']??''));
if($resolutionError!=='')$add('db.credentials','fail',$resolutionError,['sourceType'=>$sourceType]);
elseif(empty($config['detected']))$add('db.credentials','fail','Credential database belum terdeteksi.',['sourceType'=>$sourceType]);
else $add('db.credentials','pass','Credential database terdeteksi tanpa mengekspos secret.',['sourceType'=>$sourceType,'source'=>tamasyaSafeCredentialSource($config)]);

$credentialPath=$env('APP_CREDENTIALS_FILE');
if($productionLike){
    if($credentialPath==='')$add('db.credential_path','fail','APP_CREDENTIALS_FILE wajib eksplisit pada staging/production.');
    elseif(!$isAbs($credentialPath))$add('db.credential_path','fail','APP_CREDENTIALS_FILE wajib path absolut.');
    elseif(!is_file($credentialPath)||!is_readable($credentialPath))$add('db.credential_path','fail','APP_CREDENTIALS_FILE tidak ada/tidak readable.',['basename'=>basename($credentialPath)]);
    elseif(tamasyaPathIsInsideDocumentRoot($credentialPath,__DIR__))$add('db.credential_path','fail','File credential berada di dalam document root.',['basename'=>basename($credentialPath)]);
    else $add('db.credential_path','pass','File credential private tersedia di luar document root.',['basename'=>basename($credentialPath)]);
}
$expectedDb=$env('APP_EXPECTED_DB_NAME');
$requireExpected=$isTruthy('APP_REQUIRE_EXPECTED_DB_NAME',$productionLike);
$add('db.expected_name',($expectedDb!==''&&$requireExpected)?'pass':($productionLike?'fail':'warn'),$expectedDb!==''?'APP_EXPECTED_DB_NAME dikunci ke nama database yang ditentukan.':'APP_EXPECTED_DB_NAME kosong; cross-DB safety lebih lemah.');
$add('php.pdo_mysql',in_array('mysql',PDO::getAvailableDrivers(),true)?'pass':'fail',in_array('mysql',PDO::getAvailableDrivers(),true)?'pdo_mysql tersedia.':'pdo_mysql tidak tersedia pada PHP runtime ini.');
$add('php.curl',function_exists('curl_init')?'pass':(tamasyaClusterEnabled()?'fail':'warn'),function_exists('curl_init')?'cURL tersedia.':'cURL tidak tersedia; wajib jika flexible cluster/sync atau integrasi HTTP digunakan.');
$add('php.openssl',function_exists('openssl_encrypt')?'pass':'fail',function_exists('openssl_encrypt')?'OpenSSL tersedia.':'OpenSSL extension tidak tersedia.');

$backupDir=$env('BACKUP_DIR');
if($backupDir==='')$add('backup.dir',$productionLike?'fail':'warn','BACKUP_DIR belum diisi.');
elseif(!$isAbs($backupDir))$add('backup.dir','fail','BACKUP_DIR wajib path absolut.');
elseif(tamasyaPathIsInsideDocumentRoot($backupDir,__DIR__))$add('backup.dir','fail','BACKUP_DIR berada di document root/aplikasi publik.');
elseif(is_dir($backupDir))$add('backup.dir',(is_readable($backupDir)&&is_writable($backupDir))?'pass':'fail',(is_readable($backupDir)&&is_writable($backupDir))?'BACKUP_DIR private tersedia dan readable/writable.':'BACKUP_DIR ada tetapi tidak readable/writable.',['basename'=>basename($backupDir)]);
else{$parent=dirname($backupDir);$add('backup.dir',is_dir($parent)&&is_writable($parent)?'warn':'fail',is_dir($parent)&&is_writable($parent)?'BACKUP_DIR belum ada tetapi parent writable; buat dengan permission private sebelum operasi.':'BACKUP_DIR belum ada dan parent tidak writable.',['basename'=>basename($backupDir)]);}

$clusterEnv=tamasyaNodeSyncEnvironmentCheck();
$add('cluster.environment',!empty($clusterEnv['ok'])?'pass':'fail',!empty($clusterEnv['enabled'])?(!empty($clusterEnv['ok'])?'Flexible two-node configuration konsisten.':'Flexible cluster config invalid: '.implode(' ',(array)($clusterEnv['errors']??[]))):'Single-server mode; cluster/sync nonaktif.',['enabled'=>(bool)($clusterEnv['enabled']??false),'nodeId'=>$clusterEnv['nodeId']??null,'clusterId'=>$clusterEnv['clusterId']??null,'propertyId'=>$clusterEnv['propertyId']??$propertyId]);

[$pdo,$connError,$connStage]=tamasyaConnectDatabase($config);
$dbReport=['connected'=>false,'stage'=>$connStage];
if(!$pdo instanceof PDO){
    $add('db.connection','fail','Database tidak dapat dihubungkan: '.($connError?:$connStage),['stage'=>$connStage]);
}else{
    try{
        $safety=tamasyaAssertDatabaseSafety($pdo,$config);
        $baseline=tamasyaCanonicalBaselineStatus($pdo);
        $identity=tamasyaDatabasePropertyIdentity($pdo,false);
        $release=is_array($baseline['releaseState']??null)?$baseline['releaseState']:[];
        $releaseOk=(string)($release['current_release']??'')===TAMASYA_SCHEMA_RELEASE && (string)($release['patch_level']??'')===TAMASYA_PATCH_LEVEL && (int)($release['maintenance_required']??1)===0;
        $identityOk=!($identity['initialized']??false)||!empty($identity['ok']);
        $baselineOk=!empty($baseline['ready']);
        $add('db.connection','pass','Database terhubung dan APP_EXPECTED_DB_NAME safety gate lulus.',['database'=>$safety['actual']]);
        $add('db.baseline',$baselineOk?'pass':'fail',$baselineOk?'Canonical baseline lengkap: '.$baseline['expectedCoreTableCount'].' core tables + '.$baseline['expectedUniqueIndexCount'].' canonical unique keys + '.$baseline['expectedTriggerCount'].' trigger signatures.':'Canonical baseline belum lengkap/berubah.',['missingCoreTables'=>$baseline['missingCoreTables'],'missingCoreColumns'=>$baseline['missingCoreColumns'],'missingPrimaryKeys'=>$baseline['missingPrimaryKeys'],'primaryKeyMismatches'=>$baseline['primaryKeyMismatches'],'missingUniqueIndexes'=>$baseline['missingUniqueIndexes'],'uniqueIndexMismatches'=>$baseline['uniqueIndexMismatches'],'missingTriggers'=>$baseline['missingTriggers'],'triggerSignatureMismatches'=>$baseline['triggerSignatureMismatches'],'nonInnoDbCoreTables'=>$baseline['nonInnoDbCoreTables']]);
        $add('db.identity',$identityOk?'pass':'fail',$identityOk?(!empty($identity['initialized'])?'Property identity database cocok dengan deployment ENV.':'Database belum memiliki initialized property identity (normal sebelum fresh first_install).'):'Property identity database berbeda dengan deployment ENV.',['mismatches'=>$identity['mismatches']??[]]);
        $add('db.release_state',$releaseOk?'pass':'fail',$releaseOk?'Release/patch database tepat sama dengan source dan maintenance_required=0.':'Release/patch database belum sama dengan source aktif; jalankan release_hardening_finalize.php setelah baseline/backup lulus.',['expectedRelease'=>TAMASYA_SCHEMA_RELEASE,'expectedPatch'=>TAMASYA_PATCH_LEVEL,'currentRelease'=>$release['current_release']??null,'patchLevel'=>$release['patch_level']??null,'maintenanceRequired'=>$release['maintenance_required']??null]);
        $setup=null;$staffCount=null;
        try{$setup=$pdo->query("SELECT setup_status,ready_at,property_id,company_id FROM property_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:null;}catch(Throwable $ignored){}
        try{$staffCount=(int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status='active'")->fetchColumn();}catch(Throwable $ignored){}
        if(is_array($setup))$add('property.setup',($setup['setup_status']??'')==='ready'?'pass':'warn','Property setup status: '.(string)($setup['setup_status']??'unknown').(($setup['setup_status']??'')==='ready'?'.':' — selesaikan Setup Wizard sebelum transaksi live.'),['readyAt'=>$setup['ready_at']??null,'activeStaff'=>$staffCount]);
        else $add('property.setup','warn','Property belum diinisialisasi; untuk fresh install jalankan first_install.php setelah baseline DB tersedia.');
        $dbReport=['connected'=>true,'database'=>$safety['actual'],'baseline'=>$baseline,'identity'=>$identity,'releaseState'=>$release,'setup'=>$setup,'activeStaff'=>$staffCount];
    }catch(Throwable $e){$add('db.safety','fail','Database safety/preflight gagal: '.$e->getMessage());$dbReport=['connected'=>true,'error'=>$e->getMessage()];}
}

$failCount=count(array_filter($checks,static fn(array $x):bool=>$x['status']==='fail'));
$warnCount=count(array_filter($checks,static fn(array $x):bool=>$x['status']==='warn'));
$passCount=count(array_filter($checks,static fn(array $x):bool=>$x['status']==='pass'));
$payload=[
    'success'=>$failCount===0,
    'readyForUat'=>$failCount===0,
    'readyForProduction'=>false,
    'productionNote'=>'Production readiness tetap membutuhkan UAT nyata, backup+restore drill, concurrency/financial test, dan two-node failover drill bila cluster diaktifkan.',
    'summary'=>['pass'=>$passCount,'warn'=>$warnCount,'fail'=>$failCount],
    'checks'=>$checks,
    'database'=>$dbReport,
    'patch'=>TAMASYA_PATCH_LEVEL,
    'safety'=>'Read-only; tidak mengubah database atau file.',
];
tamasyaAdminToolEmit($payload,$failCount===0?200:409,$failCount!==0);
if($failCount!==0)exit(3);
