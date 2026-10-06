<?php
declare(strict_types=1);
if (!defined('TAMASYA_WEB_TOOL_ENTRY')) { define('TAMASYA_WEB_TOOL_ENTRY', true); }
require_once __DIR__ . DIRECTORY_SEPARATOR . 'runtime_web_helpers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'database_bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
session_name('tamasya_database_setup');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();


function setupKeyConfig(): array {
    $file = __DIR__ . DIRECTORY_SEPARATOR . '.database_setup_key.php';
    if (!is_file($file)) return ['enabled' => false, 'key_hash' => ''];
    $config = include $file;
    return is_array($config) ? $config : ['enabled' => false, 'key_hash' => ''];
}

function disableSetupKey(): void {
    $file = __DIR__ . DIRECTORY_SEPARATOR . '.database_setup_key.php';
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    $code = "<?php\nreturn ['enabled' => false, 'key_hash' => '', 'used_at' => " . var_export(date(DATE_ATOM), true) . "];\n";
    if (@file_put_contents($tmp, $code, LOCK_EX) === false) {
        throw new RuntimeException('Koneksi berhasil, tetapi kunci setup tidak dapat dinonaktifkan. Periksa izin tulis folder lalu ulangi.');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Koneksi berhasil, tetapi kunci setup tidak dapat dinonaktifkan secara atomik. Hapus database_connection_setup.php sebelum melanjutkan.');
    }
    @chmod($file, 0600);
    $verify = include $file;
    if (!is_array($verify) || !empty($verify['enabled'])) {
        throw new RuntimeException('Koneksi berhasil, tetapi verifikasi penonaktifan kunci setup gagal. Hapus database_connection_setup.php sebelum melanjutkan.');
    }
}

$keyConfig = setupKeyConfig();
$currentConfig = tamasyaResolveDatabaseConfig(__DIR__);
[$existingPdo, $existingError, $existingStage] = tamasyaConnectDatabase($currentConfig);
$connected = $existingPdo instanceof PDO;
if($connected){
    try{tamasyaAssertDatabaseSafety($existingPdo,$currentConfig);}catch(Throwable $existingSafetyError){$connected=false;$existingError=$existingSafetyError->getMessage();$existingStage='database_safety';}
}

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$message = '';
$messageType = 'info';
$completed = false;

$values = [
    // Nilai koneksi tidak pernah diprefill sebelum setup-key diverifikasi.
    // Kolom kosong saat submit akan memakai konfigurasi lama secara internal.
    'host' => '',
    'port' => '',
    'name' => '',
    'user' => '',
    'target' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['host','port','name','user','target'] as $field) {
        $values[$field] = trim((string)($_POST[$field] ?? $values[$field]));
    }
    $password = (string)($_POST['password'] ?? '');
    $setupKey = (string)($_POST['setup_key'] ?? '');

    try {
        if (!hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman.');
        }
        $attempts = (int)($_SESSION['setup_attempts'] ?? 0);
        $lastAttempt = (int)($_SESSION['setup_last_attempt'] ?? 0);
        if ($attempts >= 8 && (time() - $lastAttempt) < 900) {
            throw new RuntimeException('Terlalu banyak percobaan. Coba kembali setelah 15 menit.');
        }
        if (empty($keyConfig['enabled']) || empty($keyConfig['key_hash'])) {
            throw new RuntimeException('Setup database sudah dinonaktifkan.');
        }
        if (!password_verify($setupKey, (string)$keyConfig['key_hash'])) {
            $_SESSION['setup_attempts'] = $attempts + 1;
            $_SESSION['setup_last_attempt'] = time();
            throw new RuntimeException('Kunci setup salah.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Driver PHP pdo_mysql belum aktif. Aktifkan ekstensi pdo_mysql dari panel hosting.');
        }
        if (preg_match('/[\r\n\0]/', implode('', [$values['host'],$values['name'],$values['user'],$values['target']]))) {
            throw new RuntimeException('Nilai konfigurasi mengandung karakter yang tidak diizinkan.');
        }
        $candidate=tamasyaMergeDatabaseSetupInput($currentConfig,[
            'host'=>$values['host'],'port'=>$values['port'],'name'=>$values['name'],'user'=>$values['user'],'password'=>$password,
        ]);
        if(empty($candidate['detected'])) throw new RuntimeException('Host, nama database, dan username wajib diisi karena belum ada konfigurasi lama yang dapat dipakai.');
        $credentialTarget = $values['target'] !== ''
            ? $values['target']
            : (!empty($currentConfig['source'])
                ? (string)$currentConfig['source']
                : tamasyaDefaultCredentialTarget(__DIR__));

        [$testPdo, $testError, $testStage] = tamasyaConnectDatabase($candidate);
        if (!$testPdo) {
            throw new RuntimeException('Uji koneksi gagal: ' . ($testError ?: $testStage));
        }
        $testPdo->query('SELECT DATABASE(), VERSION()')->fetch();
        tamasyaAssertDatabaseSafety($testPdo,$candidate);
        // If the target already has property identity, it MUST be this deployment.
        // An empty fresh baseline remains allowed for first_install.
        $candidateIdentity=tamasyaDatabasePropertyIdentity($testPdo,false);
        if(!empty($candidateIdentity['initialized']) && empty($candidateIdentity['ok'])) throw new RuntimeException('Database target milik property/deployment berbeda. Credential tidak disimpan.');

        tamasyaWriteDatabaseCredentials($credentialTarget, $candidate, false);
        disableSetupKey();
        $_SESSION['setup_attempts'] = 0;
        $message = 'Koneksi berhasil diuji, database safety gate lulus, dan file kredensial sudah diaktifkan. api.php hanya memverifikasi baseline; schema tidak dimigrasikan otomatis. Untuk database fresh, import database_setup.sql terlebih dahulu lalu jalankan first_install.php.';
        $messageType = 'success';
        $completed = true;
        $connected = true;
    } catch (Throwable $error) {
        $message = $error->getMessage();
        $messageType = 'error';
    }
}

$statusText = $connected
    ? 'Database terhubung.'
    : ($existingStage === 'missing_driver'
        ? 'Driver pdo_mysql tidak tersedia.'
        : (in_array($existingStage,['missing_credentials','credential_file_required'],true) ? 'Kredensial belum valid/ditemukan.' : ($existingStage==='database_safety'?'Database terhubung tetapi ditolak safety gate.':'Kredensial ditemukan, tetapi koneksi gagal.')));
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Perbaikan Koneksi Database — Tamasya</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#07111f;color:#e5eef9;font:14px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;padding:24px}.wrap{max-width:760px;margin:4vh auto}.card{background:#0d1b2d;border:1px solid #20324a;border-radius:20px;padding:24px;box-shadow:0 24px 70px #0008}.title{display:flex;align-items:center;gap:12px;margin-bottom:4px}.badge{padding:6px 10px;border-radius:999px;background:#16304a;color:#8ed8ff;font-weight:700;font-size:12px}.grid{display:grid;grid-template-columns:1fr 160px;gap:14px}.grid.one{grid-template-columns:1fr}.field{margin-top:14px}label{display:block;font-weight:700;margin-bottom:6px;color:#bcd0e6}input{width:100%;padding:12px 13px;border-radius:11px;border:1px solid #2d425c;background:#081422;color:#fff;outline:none}input:focus{border-color:#37d39a}.notice{padding:12px 14px;border-radius:12px;margin:16px 0}.info{background:#102a43;color:#cde9ff}.error{background:#451923;color:#ffc4ce}.success{background:#10382d;color:#a9f3d8}.warning{background:#3b2d12;color:#ffe3a4}.check{display:flex;gap:9px;align-items:flex-start;margin-top:14px;color:#b9c9dc}.check input{width:auto;margin-top:4px}button,.button{display:inline-flex;justify-content:center;align-items:center;width:100%;padding:13px;border:0;border-radius:12px;background:#37d39a;color:#062419;font-weight:800;cursor:pointer;text-decoration:none;margin-top:18px}.secondary{background:#1b3048;color:#e5eef9}.muted{color:#8ca3ba;font-size:12px}code{background:#07101d;padding:2px 6px;border-radius:6px}@media(max-width:620px){body{padding:12px}.card{padding:18px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body><div class="wrap"><div class="card">
<div class="title"><h1 style="margin:0;font-size:22px">Perbaikan Koneksi Database</h1><span class="badge">V137</span></div>
<p class="muted">Halaman satu kali untuk memulihkan koneksi MySQL. Tidak mengimpor ulang dan tidak menghapus data.</p>
<div class="notice <?=tamasyaHtmlEscape($connected ? 'success' : 'warning')?>"><strong>Status:</strong> <?=tamasyaHtmlEscape($statusText)?></div>
<?php if ($message !== ''): ?><div class="notice <?=tamasyaHtmlEscape($messageType)?>"><?=tamasyaHtmlEscape($message)?></div><?php endif; ?>

<?php if ($completed): ?>
<a class="button" href="./">Kembali ke Login Aplikasi</a>
<p class="muted">Kunci setup telah dinonaktifkan otomatis. Halaman ini tidak dapat digunakan lagi tanpa membuat kunci baru di server.</p>
<?php elseif (empty($keyConfig['enabled'])): ?>
<div class="notice info">Setup database sudah dinonaktifkan. Bila status belum terhubung, unggah ulang file <code>.database_setup_key.php</code> dari paket perbaikan atau pasang kredensial melalui panel hosting.</div>
<a class="button secondary" href="./">Kembali ke Aplikasi</a>
<?php else: ?>
<div class="notice info">Demi keamanan, host, nama database, username, dan lokasi file lama tidak ditampilkan. Kosongkan kolom yang tidak berubah; server akan memakai nilai lama secara internal.</div>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=tamasyaHtmlEscape((string)$_SESSION['csrf'])?>">
<div class="grid">
<div class="field"><label>Host MySQL</label><input name="host" value="<?=tamasyaHtmlEscape($values['host'])?>" placeholder="Kosongkan untuk memakai host lama / localhost"></div>
<div class="field"><label>Port</label><input name="port" inputmode="numeric" value="<?=tamasyaHtmlEscape($values['port'])?>" placeholder="Kosongkan untuk memakai port lama / 3306"></div>
</div>
<div class="field"><label>Nama database</label><input name="name" value="<?=tamasyaHtmlEscape($values['name'])?>"></div>
<div class="field"><label>Username database</label><input name="user" value="<?=tamasyaHtmlEscape($values['user'])?>"></div>
<div class="field"><label>Password database</label><input type="password" name="password" value="" autocomplete="new-password"></div>
<div class="field"><label>Lokasi file kredensial di server (opsional)</label><input name="target" value="<?=tamasyaHtmlEscape($values['target'])?>" placeholder="Kosongkan untuk memilih lokasi aman otomatis"><div class="muted">Kosongkan agar server memakai file lama yang ditemukan atau lokasi aman di luar document root. Isi hanya bila hosting membutuhkan path absolut khusus.</div></div>
<div class="notice warning">Credential database wajib berada di luar public/document root. Jika hosting tidak dapat menulis otomatis ke lokasi private, buat file credential secara manual dari File Manager/terminal lalu isi path absolutnya.</div>
<div class="field"><label>Kunci setup V137</label><input type="password" name="setup_key" required autocomplete="one-time-code"></div>
<button type="submit">Uji, Simpan, dan Hubungkan</button>
</form>
<?php endif; ?>
</div></div></body></html>
