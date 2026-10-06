<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'config',
  1 => 'gemini-test',
  2 => 'telegram-set-webhook',
  3 => 'telegram-webhook-info',
  4 => 'telegram-delete-webhook',
), true)) { return; }
$routeHandled = true;
switch ($action) {
    case 'config':
        tamasyaRuntimeSetStage('config:authorize');
        global $runtime_credentials_file;
        $method = $_SERVER['REQUEST_METHOD'];
        // Konfigurasi menyangkut token bot, SMTP, rekening, saldo awal, dan kredensial DB.
        // Seluruh metode wajib Admin; menyembunyikan tab di frontend bukan otorisasi.
        requireRoles($loggedInStaff, ['admin']);
        if ($method === 'GET') {
            try {
                $fullData = getRoleScopedHotelData($pdo, $loggedInStaff);
                echo json_encode($fullData['config']);
            } catch (Exception $e) {
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage("Operasi gagal", $e)]);
            }
        } elseif ($method === 'POST') {
            tamasyaRuntimeSetStage('config:load_existing');
            // Get existing config from DB if it exists to prevent partial updates from resetting other credentials
            $existing = [];
            if ($pdo) {
                try {
                    $get_stmt = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
                    if ($get_stmt) {
                        $existing = $get_stmt->fetch() ?: [];
                    }
                } catch (Throwable $e) { throw new RuntimeException('Konfigurasi existing tidak dapat dibaca; penyimpanan dibatalkan agar credential lama tidak terhapus.',0,$e); }
            }

            tamasyaRuntimeSetStage('config:normalize_input');
            // Snapshot koneksi runtime sebelum input form dinormalisasi. Penyimpanan
            // integrasi (Gemini/Telegram/SMTP/rekening) tidak boleh gagal hanya karena
            // file credential database tidak writable bila koneksi DB memang tidak berubah.
            $runtimeDbConfig = [
                'host' => trim((string)($db_host ?? 'localhost')) ?: 'localhost',
                'port' => tamasyaNormalizeDbPort($db_port ?? 3306),
                'name' => trim((string)($db_name ?? '')),
                'user' => trim((string)($db_user ?? '')),
                'pass' => (string)($db_pass ?? ''),
            ];
            $postedGemini = $input['geminiApiKey'] ?? $input['gemini_api_key'] ?? null;
            $postedTelegram = $input['telegramBotToken'] ?? $input['telegram_bot_token'] ?? null;
            $gemini = ($postedGemini !== null && $postedGemini !== '' && $postedGemini !== '********')
                ? trim((string)$postedGemini)
                : decryptStoredSecret($existing['gemini_api_key'] ?? '');
            $telegram = ($postedTelegram !== null && $postedTelegram !== '' && $postedTelegram !== '********')
                ? trim((string)$postedTelegram)
                : decryptStoredSecret($existing['telegram_bot_token'] ?? '');
            $bal = isset($input['initialBalance']) ? (float)$input['initialBalance'] : (isset($input['initial_balance']) ? (float)$input['initial_balance'] : (isset($existing['initial_balance']) ? (float)$existing['initial_balance'] : 0));
            $target = isset($input['targetInvestment']) ? (float)$input['targetInvestment'] : (isset($input['target_investment']) ? (float)$input['target_investment'] : (isset($existing['target_investment']) ? (float)$existing['target_investment'] : 0));
            $requestedDbType = strtolower(trim((string)($input['dbType'] ?? $input['db_type'] ?? 'mysql')));
            if ($requestedDbType !== 'mysql') throw new InvalidArgumentException('Backend production hanya mendukung MySQL/MariaDB; mode offline memakai IndexedDB secara otomatis.');
            $db_type = 'mysql';
            $cfg_db_host = trim((string)($input['dbHost'] ?? $input['db_host'] ?? $db_host ?? 'localhost')) ?: 'localhost';
            $db_port = $input['dbPort'] ?? $input['db_port'] ?? $db_port ?? 3306;
            $cfg_db_name = trim((string)($input['dbName'] ?? $input['db_name'] ?? $db_name ?? ''));
            $cfg_db_user = trim((string)($input['dbUser'] ?? $input['db_user'] ?? $db_user ?? ''));
            if ($cfg_db_name === '' || $cfg_db_user === '') throw new InvalidArgumentException('Nama database dan username MySQL wajib diisi.');
            
            $post_db_pass = $input['dbPassword'] ?? $input['db_password'] ?? '';
            $cfg_db_password = (!empty($post_db_pass) && $post_db_pass !== '********') ? (string)$post_db_pass : (string)($db_pass ?? '');
            $bank_name = trim((string)($input['bankName'] ?? $input['bank_name'] ?? $existing['bank_name'] ?? ''));
            $bank_account = trim((string)($input['bankAccount'] ?? $input['bank_account'] ?? $existing['bank_account'] ?? ''));
            $bank_recipient = trim((string)($input['bankRecipient'] ?? $input['bank_recipient'] ?? $existing['bank_recipient'] ?? ''));
            $qris_merchant = trim((string)($input['qrisMerchantName'] ?? $input['qris_merchant_name'] ?? $existing['qris_merchant_name'] ?? ''));
            $qris_val = $input['qrisValue'] ?? $input['qris_value'] ?? $existing['qris_value'] ?? '';
            $webhook_active = isset($input['telegramWebhookActive']) ? (int)$input['telegramWebhookActive'] : (isset($input['telegram_webhook_active']) ? (int)$input['telegram_webhook_active'] : (isset($existing['telegram_webhook_active']) ? (int)$existing['telegram_webhook_active'] : 0));
            
            $smtp_host = $input['smtpHost'] ?? $input['smtp_host'] ?? $existing['smtp_host'] ?? '';
            $smtp_port = isset($input['smtpPort']) ? (int)$input['smtpPort'] : (isset($input['smtp_port']) ? (int)$input['smtp_port'] : (isset($existing['smtp_port']) ? (int)$existing['smtp_port'] : 587));
            $smtp_user = $input['smtpUser'] ?? $input['smtp_user'] ?? $existing['smtp_user'] ?? '';
            $postedSmtpPassword = $input['smtpPassword'] ?? $input['smtp_password'] ?? null;
            $smtp_password = ($postedSmtpPassword !== null && $postedSmtpPassword !== '' && $postedSmtpPassword !== '********')
                ? (string)$postedSmtpPassword
                : decryptStoredSecret($existing['smtp_password'] ?? '');
            $smtp_secure = $input['smtpSecure'] ?? $input['smtp_secure'] ?? $existing['smtp_secure'] ?? 'tls';
            $smtp_from = $input['smtpFrom'] ?? $input['smtp_from'] ?? $existing['smtp_from'] ?? '';

            $chatIds = $input['telegramChatIds'] ?? $input['telegram_chat_ids'] ?? (isset($existing['telegram_chat_ids']) ? json_decode($existing['telegram_chat_ids'], true) : []);
            $chatIdsJson = json_encode(is_array($chatIds) ? $chatIds : []);
            if ($bal < 0 || $target < 0) throw new InvalidArgumentException('Saldo awal dan target investasi tidak boleh negatif.');
            if ($smtp_port < 1 || $smtp_port > 65535) throw new InvalidArgumentException('Port SMTP tidak valid.');
            if (!in_array(strtolower(trim((string)$smtp_secure)), ['','none','tls','ssl','starttls'], true)) throw new InvalidArgumentException('Mode keamanan SMTP tidak valid.');
            if ($smtp_from !== '' && !filter_var($smtp_from, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Alamat pengirim SMTP tidak valid.');
            if (($bank_account !== '' || $bank_name !== '' || $bank_recipient !== '') && ($bank_account === '' || $bank_name === '' || $bank_recipient === '')) throw new InvalidArgumentException('Nama bank, nomor rekening, dan nama penerima harus diisi lengkap atau seluruhnya dikosongkan.');

            $credentials_file = '';
            $credentialBackupExists = false;
            $credentialBackupContent = null;
            $credentialBackupMode = null;
            try {
                $cfg_db_port = tamasyaNormalizeDbPort($db_port);
                $candidateDbConfig = [
                    'host' => $cfg_db_host,
                    'port' => $cfg_db_port,
                    'name' => $cfg_db_name,
                    'user' => $cfg_db_user,
                    'pass' => $cfg_db_password,
                    'detected' => true,
                    'driverAvailable' => in_array('mysql', PDO::getAvailableDrivers(), true),
                ];
                $dbCredentialsChanged =
                    $candidateDbConfig['host'] !== $runtimeDbConfig['host'] ||
                    (int)$candidateDbConfig['port'] !== (int)$runtimeDbConfig['port'] ||
                    $candidateDbConfig['name'] !== $runtimeDbConfig['name'] ||
                    $candidateDbConfig['user'] !== $runtimeDbConfig['user'] ||
                    !hash_equals((string)$runtimeDbConfig['pass'], (string)$candidateDbConfig['pass']);

                if ($dbCredentialsChanged) {
                    tamasyaRuntimeSetStage('config:test_database');
                    // Perubahan koneksi DB tetap memakai seluruh safety gate lama:
                    // connect-test, identity/schema verification, backup credential,
                    // lalu atomic replace. Jalur ini tidak dilonggarkan.
                    [$testPdo, $testDbError, $testDbStage] = tamasyaConnectDatabase($candidateDbConfig);
                    if (!$testPdo instanceof PDO) {
                        error_log('[api.php][config-db-test][' . $testDbStage . '] ' . (string)$testDbError);
                        throw new RuntimeException($testDbStage === 'missing_driver'
                            ? 'Driver PHP pdo_mysql belum aktif pada server.'
                            : 'Uji koneksi MySQL gagal. Periksa host, port, database, user, password, dan hak akses user.');
                    }
                    // A V137-looking schema is not enough: lock the real database
                    // name and property identity before changing the active credential.
                    tamasyaAssertDatabaseSafety($testPdo,$candidateDbConfig);
                    $targetIdentity=tamasyaDatabasePropertyIdentity($testPdo,true);
                    if(empty($targetIdentity['ok'])) throw new RuntimeException('Database tujuan adalah property/deployment berbeda. Perubahan credential dibatalkan.');
                    $targetSchemaStatus = tamasyaProductionSchemaStatus($testPdo);
                    if (empty($targetSchemaStatus['ready'])) {
                        throw new RuntimeException('Database tujuan tidak sesuai baseline fresh V137/trigger finansial. Verifikasi dengan admin_app/database_verify.php sebelum menyimpan konfigurasi.');
                    }
                    tamasyaRuntimeSetStage('config:write_credentials');
                    $credentials_file = $runtime_credentials_file ?: tamasyaDefaultCredentialTarget(TAMASYA_APP_ROOT);
                    $credentialBackupExists = is_file($credentials_file);
                    if ($credentialBackupExists) {
                        $credentialBackupContent = file_get_contents($credentials_file);
                        $credentialBackupMode = @fileperms($credentials_file);
                        if ($credentialBackupContent === false) throw new RuntimeException('File kredensial lama tidak dapat dicadangkan sebelum perubahan.');
                    }
                    $allowPublicCredentialFile = filter_var(getenv('ALLOW_PUBLIC_CREDENTIAL_FILE') ?: '0', FILTER_VALIDATE_BOOLEAN);
                    tamasyaWriteDatabaseCredentials($credentials_file, [
                        'host' => $cfg_db_host,
                        'port' => $cfg_db_port,
                        'name' => $cfg_db_name,
                        'user' => $cfg_db_user,
                        'pass' => $cfg_db_password,
                    ], $allowPublicCredentialFile);
                    $pdo = $testPdo;
                    // Schema target telah diverifikasi read-only sebelum kredensial aktif diganti.
                } else {
                    tamasyaRuntimeSetStage('config:reuse_database');
                    if (!$pdo instanceof PDO) throw new RuntimeException('Koneksi database aktif tidak tersedia untuk menyimpan konfigurasi.');
                }

                tamasyaRuntimeSetStage('config:persist');
                $pdo->beginTransaction();
                $beforeStmt=$pdo->query("SELECT * FROM config WHERE id='system_default' LIMIT 1 FOR UPDATE");
                $beforeConfig=$beforeStmt?$beforeStmt->fetch(PDO::FETCH_ASSOC):null;
                $stmt = $pdo->prepare("INSERT INTO config (
                    id, gemini_api_key, telegram_bot_token, initial_balance, target_investment, 
                    db_type, db_host, db_port, db_name, db_user, db_password, 
                    bank_name, bank_account, bank_recipient, qris_merchant_name, qris_value, 
                    telegram_webhook_active, telegram_chat_ids,
                    smtp_host, smtp_port, smtp_user, smtp_password, smtp_secure, smtp_from
                ) VALUES (
                    'system_default', ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, 
                    ?, ?,
                    ?, ?, ?, ?, ?, ?
                ) ON DUPLICATE KEY UPDATE 
                    gemini_api_key = VALUES(gemini_api_key),
                    telegram_bot_token = VALUES(telegram_bot_token),
                    initial_balance = VALUES(initial_balance),
                    target_investment = VALUES(target_investment),
                    db_type = VALUES(db_type),
                    db_host = VALUES(db_host),
                    db_port = VALUES(db_port),
                    db_name = VALUES(db_name),
                    db_user = VALUES(db_user),
                    db_password = VALUES(db_password),
                    bank_name = VALUES(bank_name),
                    bank_account = VALUES(bank_account),
                    bank_recipient = VALUES(bank_recipient),
                    qris_merchant_name = VALUES(qris_merchant_name),
                    qris_value = VALUES(qris_value),
                    telegram_webhook_active = VALUES(telegram_webhook_active),
                    telegram_chat_ids = VALUES(telegram_chat_ids),
                    smtp_host = VALUES(smtp_host),
                    smtp_port = VALUES(smtp_port),
                    smtp_user = VALUES(smtp_user),
                    smtp_password = VALUES(smtp_password),
                    smtp_secure = VALUES(smtp_secure),
                    smtp_from = VALUES(smtp_from)");
                
                $storedGemini = encryptStoredSecret($gemini);
                $storedTelegram = encryptStoredSecret($telegram);
                $storedSmtpPassword = encryptStoredSecret($smtp_password);
                $stmt->execute([
                    $storedGemini, $storedTelegram, $bal, $target,
                    $db_type, $cfg_db_host, $db_port, $cfg_db_name, $cfg_db_user, '',
                    $bank_name, $bank_account, $bank_recipient, $qris_merchant, $qris_val,
                    $webhook_active, $chatIdsJson,
                    $smtp_host, $smtp_port, $smtp_user, $storedSmtpPassword, $smtp_secure, $smtp_from
                ]);

                tamasyaRuntimeSetStage('config:notification');
                // Record dynamic system notification
                $notifId = generateServerId('n_cfg');
                $notifMsg = "Kredensial API Key Gemini & Token Telegram berhasil diperbarui.";
                $stmt = $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'system')");
                $stmt->execute([$notifId, $notifMsg, date("Y-m-d H:i:s")]);

                $afterStmt=$pdo->query("SELECT * FROM config WHERE id='system_default' LIMIT 1");
                $afterConfig=$afterStmt?$afterStmt->fetch(PDO::FETCH_ASSOC):null;
                writeRequiredEnterpriseAudit(
                    $pdo,$loggedInStaff,'Memperbarui konfigurasi sistem','config','system_default',
                    $beforeConfig? tamasyaAuditAttemptSnapshot($beforeConfig):null,
                    $afterConfig? tamasyaAuditAttemptSnapshot($afterConfig):null,
                    'web'
                );
                logActivity($pdo,'CONFIG_UPDATE','Memperbarui konfigurasi sistem dan koneksi database.',(string)$loggedInStaff['id'],currentStaffLabel($loggedInStaff));
                tamasyaFinancialCommit($pdo);

                // FINAL R9 Integration/API hardening: keberhasilan Save Config
                // ditentukan oleh COMMIT di atas, bukan oleh full hotel-data projection
                // setelah commit. Frontend memang melakukan authoritative refresh (Gt)
                // setelah respons sukses, sehingga projection kedua di sini redundant dan
                // sebelumnya dapat menimbulkan false-negative: config sudah COMMIT tetapi
                // response terlihat gagal bila projection berikutnya bermasalah.
                tamasyaRuntimeSetStage('config:response');
                echo json_encode([
                    "success" => true,
                    "message" => "Konfigurasi berhasil disimpan!",
                    "serverRevision" => (int)($afterConfig['server_revision'] ?? 0),
                    "secrets" => [
                        "hasGeminiApiKey" => $gemini !== '',
                        "hasTelegramBotToken" => $telegram !== '',
                        "hasSmtpPassword" => $smtp_password !== ''
                    ],
                    "databaseCredentialsChanged" => (bool)$dbCredentialsChanged,
                    "authoritativeRefreshRequired" => true
                ]);
            } catch (Throwable $e) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
                if ($credentials_file !== '') {
                    try {
                        if ($credentialBackupExists && is_string($credentialBackupContent)) {
                            $restoreTmp=$credentials_file.'.restore-'.bin2hex(random_bytes(4));
                            if(file_put_contents($restoreTmp,$credentialBackupContent,LOCK_EX)===false||!rename($restoreTmp,$credentials_file))throw new RuntimeException('restore failed');
                            if(is_int($credentialBackupMode))@chmod($credentials_file,$credentialBackupMode & 0777);
                        } elseif (!$credentialBackupExists && is_file($credentials_file)) {
                            @unlink($credentials_file);
                        }
                    } catch (Throwable $restoreError) {
                        error_log('[config] credential rollback failed: '.$restoreError->getMessage());
                    }
                }
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage("Gagal menyimpan konfigurasi", $e)]);
            }
        } else {
            http_response_code(405);
            echo json_encode(["success" => false, "error" => "Method Not Allowed"]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/gemini-test
    // Validasi server-side menggunakan secret yang SUDAH tersimpan.
    // Secret tidak pernah dikirim kembali ke browser. Endpoint ini sengaja
    // memakai POST agar resolver cluster mengarahkannya ke Primary aktif.
    // ----------------------------------------------------------------
    case 'gemini-test':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["success" => false, "error" => "Method Not Allowed"]);
            break;
        }
        if (($loggedInStaff['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Hanya Admin yang dapat menguji Gemini API Key."]);
            break;
        }
        if (function_exists('tamasyaExternalSideEffectsAllowed') && !tamasyaExternalSideEffectsAllowed()) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "error" => "Uji Gemini hanya dijalankan oleh Primary aktif dengan lease valid."
            ]);
            break;
        }
        try {
            $stmt = $pdo->query("SELECT gemini_api_key FROM config WHERE id = 'system_default' LIMIT 1");
            $confRow = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            $geminiKey = trim(decryptStoredSecret($confRow['gemini_api_key'] ?? ''));
            if ($geminiKey === '') {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Gemini API Key belum tersimpan. Simpan konfigurasi terlebih dahulu."]);
                break;
            }
            if (strlen($geminiKey) > 1024 || preg_match('/[\r\n]/', $geminiKey)) {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Format Gemini API Key tidak aman/tidak valid."]);
                break;
            }

            $model = 'gemini-2.5-flash';
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
            $payload = json_encode([
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => 'Balas tepat satu kata: OK']]
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'maxOutputTokens' => 16
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) throw new RuntimeException('Payload uji Gemini tidak dapat dibuat.');

            $timeout = max(5, min(30, (int)(getenv('GEMINI_HTTP_TIMEOUT_SECONDS') ?: 15)));
            $connectTimeout = max(2, min($timeout, (int)(getenv('GEMINI_CONNECT_TIMEOUT_SECONDS') ?: 5)));
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'x-goog-api-key: ' . $geminiKey,
            ];
            $raw = false;
            $httpCode = 0;
            $transportError = '';
            $startedAt = microtime(true);

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_USERAGENT => 'TamasyaHotelGeminiDiagnostic/1.0'
                ]);
                $raw = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($raw === false) $transportError = 'cURL ' . curl_errno($ch) . ': ' . curl_error($ch);
                unset($ch);
            } elseif (ini_get('allow_url_fopen')) {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => implode("\r\n", $headers),
                        'content' => $payload,
                        'timeout' => $timeout,
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false
                    ]
                ]);
                $raw = @file_get_contents($url, false, $context);
                $responseHeaders=(function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : (get_defined_vars()['http_response_header'] ?? []));
                if (!empty($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $m)) {
                    $httpCode = (int)$m[1];
                }
                if ($raw === false) $transportError = 'HTTPS request gagal melalui allow_url_fopen.';
            } else {
                throw new RuntimeException('Server tidak menyediakan cURL maupun allow_url_fopen untuk menguji Gemini.');
            }

            $latencyMs = max(1, (int)round((microtime(true) - $startedAt) * 1000));
            if ($raw === false) {
                http_response_code(502);
                echo json_encode([
                    'success' => false,
                    'error' => 'Server tidak dapat menghubungi Gemini. ' . $transportError,
                    'providerReachable' => false,
                    'model' => $model,
                    'latencyMs' => $latencyMs
                ]);
                break;
            }

            $decoded = json_decode((string)$raw, true);
            $providerError = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
            $providerStatus = strtoupper(trim((string)($providerError['status'] ?? '')));
            $providerMessage = trim((string)($providerError['message'] ?? ''));
            $providerMessage = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $providerMessage) ?? '';
            if ($providerMessage !== '' && str_contains($providerMessage, $geminiKey)) {
                $providerMessage = str_replace($geminiKey, '[REDACTED]', $providerMessage);
            }
            $providerMessage = substr($providerMessage, 0, 500);

            $credentialStatus = 'provider_error';
            $message = 'Gemini dapat dihubungi, tetapi provider menolak request uji.';
            if ($httpCode >= 200 && $httpCode < 300) {
                $credentialStatus = 'valid';
                $message = 'Gemini API Key valid dan provider berhasil merespons dari server ini.';
            } elseif ($httpCode === 429 || $providerStatus === 'RESOURCE_EXHAUSTED') {
                $credentialStatus = 'quota_limited';
                $message = 'Gemini dapat dihubungi, tetapi request dibatasi quota/rate limit.';
            } elseif ($httpCode === 401 || $providerStatus === 'UNAUTHENTICATED') {
                $credentialStatus = 'rejected';
                $message = 'Gemini menolak kredensial. Periksa API Key yang tersimpan.';
            } elseif ($httpCode === 403 || $providerStatus === 'PERMISSION_DENIED') {
                $credentialStatus = 'restricted';
                $message = 'Gemini menolak izin/project API Key. Periksa restriction, project, atau akses Gemini API.';
            } elseif ($httpCode === 400 || $providerStatus === 'INVALID_ARGUMENT') {
                $credentialStatus = 'request_rejected';
                $message = 'Gemini dapat dihubungi tetapi request diagnostik ditolak provider.';
            }

            echo json_encode([
                'success' => true,
                'providerReachable' => true,
                'credentialStatus' => $credentialStatus,
                'message' => $message,
                'providerHttpCode' => $httpCode,
                'providerStatus' => $providerStatus !== '' ? $providerStatus : null,
                'providerMessage' => $providerMessage !== '' ? $providerMessage : null,
                'model' => $model,
                'latencyMs' => $latencyMs,
                'hasGeminiApiKey' => true,
                'publicWebsiteAiEnabled' => filter_var(getenv('PUBLIC_HELP_CHAT_AI_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
                'node' => [
                    'id' => function_exists('tamasyaNodeId') ? tamasyaNodeId() : '',
                    'role' => function_exists('tamasyaNodeRole') ? tamasyaNodeRole() : ''
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "error" => clientExceptionMessage("Uji Gemini gagal", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/telegram-set-webhook
    // Set Telegram Bot Webhook
    // ----------------------------------------------------------------
    case 'telegram-set-webhook':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["success" => false, "error" => "Method Not Allowed"]);
            break;
        }
        if (($loggedInStaff['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Hanya Admin yang dapat mengaktifkan webhook Telegram."]);
            break;
        }

        $remoteWebhookInstalled=false;
        $token='';
        try {
            ensureTelegramWebhookColumns($pdo);
            $stmt = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
            $confRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $token = trim(decryptStoredSecret($confRow['telegram_bot_token'] ?? ''));

            if ($token === '') {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Bot Token belum tersimpan di database. Tekan Simpan Konfigurasi terlebih dahulu."]);
                break;
            }
            if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token)) {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Format Bot Token tidak valid. Salin token lengkap dari @BotFather."]);
                break;
            }

            $getMe = telegramApiCall($token, 'getMe');
            if (!$getMe['ok']) {
                $error = 'Validasi token gagal: ' . telegramApiErrorMessage($getMe);
                tamasyaPersistTelegramWebhookState($pdo,$loggedInStaff,[
                    'active'=>false,
                    'url'=>$confRow['telegram_webhook_url']??null,
                    'secret'=>$confRow['telegram_webhook_secret']??null,
                    'lastError'=>$error,
                ],'Mencatat kegagalan validasi webhook Telegram');
                http_response_code(502);
                echo json_encode(["success" => false, "error" => $error]);
                break;
            }

            $webhookUrl = buildPublicWebhookUrl();
            $urlError = validateTelegramWebhookUrl($webhookUrl);
            if ($urlError !== null) {
                tamasyaPersistTelegramWebhookState($pdo,$loggedInStaff,[
                    'active'=>false,'url'=>$webhookUrl,
                    'secret'=>$confRow['telegram_webhook_secret']??null,
                    'lastError'=>$urlError,
                ],'Mencatat URL webhook Telegram tidak valid');
                http_response_code(400);
                echo json_encode(["success" => false, "error" => $urlError, "webhookUrl" => $webhookUrl]);
                break;
            }

            $secret = trim((string)($confRow['telegram_webhook_secret'] ?? ''));
            if ($secret === '') $secret = bin2hex(random_bytes(32));

            $setResult = telegramApiCall($token, 'setWebhook', [
                'url' => $webhookUrl,
                'secret_token' => $secret,
                'allowed_updates' => ['message', 'callback_query', 'my_chat_member', 'chat_member'],
                'drop_pending_updates' => false,
                'max_connections' => max(1, min(10, (int)(getenv('TELEGRAM_WEBHOOK_MAX_CONNECTIONS') ?: 5)))
            ], 30);
            if (!$setResult['ok']) {
                $error = 'setWebhook gagal: ' . telegramApiErrorMessage($setResult);
                tamasyaPersistTelegramWebhookState($pdo,$loggedInStaff,[
                    'active'=>false,'url'=>$webhookUrl,'secret'=>$secret,'lastError'=>$error,
                ],'Mencatat kegagalan pemasangan webhook Telegram');
                http_response_code(502);
                echo json_encode(["success" => false, "error" => $error, "webhookUrl" => $webhookUrl]);
                break;
            }
            $remoteWebhookInstalled=true;

            $infoResult = telegramApiCall($token, 'getWebhookInfo');
            $actualInfo = $infoResult['data']['result'] ?? [];
            $actualUrl = (string)($actualInfo['url'] ?? '');
            if (!$infoResult['ok'] || $actualUrl !== $webhookUrl) {
                $error = !$infoResult['ok']
                    ? 'Webhook terpasang tetapi verifikasi gagal: ' . telegramApiErrorMessage($infoResult)
                    : 'Telegram melaporkan URL webhook yang berbeda.';
                // Kompensasi remote: jangan tinggalkan webhook aktif bila state lokal
                // tidak dapat diverifikasi sebagai URL yang sama.
                telegramApiCall($token,'deleteWebhook',['drop_pending_updates'=>false],20);
                $remoteWebhookInstalled=false;
                tamasyaPersistTelegramWebhookState($pdo,$loggedInStaff,[
                    'active'=>false,'url'=>$webhookUrl,'secret'=>$secret,'lastError'=>$error,
                ],'Mencatat kegagalan verifikasi webhook Telegram');
                http_response_code(502);
                echo json_encode(["success" => false, "error" => $error, "webhookUrl" => $webhookUrl]);
                break;
            }

            $lastTelegramError = trim((string)($actualInfo['last_error_message'] ?? ''));
            try {
                tamasyaPersistTelegramWebhookState($pdo,$loggedInStaff,[
                    'active'=>true,'url'=>$webhookUrl,'secret'=>$secret,
                    'lastError'=>$lastTelegramError!==''?$lastTelegramError:null,
                ],'Mengaktifkan webhook Telegram');
            } catch(Throwable $dbFinalizeError) {
                // Kompensasi external side effect bila audit/commit lokal gagal.
                telegramApiCall($token,'deleteWebhook',['drop_pending_updates'=>false],20);
                $remoteWebhookInstalled=false;
                throw new RuntimeException('Webhook Telegram dibatalkan karena finalisasi database/audit gagal.',0,$dbFinalizeError);
            }

            echo json_encode([
                "success" => true,
                "message" => "Webhook berhasil dipasang dan diverifikasi oleh Telegram.",
                "bot" => [
                    "id" => $getMe['data']['result']['id'] ?? null,
                    "username" => $getMe['data']['result']['username'] ?? null
                ],
                "webhook" => [
                    "active" => true,
                    "url" => $webhookUrl,
                    "expectedUrl" => $webhookUrl,
                    "matchesCurrentServer" => true,
                    "databaseName" => (string)($pdo->query("SELECT DATABASE()")?->fetchColumn() ?: ''),
                    "pendingUpdateCount" => (int)($actualInfo['pending_update_count'] ?? 0),
                    "lastError" => $lastTelegramError !== '' ? $lastTelegramError : null,
                    "hasCustomCertificate" => (bool)($actualInfo['has_custom_certificate'] ?? false)
                ],
                "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
            ]);
        } catch (Throwable $e) {
            if($remoteWebhookInstalled && $token!=='') {
                // Kompensasi best effort untuk exception setelah remote setWebhook.
                telegramApiCall($token,'deleteWebhook',['drop_pending_updates'=>false],20);
            }
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "error" => clientExceptionMessage("Aktivasi webhook gagal", $e)]);
        }
        break;

    case 'telegram-webhook-info':
        if (!in_array(($loggedInStaff['role'] ?? ''), ['admin', 'manager', 'owner'], true)) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Akses ditolak."]);
            break;
        }
        try {
            ensureTelegramWebhookColumns($pdo);
            $stmt = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
            $confRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $token = trim(decryptStoredSecret($confRow['telegram_bot_token'] ?? ''));
            if ($token === '') {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Bot Token belum tersimpan."]);
                break;
            }
            $getMe = telegramApiCall($token, 'getMe');
            $infoResult = telegramApiCall($token, 'getWebhookInfo');
            if (!$getMe['ok'] || !$infoResult['ok']) {
                $error = !$getMe['ok'] ? telegramApiErrorMessage($getMe) : telegramApiErrorMessage($infoResult);
                http_response_code(502);
                echo json_encode(["success" => false, "error" => $error]);
                break;
            }
            $info = $infoResult['data']['result'] ?? [];
            $url = (string)($info['url'] ?? '');
            $expectedUrl = buildPublicWebhookUrl();
            $active = $url !== '';
            $matchesCurrentServer = $active && hash_equals($expectedUrl,$url);
            $lastError = trim((string)($info['last_error_message'] ?? ''));
            tamasyaPersistTelegramWebhookState($pdo,$loggedInStaff,[
                'active'=>$active,'url'=>$url,
                'secret'=>$confRow['telegram_webhook_secret']??null,
                'lastError'=>$lastError!==''?$lastError:null,
            ],'Menyinkronkan status webhook Telegram');
            echo json_encode([
                "success" => true,
                "bot" => $getMe['data']['result'] ?? null,
                "webhook" => [
                    "active" => $active,
                    "url" => $url,
                    "expectedUrl" => $expectedUrl,
                    "matchesCurrentServer" => $matchesCurrentServer,
                    "databaseName" => (string)($pdo->query("SELECT DATABASE()")?->fetchColumn() ?: ''),
                    "pendingUpdateCount" => (int)($info['pending_update_count'] ?? 0),
                    "lastErrorDate" => $info['last_error_date'] ?? null,
                    "lastError" => $lastError !== '' ? $lastError : null,
                    "maxConnections" => (int)($info['max_connections'] ?? 0),
                    "allowedUpdates" => $info['allowed_updates'] ?? []
                ]
            ]);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "error" => clientExceptionMessage("Pemeriksaan webhook gagal", $e)]);
        }
        break;

    case 'telegram-delete-webhook':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["success" => false, "error" => "Method Not Allowed"]);
            break;
        }
        if (($loggedInStaff['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Hanya Admin yang dapat menonaktifkan webhook Telegram."]);
            break;
        }
        $token='';
        $remoteDeleted=false;
        $confRow=[];
        try {
            ensureTelegramWebhookColumns($pdo);
            $stmt = $pdo->query("SELECT * FROM config WHERE id = 'system_default' LIMIT 1");
            $confRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $token = trim(decryptStoredSecret($confRow['telegram_bot_token'] ?? ''));
            if ($token === '') {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Bot Token belum tersimpan."]);
                break;
            }
            $deleteResult = telegramApiCall($token, 'deleteWebhook', ['drop_pending_updates' => false]);
            if (!$deleteResult['ok']) {
                $error = 'deleteWebhook gagal: ' . telegramApiErrorMessage($deleteResult);
                http_response_code(502);
                echo json_encode(["success" => false, "error" => $error]);
                break;
            }
            $remoteDeleted=true;
            try {
                tamasyaPersistTelegramWebhookState($pdo,$loggedInStaff,[
                    'active'=>false,'url'=>null,'secret'=>null,'lastError'=>null,
                ],'Menonaktifkan webhook Telegram');
            } catch(Throwable $dbFinalizeError) {
                // Kompensasi: restore remote webhook lama bila state lokal/audit gagal.
                $oldUrl=trim((string)($confRow['telegram_webhook_url']??''));
                $oldSecret=trim((string)($confRow['telegram_webhook_secret']??''));
                if(!empty($confRow['telegram_webhook_active']) && $oldUrl!=='' && $oldSecret!=='') {
                    telegramApiCall($token,'setWebhook',[
                        'url'=>$oldUrl,'secret_token'=>$oldSecret,
                        'allowed_updates'=>['message','callback_query','my_chat_member','chat_member'],
                        'drop_pending_updates'=>false,
                        'max_connections'=>max(1,min(10,(int)(getenv('TELEGRAM_WEBHOOK_MAX_CONNECTIONS')?:5))),
                    ],30);
                    $remoteDeleted=false;
                }
                throw new RuntimeException('Penghapusan webhook dibatalkan karena finalisasi database/audit gagal.',0,$dbFinalizeError);
            }
            echo json_encode([
                "success" => true,
                "message" => "Webhook berhasil dilepas dari Telegram.",
                "webhook" => ["active" => false, "url" => ""],
                "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
            ]);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(["success" => false, "error" => clientExceptionMessage("Menonaktifkan webhook gagal", $e)]);
        }
        break;

    // ----------------------------------------------------------------
    // POST /api/telegram-webhook
    // Real-time Webhook listener for incoming Telegram messages from phones!
    // ----------------------------------------------------------------
}
