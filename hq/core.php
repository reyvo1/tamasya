<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/hybrid_contract.php';
require_once __DIR__ . '/control_plane.php';
require_once dirname(__DIR__).'/database_tls.php';

final class TamasyaHqError extends RuntimeException {
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus, string $message) { parent::__construct($message); }
}

/**
 * Resolve an optional private outbound CA bundle without weakening TLS verification.
 *
 * Public HTTPS endpoints can omit caFile and use the image/system trust store.
 * Private enterprise endpoints must mount a PEM bundle and configure its absolute
 * path explicitly. Environment variables are deliberately not treated as the
 * application contract because PHP/libcurl deployments can differ in how those
 * variables are inherited.
 */
function tamasyaHqOutboundCaFile(array $transport): ?string {
    if (!array_key_exists('caFile',$transport) || $transport['caFile']===null || $transport['caFile']==='') return null;
    if (!is_string($transport['caFile']) || str_contains($transport['caFile'],"\0")) throw new InvalidArgumentException('TLS_CA_INVALID');
    $raw=trim($transport['caFile']);
    if ($raw==='' || !str_starts_with($raw,DIRECTORY_SEPARATOR)) throw new InvalidArgumentException('TLS_CA_ABSOLUTE_REQUIRED');
    $real=realpath($raw);
    if ($real===false || !is_file($real) || !is_readable($real)) throw new RuntimeException('TLS_CA_UNREADABLE');
    return $real;
}

/**
 * Interpret a delivery response without changing at-most-once semantics.
 *
 * Any transport attempt that does not produce the exact terminal ACK remains
 * uncertain and is never retried automatically.
 */
function tamasyaHqDeliveryAckDecision(array $job,bool $transportOk,int $http,string $response,int $curlErrno=0): array {
    if(!$transportOk) return ['status'=>'uncertain','code'=>'TRANSPORT_ERROR_'.max(0,$curlErrno)];
    if($http!==200) return ['status'=>'uncertain','code'=>'HTTP_'.$http.'_NOT_FINAL'];
    $ack=json_decode($response,true);
    if(!is_array($ack)) return ['status'=>'uncertain','code'=>'ACK_INVALID_JSON'];
    if(($ack['success']??null)!==true) return ['status'=>'uncertain','code'=>'ACK_SUCCESS_FALSE'];
    if(($ack['jobId']??null)!==($job['job_id']??null)) return ['status'=>'uncertain','code'=>'ACK_JOB_MISMATCH'];
    if(($ack['reportId']??null)!==($job['report_id']??null)) return ['status'=>'uncertain','code'=>'ACK_REPORT_MISMATCH'];
    if(($ack['status']??null)!=='delivered') return ['status'=>'uncertain','code'=>'ACK_STATUS_NOT_DELIVERED'];
    return ['status'=>'delivered','code'=>null];
}

function tamasyaHqConfig(): array {
    $path = realpath((string)getenv('TAMASYA_HQ_CONFIG_FILE'));
    $webroot = realpath(dirname(__DIR__));
    if (!$path || !$webroot || str_starts_with(strtolower(str_replace('\\','/',$path)), strtolower(str_replace('\\','/',$webroot)).'/')) throw new RuntimeException('Konfigurasi HQ harus berada di luar document root aplikasi.');
    $config = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !is_array($config['properties'] ?? null) || !is_array($config['viewers'] ?? null) || !str_starts_with((string)($config['dsn'] ?? ''), 'mysql:')) throw new RuntimeException('Konfigurasi HQ tidak lengkap.');
    return defined('TAMASYA_CONTROL_CONFIG_RAW') ? $config : tamasyaControlOverlay($config);
}
function tamasyaHqPdo(array $config): PDO {
    $tls=tamasyaDatabaseTlsOptions($config['databaseTls']??[]);
    $pdo = new PDO($config['dsn'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]+$tls);
    tamasyaAssertDatabaseTls($pdo,$tls);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
}
function tamasyaHqAuthenticateSnapshot(array $config, array $s, string $body, array $headers, int $now): array {
    $property = $config['properties'][$s['companyId']][$s['propertyId']] ?? null;
    if (!is_array($property) || ($property['enabled'] ?? false) !== true || !is_string($property['secret'] ?? null) || strlen($property['secret']) < 32) throw new TamasyaHqError('PROPERTY_NOT_AUTHORIZED',403,'Properti tidak diizinkan.');
    $ts = (string)($headers['HTTP_X_TAMASYA_TIMESTAMP'] ?? '');
    $nonce = (string)($headers['HTTP_X_TAMASYA_NONCE'] ?? '');
    $operation = (string)($headers['HTTP_X_TAMASYA_OPERATION_ID'] ?? '');
    if (!preg_match('/^[0-9]{10}$/D',$ts) || abs($now-(int)$ts)>300 || !preg_match('/^[a-zA-Z0-9_-]{20,80}$/D',$nonce) || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D',$operation)) throw new TamasyaHqError('INVALID_ENVELOPE',401,'Envelope atau waktu pengiriman tidak valid.');
    if (($headers['HTTP_X_TAMASYA_PROPERTY_ID'] ?? '') !== $s['propertyId'] || ($headers['HTTP_X_TAMASYA_COMPANY_ID'] ?? '') !== $s['companyId']) throw new TamasyaHqError('SCOPE_MISMATCH',403,'Scope header tidak cocok.');
    $hash = hash('sha256',$body);
    $canonical = implode("\n", [$ts,$nonce,$s['companyId'],$s['propertyId'],$operation,$hash]);
    if (!hash_equals(hash_hmac('sha256',$canonical,$property['secret']), (string)($headers['HTTP_X_TAMASYA_SIGNATURE'] ?? ''))) throw new TamasyaHqError('SIGNATURE_INVALID',401,'Signature tidak cocok.');
    return ['operationId'=>$operation,'nonce'=>$nonce,'payloadHash'=>$hash];
}
function tamasyaHqReceive(PDO $pdo, array $s, array $envelope): array {
    tamasyaHybridValidate($s);
    $company=$s['companyId']; $property=$s['propertyId']; $op=$envelope['operationId']; $checksum=$s['checksumSha256'];
    $pdo->beginTransaction();
    try {
        // A dedicated HQ lock serializes receipts and head advancement for this property.
        $q=$pdo->prepare('INSERT IGNORE INTO hq_property_locks(company_id,property_id) VALUES (?,?)'); $q->execute([$company,$property]);
        $q=$pdo->prepare('SELECT property_id FROM hq_property_locks WHERE company_id=? AND property_id=? FOR UPDATE'); $q->execute([$company,$property]); $q->fetch();
        $q=$pdo->prepare('SELECT payload_hash,checksum FROM hq_receipts WHERE company_id=? AND property_id=? AND operation_id=?'); $q->execute([$company,$property,$op]); $receipt=$q->fetch();
        if ($receipt) {
            if (!hash_equals($receipt['payload_hash'],$envelope['payloadHash'])) throw new TamasyaHqError('OPERATION_PAYLOAD_CONFLICT',409,'Operation ID sudah dipakai untuk payload berbeda.');
            $pdo->commit();
            return ['success'=>true,'code'=>'SNAPSHOT_ACK','status'=>'acknowledged','retryable'=>false,'operation_id'=>$op,'receipt'=>$receipt['checksum'],'duplicate'=>true];
        }
        $q=$pdo->prepare('SELECT nonce FROM hq_nonces WHERE company_id=? AND property_id=? AND nonce=?'); $q->execute([$company,$property,$envelope['nonce']]);
        if ($q->fetch()) throw new TamasyaHqError('NONCE_REPLAY',409,'Nonce sudah dipakai.');
        $q=$pdo->prepare('SELECT s.source_revision,s.checksum FROM hq_heads h JOIN hq_snapshots s ON s.checksum=h.checksum WHERE h.company_id=? AND h.property_id=? AND h.period_from=? AND h.period_to=?');
        $q->execute([$company,$property,$s['period']['from'],$s['period']['to']]); $head=$q->fetch();
        if ($head && (int)$head['source_revision'] > $s['sourceRevision']) throw new TamasyaHqError('STALE_SOURCE_REVISION',409,'Revisi sumber lebih lama dari snapshot pusat.');
        if ($head && (int)$head['source_revision'] === $s['sourceRevision'] && $head['checksum'] !== $checksum) throw new TamasyaHqError('SOURCE_REVISION_CONFLICT',409,'Revisi sama mempunyai isi berbeda; periksa sumber hotel.');
        $q=$pdo->prepare('INSERT IGNORE INTO hq_snapshots(checksum,company_id,property_id,period_from,period_to,source_revision,body) VALUES (?,?,?,?,?,?,?)');
        $q->execute([$checksum,$company,$property,$s['period']['from'],$s['period']['to'],$s['sourceRevision'],tamasyaHybridJson($s)]);
        $q=$pdo->prepare('INSERT INTO hq_heads(company_id,property_id,period_from,period_to,checksum) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE checksum=VALUES(checksum)'); $q->execute([$company,$property,$s['period']['from'],$s['period']['to'],$checksum]);
        $q=$pdo->prepare('UPDATE hq_property_locks SET event_revision=event_revision+1 WHERE company_id=? AND property_id=?'); $q->execute([$company,$property]);
        $q=$pdo->prepare('INSERT INTO hq_receipts(company_id,property_id,operation_id,payload_hash,checksum) VALUES (?,?,?,?,?)'); $q->execute([$company,$property,$op,$envelope['payloadHash'],$checksum]);
        $q=$pdo->prepare('INSERT INTO hq_nonces(company_id,property_id,nonce) VALUES (?,?,?)'); $q->execute([$company,$property,$envelope['nonce']]);
        $pdo->commit();
        return ['success'=>true,'code'=>'SNAPSHOT_ACK','status'=>'acknowledged','retryable'=>false,'operation_id'=>$op,'receipt'=>$checksum,'duplicate'=>false];
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
function tamasyaHqViewer(array $config, string $authorization): array {
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/D',$authorization,$match)) throw new TamasyaHqError('AUTH_REQUIRED',401,'Masukkan token akses HQ.');
    $hash=hash('sha256',$match[1]);
    foreach ($config['viewers'] as $viewer) {
        if (!is_array($viewer) || !is_string($viewer['tokenSha256'] ?? null) || !hash_equals($viewer['tokenSha256'],$hash)) continue;
        if (($viewer['enabled'] ?? false) !== true || !tamasyaHybridId($viewer['companyId'] ?? null) || !is_array($viewer['propertyIds'] ?? null) || !array_is_list($viewer['propertyIds']) || !$viewer['propertyIds'] || count($viewer['propertyIds']) > 50) break;
        foreach ($viewer['propertyIds'] as $id) if (!tamasyaHybridId($id) || ($config['properties'][$viewer['companyId']][$id]['enabled'] ?? false) !== true) throw new TamasyaHqError('GRANT_INVALID',403,'Grant properti tidak valid.');
        return $viewer;
    }
    throw new TamasyaHqError('ACCESS_DENIED',403,'Token atau grant HQ tidak diizinkan.');
}
function tamasyaHqRead(PDO $pdo, array $viewer, array $requested, string $from, string $to): array {
    if (!$requested) $requested=$viewer['propertyIds'];
    foreach ($requested as $id) if (!is_string($id) || !in_array($id,$viewer['propertyIds'],true)) throw new TamasyaHqError('PROPERTY_SCOPE_DENIED',403,'Pilihan properti berada di luar grant.');
    $requested=array_values(array_unique($requested));
    if (count($requested)>50) throw new InvalidArgumentException('Maksimal 50 properti per laporan.');
    tamasyaHybridRange($from,$to);
    $in=implode(',',array_fill(0,count($requested),'?'));
    $q=$pdo->prepare("SELECT s.body,s.received_at FROM hq_heads h JOIN hq_snapshots s ON s.checksum=h.checksum WHERE h.company_id=? AND h.property_id IN ($in) AND h.period_from=? AND h.period_to=? ORDER BY h.property_id");
    $q->execute(array_merge([$viewer['companyId']],$requested,[$from,$to]));
    $snapshots=[]; $received=[];
    foreach ($q->fetchAll() as $row) { $s=json_decode($row['body'],true,32,JSON_THROW_ON_ERROR); $snapshots[]=$s; $received[$s['propertyId']]=$row['received_at'].'Z'; }
    return ['report'=>tamasyaHybridConsolidate($snapshots,$requested,$viewer['companyId'],$from,$to),'receivedAt'=>$received];
}
