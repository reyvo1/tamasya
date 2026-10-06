<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/hybrid_contract.php';
require dirname(__DIR__).'/release_contract.php';
// Produces a reviewable private bundle. It never connects to or changes a database.
$input=json_decode(stream_get_contents(STDIN,8193),true,16,JSON_THROW_ON_ERROR);
$company=$input['companyId']??null;$property=$input['propertyId']??null;
if(!tamasyaHybridId($company)||!tamasyaHybridId($property))throw new InvalidArgumentException('Explicit company/property IDs required.');
$url=$input['url']??'';$u=is_string($url)?parse_url($url):false;
if(!$u||($u['scheme']??'')!=='https'||!preg_match('/^[a-zA-Z0-9.-]+$/D',(string)($u['host']??''))||isset($u['user'])||isset($u['query'])||isset($u['fragment'])||!in_array($u['path']??'',['','/'],true)||isset($u['port']))throw new InvalidArgumentException('Use a dedicated HTTPS property origin.');
$host=$input['databaseHost']??'';$port=$input['databasePort']??3306;$userHost=$input['databaseUserHost']??'';
if(!is_string($host)||!preg_match('/^[A-Za-z0-9.-]{1,190}$/D',$host)||!is_int($port)||$port<1||$port>65535||!is_string($userHost)||!preg_match('/^[A-Za-z0-9._%-]{1,190}$/D',$userHost))throw new InvalidArgumentException('Explicit database endpoint and permitted client host required.');
$target=$argv[1]??'';$parent=realpath(dirname($target));$app=realpath(dirname(__DIR__));
if(!$parent||!$app||$target===''||file_exists($target)||str_starts_with(strtolower(str_replace('\\','/',$parent).'/'),strtolower(str_replace('\\','/',$app).'/')))throw new InvalidArgumentException('Choose a new private output directory outside the application tree.');
$name='tm_'.substr(hash('sha256',$company."\n".$property),0,24);$password=bin2hex(random_bytes(32));$hqSecret=bin2hex(random_bytes(32));
$canonicalChecksum=tamasyaCanonicalDatabaseSourceChecksum();
$attestationRun='provision_'.substr($canonicalChecksum,0,16);
$env=['APP_ENV'=>'production','APP_DEBUG'=>'0','APP_URL'=>'https://'.$u['host'],'APP_ALLOWED_HOSTS'=>$u['host'],'APP_ALLOWED_ORIGINS'=>'https://'.$u['host'],'APP_ENFORCE_ALLOWED_HOSTS'=>'1','APP_CREDENTIALS_FILE'=>'/run/secrets/db_credentials.php','APP_EXPECTED_DB_NAME'=>$name,'APP_REQUIRE_EXPECTED_DB_NAME'=>'1','APP_TIMEZONE'=>'Asia/Makassar','APP_ENCRYPTION_KEY'=>bin2hex(random_bytes(32)),'SECURITY_EVENT_HASH_KEY'=>bin2hex(random_bytes(32)),'APP_BOOTSTRAP_ADMIN_USERNAME'=>'property_admin','APP_BOOTSTRAP_ADMIN_PASSWORD'=>bin2hex(random_bytes(24)),'TAMASYA_COMPANY_ID'=>$company,'TAMASYA_PROPERTY_ID'=>$property,'TAMASYA_PROPERTY_CODE'=>strtoupper(substr(str_replace('-','',$property),0,20)),'TAMASYA_PROPERTY_NAME'=>$property,'TAMASYA_PROPERTY_CURRENCY'=>'IDR','TAMASYA_PROPERTY_COUNTRY'=>'ID','TAMASYA_PROPERTY_LOCALE'=>'id-ID','TAMASYA_NODE_ID'=>$name.'-writer','TAMASYA_NODE_ROLE'=>'online_primary','NODE_CLUSTER_ENABLED'=>'0','NODE_SYNC_ENABLED'=>'0','TAMASYA_HYBRID_OUTBOX_DIR'=>'/var/lib/tamasya/outbox','BACKUP_DIR'=>'/var/lib/tamasya/backups','CRON_SECRET'=>bin2hex(random_bytes(32)),'TAMASYA_DEVICE_WORKER_ENABLED'=>'0'];
$credentials=['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$name,'pass'=>$password];
if(!mkdir($target,0700))throw new RuntimeException('Cannot create private bundle.');
$files=[
 'db_credentials.php'=>"<?php\nreturn ".var_export($credentials,true).";\n",
 'runtime.env'=>implode("\n",array_map(static fn($key,$value)=>$key.'='.$value,array_keys($env),$env))."\n",
 'provision.sql'=>"-- Apply with a provisioning account; refuses existing database/user.\nCREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER '$name'@'$userHost' IDENTIFIED BY '$password';\nGRANT SELECT,INSERT,UPDATE,DELETE ON `$name`.* TO '$name'@'$userHost';\n",
 'release_attestation.sql'=>"-- Apply with migration/DBA authority ONLY AFTER database_setup.sql imported successfully\n-- and strict canonical trigger verification passed using the same privileged authority.\nUPDATE `$name`.`schema_release_state`\n   SET `current_release`='".TAMASYA_SCHEMA_RELEASE."',\n       `patch_level`='".TAMASYA_PATCH_LEVEL."',\n       `source_checksum`='$canonicalChecksum',\n       `migration_run_id`='$attestationRun',\n       `maintenance_required`=0,\n       `updated_at`=CURRENT_TIMESTAMP\n WHERE `id`='system_default';\n",
 'hq-registration.private.json'=>json_encode(['companyId'=>$company,'propertyId'=>$property,'secret'=>$hqSecret],JSON_PRETTY_PRINT)."\n",
 'README.txt'=>"PRIVATE GENERATED BUNDLE — do not put in a release ZIP or web root.\n1. Review provision.sql; apply using DBA connection.\n2. Import database_setup.sql into $name using migration/DBA authority.\n3. BEFORE applying release_attestation.sql, verify the exact canonical schema and all trigger signatures with that same migration/DBA authority. If verification fails, stop; never attest a failed import.\n4. After strict verification PASS, review/apply release_attestation.sql using migration/DBA authority. This records the exact source checksum for DML-only runtime startup; it does not grant runtime TRIGGER/DDL privileges.\n5. Mount runtime.env and db_credentials.php as described in deploy/README.md. Do not mount DBA/migration credentials.\n6. Run first_install.php with the runtime identity, complete setup wizard, then run runtime status. Run strict database_verify.php only from a maintenance/provisioning context with trigger-metadata visibility.\n7. Register HQ property using hq-registration.private.json and configure the same HMAC secret on the hotel.\n8. Database runtime user is restricted to this property; bootstrap application credentials must be rotated after setup.\n",
];
foreach($files as $file=>$body){$path=$target.DIRECTORY_SEPARATOR.$file;$f=fopen($path,'xb');if(!$f)throw new RuntimeException('Cannot create '.$file);try{if(fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))throw new RuntimeException('Cannot persist '.$file);}finally{fclose($f);}chmod($path,0600);}
echo json_encode(['success'=>true,'status'=>'bundle_created_not_applied','companyId'=>$company,'propertyId'=>$property,'database'=>$name,'files'=>array_keys($files)])."\n";
