<?php
declare(strict_types=1);

/**
 * Shared secret-key resolver used by the API and the maintenance utility.
 * This file is include-only and must not be exposed directly by the web server.
 */
if (PHP_SAPI !== 'cli' && !defined('TAMASYA_API_ENTRY') && !defined('TAMASYA_MAINTENANCE_ENTRY')) {
    http_response_code(404);
    exit;
}

function tamasyaSecretEncryptionKey(): ?string {
    $raw = trim((string)(getenv('APP_ENCRYPTION_KEY') ?: ''));
    if ($raw === '') return null;
    if (str_starts_with($raw, 'base64:')) {
        $decoded = base64_decode(substr($raw, 7), true);
        // A malformed value prefixed with base64: must fail closed. Falling
        // through and hashing the literal malformed text would silently derive
        // a different key and make existing encrypted configuration unreadable.
        if ($decoded === false || strlen($decoded) < 32) return null;
        return substr($decoded, 0, 32);
    }
    return hash('sha256', $raw, true);
}

function tamasyaSecretEncryptionConfigurationStatus(): array {
    $appEnv=strtolower(trim((string)(getenv('APP_ENV')?:'production')));
    $raw=trim((string)(getenv('APP_ENCRYPTION_KEY')?:''));
    $required=in_array($appEnv,['staging','production','prod'],true);
    $error=null;
    if($required && $raw==='') $error='APP_ENCRYPTION_KEY wajib diisi pada staging/production.';
    elseif($raw!=='' && str_starts_with($raw,'base64:')){
        $decoded=base64_decode(substr($raw,7),true);
        if($decoded===false || strlen($decoded)<32)$error='APP_ENCRYPTION_KEY base64 harus valid dan memuat minimal 32 byte random.';
    }elseif($required && $raw!=='' && strlen($raw)<32){
        $error='APP_ENCRYPTION_KEY non-base64 minimal 32 karakter pada staging/production.';
    }
    return ['ok'=>$error===null,'required'=>$required,'environment'=>$appEnv,'error'=>$error];
}

function tamasyaAssertSecretEncryptionConfigured(): void {
    $status=tamasyaSecretEncryptionConfigurationStatus();
    if(empty($status['ok'])) throw new RuntimeException((string)$status['error']);
}
