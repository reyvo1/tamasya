<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TAMASYA_CONTROL_CONFIG_RAW',true);
require __DIR__.'/core.php';
if(($argv[1]??'')!=='--initialize-empty-principals'){fwrite(STDERR,"Use --initialize-empty-principals; supply one JSON object on stdin with id and token. Never place the token in CLI arguments.\n");exit(1);}
$input=json_decode(stream_get_contents(STDIN,4097),true,8,JSON_THROW_ON_ERROR);
if(!tamasyaHybridId($input['id']??null)||!is_string($input['token']??null)||!preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$input['token']))throw new InvalidArgumentException('Invalid bootstrap principal.');
$pdo=tamasyaHqPdo(tamasyaHqConfig());
if(!$pdo->query("SELECT GET_LOCK('tamasya_hq_control_bootstrap',10)")->fetchColumn())throw new RuntimeException('Bootstrap busy.');
try{
 if($pdo->query('SELECT id FROM hq_principals LIMIT 1')->fetch())throw new RuntimeException('Principals already initialized.');
 $q=$pdo->prepare("INSERT INTO hq_principals(id,token_hash,role,company_id,property_ids,enabled) VALUES (?,?,'platform_admin',NULL,'[]',1)");$q->execute([$input['id'],hash('sha256',$input['token'])]);
 echo "Platform principal initialized; token was not logged.\n";
}finally{$pdo->query("SELECT RELEASE_LOCK('tamasya_hq_control_bootstrap')");}
