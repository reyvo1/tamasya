<?php
declare(strict_types=1);
require_once __DIR__.'/database_tls.php';

/**
 * Shared database bootstrap for API, installer, CLI checks, and maintenance.
 * This file never prints credentials and is safe to include before routing.
 */

/**
 * Load a small dotenv-compatible runtime file before any getenv() lookup.
 *
 * Existing process/server environment variables always win. This keeps cPanel,
 * PHP-FPM, Apache SetEnv, systemd, and Task Scheduler configuration authoritative.
 * A project .env is supported for deployments that do not inject environment
 * variables through the process manager or web server. Keep .env outside public web access; the
 * bundled Apache rule denies it and Nginx/IIS must deny dotfiles explicitly.
 */
function tamasyaLoadRuntimeEnvironment(string $appDir): array {
    static $loaded = null;
    if (is_array($loaded)) return $loaded;

    $explicit = trim((string)(getenv('APP_ENV_FILE') ?: getenv('TAMASYA_ENV_FILE') ?: ''));
    // An explicit environment file is authoritative. Falling back to a different
    // .env when the configured path is wrong can silently mix staging/production
    // identities and database targets, so fail closed instead.
    if ($explicit !== '' && (!is_file($explicit) || !is_readable($explicit))) {
        throw new RuntimeException('APP_ENV_FILE/TAMASYA_ENV_FILE ditetapkan tetapi file tidak ada atau tidak dapat dibaca. Perbaiki path; fallback otomatis dinonaktifkan demi keamanan deployment.');
    }
    $candidates = $explicit !== ''
        ? [$explicit]
        : [rtrim($appDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env'];

    $result = ['loaded'=>false,'source'=>null,'keys'=>[],'errors'=>[]];
    foreach ($candidates as $file) {
        if (!is_file($file) || !is_readable($file)) continue;
        $size = @filesize($file);
        if ($size !== false && $size > 1024 * 1024) {
            $result['errors'][] = 'File environment terlalu besar: ' . basename($file);
            continue;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            $result['errors'][] = 'File environment tidak dapat dibaca: ' . basename($file);
            continue;
        }
        foreach ($lines as $lineNumber => $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) continue;
            if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
            $separator = strpos($line, '=');
            if ($separator === false) continue;
            $key = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                $result['errors'][] = 'Nama environment tidak valid pada baris ' . ($lineNumber + 1) . '.';
                continue;
            }
            // Never override variables injected by the hosting/runtime.
            if (getenv($key) !== false) continue;

            $value = trim(substr($line, $separator + 1));
            if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = str_replace(['\\n','\\r','\\t','\\"','\\\\'], ["\n","\r","\t",'"','\\'], $value);
                }
            } else {
                // Remove an unquoted trailing comment only when preceded by whitespace.
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                $value = trim($value);
            }
            if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                $result['errors'][] = 'Nilai environment multi-baris ditolak untuk ' . $key . '.';
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            $result['keys'][] = $key;
        }
        $result['loaded'] = true;
        $result['source'] = realpath($file) ?: $file;
        break;
    }
    $loaded = $result;
    $GLOBALS['tamasya_runtime_environment'] = $result;
    return $result;
}

tamasyaLoadRuntimeEnvironment(__DIR__);

function tamasyaNormalizeDbPort($value): int {
    $raw = trim((string)$value);
    if ($raw === '' || !ctype_digit($raw)) return 3306;
    $port = (int)$raw;
    return ($port >= 1 && $port <= 65535) ? $port : 3306;
}

function tamasyaCredentialCandidates(string $appDir): array {
    $explicit = trim((string)(getenv('APP_CREDENTIALS_FILE') ?: ''));
    // Explicit means authoritative: never continue to historical fallback files
    // if this path is missing/invalid. This prevents accidental cross-database use.
    if ($explicit !== '') return [$explicit];
    $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentRootReal = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';

    $candidates = [
        $explicit,
        $documentRootReal !== '' ? dirname($documentRootReal) . DIRECTORY_SEPARATOR . 'tamasya_db_credentials.php' : '',
        dirname($appDir) . DIRECTORY_SEPARATOR . 'tamasya_db_credentials.php',
        dirname($appDir, 2) . DIRECTORY_SEPARATOR . 'tamasya_db_credentials.php',
        $appDir . DIRECTORY_SEPARATOR . 'tamasya_db_credentials.php',
        $appDir . DIRECTORY_SEPARATOR . 'db_credentials.php',
        dirname($appDir) . DIRECTORY_SEPARATOR . 'db_credentials.php',
    ];

    $unique = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '' || isset($unique[$candidate])) continue;
        $unique[$candidate] = true;
    }
    return array_keys($unique);
}

function tamasyaReadCredentialFile(string $file): ?array {
    if (!is_file($file) || !is_readable($file)) return null;

    $loaded = (static function(string $credentialFile): array {
        $db_host = 'localhost';
        $db_port = 3306;
        $db_name = '';
        $db_user = '';
        $db_pass = '';
        $dbHost = $dbPort = $dbName = $dbUser = $dbPass = null;
        $returned = include $credentialFile;
        return compact(
            'returned',
            'db_host', 'db_port', 'db_name', 'db_user', 'db_pass',
            'dbHost', 'dbPort', 'dbName', 'dbUser', 'dbPass'
        );
    })($file);

    $returned = $loaded['returned'];
    if (is_array($returned)) {
        $host = (string)($returned['host'] ?? $returned['db_host'] ?? $returned['dbHost'] ?? '');
        $port = $returned['port'] ?? $returned['db_port'] ?? $returned['dbPort'] ?? 3306;
        $name = (string)($returned['name'] ?? $returned['database'] ?? $returned['db_name'] ?? $returned['dbName'] ?? '');
        $user = (string)($returned['user'] ?? $returned['username'] ?? $returned['db_user'] ?? $returned['dbUser'] ?? '');
        $pass = (string)($returned['pass'] ?? $returned['password'] ?? $returned['db_pass'] ?? $returned['dbPass'] ?? '');
    } else {
        $host = (string)($loaded['db_host'] ?: $loaded['dbHost'] ?: 'localhost');
        $port = $loaded['db_port'] ?: $loaded['dbPort'] ?: 3306;
        $name = (string)($loaded['db_name'] ?: $loaded['dbName'] ?: '');
        $user = (string)($loaded['db_user'] ?: $loaded['dbUser'] ?: '');
        $pass = (string)($loaded['db_pass'] !== '' ? $loaded['db_pass'] : ($loaded['dbPass'] ?? ''));
    }

    $host = trim($host) ?: 'localhost';
    $name = trim($name);
    $user = trim($user);
    if ($name === '' || $user === '') return null;

    return [
        'host' => $host,
        'port' => tamasyaNormalizeDbPort($port),
        'name' => $name,
        'user' => $user,
        'pass' => $pass,
        'source' => realpath($file) ?: $file,
        'sourceType' => 'file',
    ];
}

function tamasyaResolveDatabaseConfig(string $appDir): array {
    $env = [
        'host' => trim((string)(getenv('DB_HOST') ?: '')),
        'port' => tamasyaNormalizeDbPort(getenv('DB_PORT') ?: 3306),
        'name' => trim((string)(getenv('DB_NAME') ?: '')),
        'user' => trim((string)(getenv('DB_USER') ?: '')),
        'pass' => (string)(getenv('DB_PASSWORD') ?: ''),
    ];

    $explicitCredentialFile = trim((string)(getenv('APP_CREDENTIALS_FILE') ?: ''));
    $fileConfig = null;
    $resolutionError = null;
    $invalidCredentialSource = null;
    if($explicitCredentialFile!=='' && in_array(strtolower(trim((string)(getenv('APP_ENV')?:'production'))),['staging','production','prod'],true)){
        $absolute=tamasyaPathIsAbsolute($explicitCredentialFile);
        if(!$absolute)$resolutionError='APP_CREDENTIALS_FILE wajib path absolut pada staging/production.';
    }
    foreach (tamasyaCredentialCandidates($appDir) as $candidate) {
        $fileConfig = tamasyaReadCredentialFile($candidate);
        if ($fileConfig !== null) break;
    }
    if ($explicitCredentialFile !== '' && $fileConfig === null && $resolutionError===null) {
        $resolutionError = 'APP_CREDENTIALS_FILE ditetapkan tetapi file tidak ada, tidak dapat dibaca, atau tidak berisi credential database yang valid. Fallback credential dinonaktifkan.';
    }
    if($fileConfig!==null){
        $appEnv=strtolower(trim((string)(getenv('APP_ENV')?:'production')));
        $allowPublic=filter_var(getenv('ALLOW_PUBLIC_CREDENTIAL_FILE')?:'0',FILTER_VALIDATE_BOOLEAN);
        $sourcePath=(string)($fileConfig['source']??$explicitCredentialFile);
        // Apply web-root safety to EVERY credential file, not only an explicitly
        // configured one. Historical fallback discovery must never make a public
        // credential file acceptable on staging/production.
        if($sourcePath!=='' && tamasyaPathIsInsideDocumentRoot($sourcePath,$appDir)
            && (in_array($appEnv,['staging','production','prod'],true) || !$allowPublic)){
            $invalidCredentialSource=$sourcePath;
            $fileConfig=null;
            $resolutionError='File credential database berada di dalam public/document root. Pindahkan credential ke lokasi private di luar web root.';
        }
    }

    // APP_CREDENTIALS_FILE yang eksplisit bersifat authoritative secara default.
    // Ini mencegah environment DB_* lama di PHP-FPM/cPanel menimpa database staging
    // per field dan tanpa sengaja mengarahkan subfolder testing ke database produksi.
    // Override tetap tersedia untuk deployment khusus, tetapi harus diaktifkan sadar
    // melalui APP_ALLOW_DB_ENV_OVERRIDE=1.
    $allowEnvironmentOverride = filter_var(getenv('APP_ALLOW_DB_ENV_OVERRIDE') ?: '0', FILTER_VALIDATE_BOOLEAN);
    $fileIsAuthoritative = $fileConfig !== null && $explicitCredentialFile !== '' && !$allowEnvironmentOverride;

    $base = $fileConfig ?? [
        'host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '',
        'source' => null, 'sourceType' => 'none',
    ];
    if (!$fileIsAuthoritative) {
        foreach (['host', 'name', 'user'] as $field) {
            if ($env[$field] !== '') $base[$field] = $env[$field];
        }
        if (getenv('DB_PORT') !== false && trim((string)getenv('DB_PORT')) !== '') $base['port'] = $env['port'];
        if (getenv('DB_PASSWORD') !== false) $base['pass'] = $env['pass'];

        if ($env['name'] !== '' && $env['user'] !== '') {
            $base['sourceType'] = $fileConfig ? 'environment+file' : 'environment';
            $base['source'] = $fileConfig['source'] ?? null;
        }
    } else {
        $base['sourceType'] = 'explicit-credential-file';
    }

    $base['host'] = trim((string)($base['host'] ?? 'localhost')) ?: 'localhost';
    $base['port'] = tamasyaNormalizeDbPort($base['port'] ?? 3306);
    $base['name'] = trim((string)($base['name'] ?? ''));
    $base['user'] = trim((string)($base['user'] ?? ''));
    $base['pass'] = (string)($base['pass'] ?? '');
    // Even when DB_* process variables exist, a broken explicit credential path
    // must stay failed closed. APP_ALLOW_DB_ENV_OVERRIDE only permits overriding a
    // VALID explicit credential file; it is not an escape hatch for a missing one.
    if ($resolutionError !== null) {
        $base['host'] = 'localhost';
        $base['port'] = 3306;
        $base['name'] = '';
        $base['user'] = '';
        $base['pass'] = '';
        $base['source'] = $explicitCredentialFile !== '' ? $explicitCredentialFile : ($invalidCredentialSource ?? ($fileConfig['source'] ?? null));
        $base['sourceType'] = $explicitCredentialFile !== '' ? 'explicit-credential-file-invalid' : 'credential-file-invalid';
    }
    $base['resolutionError'] = $resolutionError;
    $base['detected'] = $resolutionError === null && $base['name'] !== '' && $base['user'] !== '';
    $base['driverAvailable'] = in_array('mysql', PDO::getAvailableDrivers(), true);
    return $base;
}


/**
 * Guard database deployment sebelum migrasi atau mutasi apa pun dijalankan.
 * APP_EXPECTED_DB_NAME mengunci nama database yang boleh dipakai. Daftar pada
 * APP_FORBIDDEN_DB_NAMES selalu ditolak. Perbandingan memakai SELECT DATABASE()
 * agar yang diverifikasi adalah database nyata dari koneksi aktif, bukan hanya
 * nilai konfigurasi yang diminta.
 */
function tamasyaDatabaseSafetyPolicy(): array {
    $expected = trim((string)(getenv('APP_EXPECTED_DB_NAME') ?: getenv('TAMASYA_EXPECTED_DB_NAME') ?: ''));
    $forbiddenRaw = (string)(getenv('APP_FORBIDDEN_DB_NAMES') ?: getenv('TAMASYA_FORBIDDEN_DB_NAMES') ?: '');
    $forbidden = array_values(array_unique(array_filter(array_map(
        static fn($value) => strtolower(trim((string)$value)),
        preg_split('/[,;\s]+/', $forbiddenRaw) ?: []
    ), static fn($value) => $value !== '')));
    $expectedLower = strtolower($expected);
    $contradictory = $expectedLower !== '' && in_array($expectedLower, $forbidden, true);
    return ['expected'=>$expected, 'forbidden'=>$forbidden, 'contradictory'=>$contradictory];
}

function tamasyaAssertDatabaseSafety(PDO $pdo, array $config): array {
    $policy = tamasyaDatabaseSafetyPolicy();
    $appEnv=strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
    $requireExpectedRaw=getenv('APP_REQUIRE_EXPECTED_DB_NAME');
    $requireExpected=$requireExpectedRaw!==false
        ? filter_var((string)$requireExpectedRaw,FILTER_VALIDATE_BOOLEAN)
        : in_array($appEnv,['staging','production','prod'],true);
    if ($requireExpected && trim((string)$policy['expected'])==='') {
        throw new RuntimeException('APP_EXPECTED_DB_NAME wajib diisi pada staging/production agar salah target database gagal tertutup.');
    }
    if (!empty($policy['contradictory'])) {
        throw new RuntimeException('Kebijakan database tidak valid: APP_EXPECTED_DB_NAME juga tercantum pada APP_FORBIDDEN_DB_NAMES. Perbaiki konfigurasi sebelum koneksi atau migrasi dijalankan.');
    }
    $requested = trim((string)($config['name'] ?? ''));
    $actualValue = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $actual = trim((string)$actualValue);
    if ($actual === '') throw new RuntimeException('Database aktif tidak dapat diverifikasi. Operasi dihentikan sebelum migrasi.');
    if ($requested !== '' && strcasecmp($requested, $actual) !== 0) {
        throw new RuntimeException('Database aktif tidak sama dengan database yang diminta konfigurasi. Operasi dihentikan.');
    }
    $expected = trim((string)$policy['expected']);
    if ($expected !== '' && strcasecmp($expected, $actual) !== 0) {
        throw new RuntimeException('Database aktif tidak sesuai APP_EXPECTED_DB_NAME. Operasi dihentikan sebelum migrasi.');
    }
    $actualLower = strtolower($actual);
    if (in_array($actualLower, $policy['forbidden'], true)) {
        throw new RuntimeException('Database aktif termasuk daftar terlarang. Operasi dihentikan sebelum migrasi.');
    }
    return [
        'requested'=>$requested,
        'actual'=>$actual,
        'expected'=>$expected,
        'forbidden'=>$policy['forbidden'],
        'locked'=>$expected !== '' || count($policy['forbidden']) > 0,
    ];
}



/**
 * Shared property identity safety for non-API entry points (cron, sync agent,
 * verifier, database reconfiguration). The database may only be treated as this
 * deployment when immutable identity fields match ENV exactly.
 */
function tamasyaDatabasePropertyIdentity(PDO $pdo, bool $requireInitialized = false): array {
    $tableStmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='property_settings'");
    $tableStmt->execute();
    if((int)$tableStmt->fetchColumn()!==1){
        if($requireInitialized) throw new RuntimeException('property_settings tidak ditemukan pada database target.');
        return ['ok'=>true,'initialized'=>false,'mismatches'=>[]];
    }
    $row=$pdo->query("SELECT company_id,property_id,property_code,timezone,currency,country_code FROM property_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)){
        if($requireInitialized) throw new RuntimeException('Identitas property belum diinisialisasi pada database target.');
        return ['ok'=>true,'initialized'=>false,'mismatches'=>[]];
    }
    $env=[
        'company_id'=>strtolower(trim((string)(getenv('TAMASYA_COMPANY_ID')?:''))),
        'property_id'=>strtolower(trim((string)(getenv('TAMASYA_PROPERTY_ID')?:getenv('APP_PROPERTY_ID')?:''))),
        'property_code'=>strtoupper(trim((string)(getenv('TAMASYA_PROPERTY_CODE')?:''))),
        'timezone'=>trim((string)(getenv('APP_TIMEZONE')?:'')),
        'currency'=>strtoupper(trim((string)(getenv('TAMASYA_PROPERTY_CURRENCY')?:''))),
        'country_code'=>strtoupper(trim((string)(getenv('TAMASYA_PROPERTY_COUNTRY')?:''))),
    ];
    $db=[
        'company_id'=>strtolower(trim((string)($row['company_id']??''))),
        'property_id'=>strtolower(trim((string)($row['property_id']??''))),
        'property_code'=>strtoupper(trim((string)($row['property_code']??''))),
        'timezone'=>trim((string)($row['timezone']??'')),
        'currency'=>strtoupper(trim((string)($row['currency']??''))),
        'country_code'=>strtoupper(trim((string)($row['country_code']??''))),
    ];
    $mismatches=[];
    foreach($db as $field=>$dbValue){
        $configured=(string)($env[$field]??'');
        if($field==='company_id' && $dbValue==='' && $configured==='') continue;
        if($configured==='' || !hash_equals($configured,$dbValue))$mismatches[$field]=['database'=>$dbValue,'deployment'=>$configured];
    }
    if($mismatches && $requireInitialized){
        throw new RuntimeException('Database target adalah property/deployment yang berbeda pada field: '.implode(', ',array_keys($mismatches)).'. Operasi dihentikan untuk mencegah cross-property write/sync.');
    }
    return ['ok'=>count($mismatches)===0,'initialized'=>true,'mismatches'=>$mismatches,'database'=>$db,'deployment'=>$env];
}

/**
 * Menggabungkan input wizard dengan konfigurasi lama.
 * Nilai kosong berarti "pertahankan nilai lama", termasuk password.
 * Dengan demikian form pemulihan dapat dipakai tanpa menampilkan secret lama.
 */
function tamasyaMergeDatabaseSetupInput(array $currentConfig, array $input): array {
    $pick = static function(string $field, $fallback) use ($input) {
        $submitted = trim((string)($input[$field] ?? ''));
        return $submitted !== '' ? $submitted : $fallback;
    };

    $host = (string)$pick('host', (string)($currentConfig['host'] ?? 'localhost'));
    $port = tamasyaNormalizeDbPort($pick('port', $currentConfig['port'] ?? 3306));
    $name = (string)$pick('name', (string)($currentConfig['name'] ?? ''));
    $user = (string)$pick('user', (string)($currentConfig['user'] ?? ''));
    $submittedPassword = (string)($input['password'] ?? '');
    $pass = $submittedPassword !== ''
        ? $submittedPassword
        : (string)($currentConfig['pass'] ?? '');

    return [
        'host' => trim($host) ?: 'localhost',
        'port' => $port,
        'name' => trim($name),
        'user' => trim($user),
        'pass' => $pass,
        'detected' => trim($name) !== '' && trim($user) !== '',
        'driverAvailable' => in_array('mysql', PDO::getAvailableDrivers(), true),
    ];
}

function tamasyaMysqlDsn(array $config): string {
    $host = trim((string)($config['host'] ?? 'localhost')) ?: 'localhost';
    $name = trim((string)($config['name'] ?? ''));
    $port = tamasyaNormalizeDbPort($config['port'] ?? 3306);
    if (str_starts_with($host, 'unix_socket:')) {
        $socket = substr($host, strlen('unix_socket:'));
        return 'mysql:unix_socket=' . $socket . ';dbname=' . $name . ';charset=utf8mb4';
    }
    return 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
}

function tamasyaConnectDatabase(array $config): array {
    if (!empty($config['resolutionError'])) {
        return [null, (string)$config['resolutionError'], 'credential_file_required'];
    }
    if (empty($config['detected'])) {
        return [null, 'Konfigurasi database belum ditemukan.', 'missing_credentials'];
    }
    if (empty($config['driverAvailable'])) {
        return [null, 'Driver PHP pdo_mysql belum aktif pada server.', 'missing_driver'];
    }
    try {
        $tlsOptions=tamasyaDatabaseTlsOptions();
        $pdo = new PDO(tamasyaMysqlDsn($config), (string)$config['user'], (string)$config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]+$tlsOptions);
        tamasyaAssertDatabaseTls($pdo,$tlsOptions);
        $pdo->query('SELECT 1')->fetchColumn();
        return [$pdo, null, 'connected'];
    } catch (Throwable $error) {
        return [null, $error->getMessage(), 'connection_failed'];
    }
}

function tamasyaSafeCredentialSource(array $config): string {
    $type = (string)($config['sourceType'] ?? 'none');
    if ($type === 'environment' || $type === 'environment+file') return $type;
    if (!empty($config['source'])) return 'credential-file';
    return 'none';
}

function tamasyaPathIsAbsolute(string $path): bool {
    $path=trim($path);
    if($path==='')return false;
    return str_starts_with($path,'/') || str_starts_with($path,'\\') || (bool)preg_match('~^[A-Za-z]:[\\\\/]~',$path);
}

function tamasyaDocumentRoot(string $appDir): string {
    $root = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($root !== '') return realpath($root) ?: $root;
    return realpath($appDir) ?: $appDir;
}

function tamasyaPathIsInsideDocumentRoot(string $path,string $appDir): bool {
    $resolvedPath=realpath($path)?:$path;
    // For a new file use its parent directory, for an existing file compare the
    // file path itself. Both are normalized to the platform separator.
    $resolvedRoot=realpath(tamasyaDocumentRoot($appDir))?:tamasyaDocumentRoot($appDir);
    $normalize=static function(string $value):string{
        $value=str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$value);
        $value=rtrim($value,DIRECTORY_SEPARATOR);
        return DIRECTORY_SEPARATOR==='\\'?strtolower($value):$value;
    };
    $candidate=$normalize($resolvedPath);$root=$normalize($resolvedRoot);
    return $candidate===$root || str_starts_with($candidate.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR);
}

function tamasyaDefaultCredentialTarget(string $appDir): string {
    $explicit = trim((string)(getenv('APP_CREDENTIALS_FILE') ?: ''));
    if ($explicit !== '') return $explicit;
    $documentRoot = tamasyaDocumentRoot($appDir);
    return dirname($documentRoot) . DIRECTORY_SEPARATOR . 'tamasya_db_credentials.php';
}

function tamasyaWriteDatabaseCredentials(string $target, array $config, bool $allowInsideDocumentRoot = false): void {
    $target = trim($target);
    if ($target === '') throw new RuntimeException('Lokasi file kredensial kosong.');
    // Credential paths are deployment identity/security configuration, not a
    // working-directory-relative convenience. Requiring an absolute path avoids
    // accidentally writing a DB password under admin_app/public when cwd differs.
    if(!tamasyaPathIsAbsolute($target)) throw new RuntimeException('Lokasi file kredensial wajib path absolut. Gunakan APP_CREDENTIALS_FILE yang menunjuk lokasi private di luar document root.');
    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0700, true)) {
        throw new RuntimeException('Direktori file kredensial tidak dapat dibuat.');
    }
    $resolvedDir=realpath($targetDir)?:$targetDir;
    if(!tamasyaPathIsAbsolute($resolvedDir)) throw new RuntimeException('Direktori file kredensial tidak dapat di-resolve menjadi path absolut.');

    $insideRoot=tamasyaPathIsInsideDocumentRoot($resolvedDir,__DIR__);
    if($insideRoot){
        $allowPublicByEnv=filter_var(getenv('ALLOW_PUBLIC_CREDENTIAL_FILE')?:'0',FILTER_VALIDATE_BOOLEAN);
        $appEnv=strtolower(trim((string)(getenv('APP_ENV')?:'production')));
        // Staging/production are always fail-closed: DB passwords must live
        // outside the public document root. The legacy override remains only for
        // deliberate local/development recovery and requires BOTH the caller flag
        // and ALLOW_PUBLIC_CREDENTIAL_FILE=1.
        if(!$allowInsideDocumentRoot || !$allowPublicByEnv || in_array($appEnv,['staging','production','prod'],true)){
            throw new RuntimeException('File kredensial database wajib berada di luar document root pada staging/production.');
        }
    }

    $code = "<?php\n" .
        "\$db_host = " . var_export((string)$config['host'], true) . ";\n" .
        "\$db_port = " . var_export(tamasyaNormalizeDbPort($config['port'] ?? 3306), true) . ";\n" .
        "\$db_name = " . var_export((string)$config['name'], true) . ";\n" .
        "\$db_user = " . var_export((string)$config['user'], true) . ";\n" .
        "\$db_pass = " . var_export((string)$config['pass'], true) . ";\n";

    $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
        throw new RuntimeException('File kredensial tidak dapat ditulis.');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('File kredensial tidak dapat diaktifkan secara atomik.');
    }
    @chmod($target, 0600);
}
