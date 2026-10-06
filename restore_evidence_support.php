<?php
declare(strict_types=1);
/**
 * Cryptographic evidence for an actual isolated restore verification.
 *
 * The verifier computes the SHA256 of the exact SQL backup file, verifies the
 * completion marker, checks the isolated restored DB against the canonical
 * baseline and deployment property identity, then signs a short-lived evidence
 * token with SECURITY_EVENT_HASH_KEY. The live application accepts
 * backup-restore-tested only when this token verifies and matches the backup row.
 */

function tamasyaRestoreEvidenceKey(): string {
    $key=(string)(getenv('SECURITY_EVENT_HASH_KEY')?:'');
    if(strlen($key)<32) throw new RuntimeException('SECURITY_EVENT_HASH_KEY minimal 32 karakter diperlukan untuk bukti restore.');
    return $key;
}

function tamasyaRestoreEvidenceB64Encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
}

function tamasyaRestoreEvidenceB64Decode(string $raw): string|false {
    if($raw==='' || preg_match('/[^A-Za-z0-9_-]/',$raw)) return false;
    $padded=$raw.str_repeat('=',(4-(strlen($raw)%4))%4);
    return base64_decode(strtr($padded,'-_','+/'),true);
}

function tamasyaRestoreEvidencePropertyId(): string {
    return strtolower(trim((string)(getenv('TAMASYA_PROPERTY_ID')?:getenv('APP_PROPERTY_ID')?:'')));
}

function tamasyaRestoreEvidenceIssue(array $claims): string {
    $required=['propertyId','backupSha256','restoredDatabase','canonicalSqlSha256'];
    foreach($required as $field){
        if(trim((string)($claims[$field]??''))==='') throw new RuntimeException('Claim restore evidence kosong: '.$field);
    }
    $payload=[
        'v'=>1,
        'issuedAt'=>time(),
        'propertyId'=>strtolower(trim((string)$claims['propertyId'])),
        'companyId'=>strtolower(trim((string)($claims['companyId']??''))),
        'backupSha256'=>strtolower(trim((string)$claims['backupSha256'])),
        'restoredDatabase'=>trim((string)$claims['restoredDatabase']),
        'canonicalSqlSha256'=>strtolower(trim((string)$claims['canonicalSqlSha256'])),
        'expectedCoreTableCount'=>(int)($claims['expectedCoreTableCount']??0),
        'expectedTriggerCount'=>(int)($claims['expectedTriggerCount']??0),
        'release'=>(string)($claims['release']??''),
        'patchLevel'=>(string)($claims['patchLevel']??''),
    ];
    if(!preg_match('/^[a-f0-9]{64}$/',$payload['backupSha256'])) throw new RuntimeException('SHA256 backup untuk evidence tidak valid.');
    if(!preg_match('/^[a-f0-9]{64}$/',$payload['canonicalSqlSha256'])) throw new RuntimeException('SHA256 canonical SQL untuk evidence tidak valid.');
    if($payload['propertyId']==='') throw new RuntimeException('TAMASYA_PROPERTY_ID wajib untuk restore evidence.');
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($json)) throw new RuntimeException('Restore evidence tidak dapat dienkode.');
    $encoded=tamasyaRestoreEvidenceB64Encode($json);
    $signature=hash_hmac('sha256','v1.'.$encoded,tamasyaRestoreEvidenceKey());
    return 'v1.'.$encoded.'.'.$signature;
}

function tamasyaRestoreEvidenceVerify(string $token,array $expected=[],int $maxAgeSeconds=172800): array {
    $parts=explode('.',$token);
    if(count($parts)!==3 || $parts[0]!=='v1') throw new InvalidArgumentException('Restore evidence token tidak valid.');
    [$version,$encoded,$signature]=$parts;
    if(!preg_match('/^[a-f0-9]{64}$/',$signature)) throw new InvalidArgumentException('Signature restore evidence tidak valid.');
    $expectedSig=hash_hmac('sha256',$version.'.'.$encoded,tamasyaRestoreEvidenceKey());
    if(!hash_equals($expectedSig,$signature)) throw new InvalidArgumentException('Signature restore evidence tidak cocok.');
    $json=tamasyaRestoreEvidenceB64Decode($encoded);
    if($json===false) throw new InvalidArgumentException('Payload restore evidence tidak valid.');
    $payload=json_decode($json,true);
    if(!is_array($payload) || (int)($payload['v']??0)!==1) throw new InvalidArgumentException('Versi restore evidence tidak didukung.');
    $issued=(int)($payload['issuedAt']??0);
    $now=time();
    if($issued<=0 || $issued>$now+300) throw new InvalidArgumentException('Waktu restore evidence tidak valid.');
    if($maxAgeSeconds>0 && ($now-$issued)>$maxAgeSeconds) throw new InvalidArgumentException('Restore evidence sudah kedaluwarsa; jalankan restore_drill_verify.php lagi.');

    $normalizers=[
        'propertyId'=>static fn($v)=>strtolower(trim((string)$v)),
        'companyId'=>static fn($v)=>strtolower(trim((string)$v)),
        'backupSha256'=>static fn($v)=>strtolower(trim((string)$v)),
        'restoredDatabase'=>static fn($v)=>trim((string)$v),
        'canonicalSqlSha256'=>static fn($v)=>strtolower(trim((string)$v)),
    ];
    foreach($expected as $field=>$value){
        if($value===null || $value==='') continue;
        $actual=$payload[$field]??null;
        $normalize=$normalizers[$field]??static fn($v)=>(string)$v;
        if(!hash_equals((string)$normalize($value),(string)$normalize($actual))) throw new InvalidArgumentException('Restore evidence tidak cocok pada field '.$field.'.');
    }
    return $payload;
}
