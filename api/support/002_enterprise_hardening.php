<?php
/**
 * Enterprise hardening boundary for the canonical PHP runtime.
 *
 * This module is intentionally dependency-free so it can run on shared hosting.
 * It centralizes transport validation, trusted proxy handling, request limits,
 * password policy, account lockout, session idle policy, and endpoint throttling.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaEnvBool(string $name, bool $default = false): bool {
    $raw = getenv($name);
    if ($raw === false || trim((string)$raw) === '') return $default;
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}

function tamasyaEnvironmentName(): string {
    return strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
}

function tamasyaIsProductionEnvironment(): bool {
    return in_array(tamasyaEnvironmentName(), ['production','prod'], true);
}

function tamasyaSecurityJsonError(int $status, string $code, string $message, array $extra = []): void {
    if (headers_sent()) return;
    tamasyaDiscardOutputBuffers();
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo tamasyaJsonEncode([
        'success'=>false,
        'code'=>$code,
        'error'=>$message,
        'requestId'=>function_exists('tamasyaRuntimeRequestId') ? tamasyaRuntimeRequestId() : null,
    ] + $extra);
    exit;
}

function tamasyaEnterpriseApplyApiHeaders(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Resource-Policy: same-site');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'; base-uri \'none\'; form-action \'none\'');
}

function tamasyaNormalizeHost(string $host): string {
    $host = strtolower(trim($host));
    if ($host === '') return '';
    if ($host[0] === '[') {
        $end = strpos($host, ']');
        return $end === false ? '' : substr($host, 1, $end - 1);
    }
    $parts = explode(':', $host, 2);
    return rtrim($parts[0], '.');
}

function tamasyaConfiguredAllowedHosts(): array {
    $values = [];
    $raw = trim((string)(getenv('APP_ALLOWED_HOSTS') ?: ''));
    if ($raw !== '') $values = preg_split('/[,;\s]+/', $raw) ?: [];
    foreach (['APP_URL','PUBLIC_SITE_URL'] as $envName) {
        $url = trim((string)(getenv($envName) ?: ''));
        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') $values[] = $host;
        }
    }
    $originRaw = trim((string)(getenv('APP_ALLOWED_ORIGINS') ?: ''));
    foreach (array_filter(array_map('trim', explode(',', $originRaw))) as $origin) {
        $host = parse_url($origin, PHP_URL_HOST);
        if (is_string($host) && $host !== '') $values[] = $host;
    }
    $result = [];
    foreach ($values as $value) {
        $host = tamasyaNormalizeHost((string)$value);
        if ($host !== '' && (filter_var($host, FILTER_VALIDATE_IP) || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host))) {
            $result[$host] = true;
        }
    }
    return array_keys($result);
}

function tamasyaEnterpriseValidateHost(): void {
    $allowed = tamasyaConfiguredAllowedHosts();
    $explicit = getenv('APP_ENFORCE_ALLOWED_HOSTS');
    $enforce = $explicit !== false && trim((string)$explicit) !== ''
        ? tamasyaEnvBool('APP_ENFORCE_ALLOWED_HOSTS', false)
        : (tamasyaIsProductionEnvironment() && count($allowed) > 0);
    if (!$enforce) return;
    if (!$allowed) {
        tamasyaSecurityJsonError(503, 'HOST_POLICY_NOT_CONFIGURED', 'APP_ALLOWED_HOSTS atau APP_URL wajib dikonfigurasi pada production.');
    }
    $host = tamasyaNormalizeHost((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '' || !in_array($host, $allowed, true)) {
        tamasyaSecurityJsonError(421, 'MISDIRECTED_REQUEST', 'Host request tidak diizinkan.');
    }
}

function tamasyaAllowedOrigins(): array {
    $raw = trim((string)(getenv('APP_ALLOWED_ORIGINS') ?: ''));
    $candidates = array_filter(array_map('trim', explode(',', $raw)));
    // PUBLIC_SITE_URL is itself an explicit deployment trust decision. Include
    // it in CORS automatically so the public website cannot be broken merely
    // because the same origin was forgotten in the duplicate allow-list ENV.
    $publicSiteUrl = trim((string)(getenv('PUBLIC_SITE_URL') ?: ''));
    if ($publicSiteUrl !== '') $candidates[] = $publicSiteUrl;
    $origins = [];
    foreach ($candidates as $origin) {
        $parts = parse_url($origin);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) continue;
        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['https','http'], true)) continue;
        $normalized = $scheme . '://' . strtolower((string)$parts['host']);
        if (!empty($parts['port'])) $normalized .= ':' . (int)$parts['port'];
        $origins[$normalized] = true;
    }
    return array_keys($origins);
}

function tamasyaNormalizeOrigin(string $origin): string {
    $parts = parse_url(trim($origin));
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['https','http'], true)) return '';
    $normalized = $scheme . '://' . strtolower((string)$parts['host']);
    if (!empty($parts['port'])) $normalized .= ':' . (int)$parts['port'];
    return $normalized;
}

function tamasyaEnterpriseValidateOrigin(): ?string {
    $origin = tamasyaNormalizeOrigin((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return null;
    $allowed = tamasyaAllowedOrigins();
    if (!in_array($origin, $allowed, true)) {
        tamasyaSecurityJsonError(403, 'ORIGIN_NOT_ALLOWED', 'Origin request tidak diizinkan.');
    }
    return $origin;
}

function tamasyaIpMatchesCidr(string $ip, string $cidr): bool {
    $cidr = trim($cidr);
    if ($cidr === '') return false;
    if (!str_contains($cidr, '/')) return hash_equals(strtolower($cidr), strtolower($ip));
    [$network, $prefixRaw] = explode('/', $cidr, 2);
    if (!ctype_digit($prefixRaw)) return false;
    $ipBin = @inet_pton($ip);
    $networkBin = @inet_pton($network);
    if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) return false;
    $maxBits = strlen($ipBin) * 8;
    $prefix = (int)$prefixRaw;
    if ($prefix < 0 || $prefix > $maxBits) return false;
    $fullBytes = intdiv($prefix, 8);
    $remaining = $prefix % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) return false;
    if ($remaining === 0) return true;
    $mask = (0xFF << (8 - $remaining)) & 0xFF;
    return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
}

function tamasyaTrustedProxy(string $remoteAddress): bool {
    $raw = trim((string)(getenv('APP_TRUSTED_PROXIES') ?: ''));
    if ($raw === '' || !filter_var($remoteAddress, FILTER_VALIDATE_IP)) return false;
    foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $candidate) {
        if (tamasyaIpMatchesCidr($remoteAddress, trim((string)$candidate))) return true;
    }
    return false;
}

function tamasyaClientIp(): string {
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!filter_var($remote, FILTER_VALIDATE_IP)) $remote = '0.0.0.0';
    if (!tamasyaTrustedProxy($remote)) return $remote;
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    foreach (array_map('trim', explode(',', $forwarded)) as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
    }
    $realIp = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    return filter_var($realIp, FILTER_VALIDATE_IP) ? $realIp : $remote;
}

function tamasyaEnterpriseGlobalRequestLimit(): int {
    $configured = (int)(getenv('APP_MAX_REQUEST_BYTES') ?: 12582912);
    return max(65536, min(33554432, $configured));
}

function tamasyaEnterpriseAssertContentLength(): void {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $limit = tamasyaEnterpriseGlobalRequestLimit();
    if ($length > $limit) {
        tamasyaSecurityJsonError(413, 'REQUEST_TOO_LARGE', 'Ukuran request melebihi batas server.', ['maxBytes'=>$limit]);
    }
}

function tamasyaEnterpriseActionBodyLimit(string $action): int {
    $publicSmall = ['public-reservation-request','public-contact','public-help-chat','public-help-chat-sync'];
    if (in_array($action, $publicSmall, true)) return 524288;
    if (in_array($action, ['telegram-webhook','communication-webhook','node-cluster-channel-webhook','node-cluster-telegram-webhook'], true)) return 2097152;
    if (in_array($action, ['biometric-device-webhook','biometric-attendance','bookings'], true)) return tamasyaEnterpriseGlobalRequestLimit();
    return min(tamasyaEnterpriseGlobalRequestLimit(), 4194304);
}

function tamasyaEnterpriseValidateParsedRequest(string $action, string $rawBody, $decodedInput): array {
    if ($action !== '' && !preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $action)) {
        tamasyaSecurityJsonError(400, 'INVALID_ACTION', 'Nama action API tidak valid.');
    }
    if (count($_GET) > 50) tamasyaSecurityJsonError(400, 'TOO_MANY_QUERY_PARAMETERS', 'Parameter query terlalu banyak.');
    foreach ($_GET as $key => $value) {
        if (!is_string($key) || strlen($key) > 80 || is_array($value)) {
            tamasyaSecurityJsonError(400, 'INVALID_QUERY_PARAMETER', 'Format parameter query tidak valid.');
        }
        if (strlen((string)$value) > 4096) tamasyaSecurityJsonError(400, 'QUERY_PARAMETER_TOO_LONG', 'Nilai parameter query terlalu panjang.');
    }
    $length = strlen($rawBody);
    $limit = tamasyaEnterpriseActionBodyLimit($action);
    if ($length > $limit) tamasyaSecurityJsonError(413, 'ACTION_PAYLOAD_TOO_LARGE', 'Payload endpoint melebihi batas.', ['maxBytes'=>$limit]);

    if ($rawBody !== '') {
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        $requireJson = tamasyaEnvBool('APP_REQUIRE_JSON_CONTENT_TYPE', tamasyaIsProductionEnvironment());
        if ($requireJson && $contentType !== 'application/json') {
            tamasyaSecurityJsonError(415, 'JSON_CONTENT_TYPE_REQUIRED', 'Content-Type application/json wajib digunakan.');
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            tamasyaSecurityJsonError(400, 'MALFORMED_JSON', 'Payload JSON tidak valid.');
        }
        if (!is_array($decodedInput)) tamasyaSecurityJsonError(400, 'JSON_OBJECT_REQUIRED', 'Payload JSON harus berupa object.');
    }
    return is_array($decodedInput) ? $decodedInput : [];
}

function tamasyaEnterpriseResolveMethodOverride(array $input): void {
    $original = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $headerOverride = trim((string)($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));
    $bodyOverride = trim((string)($input['_method'] ?? ''));
    $queryOverride = trim((string)($_GET['_method'] ?? ''));
    $override = $headerOverride !== '' ? $headerOverride : ($bodyOverride !== '' ? $bodyOverride : $queryOverride);
    if ($override === '') return;
    if ($original !== 'POST') tamasyaSecurityJsonError(400, 'INVALID_METHOD_OVERRIDE', 'Method override hanya boleh dikirim melalui POST.');
    if ($queryOverride !== '' && tamasyaIsProductionEnvironment()) {
        tamasyaSecurityJsonError(400, 'QUERY_METHOD_OVERRIDE_DISABLED', 'Method override melalui query dinonaktifkan pada production.');
    }
    $override = strtoupper($override);
    if (!in_array($override, ['PUT','PATCH','DELETE'], true)) tamasyaSecurityJsonError(400, 'INVALID_METHOD_OVERRIDE', 'Method override tidak diizinkan.');
    $_SERVER['REQUEST_METHOD'] = $override;
}



/** Runtime V137 never mutates core schema. */
function tamasyaRejectUnsafeHttpMethods(): void {
    $method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
    if (in_array($method, ['TRACE','TRACK','CONNECT'], true)) {
        header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        tamasyaSecurityJsonError(405, 'HTTP_METHOD_NOT_ALLOWED', 'Metode HTTP tidak diizinkan.');
    }
}

function tamasyaProductionSchemaRequirements(): array {
    $tables = [
        'schema_migrations','schema_release_state','config','property_settings','hotel_operational_settings','staff','rooms','bookings','transactions',
        'request_operation_receipts','security_events','audit_logs','user_sessions',
        'runtime_request_events','public_support_conversations','public_support_messages',
        'public_site_settings','public_room_types','public_promotions','public_site_media',
        'pos_categories','pos_products','pos_sales','pos_sale_items',
        'pos_stock_movements','pos_print_logs','pos_delivery_events',
        'journal_entries','journal_lines',
        'lost_found_items','maintenance_cancellation_reviews','operational_entity_links','operational_incidents','guest_service_requests','room_operational_holds',
    ];
    if (function_exists('tamasyaNodeSyncEnabled') && tamasyaNodeSyncEnabled()) {
        array_push($tables,'node_sync_settings','node_sync_outbox','node_sync_conflicts','node_sync_runs','node_sync_nonces','node_sync_receipts');
    }
    if (function_exists('tamasyaClusterEnabled') && tamasyaClusterEnabled()) {
        array_push($tables,'node_cluster_state','node_cluster_members','node_cluster_events');
    }
    return [
        'markers' => [
            '2026.08.15.v137-r6-reservation-checkin-inventory-separation.1',
            '2026.08.15.v137-r7-cross-menu-state-authority.1',
        ],
        'tables' => array_values(array_unique($tables)),
        'columns' => [
            'hotel_operational_settings' => ['require_payment_before_key_issue'],
            'guest_service_requests' => [
                'context_type','guest_name_snapshot','requester_name','requester_contact','location_label',
                'request_channel','needed_at','context_verified_at','context_verified_by',
            ],
        ],
        'columnDefaults' => [
            'bookings' => ['status'=>'reserved'],
        ],
        'triggers' => [
            'trg_transactions_tax_snapshot_bi','trg_transactions_version_bu',
            'trg_transaction_allocations_ai','trg_transaction_allocations_au','trg_transaction_allocations_ad',
        ],
    ];
}

/**
 * Runtime trigger decision. Direct metadata is preferred when the runtime has
 * permission to inspect it. A deliberately DML-only identity delegates trigger
 * integrity to migration authority only when exact canonical source attestation
 * is present. Unknown inspection failures never become a PASS.
 */
function tamasyaRuntimeTriggerVerificationDecision(array $inspection, ?array $releaseState, string $expectedSourceChecksum): array {
    $visibility=(string)($inspection['visibility']??'unknown');
    $missing=array_values(array_map('strval',(array)($inspection['missing']??[])));
    if($visibility==='visible')return ['ok'=>count($missing)===0,'mode'=>'runtime_metadata','delegated'=>false,'metadataVisible'=>true,'missingTriggers'=>$missing,'reason'=>(string)($inspection['reason']??'runtime_metadata')];
    if($visibility==='hidden'){
        $actual=strtolower(trim((string)($releaseState['source_checksum']??'')));$runId=trim((string)($releaseState['migration_run_id']??''));$expected=strtolower(trim($expectedSourceChecksum));
        $attested=$expected!==''&&preg_match('/^[a-f0-9]{64}$/',$actual)===1&&hash_equals($expected,$actual)&&$runId!=='';
        return ['ok'=>$attested,'mode'=>'migration_authority_attestation','delegated'=>true,'metadataVisible'=>false,'missingTriggers'=>[],'reason'=>$attested?'canonical_source_attestation_matches':'canonical_source_attestation_missing_or_mismatch'];
    }
    return ['ok'=>false,'mode'=>'unverified','delegated'=>false,'metadataVisible'=>null,'missingTriggers'=>$missing,'reason'=>(string)($inspection['reason']??'trigger_metadata_unknown')];
}

function tamasyaProductionSchemaStatus(PDO $pdo): array {
    $requirements = tamasyaProductionSchemaRequirements();
    $missingTables = [];
    $missingColumns = [];
    $invalidColumnDefaults = [];
    $missingMarkers = [];
    $missingTriggers = [];
    $allCriticalColumns=[];
    foreach((array)($requirements['columns'] ?? []) as $table=>$columns)foreach((array)$columns as $column)$allCriticalColumns[]=(string)$table.'.'.(string)$column;
    try {
        // FINAL11: delegate table metadata checks to the canonical schema contract.
        // This preserves FINAL10's bulk-query optimization while removing another
        // independent implementation of information_schema semantics.
        $missingTables = tamasyaSchemaMissingTables($pdo, $requirements['tables']);
    } catch (Throwable $error) {
        return ['ready'=>false,'missingTables'=>$requirements['tables'],'missingColumns'=>$allCriticalColumns,'missingMarkers'=>$requirements['markers'],'missingTriggers'=>$requirements['triggers'],'error'=>'schema_table_check_failed'];
    }
    if (!$missingTables) {
        try {
            $missingColumns = tamasyaSchemaMissingColumns($pdo, (array)($requirements['columns'] ?? []));
        } catch (Throwable $error) {
            foreach ((array)($requirements['columns'] ?? []) as $table=>$columns) foreach ((array)$columns as $column) $missingColumns[] = (string)$table.'.'.(string)$column;
        }
    } else {
        foreach ((array)($requirements['columns'] ?? []) as $table=>$columns) if (in_array((string)$table,$missingTables,true)) foreach ((array)$columns as $column) $missingColumns[] = (string)$table.'.'.(string)$column;
    }
    if (!$missingTables && !$missingColumns) {
        foreach ((array)($requirements['columnDefaults'] ?? []) as $table=>$defaults) {
            foreach ((array)$defaults as $column=>$expectedDefault) {
                try {
                    $meta=tamasyaSchemaColumnMeta($pdo,(string)$table,(string)$column);
                    $actualDefault=is_array($meta)?$meta['COLUMN_DEFAULT']??null:null;
                    $normalizedActualDefault=tamasyaSchemaNormalizeColumnDefault($actualDefault);
                    $normalizedExpectedDefault=tamasyaSchemaNormalizeColumnDefault($expectedDefault);
                    if(!is_array($meta) || $normalizedActualDefault!==$normalizedExpectedDefault) {
                        $invalidColumnDefaults[]=(string)$table.'.'.(string)$column.':default='.((string)$actualDefault);
                    }
                } catch (Throwable $error) {
                    $invalidColumnDefaults[]=(string)$table.'.'.(string)$column.':default=UNKNOWN';
                }
            }
        }
    } else {
        foreach ((array)($requirements['columnDefaults'] ?? []) as $table=>$defaults) foreach ((array)$defaults as $column=>$expectedDefault) $invalidColumnDefaults[]=(string)$table.'.'.(string)$column.':default=UNKNOWN';
    }
    $triggerInspection=['visibility'=>'unknown','reason'=>'not_checked','present'=>[],'missing'=>$requirements['triggers']];
    try{$triggerInspection=tamasyaSchemaTriggerMetadataStatus($pdo,(array)$requirements['triggers']);}
    catch(Throwable $error){$triggerInspection=['visibility'=>'unknown','reason'=>'trigger_metadata_status_failed','present'=>[],'missing'=>$requirements['triggers']];}
    if (!$missingTables && !$missingColumns && !$invalidColumnDefaults) {
        try {
            $placeholders = implode(',', array_fill(0, count($requirements['markers']), '?'));
            $markerStmt = $pdo->prepare("SELECT version FROM schema_migrations WHERE version IN ({$placeholders})");
            $markerStmt->execute($requirements['markers']);
            $present = array_fill_keys(array_map('strval', $markerStmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
            foreach ($requirements['markers'] as $marker) if (!isset($present[$marker])) $missingMarkers[] = $marker;
        } catch (Throwable $error) {
            $missingMarkers = $requirements['markers'];
        }
    } else {
        $missingMarkers = $requirements['markers'];
    }
    $releaseState = null;
    if (!$missingTables && !$missingColumns && !$invalidColumnDefaults && !$missingMarkers) {
        try {
            $releaseState = $pdo->query("SELECT current_release,patch_level,source_checksum,maintenance_required,migration_run_id,updated_at FROM schema_release_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
            $expectedRelease = defined('TAMASYA_SCHEMA_RELEASE') ? TAMASYA_SCHEMA_RELEASE : 'V137_FRESH_CANONICAL_MULTI_HOTEL';
            $expectedPatch = defined('TAMASYA_PATCH_LEVEL') ? TAMASYA_PATCH_LEVEL : null;
            if (!$releaseState || (int)($releaseState['maintenance_required'] ?? 1) !== 0 || (string)($releaseState['current_release'] ?? '') !== $expectedRelease) {
                $missingMarkers[] = 'schema_release_state:ready';
            }
            if ($expectedPatch !== null && (!$releaseState || (string)($releaseState['patch_level'] ?? '') !== $expectedPatch)) {
                $missingMarkers[] = 'schema_release_state:patch';
            }
        } catch (Throwable $error) {
            $missingMarkers[] = 'schema_release_state:ready';
        }
    }
    $expectedSourceChecksum='';
    try{$expectedSourceChecksum=function_exists('tamasyaCanonicalDatabaseSourceChecksum')?tamasyaCanonicalDatabaseSourceChecksum():(string)(hash_file('sha256',dirname(__DIR__,2).DIRECTORY_SEPARATOR.'database_setup.sql')?:'');}
    catch(Throwable $ignored){$expectedSourceChecksum='';}
    $triggerDecision=tamasyaRuntimeTriggerVerificationDecision($triggerInspection,$releaseState,$expectedSourceChecksum);
    $missingTriggers=array_values(array_unique((array)($triggerDecision['missingTriggers']??[])));
    if(!empty($triggerDecision['delegated'])&&empty($triggerDecision['ok']))$missingMarkers[]='schema_release_state:source_checksum_attestation';
    if(($triggerDecision['mode']??'')==='unverified')$missingMarkers[]='trigger_verification:unavailable';
    $missingMarkers=array_values(array_unique($missingMarkers));
    return [
        'ready'=>!$missingTables&&!$missingColumns&&!$invalidColumnDefaults&&!$missingMarkers&&!empty($triggerDecision['ok']),
        'missingTables'=>$missingTables,'missingColumns'=>array_values(array_unique($missingColumns)),'invalidColumnDefaults'=>array_values(array_unique($invalidColumnDefaults)),'missingMarkers'=>$missingMarkers,'missingTriggers'=>$missingTriggers,
        'triggerVerificationMode'=>$triggerDecision['mode']??'unverified','triggerVerificationDelegated'=>(bool)($triggerDecision['delegated']??false),'triggerMetadataVisible'=>$triggerDecision['metadataVisible']??null,'triggerVerificationReason'=>$triggerDecision['reason']??null,
        'expectedSourceChecksum'=>$expectedSourceChecksum,'sourceChecksumMatches'=>is_array($releaseState)&&$expectedSourceChecksum!==''&&hash_equals($expectedSourceChecksum,strtolower(trim((string)($releaseState['source_checksum']??'')))),'releaseState'=>$releaseState,
    ];
}

function tamasyaAssertProductionSchemaReady(PDO $pdo): void {
    $status = tamasyaProductionSchemaStatus($pdo);
    if (!empty($status['ready'])) return;
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    header('Retry-After: 300');
    echo tamasyaJsonEncode([
        'success'=>false,
        'error'=>'DATABASE_BASELINE_MISMATCH',
        'message'=>'Database tidak cocok dengan source release aktif. Untuk database existing gunakan migration resmi dari release pendahulu sesuai panduan; untuk database BARU/KOSONG gunakan database_setup.sql. Jangan melakukan DDL ad-hoc dan jangan membuka traffic sampai release/patch/kolom cocok.',
        'missingTables'=>$status['missingTables'] ?? [],
        'missingColumns'=>$status['missingColumns'] ?? [],
        'invalidColumnDefaults'=>$status['invalidColumnDefaults'] ?? [],
        'missingMarkers'=>$status['missingMarkers'] ?? [],
        'missingTriggers'=>$status['missingTriggers'] ?? [],
        'triggerVerificationMode'=>$status['triggerVerificationMode'] ?? 'unverified',
        'triggerVerificationDelegated'=>$status['triggerVerificationDelegated'] ?? false,
        'triggerMetadataVisible'=>$status['triggerMetadataVisible'] ?? null,
        'triggerVerificationReason'=>$status['triggerVerificationReason'] ?? null,
        'sourceChecksumMatches'=>$status['sourceChecksumMatches'] ?? false,
        'appRelease'=>defined('TAMASYA_APP_RELEASE') ? TAMASYA_APP_RELEASE : (defined('TAMASYA_RELEASE') ? TAMASYA_RELEASE : null),
        'schemaRelease'=>defined('TAMASYA_SCHEMA_RELEASE') ? TAMASYA_SCHEMA_RELEASE : null,
        'expectedPatch'=>defined('TAMASYA_PATCH_LEVEL') ? TAMASYA_PATCH_LEVEL : null,
    ]);
    exit;
}

function tamasyaAssertTablesExist(PDO $pdo, array $tables, string $scope): void {
    $missing=tamasyaSchemaMissingTables($pdo,$tables);
    if ($missing) throw new RuntimeException($scope.' schema belum siap: '.implode(', ', $missing));
}

function tamasyaPasswordPolicyErrors(string $password, string $username = '', string $name = ''): array {
    $errors = [];
    $min = max(10, min(64, (int)(getenv('AUTH_PASSWORD_MIN_LENGTH') ?: 12)));
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    if ($length < $min) $errors[] = "minimal {$min} karakter";
    if ($length > 128) $errors[] = 'maksimal 128 karakter';
    $classes = 0;
    $classes += preg_match('/[a-z]/', $password) ? 1 : 0;
    $classes += preg_match('/[A-Z]/', $password) ? 1 : 0;
    $classes += preg_match('/[0-9]/', $password) ? 1 : 0;
    $classes += preg_match('/[^A-Za-z0-9\s]/', $password) ? 1 : 0;
    if ($classes < 3) $errors[] = 'gunakan sedikitnya tiga jenis: huruf kecil, huruf besar, angka, atau simbol';
    $normalized = strtolower($password);
    foreach (array_filter([trim(strtolower($username)), trim(strtolower($name))], static fn($v) => strlen($v) >= 4) as $identity) {
        if (str_contains($normalized, $identity)) { $errors[] = 'tidak boleh mengandung username atau nama staf'; break; }
    }
    $common = ['password','password123','admin123','qwerty123','123456789','tamasya123','hotel12345','changeme'];
    if (in_array($normalized, $common, true)) $errors[] = 'password terlalu umum';
    return array_values(array_unique($errors));
}

function tamasyaAssertPasswordPolicy(string $password, string $username = '', string $name = ''): void {
    $errors = tamasyaPasswordPolicyErrors($password, $username, $name);
    if ($errors) throw new InvalidArgumentException('Password tidak memenuhi kebijakan keamanan: ' . implode('; ', $errors) . '.');
}

function tamasyaSecurityTelemetryHash(string $value): string {
    $key = trim((string)(getenv('SECURITY_EVENT_HASH_KEY') ?: getenv('APP_ENCRYPTION_KEY') ?: ''));
    if ($key === '') $key = 'tamasya-development-only-security-telemetry-key';
    return hash_hmac('sha256', $value, $key);
}

function tamasyaSecurityEvent(PDO $pdo, string $eventType, string $severity = 'info', ?string $staffId = null, array $context = []): void {
    try {
        $safe = [];
        foreach ($context as $key => $value) {
            if (!preg_match('/^[A-Za-z0-9_.-]{1,60}$/', (string)$key)) continue;
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) $safe[$key] = $value;
            elseif (is_string($value)) $safe[$key] = substr(tamasyaRuntimeSanitizeMessage($value), 0, 250);
        }
        $ip = tamasyaClientIp();
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $stmt = $pdo->prepare('INSERT INTO security_events(event_type,severity,staff_id,request_id,ip_hash,user_agent_hash,context_json,created_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP)');
        $stmt->execute([
            substr($eventType,0,80), substr($severity,0,20), $staffId ?: null,
            function_exists('tamasyaRuntimeRequestId') ? tamasyaRuntimeRequestId() : null,
            tamasyaSecurityTelemetryHash($ip), tamasyaSecurityTelemetryHash($userAgent),
            $safe ? tamasyaJsonEncode($safe) : null,
        ]);
    } catch (Throwable $ignored) {}
}

function tamasyaLoginLockRemaining(array $user): int {
    $until = strtotime((string)($user['login_locked_until'] ?? '')) ?: 0;
    return max(0, $until - time());
}

/**
 * Release an expired account lock before evaluating the next password.
 *
 * Previous behavior kept failed_login_count >= threshold forever after the
 * timed lock expired. One subsequent wrong password therefore immediately
 * created another full lock, which made the UI look like an endless countdown.
 * A completed lock period is now a clean security boundary: the stale counter
 * is reset once, while active locks remain fail-closed.
 */
function tamasyaNormalizeExpiredLoginLock(PDO $pdo, array $user): array {
    $staffId = (string)($user['id'] ?? '');
    $rawUntil = trim((string)($user['login_locked_until'] ?? ''));
    if ($staffId === '' || $rawUntil === '') return $user;
    $until = strtotime($rawUntil) ?: 0;
    if ($until <= 0 || $until > time()) return $user;

    $pdo->prepare('UPDATE staff SET failed_login_count=0,login_locked_until=NULL WHERE id=?')->execute([$staffId]);
    $user['failed_login_count'] = 0;
    $user['login_locked_until'] = null;
    $user['_login_lock_expired_reset'] = true;
    tamasyaSecurityEvent($pdo,'account_lock_expired_reset','info',$staffId);
    return $user;
}

function tamasyaRecordLoginFailure(PDO $pdo, array $user): int {
    $staffId = (string)($user['id'] ?? '');
    if ($staffId === '') return 0;
    $threshold = max(5, min(50, (int)(getenv('AUTH_ACCOUNT_LOCK_THRESHOLD') ?: 12)));
    $lockSeconds = max(60, min(86400, (int)(getenv('AUTH_ACCOUNT_LOCK_SECONDS') ?: 900)));
    $stmt = $pdo->prepare('UPDATE staff SET failed_login_count=failed_login_count+1, login_locked_until=CASE WHEN failed_login_count+1>=? THEN DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND) ELSE login_locked_until END WHERE id=?');
    $stmt->execute([$threshold,$lockSeconds,$staffId]);
    $read = $pdo->prepare('SELECT failed_login_count,login_locked_until FROM staff WHERE id=? LIMIT 1');
    $read->execute([$staffId]);
    $state = $read->fetch(PDO::FETCH_ASSOC) ?: [];
    $remaining = tamasyaLoginLockRemaining($state);
    tamasyaSecurityEvent($pdo, $remaining > 0 ? 'account_temporarily_locked' : 'login_password_failed', $remaining > 0 ? 'warning' : 'info', $staffId, ['failedCount'=>(int)($state['failed_login_count']??0),'lockSeconds'=>$remaining]);
    return $remaining;
}

function tamasyaResetLoginFailureState(PDO $pdo, string $staffId): void {
    if ($staffId === '') return;
    $pdo->prepare('UPDATE staff SET failed_login_count=0,login_locked_until=NULL WHERE id=?')->execute([$staffId]);
}

function tamasyaRecordLoginSuccess(PDO $pdo, string $staffId): void {
    if ($staffId === '') return;
    $pdo->prepare('UPDATE staff SET failed_login_count=0,login_locked_until=NULL,last_login_at=CURRENT_TIMESTAMP,last_login_ip=? WHERE id=?')
        ->execute([tamasyaClientIp(),$staffId]);
    tamasyaSecurityEvent($pdo,'login_success','info',$staffId);
}

function tamasyaSessionIdleCutoff(): string {
    $idle = max(300, min(86400, (int)(getenv('SESSION_IDLE_TIMEOUT_SECONDS') ?: 3600)));
    return date('Y-m-d H:i:s', time() - $idle);
}

function tamasyaEnterpriseEndpointRateLimit(PDO $pdo, string $action, ?array $staff): void {
    if (!function_exists('consumeRateLimit')) return;
    $ipHash = tamasyaSecurityTelemetryHash(tamasyaClientIp());
    $gatewayFingerprint = strtolower(trim((string)($GLOBALS['tamasya_public_gateway_fingerprint'] ?? '')));
    $publicIdentity = (!empty($GLOBALS['tamasya_public_gateway_verified']) && preg_match('/^[a-f0-9]{64}$/',$gatewayFingerprint))
        ? 'gateway:'.$gatewayFingerprint
        : 'ip:'.$ipHash;
    $policy = null;
    $public = [
        'public-reservation-request'=>[20,3600,900],
        'public-contact'=>[12,3600,900],
        'public-help-chat'=>[90,600,300],
        'public-help-chat-sync'=>[120,600,300],
        'refresh-session'=>[40,600,300],
        'verify-2fa'=>[20,600,600],
    ];
    if (isset($public[$action])) {
        $policy = ['scope'=>'public:'.$action.':'.$publicIdentity] + array_combine(['limit','window','block'],$public[$action]);
    } elseif ($staff) {
        $staffId = (string)($staff['id'] ?? 'unknown');
        $device = function_exists('currentDeviceId') ? currentDeviceId() : 'unknown-device';
        $policy = ['scope'=>'authenticated:'.hash('sha256',$staffId.'|'.$device),'limit'=>600,'window'=>60,'block'=>60];
    }
    if (!$policy) return;
    $result = consumeRateLimit($pdo,$policy['scope'],$policy['limit'],$policy['window'],$policy['block']);
    if (empty($result['allowed'])) {
        header('Retry-After: '.(int)$result['retryAfter']);
        tamasyaSecurityEvent($pdo,'endpoint_rate_limited','warning',$staff['id']??null,['action'=>$action,'retryAfter'=>(int)$result['retryAfter']]);
        tamasyaSecurityJsonError(429,'RATE_LIMITED','Terlalu banyak request. Coba kembali beberapa saat.',['retryAfter'=>(int)$result['retryAfter']]);
    }
}
