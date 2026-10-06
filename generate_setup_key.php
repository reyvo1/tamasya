<?php
declare(strict_types=1);

/**
 * TAMASYA Shared Hosting Browser Setup Key Generator — FINAL file-proof flow.
 *
 * PURPOSE
 * -------
 * Bootstrap the first TC4-* configurator Setup Key on shared hosting where
 * Terminal/SSH is not available, WITHOUT asking the operator to invent/edit a
 * 32-character secret inside this PHP file.
 *
 * SECURITY MODEL
 * --------------
 * 1. HTTPS only.
 * 2. Browser session uses Secure + HttpOnly + SameSite=Strict cookie and CSRF.
 * 3. Every browser session receives a random owner-proof filename + random
 *    owner-proof content. The operator must create that exact hidden file via
 *    cPanel/File Manager. A web-only attacker cannot satisfy this filesystem
 *    proof merely by knowing the public URL.
 * 4. POST never accepts proof filename/token from the browser; expected values
 *    come only from the authenticated PHP session, preventing cross-session
 *    proof reuse.
 * 5. Generates only when .tamasya-configurator-key.json does NOT exist.
 * 6. Stores only SHA-256/fingerprint, never plaintext TC4.
 * 7. Proof file is deleted after success and generator creates a one-time lock.
 * 8. Delete this PHP after TC4 is saved and configurator access is confirmed.
 *
 * CLI users should use:
 *   php tamasya_configurator.php --generate-setup-key
 */

const TAMASYA_SETUP_KEY_GENERATOR_VERSION = 'SHARED-HOSTING-FILE-PROOF-2_20260813';
const TAMASYA_SETUP_KEY_FILE = '.tamasya-configurator-key.json';
const TAMASYA_SETUP_KEY_GENERATOR_LOCK_FILE = '.tamasya-setup-key-generator.lock';

function tsg_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function tsg_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=UTF-8');
}
function tsg_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    $trustProxy = (string)(getenv('TAMASYA_SETUP_KEY_GENERATOR_TRUST_PROXY') ?: '') === '1';
    if ($trustProxy) {
        $xfp = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        if ($xfp === 'https') return true;
    }
    return false;
}
function tsg_random_token(int $bytes = 24): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}
function tsg_fingerprint(string $secret): string {
    $hash = strtoupper(substr(hash('sha256', $secret), 0, 20));
    return implode(':', str_split($hash, 4));
}
function tsg_configurator_version(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'tamasya_configurator.php';
    if (is_file($path) && is_readable($path)) {
        $src = (string)file_get_contents($path);
        if (preg_match("/const\\s+TAMASYA_CONFIGURATOR_VERSION\\s*=\\s*'([^']+)'\\s*;/", $src, $m) === 1) return (string)$m[1];
    }
    return 'FINAL12-PROD4.2_20260813';
}
function tsg_atomic_create(string $path, string $content, int $mode = 0600): void {
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Folder admin_app tidak writable. Periksa permission hosting.');
    if (file_exists($path)) throw new RuntimeException('File target sudah ada; generator tidak akan menimpa file existing.');
    $tmp = tempnam($dir, '.tamasya-key-');
    if ($tmp === false) throw new RuntimeException('Gagal membuat temporary file.');
    try {
        if (file_put_contents($tmp, $content, LOCK_EX) === false) throw new RuntimeException('Gagal menulis temporary file.');
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) throw new RuntimeException('Gagal memasang setup-key record.');
        @chmod($path, $mode);
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}
function tsg_render(string $title, string $body, int $status = 200): never {
    http_response_code($status);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . tsg_h($title) . '</title>'
        . '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:820px;margin:32px auto;padding:0 18px;line-height:1.55;color:#14213d;background:#f7f9fc}main{background:white;border:1px solid #dce3ee;border-radius:16px;padding:24px;box-shadow:0 8px 30px rgba(20,33,61,.07)}fieldset{border:1px solid #cbd5e1;border-radius:12px;padding:18px}button{font:inherit;padding:12px 16px;border:0;border-radius:10px;background:#0f766e;color:white;font-weight:700;cursor:pointer;margin-top:12px}code,pre{white-space:pre-wrap;word-break:break-word}pre{background:#f1f5f9;padding:14px;border-radius:10px}.ok,.warn,.err,.info{padding:14px;border-radius:10px;margin:14px 0}.ok{background:#ecfdf5;border:1px solid #86efac}.warn{background:#fffbeb;border:1px solid #fde68a}.err{background:#fef2f2;border:1px solid #fca5a5}.info{background:#eff6ff;border:1px solid #bfdbfe}.muted{color:#64748b;font-size:.94em}.steps li{margin:.55rem 0}.copybox{font-size:1rem}</style>'
        . '</head><body><main><h1>' . tsg_h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}
function tsg_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('TAMASYA_SETUP_BOOTSTRAP');
    session_set_cookie_params(['httponly'=>true,'secure'=>true,'samesite'=>'Strict','path'=>'/']);
    session_start();
}
function tsg_csrf(): string {
    if (!isset($_SESSION['tsg_csrf']) || !is_string($_SESSION['tsg_csrf']) || strlen($_SESSION['tsg_csrf']) < 32) $_SESSION['tsg_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['tsg_csrf'];
}
function tsg_csrf_ok(): bool {
    $expected = (string)($_SESSION['tsg_csrf'] ?? '');
    $incoming = (string)($_POST['csrf'] ?? '');
    return $expected !== '' && $incoming !== '' && hash_equals($expected, $incoming);
}
function tsg_locked(): bool {
    return is_file(__DIR__ . DIRECTORY_SEPARATOR . TAMASYA_SETUP_KEY_GENERATOR_LOCK_FILE)
        || is_file(__DIR__ . DIRECTORY_SEPARATOR . TAMASYA_SETUP_KEY_FILE);
}
function tsg_owner_proof(): array {
    if (!isset($_SESSION['tsg_proof']) || !is_array($_SESSION['tsg_proof'])) {
        $_SESSION['tsg_proof'] = [
            'filename' => '.tamasya-owner-proof-' . bin2hex(random_bytes(8)) . '.txt',
            'content' => 'TAMASYA-OWNER-' . tsg_random_token(24),
            'createdAt' => time(),
        ];
    }
    $proof = (array)$_SESSION['tsg_proof'];
    $filename = (string)($proof['filename'] ?? '');
    $content = (string)($proof['content'] ?? '');
    if (!preg_match('/^\.tamasya-owner-proof-[a-f0-9]{16}\.txt$/', $filename) || strlen($content) < 32) {
        unset($_SESSION['tsg_proof']);
        return tsg_owner_proof();
    }
    return ['filename'=>$filename,'content'=>$content,'createdAt'=>(int)($proof['createdAt'] ?? time())];
}
function tsg_verify_owner_proof(array $proof): string {
    $filename=(string)$proof['filename'];$expected=(string)$proof['content'];
    $path=__DIR__.DIRECTORY_SEPARATOR.$filename;
    if (is_link($path)) throw new RuntimeException('Owner-proof tidak boleh berupa symlink. Hapus lalu buat regular file melalui File Manager.');
    if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Owner-proof belum ditemukan. Buat file persis seperti petunjuk di cPanel/File Manager, lalu klik lagi.');
    $actual=trim((string)file_get_contents($path));
    if ($actual==='' || !hash_equals($expected,$actual)) throw new RuntimeException('Isi owner-proof tidak cocok. Copy persis kode yang ditampilkan halaman ini.');
    return $path;
}

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Gunakan: php tamasya_configurator.php --generate-setup-key untuk CLI. File ini khusus browser shared hosting.\n");
    exit(2);
}

tsg_headers();
if (!tsg_https()) tsg_render('HTTPS Required','<div class="err">Generator Setup Key hanya boleh dibuka melalui HTTPS.</div>',403);
if (tsg_locked()) tsg_render('Not Found','<h2>404 Not Found</h2><p>Generator sudah tidak tersedia.</p>',404);

tsg_start_session();
$proof=tsg_owner_proof();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'POST') {
    $csrf=tsg_csrf();
    $filename=tsg_h((string)$proof['filename']);$content=tsg_h((string)$proof['content']);
    tsg_render('TAMASYA Setup Key Generator — Shared Hosting',
        '<div class="info"><strong>Tidak perlu membuat password 32 karakter.</strong> Halaman ini memakai bukti akses File Manager.</div>'
        . '<ol class="steps">'
        . '<li>Buka <strong>cPanel → File Manager</strong> dan masuk ke folder yang sama dengan <code>generate_setup_key.php</code>.</li>'
        . '<li>Buat file baru dengan nama persis:<pre class="copybox">'.$filename.'</pre></li>'
        . '<li>Isi file tersebut dengan satu baris persis:<pre class="copybox">'.$content.'</pre></li>'
        . '<li>Simpan file. Kembali ke halaman ini dan klik tombol di bawah.</li>'
        . '</ol>'
        . '<div class="warn">File proof ini hanya membuktikan bahwa Anda punya akses filesystem hosting. Setelah TC4 berhasil dibuat, proof akan dihapus otomatis dan generator terkunci.</div>'
        . '<form method="post"><fieldset><input type="hidden" name="csrf" value="'.tsg_h($csrf).'">'
        . '<label><input type="checkbox" name="confirm" value="VERIFY_OWNER_AND_GENERATE" required> Saya sudah membuat owner-proof melalui File Manager dan siap menyimpan TC4 yang hanya ditampilkan sekali.</label><br>'
        . '<button type="submit">VERIFIKASI FILE MANAGER + GENERATE TC4</button></fieldset></form>'
        . '<p class="muted">Jika session browser hilang, halaman dapat memberi nama proof baru. Hapus proof lama yang belum terpakai lalu ikuti nama terbaru.</p>'
    );
}

if (!tsg_csrf_ok()) tsg_render('Forbidden','<div class="err">CSRF/session tidak valid. Buka ulang halaman generator.</div>',403);
if ((string)($_POST['confirm'] ?? '') !== 'VERIFY_OWNER_AND_GENERATE') tsg_render('Bad Request','<div class="err">Konfirmasi generation wajib.</div>',400);
if (tsg_locked()) tsg_render('Not Found','<h2>404 Not Found</h2><p>Generator sudah tidak tersedia.</p>',404);

try {
    $proofPath=tsg_verify_owner_proof($proof);
    $key='TC4-'.tsg_random_token(36);
    $record=['sha256'=>hash('sha256',$key),'fingerprint'=>tsg_fingerprint($key),'createdAt'=>date(DATE_ATOM),'version'=>tsg_configurator_version(),'generator'=>TAMASYA_SETUP_KEY_GENERATOR_VERSION];
    $json=json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('Gagal membuat record JSON.');
    $keyFile=__DIR__.DIRECTORY_SEPARATOR.TAMASYA_SETUP_KEY_FILE;
    tsg_atomic_create($keyFile,$json."\n",0600);
    @unlink($proofPath);
    $lockPath=__DIR__.DIRECTORY_SEPARATOR.TAMASYA_SETUP_KEY_GENERATOR_LOCK_FILE;
    @file_put_contents($lockPath,"Locked ".date(DATE_ATOM)." after owner-file proof + TC4 generation.\n",LOCK_EX);@chmod($lockPath,0600);
    $_SESSION=[];if(session_status()===PHP_SESSION_ACTIVE)@session_destroy();
    tsg_render('SETUP KEY BERHASIL DIBUAT',
        '<div class="ok"><strong>SIMPAN SETUP KEY INI SEKARANG — plaintext tidak disimpan di server:</strong><pre>'.$key.'</pre><p>Fingerprint: <code>'.tsg_h((string)$record['fingerprint']).'</code></p></div>'
        . '<div class="warn"><strong>Berikutnya:</strong><ol><li>Simpan TC4 di password manager.</li><li>Buka <a href="./tamasya_configurator.php">tamasya_configurator.php</a> dan login dengan TC4 tersebut.</li><li>Setelah berhasil masuk, hapus <code>generate_setup_key.php</code> melalui File Manager.</li></ol></div>'
        . '<p class="muted">Server hanya menyimpan SHA-256 di <code>.tamasya-configurator-key.json</code>. Generator sudah terkunci.</p>'
    );
} catch (Throwable $e) {
    tsg_render('Belum Bisa Generate','<div class="err">'.tsg_h($e->getMessage()).'</div><p>Kembali ke halaman sebelumnya, periksa nama + isi owner-proof, lalu coba lagi. Tidak ada Setup Key yang dibuat bila verifikasi gagal.</p>',409);
}
