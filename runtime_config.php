<?php
declare(strict_types=1);

// Public runtime configuration only. Never add passwords, tokens, or secrets.
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database_bootstrap.php';

$normalizeApiUrl = static function ($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (!preg_match('#^https?://#i', $value)) return '';
    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['host'])) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http','https'], true)) return '';
    $path = rtrim((string)($parts['path'] ?? ''), '/');
    if (!preg_match('#/api\.php$#i', $path)) $path .= '/api.php';
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    return $scheme . '://' . strtolower((string)$parts['host']) . $port . $path;
};

$onlineApi = $normalizeApiUrl(
    getenv('VITE_TAMASYA_ONLINE_API_URL')
    ?: getenv('VITE_PUBLIC_API_URL')
    ?: getenv('APP_URL')
    ?: ''
);
$localApi = $normalizeApiUrl(getenv('VITE_TAMASYA_LOCAL_API_URL') ?: '');

$envFlag = static function (string $name, bool $default = false): bool {
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') return $default;
    return in_array(strtolower(trim((string)$value)), ['1','true','yes','on','enabled'], true);
};

$config = [
    'propertyTimezone' => (string)(getenv('APP_TIMEZONE') ?: ''),
    'VITE_TAMASYA_ONLINE_API_URL' => $onlineApi,
    'VITE_TAMASYA_LOCAL_API_URL' => $localApi,
    // Public booleans only. These are feature-availability hints for the UI,
    // never secrets and never an authorization substitute. API routes still
    // enforce role/permission server-side.
    'features' => [
        'growthSuiteEnabled' => $envFlag('TAMASYA_GROWTH_SUITE_ENABLED', false),
        'enterpriseCompletionEnabled' => $envFlag('TAMASYA_ENTERPRISE_COMPLETION_ENABLED', false),
        'multiPropertyFoundationEnabled' => $envFlag('TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED', false),
    ],
    'runtimeConfigVersion' => 3,
];

echo 'window.TAMASYA_RUNTIME_CONFIG=' . json_encode(
    $config,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) . ';';
