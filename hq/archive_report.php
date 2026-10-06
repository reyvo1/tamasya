<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/object_storage.php';
$input=json_decode(stream_get_contents(STDIN,4097),true,8,JSON_THROW_ON_ERROR);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.(string)($input['token']??'');$_GET['company']=(string)($input['companyId']??'');
$config=tamasyaHqConfig();$viewer=tamasyaHqViewer($config,$_SERVER['HTTP_AUTHORIZATION']);
echo tamasyaHybridJson(tamasyaHqArchiveReport(tamasyaHqPdo($config),$config,$viewer,(string)($input['reportId']??'')))."\n";
