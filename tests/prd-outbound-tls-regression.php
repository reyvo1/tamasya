<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/hq/core.php';

$passed=0;
function checkTls(string $name, callable $fn): void {
    global $passed;
    try { $fn(); $passed++; echo "PASS {$name}\n"; }
    catch (Throwable $e) { fwrite(STDERR,"FAIL {$name}: {$e->getMessage()}\n"); exit(1); }
}

checkTls('private CA resolver accepts readable absolute file', function(): void {
    $tmp=tempnam(sys_get_temp_dir(),'tamasya-ca-');
    if($tmp===false) throw new RuntimeException('temp file failed');
    file_put_contents($tmp,"-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----\n");
    try {
        $resolved=tamasyaHqOutboundCaFile(['caFile'=>$tmp]);
        if($resolved!==realpath($tmp)) throw new RuntimeException('private CA resolution mismatch');
    } finally { @unlink($tmp); }
});

checkTls('private CA resolver rejects relative paths', function(): void {
    try { tamasyaHqOutboundCaFile(['caFile'=>'relative/ca.crt']); }
    catch (InvalidArgumentException $e) {
        if($e->getMessage()!=='TLS_CA_ABSOLUTE_REQUIRED') throw $e;
        return;
    }
    throw new RuntimeException('relative CA path accepted');
});

checkTls('private CA resolver rejects unreadable or missing path', function(): void {
    $missing=sys_get_temp_dir().'/tamasya-ca-missing-'.bin2hex(random_bytes(5)).'.crt';
    try { tamasyaHqOutboundCaFile(['caFile'=>$missing]); }
    catch (RuntimeException $e) {
        if($e->getMessage()!=='TLS_CA_UNREADABLE') throw $e;
        return;
    }
    throw new RuntimeException('missing CA path accepted');
});

checkTls('delivery ACK decision accepts only exact terminal ACK', function(): void {
    $job=['job_id'=>'job-1','report_id'=>'report-1'];
    $ok=tamasyaHqDeliveryAckDecision($job,true,200,'{"success":true,"jobId":"job-1","reportId":"report-1","status":"delivered"}',0);
    if(($ok['status']??'')!=='delivered'||array_key_exists('code',$ok)&&$ok['code']!==null) throw new RuntimeException(json_encode($ok));
    $accepted=tamasyaHqDeliveryAckDecision($job,true,202,'{"success":true,"jobId":"job-1","reportId":"report-1","status":"accepted"}',0);
    if(($accepted['status']??'')!=='uncertain'||($accepted['code']??'')!=='HTTP_202_NOT_FINAL') throw new RuntimeException(json_encode($accepted));
    $wrong=tamasyaHqDeliveryAckDecision($job,true,200,'{"success":true,"jobId":"job-x","reportId":"report-1","status":"delivered"}',0);
    if(($wrong['status']??'')!=='uncertain'||($wrong['code']??'')!=='ACK_JOB_MISMATCH') throw new RuntimeException(json_encode($wrong));
    $transport=tamasyaHqDeliveryAckDecision($job,false,0,'',60);
    if(($transport['status']??'')!=='uncertain'||($transport['code']??'')!=='TRANSPORT_ERROR_60') throw new RuntimeException(json_encode($transport));
});

checkTls('delivery and object storage keep peer and hostname verification enabled with optional CURLOPT_CAINFO', function() use ($root): void {
    foreach(['hq/delivery.php','hq/object_storage.php'] as $file){
        $src=file_get_contents($root.'/'.$file);
        if(!str_contains($src,'CURLOPT_SSL_VERIFYPEER=>true')) throw new RuntimeException("$file peer verification missing");
        if(!str_contains($src,'CURLOPT_SSL_VERIFYHOST=>2')) throw new RuntimeException("$file hostname verification missing");
        if(!str_contains($src,'CURLOPT_CAINFO')) throw new RuntimeException("$file explicit CA support missing");
        if(str_contains($src,'CURLOPT_SSL_VERIFYPEER=>false')||str_contains($src,'CURLOPT_SSL_VERIFYHOST=>0')) throw new RuntimeException("$file weak TLS verification found");
    }
});

checkTls('GitHub external adapter UAT proves application-level private CA rather than environment-only trust', function() use ($root): void {
    $wf=file_get_contents($root.'/.github/workflows/tamasya-enterprise-rc1-uat.yml');
    foreach([
        '"caFile":"/run/certs/ca.crt"',
        '"caFile":"/run/certs/missing-ca.crt"',
        'php tests/prd-outbound-tls-regression.php',
    ] as $needle) if(!str_contains($wf,$needle)) throw new RuntimeException('workflow TLS contract missing: '.$needle);
    if(str_contains($wf,'-e CURL_CA_BUNDLE=/run/certs/ca.crt')||str_contains($wf,'-e SSL_CERT_FILE=/run/certs/ca.crt')) {
        throw new RuntimeException('external adapter still depends on environment-only CA injection');
    }
});

echo "{$passed} passed; 0 failed\n";
