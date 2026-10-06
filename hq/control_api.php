<?php
declare(strict_types=1);
define('TAMASYA_CONTROL_CONFIG_RAW',true);
require __DIR__.'/core.php';require_once __DIR__.'/control_plane.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    $config=tamasyaHqConfig();if(($config['controlPlane']['enabled']??false)!==true)throw new TamasyaHqError('CONTROL_DISABLED',503,'Control plane belum diaktifkan.');
    $pdo=tamasyaHqPdo($config);$actor=tamasyaControlActor($pdo,(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)throw new InvalidArgumentException('Request terlalu besar.');
        $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException('Request tidak valid.');
        echo tamasyaHybridJson(tamasyaControlMutate($pdo,$config,$actor,$input));
    } elseif(($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
        $company=(string)($_GET['company']??$actor['company_id']??'');
        if($company===''){
            if($actor['role']!=='platform_admin')throw new TamasyaHqError('COMPANY_REQUIRED',403,'Company wajib.');
            $offset=max(0,min(100000,(int)($_GET['offset']??0)));$rows=$pdo->query('SELECT id,name,enabled FROM hq_companies ORDER BY id LIMIT 50 OFFSET '.$offset)->fetchAll();
            echo tamasyaHybridJson(['success'=>true,'role'=>$actor['role'],'companies'=>$rows,'offset'=>$offset]);
        }else{
            tamasyaControlCompany($actor,$company,true);
            $offset=max(0,min(100000,(int)($_GET['offset']??0)));
            $q=$pdo->prepare('SELECT property_id,enabled FROM hq_properties WHERE company_id=? ORDER BY property_id LIMIT 50 OFFSET '.$offset);$q->execute([$company]);$properties=$q->fetchAll();
            $q=$pdo->prepare('SELECT id,role,property_ids,enabled FROM hq_principals WHERE company_id=? ORDER BY id LIMIT 50 OFFSET '.$offset);$q->execute([$company]);$principals=$q->fetchAll();
            $q=$pdo->prepare('SELECT actor_id,operation_id,command,created_at FROM hq_control_audit WHERE company_id=? ORDER BY id DESC LIMIT 50 OFFSET '.$offset);$q->execute([$company]);
            echo tamasyaHybridJson(['success'=>true,'role'=>$actor['role'],'companyId'=>$company,'properties'=>$properties,'principals'=>$principals,'audit'=>$q->fetchAll()]);
        }
    } else throw new TamasyaHqError('METHOD_NOT_ALLOWED',405,'Metode tidak didukung.');
} catch(Throwable $e){$status=$e instanceof TamasyaHqError?$e->httpStatus:($e instanceof InvalidArgumentException||$e instanceof JsonException?422:503);http_response_code($status);if($status===503)error_log($e->getMessage());echo json_encode(['success'=>false,'code'=>$e instanceof TamasyaHqError?$e->errorCode:'CONTROL_ERROR','retryable'=>$status>=500,'message'=>$status===503?'Control plane belum tersedia.':$e->getMessage()]);}
