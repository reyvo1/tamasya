<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/database_bootstrap.php';
$raw=stream_get_contents(STDIN);
$input=json_decode($raw?:'{}',true);
if(!is_array($input)||!isset($input['sql'])){fwrite(STDERR,"Invalid input\n");exit(2);}
$config=tamasyaResolveDatabaseConfig($root);
[$pdo,$error,$stage]=tamasyaConnectDatabase($config);
if(!$pdo instanceof PDO){fwrite(STDERR,"DB connect failed [$stage]: $error\n");exit(3);}
try{
    $appTimezone=trim((string)(getenv('APP_TIMEZONE')?:'UTC'));
    $tz=new DateTimeImmutable('now',new DateTimeZone($appTimezone));
    $offset=$tz->getOffset();
    $sign=$offset<0?'-':'+';$abs=abs($offset);
    $mysqlOffset=sprintf('%s%02d:%02d',$sign,intdiv($abs,3600),intdiv($abs%3600,60));
    if(!preg_match('/^[+-](?:0\d|1[0-3]):[0-5]\d$|^\+14:00$/',$mysqlOffset))throw new RuntimeException('Invalid UAT DB timezone offset');
    $pdo->exec('SET time_zone = '.$pdo->quote($mysqlOffset));
    $stmt=$pdo->prepare((string)$input['sql']);
    $stmt->execute(is_array($input['params']??null)?$input['params']:[]);
    if($stmt->columnCount()>0){echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC),JSON_UNESCAPED_SLASHES);}
    else{echo json_encode([],JSON_UNESCAPED_SLASHES);}
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(4);}
