<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 4102: sendHttpPost */
function sendHttpPost($url, $payload, $headers = [], $requestOptions = []) {
    if (!empty($GLOBALS['is_telegram_simulation']) && strpos($url, "api.telegram.org") !== false) {
        return json_encode(["ok" => true, "result" => ["message_id" => time(), "chat" => ["id" => 123456], "text" => "Simulated response"]]);
    }
    if (!tamasyaExternalSideEffectsAllowed()) {
        $host=(string)(parse_url((string)$url,PHP_URL_HOST)?:'unknown');
        error_log('[api.php] Outbound HTTP side effect to '.$host.' suppressed because this node is not the active Primary with a valid lease.');
        return false;
    }
    $jsonPayload = is_array($payload) || is_object($payload) ? json_encode($payload) : $payload;
    $outboundHost=strtolower((string)(parse_url((string)$url,PHP_URL_HOST)?:''));
    $isTelegramHost=$outboundHost==='api.telegram.org' || str_ends_with($outboundHost,'.telegram.org');
    $defaultTimeout=$isTelegramHost ? max(4,min(30,(int)(getenv('TELEGRAM_HTTP_TIMEOUT_SECONDS')?:12))) : 15;
    $defaultConnect=$isTelegramHost ? max(2,min(15,(int)(getenv('TELEGRAM_CONNECT_TIMEOUT_SECONDS')?:4))) : min(8,$defaultTimeout);
    $timeout = max(3, min(60, (int)($requestOptions['timeout'] ?? $defaultTimeout)));
    $connectTimeout = max(2, min($timeout, (int)($requestOptions['connectTimeout'] ?? min($defaultConnect, $timeout))));
    $hasContentType = false;
    foreach ($headers as $h) {
        if (stripos($h, "Content-Type") !== false) {
            $hasContentType = true;
            break;
        }
    }
    if (!$hasContentType) {
        $headers[] = "Content-Type: application/json";
    }

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $result = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        unset($ch);
        if ($result === false || $httpStatus < 200 || $httpStatus >= 300 || (isset($requestOptions['expectedStatus']) && $httpStatus !== (int)$requestOptions['expectedStatus'])) {
            error_log('[api.php] HTTPS POST gagal ke host ' . (parse_url($url, PHP_URL_HOST) ?: 'unknown') .
                '; HTTP=' . $httpStatus . '; error=' . $curlError);
            return false;
        }
        return $result;
    } else if (ini_get('allow_url_fopen')) {
        $streamOptions = [
            'http' => [
                'header'  => implode("\r\n", $headers),
                'method'  => 'POST',
                'content' => $jsonPayload,
                'timeout' => $timeout,
                'ignore_errors' => false,
                'follow_location' => 0
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ]
        ];
        $context  = stream_context_create($streamOptions);
        $result=@file_get_contents($url, false, $context);
        if(isset($requestOptions['expectedStatus'])){
            $responseHeaders=function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : (get_defined_vars()['http_response_header'] ?? []);
            if(!preg_match('#^HTTP/\S+\s+(\d{3})#',(string)($responseHeaders[0]??''),$match)||(int)$match[1]!== (int)$requestOptions['expectedStatus'])return false;
        }
        return $result;
    }
    return false;
}

/** Validate canonical Telegram config columns; never ALTER schema from a request. */
function ensureTelegramWebhookColumns($pdo) {
    if (!$pdo instanceof PDO) return;
    $required = ['telegram_webhook_secret','telegram_webhook_url','telegram_webhook_last_error','telegram_webhook_checked_at'];
    $missing=[];
    foreach ($required as $column) {
        if (tamasyaSchemaColumnMeta($pdo,'config',$column) === null) $missing[]=$column;
    }
    if ($missing) {
        throw new RuntimeException('Schema konfigurasi Telegram tidak sesuai baseline fresh V137: '.implode(', ', $missing).'. Gunakan database_setup.sql yang sesuai source release ini pada database baru.');
    }
}

/** Source line 4194: getTelegramApiBaseUrl */
function getTelegramApiBaseUrl() {
    $base = trim((string)(getenv('TELEGRAM_API_BASE_URL') ?: 'https://api.telegram.org'));
    return rtrim($base, '/');
}

/** Source line 4199: telegramApiCall */
function telegramApiCall($token, $method, $payload = [], $timeout = null) {
    if (!empty($GLOBALS['is_telegram_simulation'])) {
        if ($method === 'getMe') {
            return ['ok' => true, 'httpCode' => 200, 'data' => ['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'simulation_bot']], 'raw' => ''];
        }
        if ($method === 'getWebhookInfo') {
            return ['ok' => true, 'httpCode' => 200, 'data' => ['ok' => true, 'result' => ['url' => $GLOBALS['telegram_simulated_webhook_url'] ?? '', 'pending_update_count' => 0]], 'raw' => ''];
        }
        if ($method === 'setWebhook') {
            $GLOBALS['telegram_simulated_webhook_url'] = (string)($payload['url'] ?? '');
        }
        return ['ok' => true, 'httpCode' => 200, 'data' => ['ok' => true, 'result' => true], 'raw' => ''];
    }

    if (!tamasyaExternalSideEffectsAllowed()) {
        return ['ok'=>false,'httpCode'=>0,'data'=>null,'raw'=>'','suppressed'=>true,'transportError'=>'Telegram hanya dieksekusi oleh primary aktif.'];
    }

    $timeout=$timeout===null ? (int)(getenv('TELEGRAM_HTTP_TIMEOUT_SECONDS')?:12) : (int)$timeout;
    $timeout=max(4,min(60,$timeout));
    $connectTimeout=max(2,min($timeout,(int)(getenv('TELEGRAM_CONNECT_TIMEOUT_SECONDS')?:4)));
    $endpoint = getTelegramApiBaseUrl() . '/bot' . $token . '/' . $method;
    $jsonPayload = json_encode((object)$payload, JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'TamasyaHotelBot/1.0'
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'httpCode' => $httpCode,
                'data' => null,
                'raw' => '',
                'transportError' => "cURL {$errno}: {$error}"
            ];
        }
        $decoded = json_decode($raw, true);
        return [
            'ok' => $httpCode >= 200 && $httpCode < 300 && is_array($decoded) && !empty($decoded['ok']),
            'httpCode' => $httpCode,
            'data' => is_array($decoded) ? $decoded : null,
            'raw' => $raw,
            'transportError' => null
        ];
    }

    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json
Accept: application/json
User-Agent: TamasyaHotelBot/1.0
",
                'content' => $jsonPayload,
                'timeout' => $timeout,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        $raw = @file_get_contents($endpoint, false, $context);
        $httpCode = 0;
        $responseHeaders=(function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : (get_defined_vars()['http_response_header'] ?? []));
        if (!empty($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $m)) {
            $httpCode = (int)$m[1];
        }
        if ($raw === false) {
            $last = error_get_last();
            return ['ok' => false, 'httpCode' => $httpCode, 'data' => null, 'raw' => '', 'transportError' => $last['message'] ?? 'Koneksi HTTPS ke Telegram gagal'];
        }
        $decoded = json_decode($raw, true);
        return [
            'ok' => $httpCode >= 200 && $httpCode < 300 && is_array($decoded) && !empty($decoded['ok']),
            'httpCode' => $httpCode,
            'data' => is_array($decoded) ? $decoded : null,
            'raw' => $raw,
            'transportError' => null
        ];
    }

    return ['ok' => false, 'httpCode' => 0, 'data' => null, 'raw' => '', 'transportError' => 'Ekstensi cURL tidak tersedia dan allow_url_fopen nonaktif'];
}

/** Source line 4299: telegramSendBookingIdentityPhoto */
function telegramSendBookingIdentityPhoto(PDO $pdo, string $token, $chatId, string $storedValue, string $caption = ''): array {
    if (!empty($GLOBALS['is_telegram_simulation'])) return ['ok'=>true,'simulated'=>true];
    // Identity photos are an external Telegram side effect just like text/API
    // calls. Never let the raw multipart cURL upload bypass Primary fencing.
    if (!tamasyaExternalSideEffectsAllowed()) {
        return ['ok'=>false,'error'=>'Telegram hanya dieksekusi oleh primary aktif.','suppressed'=>true];
    }
    $storedValue = trim($storedValue);
    if ($storedValue === '') return ['ok'=>false,'error'=>'KTP tidak tersedia'];

    if (str_starts_with($storedValue, 'telegram_file_id:')) {
        $fileId = trim(substr($storedValue, strlen('telegram_file_id:')));
        $result = telegramApiCall($token, 'sendPhoto', [
            'chat_id'=>$chatId,
            'photo'=>$fileId,
            'caption'=>$caption,
            'parse_mode'=>'Markdown'
        ]);
        return ['ok'=>!empty($result['ok']),'error'=>!empty($result['ok'])?null:telegramApiErrorMessage($result)];
    }

    $identity = bookingIdentityDataUrl($pdo, $storedValue);
    $dataUrl = (string)($identity['dataUrl'] ?? '');
    if ($dataUrl === '' || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $dataUrl, $m)) {
        return ['ok'=>false,'error'=>$identity['reason'] ?? 'File KTP tidak dapat dibaca'];
    }
    $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 8 * 1024 * 1024) return ['ok'=>false,'error'=>'Ukuran KTP tidak valid'];
    if (!function_exists('curl_init') || !class_exists('CURLFile')) return ['ok'=>false,'error'=>'Server tidak mendukung upload foto ke Telegram'];
    $ext = $m[1] === 'image/png' ? '.png' : ($m[1] === 'image/webp' ? '.webp' : '.jpg');
    $tmp = tempnam(sys_get_temp_dir(), 'tgh_ktp_');
    if ($tmp === false || file_put_contents($tmp, $bytes) === false) return ['ok'=>false,'error'=>'Gagal menyiapkan foto sementara'];
    $tmpWithExt = $tmp . $ext;
    @rename($tmp, $tmpWithExt);
    $uploadPath = is_file($tmpWithExt) ? $tmpWithExt : $tmp;
    try {
        $ch = curl_init(getTelegramApiBaseUrl() . '/bot' . $token . '/sendPhoto');
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>[
                'chat_id'=>(string)$chatId,
                'photo'=>new CURLFile($uploadPath, $m[1], 'ktp'.$ext),
                'caption'=>$caption,
                'parse_mode'=>'Markdown'
            ],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>30,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return ['ok'=>$http>=200&&$http<300&&!empty($json['ok']),'error'=>$error ?: ($json['description'] ?? null)];
    } finally {
        @unlink($uploadPath);
        if ($uploadPath !== $tmp) @unlink($tmp);
    }
}

/** Source line 4358: telegramApiErrorMessage */
function telegramApiErrorMessage($result, $fallback = 'Telegram API gagal dihubungi') {
    if (!empty($result['transportError'])) {
        return $result['transportError'];
    }
    if (!empty($result['data']['description'])) {
        return (string)$result['data']['description'];
    }
    if (!empty($result['raw'])) {
        return substr((string)$result['raw'], 0, 500);
    }
    return $fallback . (!empty($result['httpCode']) ? ' (HTTP ' . $result['httpCode'] . ')' : '');
}

/** Telegram call yang wajib berhasil agar webhook tidak melaporkan sukses palsu. */
function telegramApiCallRequired($token, string $method, array $payload = [], bool $allowMessageNotModified = false): array {
    $result = telegramApiCall($token,$method,$payload);
    if (!empty($result['ok'])) return $result;
    $message = telegramApiErrorMessage($result);
    if ($allowMessageNotModified && stripos($message,'message is not modified') !== false) {
        return ['ok'=>true,'harmless'=>true,'data'=>$result['data'] ?? null];
    }
    throw new RuntimeException('Telegram '.$method.' gagal: '.$message);
}

/** Source line 4371: buildPublicWebhookUrl */
function buildPublicWebhookUrl() {
    $forwardedProto = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
    $scheme = $forwardedProto !== '' ? strtolower($forwardedProto) : ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http');
    $forwardedHost = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
    $host = $forwardedHost !== '' ? $forwardedHost : (string)($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/api.php');
    if ($script === '' || substr($script, -7) !== 'api.php') {
        $script = rtrim(str_replace('\\', '/', dirname($script)), '/') . '/api.php';
    }
    return $scheme . '://' . $host . $script . '?action=telegram-webhook';
}

/** Source line 4384: validateTelegramWebhookUrl */
function validateTelegramWebhookUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return 'URL webhook yang dibentuk server tidak valid: ' . $url;
    }
    $parts = parse_url($url);
    if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
        return 'Webhook Telegram wajib memakai HTTPS. URL saat ini: ' . $url;
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return 'Webhook harus memakai domain publik, bukan localhost.';
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : 443;
    if (!in_array($port, [443, 80, 88, 8443], true)) {
        return 'Port webhook tidak didukung Telegram. Gunakan 443, 80, 88, atau 8443.';
    }
    return null;
}

/** Source line 4404: logActivity */
function logActivity($pdo, $actionType, $description, $staffId = null, $staffName = null) {
    if (!$pdo) {
        return;
    }
    try {
        $id = generateServerId('log');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        $stmt = $pdo->prepare("INSERT INTO activity_logs (id, staff_id, staff_name, action_type, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $staffId, $staffName, $actionType, $description, $ip, $ua]);
    } catch (Throwable $e) {
        // Silently fail to not prevent core business operations
    }
}

/** Source line 4421: sendSmtpMail */
function sendSmtpMail($to, $subject, $message, $config, array $attachments = []) {
    if (!tamasyaExternalSideEffectsAllowed()) {
        error_log('[api.php] SMTP side effect suppressed because this node is not the active Primary with a valid lease.');
        return false;
    }
    $smtp_host = $config['smtpHost'] ?? $config['smtp_host'] ?? '';
    $smtp_port = (int)($config['smtpPort'] ?? $config['smtp_port'] ?? 587);
    $smtp_user = $config['smtpUser'] ?? $config['smtp_user'] ?? '';
    $smtp_pass = decryptStoredSecret($config['smtpPassword'] ?? $config['smtp_password'] ?? '');
    $smtp_secure = strtolower($config['smtpSecure'] ?? $config['smtp_secure'] ?? 'tls');
    $from_email = $config['smtpFrom'] ?? $config['smtp_from'] ?? '';

    if (empty($smtp_host)) {
        return false;
    }
    if (!filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email pengirim SMTP belum dikonfigurasi dengan alamat yang valid.');
    }

    $smtp_host = trim((string)$smtp_host);
    if (!filter_var($smtp_host, FILTER_VALIDATE_IP)
        && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $smtp_host)) {
        throw new InvalidArgumentException('Host SMTP tidak valid.');
    }
    if ($smtp_port < 1 || $smtp_port > 65535) throw new InvalidArgumentException('Port SMTP tidak valid.');
    if (!in_array($smtp_secure, ['tls','ssl','none'], true)) throw new InvalidArgumentException('Mode keamanan SMTP harus tls, ssl, atau none.');

    $tlsContext = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $smtp_host,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ],
    ]);
    $transport = $smtp_secure === 'ssl' ? 'tls://' : 'tcp://';
    $endpoint = $transport . $smtp_host . ':' . $smtp_port;
    $socket = @stream_socket_client($endpoint, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $tlsContext);
    if (!$socket) {
        throw new Exception("Koneksi SMTP ke {$smtp_host}:{$smtp_port} gagal: {$errstr} ({$errno})");
    }
    stream_set_timeout($socket, 20);

    // Read initial greeting
    $greet = fgets($socket, 515);

    // EHLO
    fwrite($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
    $res = "";
    while (($line = fgets($socket, 515)) !== false) {
        $res .= $line;
        if (substr($line, 3, 1) === " ") break;
    }

    // TLS Upgrade
    if ($smtp_secure === 'tls') {
        fwrite($socket, "STARTTLS\r\n");
        $tlsGreet = fgets($socket, 515);
        if (strpos($tlsGreet, '220') === false) {
            fclose($socket);
            throw new Exception("STARTTLS ditolak oleh server SMTP: " . trim($tlsGreet));
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            throw new Exception("Gagal mengaktifkan enkripsi TLS pada soket.");
        }
        fwrite($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
        $res = "";
        while (($line = fgets($socket, 515)) !== false) {
            $res .= $line;
            if (substr($line, 3, 1) === " ") break;
        }
    }

    // Authentication
    if (!empty($smtp_user) && !empty($smtp_pass)) {
        fwrite($socket, "AUTH LOGIN\r\n");
        $authRes = fgets($socket, 515);
        
        fwrite($socket, base64_encode($smtp_user) . "\r\n");
        $userRes = fgets($socket, 515);
        
        fwrite($socket, base64_encode($smtp_pass) . "\r\n");
        $passRes = fgets($socket, 515);
        
        if (strpos($passRes, '235') === false) {
            fclose($socket);
            throw new Exception("Otentikasi SMTP gagal: Username atau password salah. Respon server: " . trim($passRes));
        }
    }

    // Mail FROM
    fwrite($socket, "MAIL FROM: <$from_email>\r\n");
    fgets($socket, 515);

    // RCPT TO
    fwrite($socket, "RCPT TO: <$to>\r\n");
    $rcptRes = fgets($socket, 515);
    if (strpos($rcptRes, '250') === false && strpos($rcptRes, '251') === false) {
        fclose($socket);
        throw new Exception("Email tujuan <$to> ditolak oleh server SMTP: " . trim($rcptRes));
    }

    // DATA
    fwrite($socket, "DATA\r\n");
    fgets($socket, 515);

    // Headers & Body. Canonical reports may provide binary attachments; legacy
    // callers still use the original HTML-only contract.
    $headers = "MIME-Version: 1.0\r\n";
    $mimeBody = (string)$message;
    if ($attachments) {
        if (!function_exists('tamasyaBuildMimeMessage')) {
            fclose($socket);
            throw new RuntimeException('MIME attachment helper laporan tidak tersedia.');
        }
        $mime = tamasyaBuildMimeMessage((string)$message, $attachments);
        $headers .= "Content-Type: " . $mime['contentType'] . "\r\n";
        $mimeBody = (string)$mime['body'];
    } else {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    }
    $headers .= "To: <$to>\r\n";
    $headers .= "From: <$from_email>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "X-Mailer: Tamasya-SMTP-Client\r\n";

    // Double-dot escape for SMTP DATA lines starting with a dot.
    $escaped_message = str_replace("\r\n.", "\r\n..", $mimeBody);

    fwrite($socket, $headers . "\r\n" . $escaped_message . "\r\n.\r\n");
    $dataRes = fgets($socket, 515);

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return strpos($dataRes, '250') !== false;
}

/** Source line 4532: parseTelegramChatIds */
function parseTelegramChatIds($raw): array {
    if (is_array($raw)) {
        $values = $raw;
    } else {
        $text = trim((string)($raw ?? ''));
        if ($text === '') return [];
        $decoded = json_decode($text, true);
        $values = is_array($decoded) ? $decoded : preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }
    $result = [];
    foreach ($values as $value) {
        $id = trim((string)$value);
        if ($id !== '' && preg_match('/^-?\d+$/', $id)) $result[$id] = $id;
    }
    return array_values($result);
}


/** Canonical Telegram routing classifier.
 *  Callers that know the business domain should pass an explicit override.
 *  Auto-classification remains only for legacy call sites. Broad words such as
 *  "tamu" and "kamar" are intentionally NOT booking signals because they also
 *  occur in Housekeeping, Maintenance, Lost & Found and Guest Service events.
 */
function tamasyaTelegramMessageType(string $message, bool $isFinancial=false, string $override=''): string {
    $override=strtolower(trim($override));
    $allowed=['bookings','finance','inventory','operations','hr','system'];
    if(in_array($override,$allowed,true))return $override;
    if($isFinancial)return 'finance';

    $text=strtolower($message);
    if(preg_match('/absen|absensi|cuti|kehadiran/u',$text))return 'hr';
    if(preg_match('/aset|inventaris|inventory|stok|pemeliharaan aset|perawatan aset/u',$text))return 'inventory';
    if(preg_match('/housekeeping|night audit|shift|kamar dilaporkan kosong|late check-out|kontrol kunci|smart lock|laporan lapangan|maintenance|engineering|kerusakan|lost\s*&\s*found|barang tertinggal|permintaan tamu|layanan tamu|guest service|room hold|operasional/u',$text))return 'operations';
    if(preg_match('/check-in|check-out|booking|reservasi|sewa kamar|room rental|room sale/u',$text))return 'bookings';
    if(preg_match('/transaksi|pemasukan|pengeluaran|gaji|nominal|harga|biaya|bayar|pembayaran|saldo|kas|tagihan|refund|transfer|qris|tunai|\\brp\\b/u',$text))return 'finance';
    if(preg_match('/sistem|2fa|mendaftar|login|keamanan akun/u',$text))return 'system';
    return 'system';
}

/** Remove Telegram Markdown control characters from user/business free text. */
function tamasyaTelegramPlainText($value, int $maxLength=500): string {
    $text=trim((string)$value);
    $text=preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u','',$text) ?? '';
    $text=str_replace(["*","_","`","[","]"],['','','','',''],$text);
    $text=preg_replace('/\\s+/u',' ',$text) ?? $text;
    if(function_exists('mb_substr'))return mb_substr($text,0,$maxLength);
    return substr($text,0,$maxLength);
}

/** Resolve the exact Telegram recipients once, before any network call. */
function tamasyaTelegramBroadcastTargets($pdo, $message, $isFinancial = false, $messageTypeOverride = '', $replyMarkup = null): array {
    $stmt = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
    $conf = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $token = trim(decryptStoredSecret($conf['telegram_bot_token'] ?? ''));
    if ($token === '') return ['token'=>'','messageType'=>'system','targets'=>[]];

    $chatIds = parseTelegramChatIds($conf['telegram_chat_ids'] ?? ($conf['telegram_chat_id'] ?? ($conf['chat_id'] ?? '')));
    $stmtStaff = $pdo->query("SELECT tb.telegram_user_id AS telegram_chat_id,s.id AS staff_id,s.role,s.permissions
        FROM telegram_bindings tb
        JOIN staff s ON s.id=tb.staff_id
        WHERE tb.status='active' AND s.status='active'");
    $staffList = $stmtStaff->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $chatIdToStaff = [];
    foreach ($staffList as $staff) {
        $cId = trim((string)($staff['telegram_chat_id'] ?? ''));
        if ($cId !== '') {
            $chatIdToStaff[$cId] = $staff;
            $chatIds[] = $cId;
        }
    }
    $chatIds = array_values(array_unique(array_map('strval', (array)$chatIds)));
    $messageType=tamasyaTelegramMessageType((string)$message,(bool)$isFinancial,(string)$messageTypeOverride);
    $isFn = $messageType==='finance' || (bool)preg_match('/transaksi|pemasukan|pengeluaran|gaji|nominal|harga|biaya|bayar|pembayaran|saldo|kas|tagihan|refund|transfer|qris|tunai|\brp\b/i', (string)$message);
    $defaultTelegramRoles = [
        'bookings'=>['admin','manager','receptionist'],
        'finance'=>['admin','manager','finance'],
        'inventory'=>['admin','manager','receptionist','koki','cleaning_service'],
        'operations'=>['admin','manager','receptionist','cleaning_service','keamanan'],
        'hr'=>['admin','manager'],
        'system'=>['admin','manager'],
    ];
    $targets=[];
    foreach ($chatIds as $chatId) {
        $trimmedChatId = trim((string)$chatId);
        if ($trimmedChatId === '') continue;
        if ((int)$trimmedChatId > 0 && !isset($chatIdToStaff[$trimmedChatId])) continue;

        $staffId=null;
        if (isset($chatIdToStaff[$trimmedChatId])) {
            $staff = $chatIdToStaff[$trimmedChatId];
            $staffId=trim((string)($staff['staff_id']??'')) ?: null;
            $role = strtolower(trim((string)($staff['role']??'')));
            $defaultAllowed = in_array($role, $defaultTelegramRoles[$messageType] ?? ['admin','manager'], true);
            // Reuse the canonical fail-closed permission parser. Corrupt JSON or
            // malformed groups must never fall back to a privileged role default.
            $notificationOverride = function_exists('tamasyaPermissionOverride')
                ? tamasyaPermissionOverride($staff['permissions'] ?? null, 'telegramNotifications', $messageType)
                : false;
            if ($notificationOverride !== null) {
                if ($notificationOverride !== true) continue;
            } elseif (!$defaultAllowed) {
                continue;
            }
        } else {
            $isManualOrFinancial = $isFinancial ||
                (stripos((string)$message, '(manual)') !== false) ||
                (stripos((string)$message, 'pemasukan manual') !== false) ||
                (stripos((string)$message, 'pengeluaran manual') !== false) ||
                (stripos((string)$message, 'input manual') !== false);
            if ($isManualOrFinancial) continue;
        }
        if ($isFn && (int)$trimmedChatId < 0) continue;

        $chatMessage = (string)$message;
        if ((int)$trimmedChatId < 0) {
            $lines = explode("\n", $chatMessage);
            $filteredLines = [];
            foreach ($lines as $line) {
                if (stripos($line, 'Total:') !== false ||
                    stripos($line, 'Pembayaran:') !== false ||
                    stripos($line, 'Nominal:') !== false ||
                    stripos($line, 'Total Bayar:') !== false ||
                    stripos($line, 'Harga:') !== false) continue;
                $filteredLines[] = $line;
            }
            $chatMessage = implode("\n", $filteredLines);
        }
        $targetReplyMarkup=null;
        if($replyMarkup && (int)$trimmedChatId>0 && isset($chatIdToStaff[$trimmedChatId]))$targetReplyMarkup=$replyMarkup;
        $targets[]=[
            'chatId'=>$trimmedChatId,
            'staffId'=>$staffId,
            'message'=>$chatMessage,
            'replyMarkup'=>$targetReplyMarkup,
        ];
    }
    return ['token'=>$token,'messageType'=>$messageType,'targets'=>$targets];
}

/** Queue generic Telegram broadcasts durably before provider delivery. */
function tamasyaQueueTelegramBroadcast(PDO $pdo, string $message, bool $isFinancial=false, string $messageTypeOverride='', $replyMarkup=null): array {
    $routing=tamasyaTelegramBroadcastTargets($pdo,$message,$isFinancial,$messageTypeOverride,$replyMarkup);
    $targets=(array)($routing['targets']??[]);
    if(!$targets)return [];
    static $legacyChannelSynced=false;
    if(!$legacyChannelSynced && function_exists('tamasyaCommunicationSyncLegacyChannels')){
        tamasyaCommunicationSyncLegacyChannels($pdo);
        $legacyChannelSynced=true;
    }
    $ids=[];
    foreach($targets as $target){
        $chatId=trim((string)($target['chatId']??''));
        if($chatId==='')continue;
        $payload=[
            'eventType'=>'telegram_broadcast_'.substr((string)($routing['messageType']??'system'),0,70),
            'recipientStaffId'=>$target['staffId']??null,
            'preferredChannelId'=>'channel_telegram_main',
            'fallbackChannelIds'=>[],
            'priority'=>$isFinancial?8:6,
            'message'=>[
                'text'=>(string)($target['message']??''),
                'parseMode'=>'Markdown',
                'replyMarkup'=>$target['replyMarkup']??null,
                '_tamasyaDirectRecipient'=>'telegram_broadcast_v1',
                '_directChannelId'=>'channel_telegram_main',
                '_providerConversationId'=>$chatId,
                '_providerUserId'=>$chatId,
            ],
        ];
        $ids[]=tamasyaCommunicationQueue($pdo,$payload,['id'=>null,'name'=>'Telegram Broadcast','role'=>'system']);
    }
    return $ids;
}

/** Source line 4550: broadcastTelegramNotification */
function broadcastTelegramNotification($pdo, $message, $isFinancial = false, $messageTypeOverride = '', $replyMarkup = null) {
    if (!tamasyaExternalSideEffectsAllowed()) return;
    $message=(string)$message;
    if($message==='')return;

    $durableEnabled = strtolower(trim((string)(getenv('TAMASYA_DURABLE_TELEGRAM_BROADCAST') ?: '1'))) !== '0';
    if(!$durableEnabled || !function_exists('tamasyaCommunicationQueue') || !function_exists('tamasyaCommunicationProcessOutbox') || !empty($GLOBALS['is_telegram_simulation'])){
        tamasyaBroadcastTelegramNotificationNow($pdo,$message,$isFinancial,(string)$messageTypeOverride,$replyMarkup);
        return;
    }

    try{
        $queuedIds=tamasyaQueueTelegramBroadcast($pdo,$message,(bool)$isFinancial,(string)$messageTypeOverride,$replyMarkup);
        if(!$queuedIds)return;
    }catch(Throwable $queueError){
        // Compatibility fallback only when the durable subsystem itself is unavailable.
        // Provider failures do not use this path; they remain in communication_outbox retry.
        error_log('[Telegram Durable Broadcast Queue] '.$queueError->getMessage());
        tamasyaBroadcastTelegramNotificationNow($pdo,$message,$isFinancial,(string)$messageTypeOverride,$replyMarkup);
        return;
    }

    $deferEnabled = PHP_SAPI !== 'cli'
        && function_exists('fastcgi_finish_request')
        && strtolower(trim((string)(getenv('TAMASYA_DEFER_TELEGRAM_BROADCAST') ?: '1'))) !== '0';

    if(!$pdo->inTransaction() && !$deferEnabled){
        try{tamasyaCommunicationProcessOutbox($pdo,100,['id'=>null,'name'=>'Telegram Broadcast','role'=>'system']);}
        catch(Throwable $e){error_log('[Telegram Durable Broadcast Process] '.$e->getMessage());}
        return;
    }

    if(!empty($GLOBALS['tamasya_deferred_communication_outbox_registered']))return;
    $GLOBALS['tamasya_deferred_communication_outbox_registered']=true;
    register_shutdown_function(static function() use ($pdo): void {
        try{@fastcgi_finish_request();}catch(Throwable $ignored){}
        try{
            if($pdo instanceof PDO && !$pdo->inTransaction()){
                tamasyaCommunicationProcessOutbox($pdo,100,['id'=>null,'name'=>'Telegram Broadcast','role'=>'system']);
            }
        }catch(Throwable $e){error_log('[Telegram Deferred Durable Broadcast] '.$e->getMessage());}
    });
}

function tamasyaBroadcastTelegramNotificationNow($pdo, $message, $isFinancial = false, $messageTypeOverride = '', $replyMarkup = null) {
    if (!tamasyaExternalSideEffectsAllowed()) return;
    try {
        $routing=tamasyaTelegramBroadcastTargets($pdo,(string)$message,(bool)$isFinancial,(string)$messageTypeOverride,$replyMarkup);
        $token=(string)($routing['token']??'');
        if($token==='')return;
        foreach((array)($routing['targets']??[]) as $target){
            $payload=[
                'chat_id'=>(string)($target['chatId']??''),
                'text'=>(string)($target['message']??''),
                'parse_mode'=>'Markdown',
            ];
            if(!empty($target['replyMarkup'])&&is_array($target['replyMarkup']))$payload['reply_markup']=$target['replyMarkup'];
            $result=telegramApiCall($token,'sendMessage',$payload,4);
            if(empty($result['ok']))error_log('[Telegram Broadcast] '.telegramApiErrorMessage($result,'Pengiriman Telegram gagal.'));
        }
    } catch (Throwable $e) {
        error_log('[Telegram Broadcast] ' . $e->getMessage());
    }
}

/** Source line 4686: getCheckInTelegramMessage */
function getCheckInTelegramMessage($booking, $roomType, $taxDetailStr = "", $staffName = "System") {
    $nights = (strtotime($booking['checkOut']) - strtotime($booking['checkIn'])) / 86400;
    $nights = max(1, (int)round($nights));
    
    $pStatus = $booking['paymentStatus'] ?? 'unpaid';
    $pMethodLabel = strtoupper($pStatus);
    if ($pStatus === 'paid') {
        if (!empty($booking['isSplitPayment'])) {
            $nonCashLabel = "Transfer/QRIS";
            if (!empty($booking['splitTransferBankAccountId'])) {
                global $pdo;
                if (isset($pdo)) {
                    $accStmt = $pdo->prepare("SELECT type FROM bank_accounts WHERE id = ?");
                    $accStmt->execute([$booking['splitTransferBankAccountId']]);
                    $accRow = $accStmt->fetch(PDO::FETCH_ASSOC);
                    if ($accRow) {
                        $nonCashLabel = ($accRow['type'] === 'edc_qris') ? 'QRIS' : 'Transfer';
                    }
                }
            }
            $pMethodLabel = "LUNAS (SPLIT -> Tunai: Rp " . number_format($booking['splitCashAmount'] ?? 0, 0, ',', '.') . ", " . $nonCashLabel . ": Rp " . number_format($booking['splitTransferAmount'] ?? 0, 0, ',', '.') . ")";
        } else {
            $pMethod = $booking['paymentMethod'] ?? '';
            $pMethodLabel = "LUNAS (" . ($pMethod === 'qris' ? 'QRIS' : ($pMethod === 'transfer' ? 'TRANSFER' : 'TUNAI')) . ")";
        }
    }
    
    $phoneStr = !empty($booking['guestPhone']) ? " (" . $booking['guestPhone'] . ")" : "";
    $sourceStr = !empty($booking['bookingSource']) ? $booking['bookingSource'] : "Direct";
    $roomNum = $booking['roomNumber'] ?? '';
    
    return "🟢 *REGISTRASI TAMU BARU* (Check-in)\n" .
           "──────────────────\n" .
           "👤 Tamu: *" . ($booking['guestName'] ?? '') . "*" . $phoneStr . "\n" .
           "🔑 Kamar: *" . $roomNum . "* (" . $roomType . ")\n" .
           "📅 Check-in: *" . ($booking['checkIn'] ?? '') . "*\n" .
           "📅 Check-out: *" . ($booking['checkOut'] ?? '') . "* (" . $nights . " Malam)\n" .
           "ℹ️ Sumber: *" . $sourceStr . "*\n" .
           "💰 Total: *Rp " . number_format($booking['totalAmount'] ?? 0, 0, ',', '.') . "*\n" .
           $taxDetailStr .
           "💳 Pembayaran: *" . $pMethodLabel . "*\n" .
           "👤 Oleh: *" . $staffName . "*\n" .
           "──────────────────\n" .
           "📌 *PERINTAH CEPAT (HP/BOT)*:\n" .
           "• Check-out kamar ini: `/checkout " . $roomNum . "`\n" .
           "• Cetak nota kamar ini: `/cetak_nota " . $roomNum . "`\n" .
           "──────────────────\n" .
           "Data tersinkronisasi di dasbor hotel.";
}

/** Source line 4737: getCheckOutTelegramMessage */
function getCheckOutTelegramMessage($booking, $roomType, $staffName = "System") {
    $pStatus = $booking['paymentStatus'] ?? 'unpaid';
    $pMethodLabel = strtoupper($pStatus);
    if ($pStatus === 'paid') {
        if (!empty($booking['isSplitPayment'])) {
            $nonCashLabel = "Transfer/QRIS";
            if (!empty($booking['splitTransferBankAccountId'])) {
                global $pdo;
                if (isset($pdo)) {
                    $accStmt = $pdo->prepare("SELECT type FROM bank_accounts WHERE id = ?");
                    $accStmt->execute([$booking['splitTransferBankAccountId']]);
                    $accRow = $accStmt->fetch(PDO::FETCH_ASSOC);
                    if ($accRow) {
                        $nonCashLabel = ($accRow['type'] === 'edc_qris') ? 'QRIS' : 'Transfer';
                    }
                }
            }
            $pMethodLabel = "LUNAS (SPLIT -> Tunai: Rp " . number_format($booking['splitCashAmount'] ?? 0, 0, ',', '.') . ", " . $nonCashLabel . ": Rp " . number_format($booking['splitTransferAmount'] ?? 0, 0, ',', '.') . ")";
        } else {
            $pMethod = $booking['paymentMethod'] ?? '';
            $pMethodLabel = "LUNAS (" . ($pMethod === 'qris' ? 'QRIS' : ($pMethod === 'transfer' ? 'TRANSFER' : 'TUNAI')) . ")";
        }
    }
    
    return "🔴 *TAMU CHECK-OUT SELESAI*\n" .
           "──────────────────\n" .
           "👤 Tamu: *" . ($booking['guestName'] ?? '') . "*\n" .
           "🔑 Kamar: *" . ($booking['roomNumber'] ?? '') . "* (" . $roomType . ")\n" .
           "📅 Check-in: *" . ($booking['checkIn'] ?? '') . "*\n" .
           "📅 Check-out: *" . ($booking['checkOut'] ?? '') . "*\n" .
           "💰 Total: *Rp " . number_format($booking['totalAmount'] ?? 0, 0, ',', '.') . "*\n" .
           "💳 Pembayaran: *" . $pMethodLabel . "*\n" .
           "👤 Oleh: *" . $staffName . "*\n" .
           "──────────────────\n" .
           "📌 *PERINTAH CEPAT (HP/BOT)*:\n" .
           "• Cek ketersediaan kamar live: `/status_kamar`\n" .
           "──────────────────\n" .
           "🧹 Status Kamar otomatis dialihkan ke *🛠️ MAINTENANCE* untuk pembersihan oleh divisi Housekeeping.";
}


/** V137 canonical: Telegram callback_data must be <=64 bytes; long operational payloads are tokenized server-side. */
function tamasyaTelegramCompactReplyMarkup(PDO $pdo, ?array $staff, ?array $replyMarkup): ?array {
    if (!$replyMarkup || empty($replyMarkup['inline_keyboard']) || !is_array($replyMarkup['inline_keyboard'])) return $replyMarkup;
    $staffId = trim((string)($staff['id'] ?? ''));
    foreach ($replyMarkup['inline_keyboard'] as $ri => $row) {
        if (!is_array($row)) continue;
        foreach ($row as $bi => $button) {
            if (!is_array($button) || !isset($button['callback_data'])) continue;
            $callback = (string)$button['callback_data'];
            if (strlen($callback) <= 64) continue;
            if ($staffId === '') throw new RuntimeException('Callback Telegram terlalu panjang dan tidak memiliki konteks staf untuk tokenisasi aman.');
            $token = 'tcb_' . bin2hex(random_bytes(12));
            $pdo->prepare("INSERT INTO telegram_callback_tokens(token,staff_id,callback_data,created_at,expires_at) VALUES (?,?,?,CURRENT_TIMESTAMP,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 30 MINUTE))")
                ->execute([$token,$staffId,$callback]);
            $replyMarkup['inline_keyboard'][$ri][$bi]['callback_data'] = $token;
        }
    }
    try { $pdo->exec("DELETE FROM telegram_callback_tokens WHERE expires_at<CURRENT_TIMESTAMP LIMIT 100"); } catch (Throwable $ignored) {}
    return $replyMarkup;
}

function tamasyaTelegramResolveCallbackData(PDO $pdo, ?array $staff, string $callbackData): string {
    if (!str_starts_with($callbackData,'tcb_')) return $callbackData;
    $staffId = trim((string)($staff['id'] ?? ''));
    if ($staffId === '') throw new RuntimeException('Token tombol Telegram tidak memiliki sesi staf aktif.');
    if (!preg_match('/^tcb_[a-f0-9]{24}$/',$callbackData)) throw new RuntimeException('Format token tombol Telegram tidak valid.');
    $stmt=$pdo->prepare("SELECT callback_data FROM telegram_callback_tokens WHERE token=? AND staff_id=? AND expires_at>=CURRENT_TIMESTAMP LIMIT 1");
    $stmt->execute([$callbackData,$staffId]);
    $resolved=$stmt->fetchColumn();
    if (!is_string($resolved) || $resolved==='') throw new RuntimeException('Tombol Telegram sudah kedaluwarsa. Ulangi langkah dari menu bot.');
    return $resolved;
}

/** Explicit, booking-bound payment selection for Telegram extension/extra charges. */
function tamasyaTelegramChargeMoney(float $amount): string {
    return 'Rp '.tamasyaTelegramFormatAmount($amount);
}
function tamasyaTelegramChargeAssertRole(array $actor): void {
    if(!in_array(strtolower((string)($actor['role']??'')),['admin','manager','receptionist'],true))throw new RuntimeException('Peran akun tidak berhak menambah biaya booking.');
}
function tamasyaTelegramChargeMarkup(array $ctx): array {
    $nonce=(string)$ctx['nonce'];
    return ['inline_keyboard'=>[
        [['text'=>'💵 Tunai','callback_data'=>'r_charge_method:'.$nonce.':cash']],
        [['text'=>'🏦 Transfer','callback_data'=>'r_charge_method:'.$nonce.':transfer'],['text'=>'📱 QRIS','callback_data'=>'r_charge_method:'.$nonce.':qris']],
        [['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]
    ]];
}
function tamasyaTelegramChargeSaveContext(PDO $pdo,array $actor,array $ctx): void {
    $pdo->prepare("UPDATE staff SET telegram_state='waiting_for_charge_payment',telegram_context=? WHERE id=?")
        ->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(string)$actor['id']]);
}
function tamasyaTelegramChargeBegin(PDO $pdo,array $actor,array $booking,array $payload,string $sourceOperationId): array {
    tamasyaTelegramChargeAssertRole($actor);
    if(($booking['status']??'')!=='active'||empty($booking['id'])||!in_array($payload['action']??'', ['extension','extra'],true))throw new RuntimeException('Booking aktif atau jenis biaya tidak valid.');
    if(!is_finite((float)($payload['amount']??0)) || (float)($payload['amount']??0)<=0 || (float)$payload['amount']>1000000000000)throw new InvalidArgumentException('Nominal biaya tambahan tidak valid.');
    $payload['bookingId']=(string)$booking['id'];$payload['roomNumber']=(string)$booking['roomNumber'];$payload['paymentStatus']='paid';
    $payload['expectedCheckOut']=(string)$booking['checkOut'];$payload['expectedTotalAmount']=round((float)$booking['totalAmount'],2);
    unset($payload['paymentMethod'],$payload['bankAccountId']);
    $ctx=['flow'=>'booking_charge_payment','nonce'=>substr(hash('sha256',(string)$actor['id'].'|'.$sourceOperationId),0,24),
        'expiresAt'=>time()+900,'payload'=>$payload];
    tamasyaTelegramChargeSaveContext($pdo,$actor,$ctx);
    return ['text'=>"💳 *PILIH PEMBAYARAN BIAYA TAMBAHAN*\n\nKamar: *".tamasyaTelegramPlainText($booking['roomNumber'])."*\nTagihan tambahan: *".tamasyaTelegramChargeMoney((float)$payload['amount'])."*\n\nPilih Tunai, Transfer, atau QRIS. Pembayaran baru dicatat setelah konfirmasi; status lunas ini hanya untuk biaya tambahan tersebut.", 'markup'=>tamasyaTelegramChargeMarkup($ctx)];
}
function tamasyaTelegramChargeContext(array $actor,string $nonce): array {
    tamasyaTelegramChargeAssertRole($actor);
    $ctx=json_decode((string)($actor['telegram_context']??''),true);
    if(($actor['telegram_state']??'')!=='waiting_for_charge_payment'||!is_array($ctx)||($ctx['flow']??'')!=='booking_charge_payment'
        ||!is_array($ctx['payload']??null)||!hash_equals((string)($ctx['nonce']??''),$nonce)||(int)($ctx['expiresAt']??0)<time()){
        throw new RuntimeException('Pilihan pembayaran kedaluwarsa. Mulai kembali perpanjangan/layanan dari menu.');
    }
    return $ctx;
}
function tamasyaTelegramChargeConfirmation(PDO $pdo,array $actor,array $ctx): array {
    $p=$ctx['payload'];$labels=['cash'=>'Tunai','transfer'=>'Transfer','qris'=>'QRIS'];
    $method=(string)($p['paymentMethod']??'');
    $account=tamasyaResolvePaymentAccount($pdo,$method,(string)($p['bankAccountId']??''),['context'=>'Biaya tambahan Telegram']);
    $p['bankAccountId']=$account;$ctx['payload']=$p;
    tamasyaTelegramChargeSaveContext($pdo,$actor,$ctx);
    $accountLabel='Kas fisik';
    if($account){$stmt=$pdo->prepare('SELECT name FROM bank_accounts WHERE id=? LIMIT 1');$stmt->execute([$account]);$accountLabel=(string)$stmt->fetchColumn();}
    return ['text'=>"🧾 *KONFIRMASI BIAYA DAN PEMBAYARAN*\n\nKamar: *".tamasyaTelegramPlainText($p['roomNumber'])."*\nBiaya: *".tamasyaTelegramChargeMoney((float)$p['amount'])."*\nMetode: *".$labels[$method]."*\nAkun: *".tamasyaTelegramPlainText($accountLabel)."*\n\nSimpan untuk menambah tagihan dan mencatat penerimaan dengan metode di atas.",
        'markup'=>['inline_keyboard'=>[[['text'=>'✅ Simpan Biaya & Pembayaran','callback_data'=>'r_charge_save:'.$ctx['nonce']]],
            [['text'=>'⬅️ Ganti Metode','callback_data'=>'r_charge_method:'.$ctx['nonce'].':choose']],
            [['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']]]]];
}
function tamasyaTelegramChargeHandle(PDO $pdo,array $actor,string $callbackData): array {
    $parts=explode(':',$callbackData,3);$ctx=tamasyaTelegramChargeContext($actor,(string)($parts[1]??''));$nonce=(string)$ctx['nonce'];
    if($parts[0]==='r_charge_method'){
        $method=(string)($parts[2]??'');
        unset($ctx['payload']['paymentMethod'],$ctx['payload']['bankAccountId']);
        if($method==='choose'){tamasyaTelegramChargeSaveContext($pdo,$actor,$ctx);return ['text'=>'💳 Pilih metode pembayaran biaya tambahan.','markup'=>tamasyaTelegramChargeMarkup($ctx)];}
        if(!in_array($method,['cash','transfer','qris'],true))throw new InvalidArgumentException('Metode pembayaran tidak valid.');
        $ctx['payload']['paymentMethod']=$method;
        if($method==='cash')return tamasyaTelegramChargeConfirmation($pdo,$actor,$ctx);
        $type=$method==='qris'?'edc_qris':'bank';
        $stmt=$pdo->prepare('SELECT id,name FROM bank_accounts WHERE isActive=1 AND type=? ORDER BY name,id');$stmt->execute([$type]);$accounts=$stmt->fetchAll(PDO::FETCH_ASSOC);
        tamasyaTelegramChargeSaveContext($pdo,$actor,$ctx);
        $rows=[];foreach($accounts as $account)$rows[]=[['text'=>tamasyaTelegramPlainText($account['name'],48),'callback_data'=>'r_charge_account:'.$nonce.':'.$account['id']]];
        $rows[]=[['text'=>'⬅️ Ganti Metode','callback_data'=>'r_charge_method:'.$nonce.':choose']];
        $rows[]=[['text'=>'❌ Batalkan','callback_data'=>'cancel_booking_process']];
        return ['text'=>$accounts?'🏦 Pilih akun '.strtoupper($method).' aktif untuk biaya tambahan.':'⚠️ Tidak ada akun '.strtoupper($method).' aktif. Atur rekening/QRIS dahulu atau pilih metode lain. Tidak ada pembayaran yang dicatat.', 'markup'=>['inline_keyboard'=>$rows]];
    }
    if($parts[0]==='r_charge_account'){
        if(!in_array($ctx['payload']['paymentMethod']??'', ['transfer','qris'],true))throw new RuntimeException('Pilih metode bank/QRIS terlebih dahulu.');
        $ctx['payload']['bankAccountId']=(string)($parts[2]??'');
        return tamasyaTelegramChargeConfirmation($pdo,$actor,$ctx);
    }
    if($parts[0]!=='r_charge_save')throw new InvalidArgumentException('Tombol pembayaran tidak dikenal.');
    $payload=$ctx['payload'];
    if(!in_array($payload['paymentMethod']??'', ['cash','transfer','qris'],true))throw new RuntimeException('Metode pembayaran wajib dipilih.');
    // Stable per draft: new message IDs or repeated clicks cannot post the same charge twice.
    $operation='tg_charge_'.hash('sha256',(string)$actor['id'].'|'.$nonce);
    $result=applyCanonicalTelegramBookingChargeWorkflow($pdo,$actor,$payload,$operation);
    $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=? AND telegram_context=?")
        ->execute([(string)$actor['id'],(string)$actor['telegram_context']]);
    $method=strtoupper((string)$payload['paymentMethod']);
    $text="✅ *BIAYA TAMBAHAN DAN PEMBAYARAN TERSIMPAN*\n\nKamar: *".tamasyaTelegramPlainText($payload['roomNumber'])."*\nBiaya diterima: *".tamasyaTelegramChargeMoney((float)$result['amount'])."*\nMetode: *{$method}*";
    if($payload['action']==='extension')$text.="\nCheck-out baru: *".tamasyaTelegramPlainText($result['newCheckOut'])."*";
    $text.="\n\nLunas untuk tambahan ini. Sisa seluruh reservasi mengikuti ledger booking.";
    return ['text'=>$text,'markup'=>['inline_keyboard'=>[[['text'=>'⬅️ Kamar & Tamu','callback_data'=>'guest_ops_menu']]]], 'broadcast'=>[$text,true]];
}

/** Indonesian money input: never turn a minus, decimal point or text into different digits. */
function tamasyaTelegramParseMoney(string $input): ?float {
    $raw=trim(preg_replace('/^rp\.?\s*/i','',trim($input)));
    if(!preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/',$raw))return null;
    $amount=(float)str_replace([ '.', ',' ],[ '', '.' ],$raw);
    return is_finite($amount)&&$amount<=1000000000000?round($amount,2):null;
}
function tamasyaTelegramFormatAmount($amount): string {
    return number_format((float)$amount,abs(round((float)$amount,2)-round((float)$amount))<0.001?0:2,',','.');
}

/** Simulator response data follows the resolved Telegram identity, never the caller role. */
function tamasyaTelegramSimulationHotelData($pdo, ?array $staff): ?array {
    return $staff ? getRoleScopedHotelData($pdo, $staff) : null;
}
