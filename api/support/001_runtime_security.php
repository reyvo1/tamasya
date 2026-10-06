<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 24: tamasyaRuntimeRequirements */
function tamasyaRuntimeRequirements(): array {
    $extensions = [
        'pdo' => extension_loaded('PDO'),
        'pdo_mysql' => in_array('mysql', PDO::getAvailableDrivers(), true),
        'json' => extension_loaded('json'),
        'openssl' => extension_loaded('openssl'),
        'fileinfo' => extension_loaded('fileinfo'),
        'curl' => extension_loaded('curl'),
        'mbstring' => extension_loaded('mbstring'),
    ];
    $required = ['pdo','pdo_mysql','json','openssl','fileinfo'];
    $integration = ['curl','mbstring'];
    return [
        'phpVersion' => PHP_VERSION,
        'minimumPhpVersion' => '8.2.0',
        'recommendedPhpVersions' => ['8.3','8.4','8.5'],
        'extensions' => $extensions,
        'missingRequired' => array_values(array_filter($required, static fn($name) => empty($extensions[$name]))),
        'missingIntegration' => array_values(array_filter($integration, static fn($name) => empty($extensions[$name]))),
    ];
}


/**
 * Encode payload API secara konsisten. JSON_INVALID_UTF8_SUBSTITUTE mencegah
 * satu baris data lama dengan encoding rusak membuat seluruh respons kosong.
 */
function tamasyaJsonEncode($value): string {
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json !== false) return $json;
    $reference = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    error_log('[api.php]['.$reference.'] JSON encoding failed: '.json_last_error_msg());
    return '{"success":false,"error":"Respons server gagal diserialisasi. Referensi: '.$reference.'"}';
}

/** Bersihkan seluruh output buffer API sebelum mengirim satu respons final. */
function tamasyaDiscardOutputBuffers(): void {
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) break;
    }
}

/**
 * Tandai receipt yang gagal diselesaikan sebagai uncertain tanpa melempar
 * exception kedua ke body HTTP yang sudah selesai dibuat.
 */
function tamasyaMarkRequestOperationUncertain(PDO $pdo, string $operationId, string $reason): void {
    if ($operationId === '') return;
    try {
        $stmt = $pdo->prepare("UPDATE request_operation_receipts
            SET status='uncertain',http_status=409,error_message=?,updated_at=CURRENT_TIMESTAMP
            WHERE operation_id=? AND status='processing'");
        $stmt->execute([substr(trim($reason), 0, 1000), $operationId]);
    } catch (Throwable $ignored) {
        error_log('[api.php] Gagal menandai receipt uncertain untuk operation_id '.substr($operationId, 0, 32));
    }
}

/** Source line 109: clientExceptionMessage */
function clientExceptionMessage(string $context, Throwable $error): string {
    global $appDebug;
    $reference = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    error_log(sprintf(
        '[api.php][%s] %s: %s in %s:%d',
        $reference,
        $context,
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    ));

    if ($appDebug) {
        return trim($context . ': ' . $error->getMessage(), ': ');
    }

    if ($error instanceof InvalidArgumentException ||
        $error instanceof DomainException ||
        ($error instanceof RuntimeException && !($error instanceof PDOException))) {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($error->getMessage())));
        $containsSqlDetail = preg_match(
            '/\b(?:SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|PDO|SQLSTATE)\b/i',
            $message
        ) === 1;
        $lowerMessage = strtolower($message);
        $containsPhpPath = str_contains($lowerMessage, '.php') &&
            (str_contains($message, '/') || str_contains($message, '\\'));

        if ($message !== '' && strlen($message) <= 300 && !$containsSqlDetail && !$containsPhpPath) {
            return trim($context . ': ' . $message, ': ');
        }
    }

    return trim($context . '. Referensi: ' . $reference, '. ');
}

/**
 * Memetakan exception bisnis ke status HTTP yang tepat. Sebelumnya banyak
 * validasi RuntimeException tertangkap sebagai HTTP 500, sehingga pengguna
 * melihat seolah server rusak padahal request hanya konflik/tidak valid.
 * PDO, schema, dan kegagalan infrastruktur tetap 500.
 */

/**
 * Pesan untuk jalur validasi yang boleh membawa detail hanya dari exception
 * validasi terkontrol. Kegagalan database/runtime lain selalu disamarkan dengan
 * reference ID agar SQL, path server, atau pesan integrasi tidak bocor.
 */
function tamasyaClientValidationExceptionMessage(string $context, Throwable $error): string {
    if ($error instanceof InvalidArgumentException || $error instanceof DomainException) {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($error->getMessage())));
        if ($message !== '' && strlen($message) <= 300 &&
            preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|PDO|SQLSTATE)\b/i', $message) !== 1 &&
            !(str_contains(strtolower($message), '.php') && (str_contains($message, '/') || str_contains($message, '\\')))) {
            return $message;
        }
    }
    $reference = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    error_log(sprintf('[api.php][%s] %s: %s in %s:%d',
        $reference,$context,$error->getMessage(),$error->getFile(),$error->getLine()));
    return trim($context . '. Referensi: ' . $reference, '. ');
}

function tamasyaExceptionHttpStatus(Throwable $error, int $fallback = 500): int {
    if ($error instanceof PDOException) return 500;
    if ($error instanceof InvalidArgumentException) return 400;
    if ($error instanceof DomainException) return 409;

    $message = strtolower(trim((string)$error->getMessage()));
    if ($message !== '') {
        if (preg_match('/\b(unauthorized|belum login|sesi tidak valid)\b/i', $message)) return 401;
        if (preg_match('/\b(forbidden|tidak memiliki izin|izin .* diperlukan|akses ditolak)\b/i', $message)) return 403;
        if (preg_match('/\b(tidak ditemukan|sudah dihapus)\b/i', $message)) return 404;
        if (preg_match('/\b(dikunci|locked|tutup shift)\b/i', $message)) return 423;
        if (preg_match('/\b(buka shift|shift aktif|pendamping shift)\b/i', $message)) return 409;
        if (preg_match('/\b(koneksi|driver|gateway|primary aktif belum|tidak dapat dihubungi|service unavailable|sementara tidak tersedia|adapter .*tidak terpasang)\b/i', $message)) return 503;
        if (preg_match('/\b(sqlstate|pdo|select |insert |update |delete |alter |create |drop |tabel wajib|kolom wajib|schema|database aktif|query)\b/i', $message)) return 500;
        if (preg_match('/(gagal (dibuat|dibaca|diserialisasi|dinormalisasi)|jurnal .*gagal|receipt .*gagal|wajib dijalankan di dalam transaksi|integritas data)/i', $message)) return 500;
        if (preg_match('/\b(sudah ada|duplikat|konflik|bertabrakan|overlap|tidak sesuai|melebihi|tidak dapat diubah|tidak boleh|tidak mendukung|harus|wajib|belum tersedia|tidak aktif|tidak valid|dinonaktifkan|belum diaktifkan)\b/i', $message)) return 409;
    }

    // RuntimeException yang tidak dikenal tidak otomatis dianggap konflik bisnis.
    // Ini mencegah bug internal baru tersamarkan sebagai HTTP 409.
    if ($error instanceof RuntimeException) return $fallback >= 400 && $fallback <= 599 ? $fallback : 500;
    return $fallback >= 400 && $fallback <= 599 ? $fallback : 500;
}

function tamasyaApplyExceptionHttpStatus(Throwable $error, int $fallback = 500): int {
    $current = http_response_code();
    if ($current >= 400 && $current <= 599) return $current;
    $status = tamasyaExceptionHttpStatus($error, $fallback);
    http_response_code($status);
    return $status;
}

/** Source line 144: appSecretEncryptionKey */
function appSecretEncryptionKey(): ?string {
    return tamasyaSecretEncryptionKey();
}

/** Source line 154: decryptStoredSecret */
function decryptStoredSecret($stored): string {
    $value = (string)($stored ?? '');
    if ($value === '' || !str_starts_with($value, 'enc:v1:')) return $value;
    $key = appSecretEncryptionKey();
    if ($key === null) {
        error_log('[api.php] APP_ENCRYPTION_KEY tidak tersedia untuk membaca secret terenkripsi.');
        return '';
    }
    $packed = base64_decode(substr($value, 7), true);
    if ($packed === false || strlen($packed) < 29) return '';
    $iv = substr($packed, 0, 12);
    $tag = substr($packed, 12, 16);
    $ciphertext = substr($packed, 28);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'tamasya-config-v1');
    return $plain === false ? '' : $plain;
}

/** Source line 171: encryptStoredSecret */
function encryptStoredSecret($plain): string {
    $value = (string)($plain ?? '');
    if ($value === '') return '';
    if (str_starts_with($value, 'enc:v1:')) return $value;
    $key = appSecretEncryptionKey();
    $appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
    if ($key === null) {
        if (!in_array($appEnv, ['development', 'dev', 'test', 'local'], true)) {
            throw new RuntimeException('APP_ENCRYPTION_KEY wajib dikonfigurasi sebelum menyimpan token/password pada production.');
        }
        error_log('[api.php] WARNING: secret disimpan plaintext karena APP_ENV non-production dan APP_ENCRYPTION_KEY kosong.');
        return $value;
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'tamasya-config-v1', 16);
    if ($ciphertext === false) throw new RuntimeException('Enkripsi secret konfigurasi gagal.');
    return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
}

