<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
require_once $root.'/growth_activation_profile.php';
if(in_array('--help',$argv,true)){
    echo "Dry run: php scripts/activate-growth-enterprise.php --expected-db=NODE_DB --env-file=/private/node.env\n";
    echo "Apply: tambah --apply --backup-confirmed=VERIFIED_BACKUP_REFERENCE\n";
    echo "Jalankan pada KEDUA node dalam maintenance window; restart runtime setelah semua node siap.\n";
    exit;
}
$args=[];
foreach($argv as $arg)if(preg_match('/^--(expected-db|env-file|backup-confirmed)=(.*)$/s',$arg,$m))$args[$m[1]]=$m[2];
$apply=in_array('--apply',$argv,true);
try{
    $expected=trim($args['expected-db']??'');
    $env=realpath($args['env-file']??'');
    if($expected===''||!$env||!is_file($env)||!is_readable($env))throw new RuntimeException('Nama database dan file environment node yang sudah ada wajib diberikan.');
    if($apply&&trim($args['backup-confirmed']??'')==='')throw new RuntimeException('Referensi backup terverifikasi wajib untuk Apply.');
    $configured=trim((string)(getenv('APP_ENV_FILE')?:getenv('TAMASYA_ENV_FILE')?:''));
    if($configured!==''&&realpath($configured)!==$env)throw new RuntimeException('Environment proses menunjuk file berbeda; hentikan agar node tidak tertukar.');
    putenv('APP_ENV_FILE='.$env);
    putenv('APP_EXPECTED_DB_NAME='.$expected);
    putenv('APP_REQUIRE_EXPECTED_DB_NAME=1');
    require_once $root.'/database_bootstrap.php';
    $config=tamasyaResolveDatabaseConfig($root);
    [$pdo,$error]=tamasyaConnectDatabase($config);
    if(!$pdo instanceof PDO)throw new RuntimeException('Database node tidak dapat dihubungkan.');
    $safety=tamasyaAssertDatabaseSafety($pdo,$config);
    $identity=tamasyaDatabasePropertyIdentity($pdo,false);
    if($apply&&!is_writable($env))throw new RuntimeException('File environment node tidak dapat ditulis.');
    // Installer owns additive DDL and schema completeness checks. It does not
    // promote a node, change credentials, or bypass the hybrid mutation fence.
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/optional_modules_install.php').' --target=all';
    if($apply)$command.=' --apply '.escapeshellarg('--backup-confirmed='.$args['backup-confirmed']);
    $output=[];$code=0;exec($command,$output,$code);
    $report=json_decode(implode("\n",$output),true);
    if($code!==0||!is_array($report)||empty($report['success']))throw new RuntimeException('Installer modul belum berhasil; feature flag tidak diubah. Periksa installer secara langsung.');
    if(!$apply){echo json_encode(['success'=>true,'mode'=>'dry-run','database'=>$safety['actual'],'schema'=>$report,'activationProfile'=>tamasyaGrowthActivationProfile()],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit;}
    $old=file_get_contents($env);
    if($old===false)throw new RuntimeException('Environment tidak dapat dibaca kembali.');
    $backup=$env.'.before-growth-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
    $tmp=tempnam(dirname($env),'.tamasya-growth-');
    if($tmp===false)throw new RuntimeException('File environment sementara tidak dapat dibuat.');
    try{
        if(file_put_contents($backup,$old,LOCK_EX)===false||!chmod($backup,0600))throw new RuntimeException('Backup environment privat gagal.');
        if(!chmod($tmp,0600)||file_put_contents($tmp,tamasyaGrowthProfileEnvironment($old),LOCK_EX)===false)throw new RuntimeException('Penulisan environment gagal.');
        if(!rename($tmp,$env))throw new RuntimeException('Aktivasi environment atomik gagal.');
    }finally{if(is_file($tmp))unlink($tmp);}
    echo json_encode(['success'=>true,'mode'=>'apply','database'=>$safety['actual'],'schema'=>$report['applied']??$report,
        'next'=>'Ulangi pada node pasangan, samakan flag pada environment process manager bila diinjeksi, lalu restart PHP-FPM/Apache/worker. Periksa status Growth/Enterprise di kedua node sebelum membuka maintenance.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,'ACTIVATION BLOCKED: '.$e->getMessage()."\n");exit(1);}
