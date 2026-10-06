<?php
declare(strict_types=1);
require_once __DIR__.'/reporting.php';
/** Private, immutable report objects; authorization always precedes storage access. */
function tamasyaObjectRequest(array $config,string $method,string $key,string $body=''): array {
    $s=$config['objectStorage']??[];
    if(($s['enabled']??false)!==true||!defined('CURLOPT_AWS_SIGV4'))throw new RuntimeException('S3 adapter disabled or curl SigV4 unavailable.');
    $endpoint=rtrim((string)($s['endpoint']??''),'/');$parts=parse_url($endpoint);
    if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['query'])||isset($parts['fragment'])||!empty($parts['path']))throw new InvalidArgumentException('S3 endpoint must be an HTTPS origin.');
    if(!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D',(string)($s['bucket']??''))||!preg_match('/^[a-z0-9-]{1,40}$/D',(string)($s['region']??'')))throw new InvalidArgumentException('Invalid bucket/region.');
    foreach(['accessKey','secretKey'] as $field)if(!is_string($s[$field]??null)||strlen($s[$field])<16||preg_match('/[\r\n:]/',$s[$field]))throw new InvalidArgumentException('Invalid storage credential.');
    if(!in_array($method,['GET','PUT'],true)||strlen($body)>16777216)throw new InvalidArgumentException('Unsupported request or report over 16 MiB.');
    $url=$endpoint.'/'.rawurlencode($s['bucket']).'/'.implode('/',array_map('rawurlencode',explode('/',$key)));
    $headers=['x-amz-content-sha256: '.hash('sha256',$body)];
    if(!empty($s['sessionToken'])){$t=$s['sessionToken'];if(!is_string($t)||preg_match('/[\r\n]/',$t))throw new InvalidArgumentException('Invalid session token.');$headers[]='x-amz-security-token: '.$t;}
    if($method==='PUT')$headers=array_merge($headers,['Content-Type: application/json','If-None-Match: *','x-amz-checksum-sha256: '.base64_encode(hash('sha256',$body,true)),'x-amz-server-side-encryption: AES256']);
    $caFile=tamasyaHqOutboundCaFile($s);
    $received='';$ch=curl_init($url);
    $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_AWS_SIGV4=>'aws:amz:'.$s['region'].':s3',CURLOPT_USERPWD=>$s['accessKey'].':'.$s['secretKey'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$received){if(strlen($received)+strlen($chunk)>16777216)return 0;$received.=$chunk;return strlen($chunk);}];
    if($caFile!==null)$options[CURLOPT_CAINFO]=$caFile;
    curl_setopt_array($ch,$options);
    if($method==='PUT')curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlErrno=curl_errno($ch);unset($ch);
    if($ok===false)throw new RuntimeException('STORAGE_TRANSPORT_ERROR_'.max(0,$curlErrno));
    return ['status'=>$status,'body'=>$received];
}
function tamasyaHqArchiveReport(PDO $pdo,array $config,array $viewer,string $reportId): array {
    $data=tamasyaHqStoredReport($pdo,$viewer,$reportId);$body=tamasyaHybridJson($data);$hash=hash('sha256',$body);
    $key=$viewer['companyId'].'/reports/'.$reportId.'/'.$hash.'.json';
    $put=tamasyaObjectRequest($config,'PUT',$key,$body);
    if(!in_array($put['status'],[200,201,412],true))throw new RuntimeException('Object archive was not acknowledged.');
    $read=tamasyaObjectRequest($config,'GET',$key);
    if($read['status']!==200||!hash_equals($hash,hash('sha256',$read['body'])))throw new RuntimeException('Archived object failed read-back checksum verification.');
    return ['success'=>true,'status'=>'verified','reportId'=>$reportId,'objectKey'=>$key,'objectSha256'=>$hash,'duplicate'=>$put['status']===412];
}
