<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
function tamasyaHqRealtimeTicket(array $config,array $viewer): array {
    $secret=(string)($config['realtime']['secret']??''); $url=(string)($config['realtime']['url']??'');
    if (($config['realtime']['enabled']??false)!==true || strlen($secret)<32 || $url==='') return ['enabled'=>false];
    $claims=['v'=>1,'aud'=>'tamasya-realtime','companyId'=>$viewer['companyId'],'propertyIds'=>$viewer['propertyIds'],'exp'=>time()+60,'jti'=>bin2hex(random_bytes(16))];
    $payload=rtrim(strtr(base64_encode(tamasyaHybridJson($claims)),'+/','-_'),'=');
    $signature=rtrim(strtr(base64_encode(hash_hmac('sha256',$payload,$secret,true)),'+/','-_'),'=');
    return ['enabled'=>true,'url'=>$url,'ticket'=>$payload.'.'.$signature,'expiresAt'=>$claims['exp'],'contractVersion'=>'tamasya-realtime-v1'];
}
function tamasyaHqEventRevision(PDO $pdo,array $viewer): array {
    $in=implode(',',array_fill(0,count($viewer['propertyIds']),'?'));
    $q=$pdo->prepare("SELECT property_id,event_revision FROM hq_property_locks WHERE company_id=? AND property_id IN ($in) ORDER BY property_id");
    $q->execute(array_merge([$viewer['companyId']],$viewer['propertyIds']));
    $revisions=array_fill_keys($viewer['propertyIds'],'0');
    foreach ($q->fetchAll() as $r) $revisions[$r['property_id']]=(string)$r['event_revision'];
    return ['contractVersion'=>'tamasya-realtime-v1','companyId'=>$viewer['companyId'],'revisions'=>$revisions];
}
