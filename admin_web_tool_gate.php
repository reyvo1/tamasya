<?php
declare(strict_types=1);

/**
 * Shared browser gate for TAMASYA administrative maintenance tools.
 *
 * Browser access is OFF by default. To enable it, add to admin_app/.env:
 *   ADMIN_WEB_TOOLS_ENABLED=1
 *   ADMIN_WEB_TOOLS_SECRET=<random secret, minimum 32 characters>
 * Optional for local/non-TLS staging only:
 *   ADMIN_WEB_TOOLS_ALLOW_HTTP=1
 *
 * The secret is accepted only through POST field "admin_tool_secret" or
 * X-Tamasya-Admin-Tool-Secret header. It is never accepted from the query
 * string so it does not leak into ordinary access logs/referrers.
 */

function tamasyaAdminToolIsCli(): bool {
    return PHP_SAPI === 'cli';
}

function tamasyaAdminToolBoolEnv(string $key, bool $default = false): bool {
    $raw = getenv($key);
    if ($raw === false || trim((string)$raw) === '') return $default;
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}

function tamasyaAdminToolHttpsActive(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    $proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($proto === 'https') return true;
    return false;
}

function tamasyaAdminToolBeginWeb(string $toolName): void {
    if (tamasyaAdminToolIsCli()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('Content-Type: text/html; charset=UTF-8');

    if (!tamasyaAdminToolBoolEnv('ADMIN_WEB_TOOLS_ENABLED', false)) {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><title>Not Found</title><h1>404 Not Found</h1>';
        exit;
    }

    $secret = (string)(getenv('ADMIN_WEB_TOOLS_SECRET') ?: '');
    if (strlen($secret) < 32) {
        http_response_code(503);
        echo tamasyaAdminToolHtml($toolName, '<p>Browser tool belum siap: <code>ADMIN_WEB_TOOLS_SECRET</code> wajib minimal 32 karakter.</p>');
        exit;
    }

    if (!tamasyaAdminToolHttpsActive() && !tamasyaAdminToolBoolEnv('ADMIN_WEB_TOOLS_ALLOW_HTTP', false)) {
        http_response_code(403);
        echo tamasyaAdminToolHtml($toolName, '<p>Akses browser ditolak karena koneksi bukan HTTPS. Gunakan HTTPS atau, hanya untuk staging lokal, set <code>ADMIN_WEB_TOOLS_ALLOW_HTTP=1</code>.</p>');
        exit;
    }
}

function tamasyaAdminToolRequestSecret(): string {
    $header = trim((string)($_SERVER['HTTP_X_TAMASYA_ADMIN_TOOL_SECRET'] ?? ''));
    if ($header !== '') return $header;
    return (string)($_POST['admin_tool_secret'] ?? '');
}

function tamasyaAdminToolAuthorizeWeb(): void {
    if (tamasyaAdminToolIsCli()) return;
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo tamasyaAdminToolHtml('TAMASYA Admin Tool', '<p>Operasi hanya boleh dijalankan dengan POST.</p>');
        exit;
    }
    $expected = (string)(getenv('ADMIN_WEB_TOOLS_SECRET') ?: '');
    $incoming = tamasyaAdminToolRequestSecret();
    if ($incoming === '' || !hash_equals($expected, $incoming)) {
        http_response_code(403);
        echo tamasyaAdminToolHtml('TAMASYA Admin Tool', '<p>Secret salah atau tidak diberikan.</p>');
        exit;
    }
}

function tamasyaAdminToolHtml(string $title, string $body): string {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>'.$safeTitle.'</title><style>body{font-family:system-ui,-apple-system,sans-serif;max-width:760px;margin:40px auto;padding:0 18px;line-height:1.5}fieldset{border:1px solid #bbb;border-radius:8px;padding:18px}label{display:block;margin:12px 0 4px}input,select,button{font:inherit;padding:10px;max-width:100%;box-sizing:border-box}input[type=text],input[type=password],select{width:100%}button{margin-top:16px;cursor:pointer}code,pre{white-space:pre-wrap;word-break:break-word}pre{background:#f4f4f4;padding:14px;border-radius:8px}</style></head><body><h1>'.$safeTitle.'</h1>'.$body.'</body></html>';
}

function tamasyaAdminToolForm(string $title, string $description, string $fieldsHtml, string $buttonLabel): void {
    if (tamasyaAdminToolIsCli()) return;
    $body = '<p>'.htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>'
        . '<form method="post" autocomplete="off"><fieldset>'
        . '<label for="admin_tool_secret">Admin Web Tool Secret</label>'
        . '<input id="admin_tool_secret" name="admin_tool_secret" type="password" minlength="32" required autocomplete="current-password">'
        . $fieldsHtml
        . '<button type="submit">'.htmlspecialchars($buttonLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</button>'
        . '</fieldset></form>';
    echo tamasyaAdminToolHtml($title, $body);
    exit;
}

function tamasyaAdminToolEmit(array $payload, int $httpStatus = 200, bool $error = false): void {
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) $json = '{"success":false,"error":"JSON encoding failed"}';
    if (tamasyaAdminToolIsCli()) {
        $stream = $error ? STDERR : STDOUT;
        fwrite($stream, $json . PHP_EOL);
        return;
    }
    http_response_code($httpStatus);
    echo tamasyaAdminToolHtml('TAMASYA Admin Tool Result', '<pre>'.htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre><p><a href="'.htmlspecialchars((string)($_SERVER['PHP_SELF'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">Kembali</a></p>');
}
