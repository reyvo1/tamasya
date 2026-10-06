<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Optional durable aggregate outbox. It has no financial mutation handler. */
final class TamasyaHybridOutboxBusy extends RuntimeException {}
function tamasyaHybridOutboxDirectory(): string {
    $configured=trim((string)getenv('TAMASYA_HYBRID_OUTBOX_DIR'));
    $root=realpath($configured); $app=realpath(dirname(__DIR__,3));
    if ($configured==='' || !$root || !$app || !is_dir($root)) throw new RuntimeException('Siapkan TAMASYA_HYBRID_OUTBOX_DIR berupa direktori private di luar document root.');
    $normalized=strtolower(str_replace('\\','/',$root)); $public=strtolower(str_replace('\\','/',$app));
    if ($normalized===$public || str_starts_with($normalized,$public.'/')) throw new RuntimeException('Outbox tidak boleh berada di document root.');
    $identity=tamasyaMultiPropertyIdentity();
    if (!tamasyaHybridId($identity['companyId']) || !tamasyaHybridId($identity['propertyId'])) throw new RuntimeException('Identitas outbox belum siap.');
    $scoped=$root.'/'.hash('sha256',$identity['companyId']."\n".$identity['propertyId']);
    if (!is_dir($scoped) && !mkdir($scoped,0700) && !is_dir($scoped)) throw new RuntimeException('Direktori outbox gagal dibuat.');
    return $scoped;
}
function tamasyaHybridOutboxLock(string $directory) {
    $lock=fopen($directory.'/queue.lock','c+b');
    if (!$lock)throw new RuntimeException('Lock outbox tidak dapat dibuka.');
    if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new TamasyaHybridOutboxBusy('Outbox sedang diproses; coba kembali.'); }
    return $lock;
}
function tamasyaHybridOutboxSave(string $path,array $job): void {
    $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
    $handle=fopen($tmp,'xb');
    if (!$handle) throw new RuntimeException('Penyimpanan outbox gagal.');
    try {
        $bytes=tamasyaHybridJson($job); $offset=0;
        while ($offset<strlen($bytes)) { $written=fwrite($handle,substr($bytes,$offset)); if (!$written) throw new RuntimeException('Outbox belum tersimpan lengkap.'); $offset+=$written; }
        if (!fflush($handle) || !fsync($handle)) throw new RuntimeException('Outbox belum tersimpan secara durable.');
        fclose($handle); $handle=null;
        if (!rename($tmp,$path)) throw new RuntimeException('Commit file outbox gagal.');
    } finally { if (is_resource($handle)) fclose($handle); if (is_file($tmp)) unlink($tmp); }
}
function tamasyaHybridOutboxPublic(array $job): array {
    return array_intersect_key($job,array_flip(['job_id','operation_id','type','property_id','company_id','payload_version','created_at','attempts','status','checksum','next_attempt_at','receipt','last_error']));
}
function tamasyaHybridOutboxEnqueue(PDO $pdo,string $from,string $to,string $operation): array {
    if (!preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D',$operation)) throw new InvalidArgumentException('Operation ID wajib stabil dan valid.');
    tamasyaHybridRange($from,$to);
    $directory=tamasyaHybridOutboxDirectory(); $lock=tamasyaHybridOutboxLock($directory);
    try {
        $path=$directory.'/'.hash('sha256',$operation).'.json';
        if (is_file($path)) {
            $job=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            if ($job['snapshot']['period']!==['from'=>$from,'to'=>$to]) throw new InvalidArgumentException('Operation ID sudah terikat ke periode lain.');
            return tamasyaHybridOutboxPublic($job);
        }
        if (count(glob($directory.'/*.json') ?: [])>=1000) throw new RuntimeException('Outbox mencapai kapasitas 1000 job. Arsipkan job ACK melalui prosedur operator.');
        $snapshot=tamasyaHybridSnapshot($pdo,$from,$to);
        $job=['job_id'=>'hq_'.hash('sha256',$snapshot['companyId']."\n".$snapshot['propertyId']."\n".$operation),'operation_id'=>$operation,'type'=>'hq.aggregate.snapshot','company_id'=>$snapshot['companyId'],'property_id'=>$snapshot['propertyId'],'payload_version'=>2,'created_at'=>gmdate('c'),'attempts'=>0,'status'=>'pending','checksum'=>$snapshot['checksumSha256'],'snapshot'=>$snapshot,'next_attempt_at'=>0,'receipt'=>null,'last_error'=>null];
        tamasyaHybridOutboxSave($path,$job);
        return tamasyaHybridOutboxPublic($job);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function tamasyaHybridOutboxList(int $offset=0): array {
    $directory=tamasyaHybridOutboxDirectory(); $rows=[];
    foreach (glob($directory.'/*.json') ?: [] as $path) { $rows[]=tamasyaHybridOutboxPublic(json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR)); }
    usort($rows,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']) ?: strcmp($a['job_id'],$b['job_id']));
    $counts=array_count_values(array_column($rows,'status'));
    $offset=max(0,min(1000,$offset));
    return ['jobs'=>array_slice($rows,$offset,50),'counts'=>$counts,'total'=>count($rows),'limit'=>50,'offset'=>$offset,'nextOffset'=>$offset+50<count($rows)?$offset+50:null];
}
function tamasyaHybridOutboxRequeue(string $operation): array {
    $directory=tamasyaHybridOutboxDirectory(); $lock=tamasyaHybridOutboxLock($directory);
    try {
        $path=$directory.'/'.hash('sha256',$operation).'.json';
        if (!is_file($path)) throw new InvalidArgumentException('Job tidak ditemukan.');
        $job=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if ($job['status']!=='dead_letter') throw new InvalidArgumentException('Hanya dead-letter yang dapat dijadwalkan ulang.');
        tamasyaHybridValidate($job['snapshot']);
        $job['status']='pending'; $job['next_attempt_at']=0; $job['max_attempts']=$job['attempts']+5;
        $job['redrives']=($job['redrives']??0)+1;
        tamasyaHybridOutboxSave($path,$job);
        return tamasyaHybridOutboxPublic($job);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function tamasyaHybridOutboxDeliver(string $operation): array {
    if (!tamasyaHqBridgeEnabled()) throw new RuntimeException('HQ bridge belum aktif.');
    if (!tamasyaExternalSideEffectsAllowed()) throw new RuntimeException('Pengiriman hanya oleh Primary aktif dengan lease valid.');
    tamasyaGrowthRequireWriter();
    $url=rtrim(tamasyaMultiPropertyEnv('TAMASYA_HQ_HUB_URL'),'/'); $secret=tamasyaMultiPropertyEnv('TAMASYA_HQ_SHARED_SECRET');
    if (!str_starts_with($url,'https://') || strlen($secret)<32 || !function_exists('curl_init')) throw new RuntimeException('HQ memerlukan URL HTTPS, secret minimal 32 karakter, dan cURL.');
    $directory=tamasyaHybridOutboxDirectory(); $lock=tamasyaHybridOutboxLock($directory);
    try {
        $path=$directory.'/'.hash('sha256',$operation).'.json';
        if (!is_file($path)) throw new InvalidArgumentException('Job tidak ditemukan di properti ini.');
        $job=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if (in_array($job['status'],['acknowledged','dead_letter'],true) || $job['next_attempt_at']>time()) return tamasyaHybridOutboxPublic($job);
        tamasyaHybridValidate($job['snapshot']);
        $identity=tamasyaMultiPropertyIdentity();
        if ($job['company_id']!==$identity['companyId'] || $job['property_id']!==$identity['propertyId']) throw new RuntimeException('Scope job berbeda dari server.');
        // Persist attempt before I/O; a process crash replays the SAME immutable body/id.
        $job['status']='sending'; $job['attempts']++; tamasyaHybridOutboxSave($path,$job);
        try {
            $body=tamasyaHybridJson($job['snapshot']); $ts=time(); $nonce='hq_'.bin2hex(random_bytes(20));
            $signature=hash_hmac('sha256',implode("\n",[$ts,$nonce,$job['company_id'],$job['property_id'],$operation,hash('sha256',$body)]),$secret);
            $ch=curl_init($url.'/api.php?action=property-snapshot');
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Tamasya-Company-ID: '.$job['company_id'],'X-Tamasya-Property-ID: '.$job['property_id'],'X-Tamasya-Operation-ID: '.$operation,'X-Tamasya-Timestamp: '.$ts,'X-Tamasya-Nonce: '.$nonce,'X-Tamasya-Signature: '.$signature]]);
            $response=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); unset($ch);
            $ack=is_string($response) ? json_decode($response,true) : null;
            if ($http===200 && is_array($ack) && ($ack['success'] ?? null)===true && ($ack['status'] ?? '')==='acknowledged' && ($ack['operation_id'] ?? '')===$operation && ($ack['receipt'] ?? '')===$job['checksum']) {
                $job['status']='acknowledged'; $job['receipt']=$ack['receipt']; $job['last_error']=null;
            } else {
                $job['status']=($http>=400 && $http<500 && !in_array($http,[408,429],true)) || $job['attempts']>=($job['max_attempts']??5) ? 'dead_letter' : 'pending';
                $job['last_error']='HTTP '.$http.' / '.substr((string)($ack['code'] ?? 'ACK_NOT_CONFIRMED'),0,80);
                $job['next_attempt_at']=time()+min(3600,30*(2 ** min(6,$job['attempts'])));
            }
        } catch (Throwable $error) {
            $job['status']=$job['attempts']>=($job['max_attempts']??5) ? 'dead_letter' : 'pending'; $job['last_error']='DELIVERY_INTERRUPTED'; $job['next_attempt_at']=time()+60;
        }
        tamasyaHybridOutboxSave($path,$job);
        return tamasyaHybridOutboxPublic($job);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}
