<?php
/** V137 canonical property setup route. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'property-setup') return;
$routeHandled=true;
requireRoles($loggedInStaff,['admin']);
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$command=strtolower(trim((string)($_GET['command']??$input['command']??'status')));
try{
    if($method==='GET'){
        if(!in_array($command,['status','overview'],true)) throw new InvalidArgumentException('Command property setup tidak dikenal.');
        echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaPropertySetupSnapshot($pdo)]);
        return;
    }
    if($method!=='POST'){
        http_response_code(405);
        echo tamasyaJsonEncode(['success'=>false,'error'=>'Metode property setup tidak didukung.']);
        return;
    }
    if($command==='save'){
        echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaPropertySetupSave($pdo,$loggedInStaff,is_array($input)?$input:[])]);
        return;
    }
    if($command==='finalize'){
        echo tamasyaJsonEncode(['success'=>true,'data'=>tamasyaPropertySetupFinalize($pdo,$loggedInStaff)]);
        return;
    }
    throw new InvalidArgumentException('Command property setup POST tidak dikenal.');
}catch(Throwable $e){
    tamasyaApplyExceptionHttpStatus($e,500);
    echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Setup property gagal',$e)]);
}
