<?php
declare(strict_types=1);
require dirname(__DIR__).'/database_tls.php';
putenv('DB_TLS_REQUIRED');putenv('DB_SSL_CA');
if(tamasyaDatabaseTlsOptions()!==[])throw new RuntimeException('Optional TLS changed default.');
$count=1;
foreach([['required'=>'invalid'],['required'=>true],['required'=>true,'caFile'=>'/missing/ca.pem']] as $config){$blocked=false;try{tamasyaDatabaseTlsOptions($config);}catch(RuntimeException $e){$blocked=true;}if(!$blocked)throw new RuntimeException('Invalid TLS configuration accepted.');$count++;}
echo "Database TLS config assertions passed: $count\n";
