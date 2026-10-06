<?php
declare(strict_types=1);

/**
 * TAMASYA FINAL12-PROD4 — Unified Environment, Two-Server & Database Configurator.
 *
 * ONE FILE / TWO INTERFACES:
 *   CLI    : php tamasya_configurator.php
 *   Browser: upload this file into admin_app, then open it over HTTPS.
 *
 * It can generate and validate:
 *   - Local node .env (PRIMARY/STANDBY selectable)
 *   - Hosting/online node .env (peer role automatically complementary)
 *   - Private DB credential PHP files for both nodes
 *   - Public-site runtime-config.js, robots.txt, sitemap.xml and CSP .htaccess (embedded fallback; no external template required)
 *   - A single ZIP bundle containing upload-ready files
 *   - Optional apply-to-current-server with timestamped backup + optional browser lock
 *   - Browser/CLI fresh commissioning on the applied PRIMARY: verified canonical SQL import, full validation, first-admin/property bootstrap, bootstrap-secret cleanup, and final gate
 *
 * Security:
 *   - Web POST requires a setup key. A new key can be generated/rotated by this same PHP; only its SHA-256 is persisted.
 *   - Browser auth accepts the generated TC4 key record or an explicit TAMASYA_CONFIGURATOR_SETUP_KEY (>=32 chars).
 *   - Public HTTP is rejected; HTTP is accepted only on localhost/private LAN hosts.
 *   - DB passwords are never placed in .env; credential PHP files remain separate.
 *   - The script never uploads data anywhere.
 *
 * IMPORTANT: APP_ENCRYPTION_KEY must be preserved for an existing database that
 * already contains encrypted values. Do not blindly rotate it.
 */

const TAMASYA_CONFIGURATOR_VERSION = 'FINAL12-PROD4.5_R7_CROSS_MENU_STATE_AUTHORITY_20260815';
const TAMASYA_CONFIGURATOR_UI_BUILD = 'UI-FINAL-R9_R7_CROSS_MENU_STATE_AUTHORITY_20260815';
const TAMASYA_CONFIGURATOR_SETUP_KEY_SHA256 = ''; // No built-in/legacy browser key in production.
const TAMASYA_CONFIGURATOR_LOCK_FILE = '.tamasya-configurator.lock';
const TAMASYA_CONFIGURATOR_SETUP_KEY_FILE = '.tamasya-configurator-key.json';
const TAMASYA_CONFIGURATOR_MIN_SECRET_LENGTH = 32;
const TAMASYA_SCHEMA_RELEASE_EXPECTED = 'V137_FRESH_CANONICAL_MULTI_HOTEL';
const TAMASYA_PATCH_LEVEL_EXPECTED = 'V137_STABLE_HARDENED_R7_CROSS_MENU_STATE_AUTHORITY_R1_20260815';
const TAMASYA_CANONICAL_MANIFEST_GZ_B64 = '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';

const TAMASYA_LOCAL_ENV_FALLBACK = <<<'TAMASYA_LOCAL_ENV'
# Wajib sesuaikan dengan timezone IANA property. Contoh berikut bukan default produk.
APP_TIMEZONE=REPLACE_IANA_TIMEZONE
APP_ENV=production
APP_DEBUG=0
APP_URL="http://192.168.1.10"
APP_ALLOWED_ORIGINS="http://192.168.1.10,http://hotel.local,https://app.example.com,https://hotel.example.com"

APP_ALLOWED_HOSTS="192.168.1.10,hotel.local,app.example.com,hotel.example.com"
APP_ENFORCE_ALLOWED_HOSTS=1
APP_TRUSTED_PROXIES=""
APP_REQUIRE_JSON_CONTENT_TYPE=1
APP_MAX_REQUEST_BYTES=12582912
SESSION_TTL_SECONDS=43200
SESSION_IDLE_TIMEOUT_SECONDS=3600
OFFLINE_REFRESH_TTL_SECONDS=2592000
AUTH_PASSWORD_MIN_LENGTH=12
AUTH_ACCOUNT_LOCK_THRESHOLD=12
AUTH_ACCOUNT_LOCK_SECONDS=900
SECURITY_EVENT_RETENTION_DAYS=180
# Wajib staging/production: HMAC audit/restore evidence terpisah, minimal 32 karakter random.
SECURITY_EVENT_HASH_KEY="CHANGE_ME_RANDOM_SEPARATE_HMAC_KEY_MIN_32"

# DB host/name/user/password disimpan di file private, bukan .env public deployment.
APP_CREDENTIALS_FILE="C:/tamasya-private/hotel_local_credentials.php"

# Database safety gate: isi sesuai database node ini.
APP_EXPECTED_DB_NAME="GANTI_NAMA_DATABASE"
APP_REQUIRE_EXPECTED_DB_NAME=1
APP_FORBIDDEN_DB_NAMES=""
APP_ALLOW_DB_ENV_OVERRIDE=0
ALLOW_PUBLIC_CREDENTIAL_FILE=0

APP_BOOTSTRAP_ADMIN_PASSWORD="CHANGE_ME_BOOTSTRAP_ADMIN_PASSWORD"
APP_BOOTSTRAP_ADMIN_USERNAME="admin"
APP_BOOTSTRAP_ADMIN_NAME="Administrator Utama"
APP_ENCRYPTION_KEY="base64:CHANGE_ME_GENERATE_A_RANDOM_32_BYTE_KEY"
TELEGRAM_SIMULATION_ENABLED=0
# Telegram dibuat ringan: timeout pendek, tanpa mengubah idempotency/webhook/Primary-only logic.
TELEGRAM_HTTP_TIMEOUT_SECONDS=12
TELEGRAM_CONNECT_TIMEOUT_SECONDS=4
COMMUNICATION_WORKER_BATCH_SIZE=20

# Reject direct POS sales attached to a cash shift older than this limit.
TAMASYA_POS_MAX_OPEN_SHIFT_HOURS=24
CRON_SECRET="CHANGE_ME_RANDOM_CRON_SECRET"
CRON_ALLOW_HTTP=0

# Protected browser access for administrative tools on hosting without terminal.
# Keep OFF unless needed; use HTTPS and a unique random secret >= 32 characters.
ADMIN_WEB_TOOLS_ENABLED=0
ADMIN_WEB_TOOLS_SECRET="CHANGE_ME_RANDOM_ADMIN_WEB_TOOL_SECRET_MIN_32_CHARS"
ADMIN_WEB_TOOLS_ALLOW_HTTP=0
ADMIN_WEB_TOOL_TIMEOUT_SECONDS=120
# Optional: absolute public-site document root when it is not the packaged sibling folder.
TAMASYA_PUBLIC_SITE_ROOT=""
# Backup SQL otomatis wajib private; jangan override ini kecuali benar-benar memahami risikonya.
ALLOW_PUBLIC_BACKUP_DIR=0
TAMASYA_NODE_LOCAL_BACKUP_KEEP=14
TAMASYA_COMPANY_LINK_BACKUP_MAX_AGE_HOURS=24
# Destructive implementation cleanup. Production default OFF; aktifkan sementara hanya saat maintenance terkontrol.
TAMASYA_IMPLEMENTATION_CLEANUP_ENABLED=0
TAMASYA_CLEANUP_MAX_BACKUP_AGE_HOURS=24
BACKUP_DIR="C:/tamasya-private/backups"

# Local menjadi primary pertama. Role dapat dipindahkan secara planned
# switchover ke hosting tanpa mengganti source atau database schema.
TAMASYA_NODE_MODE=flexible
NODE_CLUSTER_ENABLED=1
TAMASYA_CLUSTER_ID=property-cluster-01
TAMASYA_NODE_ID=property-local-01
TAMASYA_NODE_KIND=local
TAMASYA_NODE_INITIAL_ROLE=primary
NODE_CLUSTER_PUBLIC_URL="http://192.168.1.10"
NODE_CLUSTER_PEER_ID=property-hosting-01
NODE_CLUSTER_PEER_URL="https://app.example.com"

NODE_SYNC_ENABLED=1
TAMASYA_ALLOWED_NODE_IDS=property-hosting-01
NODE_SYNC_SHARED_SECRET="GANTI_SECRET_RANDOM_YANG_SAMA_MINIMAL_32_KARAKTER"
NODE_SYNC_PRIMARY_URL="https://app.example.com"
NODE_SYNC_SELF_URL="http://192.168.1.10"
NODE_SYNC_FORWARD_WHEN_ONLINE=1
NODE_SYNC_INTERVAL_SECONDS=10
NODE_CLUSTER_CONNECT_TIMEOUT_SECONDS=3
NODE_SYNC_MAX_SNAPSHOT_ROWS=100000

# HTTP lokal hanya boleh pada Wi-Fi staf terisolasi. HTTPS lokal lebih baik.
NODE_SYNC_ALLOW_HTTP_LOCAL=1

# Agar hosting dapat menarik mirror ketika local menjadi primary, alamat local
# harus dapat dijangkau hosting melalui VPN site-to-site, Tailscale/ZeroTier,
# reverse tunnel, atau gateway HTTPS yang dibatasi firewall dan HMAC.

# Leadership lease/fencing. Lease tidak boleh dibuat terlalu pendek karena
# gangguan Wi-Fi sesaat tidak boleh mematikan operasional hotel.
NODE_CLUSTER_LEASE_TTL_SECONDS=300
NODE_CLUSTER_LEASE_RENEW_WINDOW_SECONDS=120
NODE_CLUSTER_STALE_AFTER_SECONDS=900
NODE_CLUSTER_PROBE_BEFORE_WRITE=1

# Frontend HP/desktop menyimpan dua alamat kandidat dan mendeteksi Primary aktif.
# Gunakan HTTPS gateway lokal bila frontend dibuka dari domain HTTPS hosting;
# browser akan memblokir akses HTTPS -> HTTP sebagai mixed content.
VITE_TAMASYA_LOCAL_API_URL="http://192.168.1.10/api.php"
VITE_TAMASYA_ONLINE_API_URL="https://app.example.com/api.php"

# Runtime diagnosis: off | errors | mutations | all
# Staging dianjurkan mutations; production dianjurkan errors.
APP_RUNTIME_TRACE=errors

# Chat bantuan website memakai jawaban deterministik secara default.
PUBLIC_HELP_CHAT_AI_ENABLED=0
# Endpoint chat AI legacy per-staf dimatikan; gunakan Support website/Telegram.
INTERNAL_STAFF_HELP_CHAT_ENABLED=0
# Website publik dibuild terpisah; arahkan ke API pada node aplikasi yang sesuai.
VITE_PUBLIC_API_URL=https://app.example.com/api.php
PUBLIC_SITE_URL=https://hotel.example.com

# PRODUCTION BIOMETRIC ATTENDANCE
BIOMETRIC_BRIDGE_SHARED_SECRET=""
VITE_BIOMETRIC_BRIDGE_URL="http://127.0.0.1:8765"
FACE_VERIFICATION_URL=""
FACE_VERIFICATION_API_KEY=""
FACE_VERIFICATION_MIN_SCORE="0.82"
FACE_VERIFICATION_TIMEOUT_SECONDS="20"
BIOMETRIC_MAX_IMAGE_BYTES="2500000"
ALLOW_INSECURE_BIOMETRIC_PROVIDER="0"
ATTENDANCE_LATE_AFTER="08:30"
# GPS attendance is backward-compatible: 0 = GPS captured when available but does not block attendance.
# Set to 1 only after HTTPS + browser permission + geofence UAT are confirmed.
ATTENDANCE_REQUIRE_GPS="0"
ATTENDANCE_MAX_GPS_ACCURACY_METERS="150"
ATTENDANCE_GPS_MAX_AGE_SECONDS="300"
# Optional server-side geofence. Leave blank/0 to disable radius enforcement.
ATTENDANCE_GEOFENCE_LAT=""
ATTENDANCE_GEOFENCE_LNG=""
ATTENDANCE_GEOFENCE_RADIUS_METERS="0"
BIOMETRIC_DEVICE_MAX_EVENT_AGE_DAYS="30"


# ================================================================
# Optional Growth Suite: tetap 0 sampai installer opsional dijalankan dan UAT lulus.
# Untuk modul opsional gunakan installer CLI eksplisit:
# php optional_modules_install.php --help
# G1 phased activation: semua child default 0 dan harus explicit opt-in.
# KPI adalah Class A read-only dan tidak membutuhkan Growth schema; modul mutation/later-phase tetap membutuhkan schema + UAT fase terkait.
TAMASYA_GROWTH_SUITE_ENABLED=0
TAMASYA_GROWTH_KPI_ENABLED=0
TAMASYA_GROWTH_RATE_MANAGER_ENABLED=0
TAMASYA_GROWTH_GROUP_CORPORATE_ENABLED=0
TAMASYA_GROWTH_ADVANCED_FOLIO_ENABLED=0
TAMASYA_GROWTH_PROCUREMENT_ENABLED=0
TAMASYA_GROWTH_CHANNEL_FOUNDATION_ENABLED=0
TAMASYA_GROWTH_PAYMENT_FOUNDATION_ENABLED=0


# ================================================================
# Optional Enterprise Completion memerlukan Growth Suite schema lebih dahulu.
# Apply hanya setelah backup terverifikasi pada node yang relevan melalui optional_modules_install.php.
TAMASYA_ENTERPRISE_COMPLETION_ENABLED=0
TAMASYA_ENTERPRISE_FOLIO_WORKFLOW_ENABLED=1
TAMASYA_ENTERPRISE_PROCUREMENT_AP_ENABLED=1
TAMASYA_ENTERPRISE_CRM_LOYALTY_ENABLED=1
TAMASYA_ENTERPRISE_HEALTH_MONITORING_ENABLED=1
# Provider adapter metadata may be configured, but external auto mutation remains disabled.
TAMASYA_ENTERPRISE_PROVIDER_ADAPTERS_ENABLED=0
TAMASYA_ENTERPRISE_CRM_CAMPAIGN_SEND_ENABLED=0

# ================================================================
# This does NOT merge hotel databases. Each property keeps its own DB and
# Primary/Standby cluster. HQ is a separate future database/application.
# IMPORTANT: changing TAMASYA_PROPERTY_ID changes hotelScopeId. Do it only
# during controlled maintenance with pending offline=0 and BOTH nodes aligned.
TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED=0
# MODEL FLEKSIBEL: satu build untuk hotel independen maupun cabang; tidak ada mode produk standalone.
# HOTEL BERBEDA/INDEPENDEN: COMPANY_ID boleh kosong; property identity dan database tetap unik.
# CABANG SATU PERUSAHAAN: TAMASYA_COMPANY_ID SAMA, tetapi PROPERTY_ID, PROPERTY_CODE,
# CLUSTER_ID, dan database WAJIB berbeda untuk setiap cabang/property.
TAMASYA_COMPANY_ID=""
TAMASYA_PROPERTY_ID="property-01"
TAMASYA_PROPERTY_CODE="PROPERTY01"
TAMASYA_PROPERTY_NAME="NAMA HOTEL"
TAMASYA_PROPERTY_CURRENCY="IDR"
TAMASYA_PROPERTY_COUNTRY="ID"
TAMASYA_PROPERTY_LOCALE="id-ID"
TAMASYA_INVOICE_PREFIX="PROPERTY01"
# Future HQ bridge remains disabled in this release. No automatic network sync.
TAMASYA_COMPANY_NAME=""
TAMASYA_HQ_BRIDGE_ENABLED=0
TAMASYA_HQ_HUB_URL=""
TAMASYA_HQ_SHARED_SECRET=""
TAMASYA_HQ_TIMEOUT_SECONDS=15
# Safety locks: MUST remain 0 until a future signed/idempotent workflow passes UAT.
TAMASYA_HQ_ALLOW_WRITEBACK=0
TAMASYA_CROSS_PROPERTY_RESERVATION_ENABLED=0


# Public website media safety
PUBLIC_SITE_MAX_IMAGE_DIMENSION="12000"
PUBLIC_SITE_MAX_IMAGE_PIXELS="40000000"
TAMASYA_LOCAL_ENV;

const TAMASYA_ONLINE_ENV_FALLBACK = <<<'TAMASYA_ONLINE_ENV'
# Wajib sesuaikan dengan timezone IANA property. Contoh berikut bukan default produk.
APP_TIMEZONE=REPLACE_IANA_TIMEZONE
APP_ENV=production
APP_DEBUG=0
APP_URL="https://app.example.com"
APP_ALLOWED_ORIGINS="https://app.example.com,https://hotel.example.com"

APP_ALLOWED_HOSTS="app.example.com,hotel.example.com"
APP_ENFORCE_ALLOWED_HOSTS=1
APP_TRUSTED_PROXIES=""
APP_REQUIRE_JSON_CONTENT_TYPE=1
APP_MAX_REQUEST_BYTES=12582912
SESSION_TTL_SECONDS=43200
SESSION_IDLE_TIMEOUT_SECONDS=3600
OFFLINE_REFRESH_TTL_SECONDS=2592000
AUTH_PASSWORD_MIN_LENGTH=12
AUTH_ACCOUNT_LOCK_THRESHOLD=12
AUTH_ACCOUNT_LOCK_SECONDS=900
SECURITY_EVENT_RETENTION_DAYS=180
# Wajib staging/production: HMAC audit/restore evidence terpisah, minimal 32 karakter random.
SECURITY_EVENT_HASH_KEY="CHANGE_ME_RANDOM_SEPARATE_HMAC_KEY_MIN_32"

# DB host/name/user/password disimpan di file private, bukan .env public deployment.
APP_CREDENTIALS_FILE="/home/account/private/hotel_credentials.php"

# Database safety gate: isi sesuai database node ini.
APP_EXPECTED_DB_NAME="GANTI_NAMA_DATABASE"
APP_REQUIRE_EXPECTED_DB_NAME=1
APP_FORBIDDEN_DB_NAMES=""
APP_ALLOW_DB_ENV_OVERRIDE=0
ALLOW_PUBLIC_CREDENTIAL_FILE=0

APP_BOOTSTRAP_ADMIN_PASSWORD="CHANGE_ME_BOOTSTRAP_ADMIN_PASSWORD"
APP_BOOTSTRAP_ADMIN_USERNAME="admin"
APP_BOOTSTRAP_ADMIN_NAME="Administrator Utama"
APP_ENCRYPTION_KEY="base64:CHANGE_ME_GENERATE_A_RANDOM_32_BYTE_KEY"
TELEGRAM_SIMULATION_ENABLED=0
# Telegram dibuat ringan: timeout pendek, tanpa mengubah idempotency/webhook/Primary-only logic.
TELEGRAM_HTTP_TIMEOUT_SECONDS=12
TELEGRAM_CONNECT_TIMEOUT_SECONDS=4
COMMUNICATION_WORKER_BATCH_SIZE=20

# Reject direct POS sales attached to a cash shift older than this limit.
TAMASYA_POS_MAX_OPEN_SHIFT_HOURS=24

CRON_SECRET="CHANGE_ME_RANDOM_CRON_SECRET"
CRON_ALLOW_HTTP=0

# Protected browser access for administrative tools on hosting without terminal.
# Keep OFF unless needed; use HTTPS and a unique random secret >= 32 characters.
ADMIN_WEB_TOOLS_ENABLED=0
ADMIN_WEB_TOOLS_SECRET="CHANGE_ME_RANDOM_ADMIN_WEB_TOOL_SECRET_MIN_32_CHARS"
ADMIN_WEB_TOOLS_ALLOW_HTTP=0
ADMIN_WEB_TOOL_TIMEOUT_SECONDS=120
# Optional: absolute public-site document root when it is not the packaged sibling folder.
TAMASYA_PUBLIC_SITE_ROOT=""
# Backup SQL otomatis wajib private; jangan override ini kecuali benar-benar memahami risikonya.
ALLOW_PUBLIC_BACKUP_DIR=0
TAMASYA_NODE_LOCAL_BACKUP_KEEP=14
TAMASYA_COMPANY_LINK_BACKUP_MAX_AGE_HOURS=24
# Destructive implementation cleanup. Production default OFF; aktifkan sementara hanya saat maintenance terkontrol.
TAMASYA_IMPLEMENTATION_CLEANUP_ENABLED=0
TAMASYA_CLEANUP_MAX_BACKUP_AGE_HOURS=24
BACKUP_DIR="/home/account/private/hotel-backups"

# Flexible cluster. Pada pemasangan pertama, hosting menjadi standby.
# Ubah TAMASYA_NODE_INITIAL_ROLE=primary bila hosting akan menjadi primary pertama.
TAMASYA_NODE_MODE=flexible
NODE_CLUSTER_ENABLED=1
TAMASYA_CLUSTER_ID=property-cluster-01
TAMASYA_NODE_ID=property-hosting-01
TAMASYA_NODE_KIND=hosting
TAMASYA_NODE_INITIAL_ROLE=standby
NODE_CLUSTER_PUBLIC_URL="https://app.example.com"
NODE_CLUSTER_PEER_ID=property-local-01
NODE_CLUSTER_PEER_URL="https://local-gateway.example.net"

NODE_SYNC_ENABLED=1
TAMASYA_ALLOWED_NODE_IDS=property-local-01
NODE_SYNC_SHARED_SECRET="GANTI_SECRET_RANDOM_YANG_SAMA_MINIMAL_32_KARAKTER"
NODE_SYNC_PRIMARY_URL="https://local-gateway.example.net"
NODE_SYNC_SELF_URL="https://app.example.com"
NODE_SYNC_FORWARD_WHEN_ONLINE=1
NODE_SYNC_INTERVAL_SECONDS=10
NODE_CLUSTER_CONNECT_TIMEOUT_SECONDS=3
NODE_SYNC_MAX_SNAPSHOT_ROWS=100000

# Telegram webhook tetap dapat diarahkan ke hosting. Saat hosting standby,
# webhook diteruskan secara HMAC ke primary aktif. Token bot/config Telegram
# harus dikonfigurasi independen pada kedua node karena secret tidak dimirror.

NODE_CLUSTER_LEASE_TTL_SECONDS=300
NODE_CLUSTER_LEASE_RENEW_WINDOW_SECONDS=120
NODE_CLUSTER_STALE_AFTER_SECONDS=900
NODE_CLUSTER_PROBE_BEFORE_WRITE=1

# Build yang sama boleh dipasang di kedua node. Untuk akses local dari halaman
# HTTPS, VITE_TAMASYA_LOCAL_API_URL harus berupa gateway HTTPS/VPN yang sah.
VITE_TAMASYA_LOCAL_API_URL="https://local-gateway.example.net/api.php"
VITE_TAMASYA_ONLINE_API_URL="https://app.example.com/api.php"

# Runtime diagnosis: off | errors | mutations | all
# Staging dianjurkan mutations; production dianjurkan errors.
APP_RUNTIME_TRACE=errors

# Public website origin and anti-spam boundary
# APP_ALLOWED_ORIGINS=https://example.com,https://www.example.com
# PUBLIC_SITE_RATE_LIMIT_SALT="CHANGE_ME_RANDOM_RATE_LIMIT_SALT"
# PUBLIC_SITE_REQUIRE_ALLOWED_ORIGIN=1
# Chat bantuan website tetap berfungsi tanpa AI. Aktifkan hanya bila ingin memakai Gemini dengan data publik saja.
PUBLIC_HELP_CHAT_AI_ENABLED=0
# Endpoint chat AI legacy per-staf dimatikan; gunakan Support website/Telegram.
INTERNAL_STAFF_HELP_CHAT_ENABLED=0
# Website publik dibuild terpisah; arahkan ke API pada node aplikasi yang sesuai.
VITE_PUBLIC_API_URL=https://app.example.com/api.php

PUBLIC_SITE_URL=https://hotel.example.com
PUBLIC_SITE_MAX_IMAGE_BYTES=6291456

# PRODUCTION BIOMETRIC ATTENDANCE
BIOMETRIC_BRIDGE_SHARED_SECRET=""
VITE_BIOMETRIC_BRIDGE_URL="http://127.0.0.1:8765"
FACE_VERIFICATION_URL=""
FACE_VERIFICATION_API_KEY=""
FACE_VERIFICATION_MIN_SCORE="0.82"
FACE_VERIFICATION_TIMEOUT_SECONDS="20"
BIOMETRIC_MAX_IMAGE_BYTES="2500000"
ALLOW_INSECURE_BIOMETRIC_PROVIDER="0"
ATTENDANCE_LATE_AFTER="08:30"
# GPS attendance is backward-compatible: 0 = GPS captured when available but does not block attendance.
# Set to 1 only after HTTPS + browser permission + geofence UAT are confirmed.
ATTENDANCE_REQUIRE_GPS="0"
ATTENDANCE_MAX_GPS_ACCURACY_METERS="150"
ATTENDANCE_GPS_MAX_AGE_SECONDS="300"
# Optional server-side geofence. Leave blank/0 to disable radius enforcement.
ATTENDANCE_GEOFENCE_LAT=""
ATTENDANCE_GEOFENCE_LNG=""
ATTENDANCE_GEOFENCE_RADIUS_METERS="0"
BIOMETRIC_DEVICE_MAX_EVENT_AGE_DAYS="30"


# ================================================================
# Optional Growth Suite: tetap 0 sampai installer opsional dijalankan dan UAT lulus.
# Untuk modul opsional gunakan installer CLI eksplisit:
# php optional_modules_install.php --help
# G1 phased activation: semua child default 0 dan harus explicit opt-in.
# KPI adalah Class A read-only dan tidak membutuhkan Growth schema; modul mutation/later-phase tetap membutuhkan schema + UAT fase terkait.
TAMASYA_GROWTH_SUITE_ENABLED=0
TAMASYA_GROWTH_KPI_ENABLED=0
TAMASYA_GROWTH_RATE_MANAGER_ENABLED=0
TAMASYA_GROWTH_GROUP_CORPORATE_ENABLED=0
TAMASYA_GROWTH_ADVANCED_FOLIO_ENABLED=0
TAMASYA_GROWTH_PROCUREMENT_ENABLED=0
# External integration foundations remain opt-in. They do NOT auto-mutate
# bookings or money in this release.
TAMASYA_GROWTH_CHANNEL_FOUNDATION_ENABLED=0
TAMASYA_GROWTH_PAYMENT_FOUNDATION_ENABLED=0


# ================================================================
# Optional Enterprise Completion memerlukan Growth Suite schema lebih dahulu.
# Apply hanya setelah backup terverifikasi pada node yang relevan melalui optional_modules_install.php.
TAMASYA_ENTERPRISE_COMPLETION_ENABLED=0
TAMASYA_ENTERPRISE_FOLIO_WORKFLOW_ENABLED=1
TAMASYA_ENTERPRISE_PROCUREMENT_AP_ENABLED=1
TAMASYA_ENTERPRISE_CRM_LOYALTY_ENABLED=1
TAMASYA_ENTERPRISE_HEALTH_MONITORING_ENABLED=1
# Provider adapter metadata may be configured, but external auto mutation remains disabled.
TAMASYA_ENTERPRISE_PROVIDER_ADAPTERS_ENABLED=0
TAMASYA_ENTERPRISE_CRM_CAMPAIGN_SEND_ENABLED=0

# ================================================================
# This does NOT merge hotel databases. Each property keeps its own DB and
# Primary/Standby cluster. HQ is a separate future database/application.
# IMPORTANT: changing TAMASYA_PROPERTY_ID changes hotelScopeId. Do it only
# during controlled maintenance with pending offline=0 and BOTH nodes aligned.
TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED=0
# MODEL FLEKSIBEL: satu build untuk hotel independen maupun cabang; tidak ada mode produk standalone.
# HOTEL BERBEDA/INDEPENDEN: COMPANY_ID boleh kosong; property identity dan database tetap unik.
# CABANG SATU PERUSAHAAN: TAMASYA_COMPANY_ID SAMA, tetapi PROPERTY_ID, PROPERTY_CODE,
# CLUSTER_ID, dan database WAJIB berbeda untuk setiap cabang/property.
TAMASYA_COMPANY_ID=""
TAMASYA_PROPERTY_ID="property-01"
TAMASYA_PROPERTY_CODE="PROPERTY01"
TAMASYA_PROPERTY_NAME="NAMA HOTEL"
TAMASYA_PROPERTY_CURRENCY="IDR"
TAMASYA_PROPERTY_COUNTRY="ID"
TAMASYA_PROPERTY_LOCALE="id-ID"
TAMASYA_INVOICE_PREFIX="PROPERTY01"
# Future HQ bridge remains disabled in this release. No automatic network sync.
TAMASYA_COMPANY_NAME=""
TAMASYA_HQ_BRIDGE_ENABLED=0
TAMASYA_HQ_HUB_URL=""
TAMASYA_HQ_SHARED_SECRET=""
TAMASYA_HQ_TIMEOUT_SECONDS=15
# Safety locks: MUST remain 0 until a future signed/idempotent workflow passes UAT.
TAMASYA_HQ_ALLOW_WRITEBACK=0
TAMASYA_CROSS_PROPERTY_RESERVATION_ENABLED=0


# Public website media safety
PUBLIC_SITE_MAX_IMAGE_DIMENSION="12000"
PUBLIC_SITE_MAX_IMAGE_PIXELS="40000000"
TAMASYA_ONLINE_ENV;

const TAMASYA_PUBLIC_HTACCESS_FALLBACK = <<<'HTACCESS'
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !https [NC]
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} \.(?:php|js|mjs|css|json|map|webmanifest|xml|txt|csv|xlsx|pdf|png|jpe?g|gif|svg|ico|woff2?|ttf)$ [NC]
RewriteRule ^ - [R=404,L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . index.html [L]
</IfModule>
Options -Indexes
<IfModule mod_headers.c>
<FilesMatch "^index\.html$">
Header set Cache-Control "no-cache, no-store, must-revalidate"
Header set Pragma "no-cache"
Header set Expires 0
</FilesMatch>
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "SAMEORIGIN"
Header set Referrer-Policy "strict-origin-when-cross-origin"
Header set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()"
# TAMASYA_API_ORIGIN: https://app.nolink.my.id — generated by tamasya_configurator.php
Header set Content-Security-Policy "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data: https:; connect-src 'self' https://app.nolink.my.id; frame-src https://google.com https://www.google.com https://maps.google.com; manifest-src 'self'"
</IfModule>
<FilesMatch "^\.(?:env|git)">
Require all denied
</FilesMatch>
HTACCESS;

function tc_cli(): bool { return PHP_SAPI === 'cli'; }
function tc_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function tc_bool($v): bool { return filter_var($v, FILTER_VALIDATE_BOOLEAN); }
function tc_random_hex(int $bytes=32): string { return bin2hex(random_bytes($bytes)); }
function tc_random_token(int $bytes=32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'='); }
function tc_random_b64_key(): string { return 'base64:'.base64_encode(random_bytes(32)); }
function tc_random_password(int $length=28): string {
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%_-';
    $out=''; $n=strlen($alphabet);
    for($i=0;$i<$length;$i++) $out.=$alphabet[random_int(0,$n-1)];
    return $out;
}
function tc_secret_fingerprint(string $secret): string {
    $h=strtoupper(substr(hash('sha256',$secret),0,20));
    return implode(':',str_split($h,4));
}
function tc_secret_min(string $value,int $min=TAMASYA_CONFIGURATOR_MIN_SECRET_LENGTH): bool { return strlen($value)>=$min; }
function tc_valid_encryption_key(string $value): bool {
    if(str_starts_with($value,'base64:')){$d=base64_decode(substr($value,7),true);return $d!==false&&strlen($d)>=32;}
    return tc_secret_min($value);
}
function tc_slug(string $v, string $fallback='hotel-01'): string {
    $v=strtolower(trim($v));
    $v=preg_replace('/[^a-z0-9._-]+/','-',$v)??'';
    $v=trim($v,'-.');
    return $v!==''?substr($v,0,80):$fallback;
}
function tc_path_absolute(string $path): bool {
    $path=trim($path); if($path==='') return false;
    if($path[0]==='/' || $path[0]==='\\') return true;
    return (bool)preg_match('/^[A-Za-z]:[\\\\\/]/',$path);
}
function tc_path_normalize(string $path): string {
    $path=str_replace('\\','/',trim($path));$prefix='';
    if(preg_match('/^[A-Za-z]:\//',$path)){$prefix=strtoupper(substr($path,0,2));$path=substr($path,2);}elseif(str_starts_with($path,'/')){$prefix='/';}
    $parts=[];foreach(explode('/',$path) as $part){if($part===''||$part==='.')continue;if($part==='..'){if($parts)array_pop($parts);continue;}$parts[]=$part;}
    $out=implode('/',$parts);if($prefix==='/')return '/'.$out;if($prefix!=='')return $prefix.'/'.$out;return $out;
}
function tc_path_within(string $child,string $parent): bool {
    if(!tc_path_absolute($child)||!tc_path_absolute($parent))return false;
    $c=rtrim(tc_path_normalize($child),'/').'/';$p=rtrim(tc_path_normalize($parent),'/').'/';if(DIRECTORY_SEPARATOR==='\\'){$c=strtolower($c);$p=strtolower($p);}return str_starts_with($c,$p);
}

/**
 * Detect paths that are unsafe for DB credentials or backups.
 *
 * Important: checking only Admin App Root/Public Site Root is not enough on
 * shared hosting. A path such as /home/user/public_html/tamasya-private is a
 * sibling of admin_app but is still publicly addressable under cPanel's web
 * root. This guard therefore combines active roots with conservative,
 * well-known document-root directory names.
 */
function tc_known_webroot_ancestor(string $path): string {
    if($path===''||!tc_path_absolute($path))return '';
    $normalized=str_replace('\\','/',tc_path_normalize($path));
    if(preg_match('#^(.*?/(?:public_html|htdocs|httpdocs|wwwroot|webroot))(?:/|$)#i',$normalized,$m)===1)return rtrim((string)$m[1],'/');
    return '';
}
function tc_private_path_guard(string $path,string $adminRoot='',string $publicSiteRoot=''): array {
    $path=trim($path);$reasons=[];
    if($path===''||!tc_path_absolute($path))return ['ok'=>false,'reasons'=>['path harus absolut'],'path'=>$path];
    $normalized=tc_path_normalize($path);
    if($adminRoot!==''&&tc_path_absolute($adminRoot)&&tc_path_within($normalized,$adminRoot))$reasons[]='berada di Admin App Root';
    if($publicSiteRoot!==''&&tc_path_absolute($publicSiteRoot)&&tc_path_within($normalized,$publicSiteRoot))$reasons[]='berada di Public Site Root';
    $doc=trim((string)($_SERVER['DOCUMENT_ROOT']??''));
    if($doc!==''&&tc_path_absolute($doc)&&tc_path_within($normalized,$doc))$reasons[]='berada di DOCUMENT_ROOT aktif';
    $lower='/'.trim(strtolower(str_replace('\\','/',$normalized)),'/').'/';
    foreach(['public_html','htdocs','httpdocs','wwwroot','webroot'] as $segment){
        if(str_contains($lower,'/'.$segment.'/')){$reasons[]='melewati folder web publik '.$segment;break;}
    }
    return ['ok'=>!$reasons,'reasons'=>array_values(array_unique($reasons)),'path'=>$normalized];
}
function tc_private_path_error(string $path,string $label,string $adminRoot='',string $publicSiteRoot=''): ?string {
    $g=tc_private_path_guard($path,$adminRoot,$publicSiteRoot);
    return !empty($g['ok'])?null:$label.' wajib berada di luar document/public root ('.implode('; ',(array)$g['reasons']).'). Gunakan contoh /home/USERNAME/tamasya-private/...';
}
function tc_private_host(string $host): bool {
    $host=strtolower(trim($host,'[]'));
    if(in_array($host,['localhost','127.0.0.1','::1'],true) || str_ends_with($host,'.local') || (!str_contains($host,'.')&&!str_contains($host,':'))) return true;
    if(filter_var($host,FILTER_VALIDATE_IP))return filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false;
    return false;
}
function tc_validate_proxy_list(string $value,string $label): string {
    $value=trim($value);if($value==='')return '';$parts=preg_split('/[\\s,]+/',$value,-1,PREG_SPLIT_NO_EMPTY)?:[];$clean=[];
    foreach($parts as $part){$ip=$part;$prefix=null;if(str_contains($part,'/')){[$ip,$prefix]=explode('/',$part,2);if($prefix===''||!ctype_digit($prefix))throw new RuntimeException($label.' CIDR invalid: '.$part);}if(!filter_var($ip,FILTER_VALIDATE_IP))throw new RuntimeException($label.' hanya menerima IP/CIDR: '.$part);if($prefix!==null){$max=str_contains($ip,':')?128:32;if((int)$prefix<0||(int)$prefix>$max)throw new RuntimeException($label.' CIDR prefix invalid: '.$part);}$clean[]=$part;}
    return implode(',',array_values(array_unique($clean)));
}
function tc_suggest_private_base(string $adminRoot,string $propertyId): string {
    $adminRoot=tc_path_normalize($adminRoot);$doc=trim((string)($_SERVER['DOCUMENT_ROOT']??''));$base='';
    // On cPanel/Plesk the Admin root may be a child of public_html/httpdocs.
    // Climb ABOVE that known public ancestor; never suggest a sibling that is
    // still inside the same web root.
    $webAncestor=tc_known_webroot_ancestor($adminRoot);
    if($webAncestor!=='')$base=dirname($webAncestor);
    elseif($doc!==''&&tc_path_absolute($doc)){
        $docNorm=tc_path_normalize($doc);$docWeb=tc_known_webroot_ancestor($docNorm);
        if($docWeb!=='')$base=dirname($docWeb);
        elseif(tc_path_within($adminRoot,$docNorm))$base=dirname($docNorm);
    }
    if($base==='')$base=dirname($adminRoot);
    return rtrim($base,'/\\').DIRECTORY_SEPARATOR.'tamasya-private'.DIRECTORY_SEPARATOR.tc_slug($propertyId,'hotel');
}
function tc_origin(string $raw,string $label,bool $publicProduction=true,bool $allowPrivateHttp=false): array {
    $raw=trim($raw);
    if($raw==='') throw new RuntimeException($label.' wajib diisi.');
    if(strlen($raw)>2048 || !filter_var($raw,FILTER_VALIDATE_URL)) throw new RuntimeException($label.' bukan URL valid.');
    $p=parse_url($raw);
    if(!is_array($p)||empty($p['scheme'])||empty($p['host'])) throw new RuntimeException($label.' harus URL origin lengkap.');
    $scheme=strtolower((string)$p['scheme']); $host=strtolower((string)$p['host']);
    if(!in_array($scheme,['http','https'],true)) throw new RuntimeException($label.' hanya boleh HTTP/HTTPS.');
    if(isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])) throw new RuntimeException($label.' tidak boleh memuat user/password/query/fragment.');
    $path=(string)($p['path']??''); if($path!==''&&$path!=='/') throw new RuntimeException($label.' harus origin tanpa path.');
    $isPrivate=tc_private_host($host);
    if($scheme!=='https' && !($allowPrivateHttp && $isPrivate)) throw new RuntimeException($label.' wajib HTTPS; HTTP hanya diizinkan untuk host private/LAN.');
    if($publicProduction && !$isPrivate && preg_match('/(^|[.-])(staging|stage|dev|test|example|localhost|local)([.-]|$)/i',$host)) throw new RuntimeException($label.' terlihat sebagai host non-production: '.$host);
    $port=isset($p['port'])?(int)$p['port']:null;
    if($port!==null&&($port<1||$port>65535)) throw new RuntimeException($label.' port tidak valid.');
    $hostOut=str_contains($host,':')?'['.$host.']':$host;
    $origin=$scheme.'://'.$hostOut.($port!==null?':'.$port:'');
    return ['origin'=>$origin,'host'=>$host,'scheme'=>$scheme,'private'=>$isPrivate,'port'=>$port];
}
function tc_validate_timezone(string $tz): string {
    $tz=trim($tz); if($tz===''||!in_array($tz,timezone_identifiers_list(),true)) throw new RuntimeException('APP_TIMEZONE tidak valid: '.$tz);
    return $tz;
}
function tc_validate_db_name(string $v,string $label): string {
    $v=trim($v); if(!preg_match('/^[A-Za-z0-9_$-]{1,64}$/',$v)) throw new RuntimeException($label.' hanya boleh 1-64 karakter huruf/angka/_/$/-.'); return $v;
}
function tc_validate_node_id(string $v,string $label): string {
    $v=trim($v); if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,99}$/',$v)) throw new RuntimeException($label.' wajib 2-100 karakter huruf/angka/._-.'); return $v;
}
function tc_validate_property_id(string $v): string {
    $v=strtolower(trim($v)); if(!preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$v)) throw new RuntimeException('PROPERTY_ID wajib 2-80 karakter huruf kecil/angka/._:-.'); return $v;
}
function tc_validate_abs_path(string $v,string $label): string { $v=trim($v); if(!tc_path_absolute($v)) throw new RuntimeException($label.' wajib path absolut.'); return $v; }
function tc_env_quote(string $v): string { return '"'.str_replace(['\\','"',"\r","\n"],['\\\\','\\"','',''],$v).'"'; }
function tc_set_env(string $text,string $key,string $value,bool $quote=true): string {
    $line=$key.'='.($quote?tc_env_quote($value):$value);
    $pattern='/^'.preg_quote($key,'/').'\s*=.*$/m';
    if(preg_match($pattern,$text)) return preg_replace($pattern,$line,$text,1)??$text;
    return rtrim($text)."\n".$line."\n";
}
function tc_template(string $kind): string {
    $candidate=__DIR__.DIRECTORY_SEPARATOR.($kind==='local'?'.env.local.example':'.env.online.example');
    if(is_file($candidate)&&is_readable($candidate)){
        $v=file_get_contents($candidate); if(is_string($v)&&strlen($v)>100) return $v;
    }
    return $kind==='local'?TAMASYA_LOCAL_ENV_FALLBACK:TAMASYA_ONLINE_ENV_FALLBACK;
}
function tc_active_placeholders(string $env): array {
    $bad=[]; $markers=['GANTI_','GANTI-','CHANGE_ME','REPLACE_IANA_TIMEZONE','hotel.example.com','app.example.com','local-gateway.example.net','/home/account/','NAMA HOTEL','property-01','PROPERTY01'];
    foreach(preg_split('/\R/',$env)?:[] as $line){
        $line=trim($line); if($line===''||$line[0]==='#'||$line[0]===';') continue;
        foreach($markers as $m) if(str_contains($line,$m)) $bad[]=$m;
    }
    return array_values(array_unique($bad));
}
function tc_credential_php(array $db): string {
    $payload=['host'=>$db['host'],'port'=>(int)$db['port'],'name'=>$db['name'],'user'=>$db['user'],'pass'=>$db['pass']];
    return "<?php\ndeclare(strict_types=1);\n// Generated by TAMASYA unified configurator. Keep OUTSIDE document root.\nreturn ".var_export($payload,true).";\n";
}
function tc_array_get(array $a,string $k,$default=''){ return array_key_exists($k,$a)?$a[$k]:$default; }
function tc_value(array $a,string $k,string $default=''): string { return trim((string)tc_array_get($a,$k,$default)); }
function tc_checkbox(array $a,string $k,bool $default=false): bool { return array_key_exists($k,$a)?tc_bool($a[$k]):$default; }
function tc_defaults(): array {
    return [
        'mode'=>'dual','timezone'=>'Asia/Makassar','online_url'=>'https://app.nolink.my.id','public_url'=>'https://tamasya.nolink.my.id','local_url'=>'http://192.168.1.10',
        'property_id'=>'','property_code'=>'','property_name'=>'','company_id'=>'','company_name'=>'','currency'=>'IDR','country'=>'ID','locale'=>'id-ID','invoice_prefix'=>'',
        'initial_primary'=>'local','cluster_id'=>'','local_node_id'=>'','online_node_id'=>'','fresh_install'=>(is_file(__DIR__.DIRECTORY_SEPARATOR.'.env')?'0':'1'),
        'local_db_host'=>'127.0.0.1','local_db_port'=>'3306','local_db_name'=>'','local_db_user'=>'','local_db_pass'=>'','local_credential_path'=>'','local_backup_dir'=>'','local_trusted_proxies'=>'',
        'online_db_host'=>'localhost','online_db_port'=>'3306','online_db_name'=>'','online_db_user'=>'','online_db_pass'=>'','online_credential_path'=>'','online_backup_dir'=>'','online_trusted_proxies'=>'',
        'existing_encryption_key'=>'','existing_sync_secret'=>'','preserve_existing_env_secrets'=>'1','include_secret_inventory'=>'1','public_site_root'=>'','admin_root'=>__DIR__,'apply_target'=>'none','apply_public'=>'0','lock_after_apply'=>'1'
    ];
}
function tc_merge_defaults(array $input): array { return array_merge(tc_defaults(),$input); }

function tc_validate_input(array $raw): array {
    $v=tc_merge_defaults($raw); $errors=[];$caps=tc_capabilities();if(!$caps['php82Plus'])$errors[]='PHP 8.2+ wajib.';if(!$caps['randomBytes'])$errors[]='random_bytes() wajib tersedia.';if(!$caps['openssl'])$errors[]='OpenSSL extension wajib tersedia.';
    try{$v['timezone']=tc_validate_timezone(tc_value($v,'timezone'));}catch(Throwable $e){$errors[]=$e->getMessage();}
    $mode=tc_value($v,'mode','dual'); if(!in_array($mode,['dual','single_online','single_local'],true)){$errors[]='Mode deployment tidak valid.';$mode='dual';} $v['mode']=$mode;
    try{$online=tc_origin(tc_value($v,'online_url'),'ONLINE APP URL',true,false);$v['online_origin']=$online['origin'];$v['online_host']=$online['host'];}catch(Throwable $e){$errors[]=$e->getMessage();}
    try{$public=tc_origin(tc_value($v,'public_url'),'PUBLIC SITE URL',true,false);$v['public_origin']=$public['origin'];$v['public_host']=$public['host'];}catch(Throwable $e){$errors[]=$e->getMessage();}
    if($mode!=='single_online'){
        try{$local=tc_origin(tc_value($v,'local_url'),'LOCAL APP URL',false,true);$v['local_origin']=$local['origin'];$v['local_host']=$local['host'];$v['local_http']=$local['scheme']==='http';}catch(Throwable $e){$errors[]=$e->getMessage();}
    } else { $v['local_origin']=tc_value($v,'local_url'); $v['local_host']=''; $v['local_http']=false; }
    try{$v['property_id']=tc_validate_property_id(tc_value($v,'property_id'));}catch(Throwable $e){$errors[]=$e->getMessage();}
    $v['property_code']=strtoupper(trim(tc_value($v,'property_code'))); if($v['property_code']===''||strlen($v['property_code'])>30||!preg_match('/^[A-Z0-9._-]+$/',$v['property_code']))$errors[]='PROPERTY_CODE wajib 1-30 karakter A-Z/0-9/._-.';
    $v['property_name']=tc_value($v,'property_name'); if($v['property_name']===''||strlen($v['property_name'])>120)$errors[]='PROPERTY_NAME wajib 1-120 karakter.';
    $v['invoice_prefix']=strtoupper(tc_value($v,'invoice_prefix',$v['property_code'])); if($v['invoice_prefix']==='')$v['invoice_prefix']=$v['property_code']; if(strlen($v['invoice_prefix'])>30||!preg_match('/^[A-Z0-9._-]+$/',$v['invoice_prefix']))$errors[]='INVOICE_PREFIX wajib 1-30 karakter A-Z/0-9/._-.';
    $v['company_id']=strtolower(tc_value($v,'company_id')); if($v['company_id']!==''&&!preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$v['company_id']))$errors[]='COMPANY_ID invalid; kosongkan jika hotel independen.';
    $v['company_name']=tc_value($v,'company_name'); if(strlen($v['company_name'])>120)$errors[]='COMPANY_NAME maksimal 120 karakter.';
    $v['currency']=strtoupper(tc_value($v,'currency','IDR')); if(!preg_match('/^[A-Z]{3}$/',$v['currency']))$errors[]='Currency wajib kode 3 huruf.';
    $v['country']=strtoupper(tc_value($v,'country','ID')); if(!preg_match('/^[A-Z]{2}$/',$v['country']))$errors[]='Country wajib kode 2 huruf.';
    $v['locale']=tc_value($v,'locale','id-ID'); if(!preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8}){0,2}$/',$v['locale']))$errors[]='Locale tidak valid.';
    $slug=tc_slug($v['property_id']?:'hotel');
    $v['cluster_id']=tc_value($v,'cluster_id',$slug.'-cluster'); if($v['cluster_id']==='')$v['cluster_id']=$slug.'-cluster';
    $v['local_node_id']=tc_value($v,'local_node_id',$slug.'-local-01'); if($v['local_node_id']==='')$v['local_node_id']=$slug.'-local-01';
    $v['online_node_id']=tc_value($v,'online_node_id',$slug.'-hosting-01'); if($v['online_node_id']==='')$v['online_node_id']=$slug.'-hosting-01';
    try{$v['cluster_id']=tc_validate_node_id($v['cluster_id'],'CLUSTER_ID');}catch(Throwable $e){$errors[]=$e->getMessage();}
    try{$v['local_node_id']=tc_validate_node_id($v['local_node_id'],'LOCAL NODE_ID');}catch(Throwable $e){$errors[]=$e->getMessage();}
    try{$v['online_node_id']=tc_validate_node_id($v['online_node_id'],'ONLINE NODE_ID');}catch(Throwable $e){$errors[]=$e->getMessage();}
    if($v['local_node_id']===$v['online_node_id'])$errors[]='LOCAL NODE_ID dan ONLINE NODE_ID harus berbeda.';
    $v['initial_primary']=tc_value($v,'initial_primary','local'); if(!in_array($v['initial_primary'],['local','online'],true))$errors[]='Initial Primary harus local atau online.';
    $v['fresh_install']=tc_checkbox($v,'fresh_install',false);
    $adminGuess=tc_value($v,'admin_root',__DIR__);$privateBase=tc_path_absolute($adminGuess)?tc_suggest_private_base($adminGuess,(string)$v['property_id']):'';
    foreach(['local','online'] as $node){if($privateBase!==''&&tc_value($v,$node.'_credential_path')==='')$v[$node.'_credential_path']=$privateBase.DIRECTORY_SEPARATOR.$node.DIRECTORY_SEPARATOR.'db-credentials.php';if($privateBase!==''&&tc_value($v,$node.'_backup_dir')==='')$v[$node.'_backup_dir']=$privateBase.DIRECTORY_SEPARATOR.$node.DIRECTORY_SEPARATOR.'backups';
        if(($mode==='single_online'&&$node==='local')||($mode==='single_local'&&$node==='online')) continue;
        $host=tc_value($v,$node.'_db_host'); if($host==='')$errors[]=strtoupper($node).' DB host wajib diisi.';elseif(preg_match('/[\x00-\x20;=]/',$host))$errors[]=strtoupper($node).' DB host mengandung karakter tidak valid.'; $v[$node.'_db_host']=$host;
        $port=(int)tc_value($v,$node.'_db_port','3306'); if($port<1||$port>65535)$errors[]=strtoupper($node).' DB port invalid.'; $v[$node.'_db_port']=$port;
        try{$v[$node.'_db_name']=tc_validate_db_name(tc_value($v,$node.'_db_name'),strtoupper($node).' DB name');}catch(Throwable $e){$errors[]=$e->getMessage();}
        $user=tc_value($v,$node.'_db_user'); if($user==='')$errors[]=strtoupper($node).' DB user wajib diisi.';elseif(strlen($user)>128||preg_match('/[\r\n\x00]/',$user))$errors[]=strtoupper($node).' DB user tidak valid.'; $v[$node.'_db_user']=$user;
        $v[$node.'_db_pass']=(string)tc_array_get($v,$node.'_db_pass',''); if($v[$node.'_db_pass']===''||preg_match('/[\r\n\x00]/',$v[$node.'_db_pass']))$errors[]=strtoupper($node).' DB password wajib diisi dan tidak boleh mengandung newline/NUL.';
        try{$v[$node.'_credential_path']=tc_validate_abs_path(tc_value($v,$node.'_credential_path'),strtoupper($node).' credential path');}catch(Throwable $e){$errors[]=$e->getMessage();}
        try{$v[$node.'_backup_dir']=tc_validate_abs_path(tc_value($v,$node.'_backup_dir'),strtoupper($node).' backup dir');}catch(Throwable $e){$errors[]=$e->getMessage();}
        try{$v[$node.'_trusted_proxies']=tc_validate_proxy_list(tc_value($v,$node.'_trusted_proxies'),strtoupper($node).' trusted proxies');}catch(Throwable $e){$errors[]=$e->getMessage();}
    }
    if($mode==='dual' && isset($v['local_db_name'],$v['online_db_name']) && $v['local_db_name']===$v['online_db_name']) $errors[]='Database Local dan Hosting wajib berbeda pada model dua-server.';
    if($mode==='dual' && !empty($v['local_credential_path']) && $v['local_credential_path']===$v['online_credential_path'])$errors[]='Credential path Local dan Hosting wajib berbeda.';
    $enc=tc_value($v,'existing_encryption_key'); if($enc!==''&&!tc_valid_encryption_key($enc))$errors[]='Existing APP_ENCRYPTION_KEY invalid; minimal 32 byte/karakter.';
    $sync=tc_value($v,'existing_sync_secret'); if($sync!==''&&!tc_secret_min($sync))$errors[]='Existing NODE_SYNC_SHARED_SECRET minimal 32 karakter.';
    $v['public_site_root']=tc_value($v,'public_site_root'); if($v['public_site_root']!==''&&!tc_path_absolute($v['public_site_root']))$errors[]='Public-site root harus path absolut atau kosong.';
    $v['admin_root']=tc_value($v,'admin_root',__DIR__); if($v['admin_root']!==''&&!tc_path_absolute($v['admin_root']))$errors[]='Admin root harus path absolut.';
    $v['apply_target']=tc_value($v,'apply_target','none'); if(!in_array($v['apply_target'],['none','local','online'],true))$errors[]='Apply target invalid.';
    $v['preserve_existing_env_secrets']=tc_checkbox($v,'preserve_existing_env_secrets',true);$v['include_secret_inventory']=tc_checkbox($v,'include_secret_inventory',true);
    $v['apply_public']=tc_checkbox($v,'apply_public',false); $v['lock_after_apply']=tc_checkbox($v,'lock_after_apply',true);
    if(($mode==='single_online'&&$v['apply_target']==='local')||($mode==='single_local'&&$v['apply_target']==='online'))$errors[]='Apply target tidak tersedia untuk mode deployment yang dipilih.';
    foreach(['local','online'] as $node){if(($mode==='single_online'&&$node==='local')||($mode==='single_local'&&$node==='online'))continue;$cred=(string)($v[$node.'_credential_path']??'');$bak=(string)($v[$node.'_backup_dir']??'');
        $e=tc_private_path_error($cred,strtoupper($node).' credential path',(string)$v['admin_root'],(string)$v['public_site_root']);if($e!==null)$errors[]=$e;
        $e=tc_private_path_error($bak,strtoupper($node).' backup dir',(string)$v['admin_root'],(string)$v['public_site_root']);if($e!==null)$errors[]=$e;
    }
    return ['ok'=>!$errors,'errors'=>$errors,'values'=>$v];
}

function tc_generate_node_env(string $kind,array $v,array $secrets): string {
    $isLocal=$kind==='local'; $mode=$v['mode'];
    $env=tc_template($kind);
    $selfOrigin=$isLocal?($v['local_origin']??''):$v['online_origin'];
    $peerOrigin=$isLocal?$v['online_origin']:($v['local_origin']??'');
    $selfNode=$isLocal?$v['local_node_id']:$v['online_node_id'];
    $peerNode=$isLocal?$v['online_node_id']:$v['local_node_id'];
    $isCluster=$mode==='dual';
    $primaryNode=$v['initial_primary'];
    $role=($isLocal&&$primaryNode==='local')||(!$isLocal&&$primaryNode==='online')?'primary':'standby';
    if(!$isCluster)$role='primary';
    $origins=array_values(array_unique(array_filter([$v['online_origin']??'', $v['public_origin']??'', $v['local_origin']??''])));
    $hosts=[]; foreach($origins as $o){$p=parse_url($o);if(is_array($p)&&!empty($p['host']))$hosts[]=(string)$p['host'];}
    $hosts=array_values(array_unique($hosts));
    $map=[
        'APP_TIMEZONE'=>$v['timezone'],'APP_ENV'=>'production','APP_DEBUG'=>'0','APP_URL'=>$selfOrigin,
        'APP_ALLOWED_ORIGINS'=>implode(',',$origins),'APP_ALLOWED_HOSTS'=>implode(',',$hosts),'APP_ENFORCE_ALLOWED_HOSTS'=>'1','APP_TRUSTED_PROXIES'=>$v[$kind.'_trusted_proxies']??'',
        'APP_REQUIRE_JSON_CONTENT_TYPE'=>'1','APP_MAX_REQUEST_BYTES'=>'12582912','APP_RUNTIME_TRACE'=>'errors',
        'SECURITY_EVENT_HASH_KEY'=>$secrets[$kind.'_security_hash'],'APP_CREDENTIALS_FILE'=>$v[$kind.'_credential_path'],'APP_EXPECTED_DB_NAME'=>$v[$kind.'_db_name'],
        'APP_REQUIRE_EXPECTED_DB_NAME'=>'1','APP_ALLOW_DB_ENV_OVERRIDE'=>'0','ALLOW_PUBLIC_CREDENTIAL_FILE'=>'0',
        'APP_BOOTSTRAP_ADMIN_PASSWORD'=>(($v['fresh_install']&&$role==='primary')?$secrets['bootstrap_password']:''),'APP_BOOTSTRAP_ADMIN_USERNAME'=>'admin','APP_BOOTSTRAP_ADMIN_NAME'=>'Administrator Utama',
        'APP_ENCRYPTION_KEY'=>$secrets['encryption_key'],'CRON_SECRET'=>$secrets[$kind.'_cron'],'CRON_ALLOW_HTTP'=>'0',
        'ADMIN_WEB_TOOLS_ENABLED'=>'0','ADMIN_WEB_TOOLS_SECRET'=>$secrets[$kind.'_admin_web'],'ADMIN_WEB_TOOLS_ALLOW_HTTP'=>'0','ADMIN_WEB_TOOL_TIMEOUT_SECONDS'=>'120',
        'TAMASYA_PUBLIC_SITE_ROOT'=>($kind==='online'?$v['public_site_root']:''),'BACKUP_DIR'=>$v[$kind.'_backup_dir'],'ALLOW_PUBLIC_BACKUP_DIR'=>'0',
        'TAMASYA_NODE_MODE'=>$isCluster?'flexible':'single','NODE_CLUSTER_ENABLED'=>$isCluster?'1':'0','NODE_SYNC_ENABLED'=>$isCluster?'1':'0',
        'TAMASYA_CLUSTER_ID'=>$isCluster?$v['cluster_id']:'','TAMASYA_NODE_ID'=>$selfNode,'TAMASYA_NODE_KIND'=>$isLocal?'local':'hosting','TAMASYA_NODE_INITIAL_ROLE'=>$role,
        'NODE_CLUSTER_PUBLIC_URL'=>$selfOrigin,'NODE_CLUSTER_PEER_ID'=>$isCluster?$peerNode:'','NODE_CLUSTER_PEER_URL'=>$isCluster?$peerOrigin:'',
        'TAMASYA_ALLOWED_NODE_IDS'=>$isCluster?$peerNode:'','NODE_SYNC_SHARED_SECRET'=>$isCluster?$secrets['sync_shared']:'','NODE_SYNC_PRIMARY_URL'=>$isCluster?$peerOrigin:'','NODE_SYNC_SELF_URL'=>$selfOrigin,
        'NODE_SYNC_FORWARD_WHEN_ONLINE'=>'1','NODE_SYNC_INTERVAL_SECONDS'=>'10','NODE_CLUSTER_CONNECT_TIMEOUT_SECONDS'=>'3','NODE_SYNC_MAX_SNAPSHOT_ROWS'=>'100000','NODE_SYNC_ALLOW_HTTP_LOCAL'=>(!empty($v['local_http']))?'1':'0',
        'NODE_CLUSTER_LEASE_TTL_SECONDS'=>'300','NODE_CLUSTER_LEASE_RENEW_WINDOW_SECONDS'=>'120','NODE_CLUSTER_STALE_AFTER_SECONDS'=>'900','NODE_CLUSTER_PROBE_BEFORE_WRITE'=>'1',
        'VITE_TAMASYA_LOCAL_API_URL'=>rtrim((string)($v['local_origin']??''),'/').'/api.php','VITE_TAMASYA_ONLINE_API_URL'=>rtrim($v['online_origin'],'/').'/api.php',
        'VITE_PUBLIC_API_URL'=>rtrim($v['online_origin'],'/').'/api.php','PUBLIC_SITE_URL'=>$v['public_origin'],'PUBLIC_SITE_RATE_LIMIT_SALT'=>$secrets[$kind.'_rate_salt'],
        'PUBLIC_HELP_CHAT_AI_ENABLED'=>'0','INTERNAL_STAFF_HELP_CHAT_ENABLED'=>'0','TELEGRAM_SIMULATION_ENABLED'=>'0','CRON_ALLOW_HTTP'=>'0',
        'TAMASYA_COMPANY_ID'=>$v['company_id'],'TAMASYA_COMPANY_NAME'=>$v['company_name'],'TAMASYA_PROPERTY_ID'=>$v['property_id'],'TAMASYA_PROPERTY_CODE'=>$v['property_code'],'TAMASYA_PROPERTY_NAME'=>$v['property_name'],
        'TAMASYA_PROPERTY_CURRENCY'=>$v['currency'],'TAMASYA_PROPERTY_COUNTRY'=>$v['country'],'TAMASYA_PROPERTY_LOCALE'=>$v['locale'],'TAMASYA_INVOICE_PREFIX'=>$v['invoice_prefix'],
        'TAMASYA_GROWTH_SUITE_ENABLED'=>'0','TAMASYA_GROWTH_KPI_ENABLED'=>'0','TAMASYA_GROWTH_RATE_MANAGER_ENABLED'=>'0','TAMASYA_GROWTH_GROUP_CORPORATE_ENABLED'=>'0','TAMASYA_GROWTH_ADVANCED_FOLIO_ENABLED'=>'0','TAMASYA_GROWTH_PROCUREMENT_ENABLED'=>'0','TAMASYA_GROWTH_CHANNEL_FOUNDATION_ENABLED'=>'0','TAMASYA_GROWTH_PAYMENT_FOUNDATION_ENABLED'=>'0',
        'TAMASYA_ENTERPRISE_COMPLETION_ENABLED'=>'0','TAMASYA_ENTERPRISE_PROVIDER_ADAPTERS_ENABLED'=>'0','TAMASYA_ENTERPRISE_CRM_CAMPAIGN_SEND_ENABLED'=>'0','TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED'=>'0','TAMASYA_HQ_BRIDGE_ENABLED'=>'0','TAMASYA_HQ_ALLOW_WRITEBACK'=>'0','TAMASYA_CROSS_PROPERTY_RESERVATION_ENABLED'=>'0'
    ];
    $unquoted=['APP_DEBUG','APP_ENFORCE_ALLOWED_HOSTS','APP_REQUIRE_JSON_CONTENT_TYPE','APP_MAX_REQUEST_BYTES','APP_REQUIRE_EXPECTED_DB_NAME','APP_ALLOW_DB_ENV_OVERRIDE','ALLOW_PUBLIC_CREDENTIAL_FILE','CRON_ALLOW_HTTP','ADMIN_WEB_TOOLS_ENABLED','ADMIN_WEB_TOOLS_ALLOW_HTTP','ADMIN_WEB_TOOL_TIMEOUT_SECONDS','ALLOW_PUBLIC_BACKUP_DIR','NODE_CLUSTER_ENABLED','NODE_SYNC_ENABLED','NODE_SYNC_FORWARD_WHEN_ONLINE','NODE_SYNC_INTERVAL_SECONDS','NODE_CLUSTER_CONNECT_TIMEOUT_SECONDS','NODE_SYNC_MAX_SNAPSHOT_ROWS','NODE_SYNC_ALLOW_HTTP_LOCAL','NODE_CLUSTER_LEASE_TTL_SECONDS','NODE_CLUSTER_LEASE_RENEW_WINDOW_SECONDS','NODE_CLUSTER_STALE_AFTER_SECONDS','NODE_CLUSTER_PROBE_BEFORE_WRITE','PUBLIC_HELP_CHAT_AI_ENABLED','INTERNAL_STAFF_HELP_CHAT_ENABLED','TELEGRAM_SIMULATION_ENABLED','TAMASYA_GROWTH_SUITE_ENABLED','TAMASYA_GROWTH_KPI_ENABLED','TAMASYA_GROWTH_RATE_MANAGER_ENABLED','TAMASYA_GROWTH_GROUP_CORPORATE_ENABLED','TAMASYA_GROWTH_ADVANCED_FOLIO_ENABLED','TAMASYA_GROWTH_PROCUREMENT_ENABLED','TAMASYA_GROWTH_CHANNEL_FOUNDATION_ENABLED','TAMASYA_GROWTH_PAYMENT_FOUNDATION_ENABLED','TAMASYA_ENTERPRISE_COMPLETION_ENABLED','TAMASYA_ENTERPRISE_PROVIDER_ADAPTERS_ENABLED','TAMASYA_ENTERPRISE_CRM_CAMPAIGN_SEND_ENABLED','TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED','TAMASYA_HQ_BRIDGE_ENABLED','TAMASYA_HQ_ALLOW_WRITEBACK','TAMASYA_CROSS_PROPERTY_RESERVATION_ENABLED'];
    foreach($map as $k=>$value)$env=tc_set_env($env,$k,(string)$value,!in_array($k,$unquoted,true));
    $bad=tc_active_placeholders($env); if($bad) throw new RuntimeException(strtoupper($kind).' ENV masih memiliki placeholder aktif: '.implode(', ',$bad));
    return rtrim($env)."\n";
}

function tc_public_files(array $v,string $baseHtaccess=''): array {
    if($baseHtaccess==='')$baseHtaccess=TAMASYA_PUBLIC_HTACCESS_FALLBACK;
    $api=rtrim($v['online_origin'],'/').'/api.php'; $site=rtrim($v['public_origin'],'/'); $app=rtrim($v['online_origin'],'/');
    $runtime="(function () {\n  \"use strict\";\n\n  // Generated by TAMASYA unified configurator. URLs only; never store secrets here.\n  const runtime = Object.freeze({\n    API_URL: ".json_encode($api,JSON_UNESCAPED_SLASHES).",\n    SITE_URL: ".json_encode($site,JSON_UNESCAPED_SLASHES)."\n  });\n\n  window.TAMASYA_PUBLIC_RUNTIME = runtime;\n  window.TAMASYA_PUBLIC_API_URL = runtime.API_URL;\n  window.TAMASYA_PUBLIC_SITE_URL = runtime.SITE_URL;\n})();\n";
    $robots="User-agent: *\nAllow: /\nSitemap: ".$site."/sitemap.xml\n";
    $sitemap="<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n  <url>\n    <loc>".htmlspecialchars($site.'/',ENT_XML1|ENT_QUOTES,'UTF-8')."</loc>\n    <changefreq>weekly</changefreq>\n    <priority>1.0</priority>\n  </url>\n</urlset>\n";
    $files=['runtime-config.js'=>$runtime,'robots.txt'=>$robots,'sitemap.xml'=>$sitemap];
    if($baseHtaccess!==''){
        $ht=preg_replace('/^# TAMASYA_API_ORIGIN:.*$/m','# TAMASYA_API_ORIGIN: '.$app.' — generated by tamasya_configurator.php',$baseHtaccess,1,$mc);
        if(is_string($ht)&&$mc===1){$ht=preg_replace("/connect-src 'self' [^;]+;/","connect-src 'self' ".$app.";",$ht,1,$cc);if(is_string($ht)&&$cc===1)$files['.htaccess']=$ht;}
    }
    if(!isset($files['.htaccess']))$files['CSP_CONNECT_SRC.txt']="Set/patch public_site .htaccess CSP to:\nconnect-src 'self' ".$app.";\n";
    return $files;
}
function tc_detect_public_root(array $v): string {
    $candidates=[]; if(!empty($v['public_site_root']))$candidates[]=$v['public_site_root']; $candidates[]=dirname(__DIR__).DIRECTORY_SEPARATOR.'public_site';
    foreach($candidates as $c){$r=realpath($c);if($r!==false&&is_dir($r)&&is_file($r.DIRECTORY_SEPARATOR.'runtime-config.js'))return $r;}
    return '';
}
function tc_parse_dotenv_text(string $text): array {
    $out=[];foreach(preg_split('/\R/',$text)?:[] as $line){$line=trim((string)$line);if($line===''||$line[0]==='#'||$line[0]===';')continue;$p=strpos($line,'=');if($p===false)continue;$k=trim(substr($line,0,$p));if(!preg_match('/^[A-Z0-9_]+$/',$k))continue;$val=trim(substr($line,$p+1));if($val!==''&&(($val[0]==='"'&&str_ends_with($val,'"'))||($val[0]==="'"&&str_ends_with($val,"'")))){$q=$val[0];$val=substr($val,1,-1);if($q==='"')$val=str_replace(['\\n','\\r','\\"','\\\\'],["\n","\r",'"','\\'],$val);} $out[$k]=$val;}return $out;
}
function tc_existing_env(array $v): array {
    if(empty($v['preserve_existing_env_secrets']))return ['values'=>[],'node'=>null,'path'=>null,'reason'=>'disabled'];$root=(string)($v['admin_root']??__DIR__);$path=rtrim($root,'/\\').DIRECTORY_SEPARATOR.'.env';if(!is_file($path)||!is_readable($path))return ['values'=>[],'node'=>null,'path'=>$path,'reason'=>'not_found'];
    $env=tc_parse_dotenv_text((string)file_get_contents($path));$property=(string)($env['TAMASYA_PROPERTY_ID']??'');if($property!==''&&!hash_equals($property,(string)$v['property_id']))return ['values'=>[],'node'=>null,'path'=>$path,'reason'=>'property_mismatch'];$node=null;$kind=strtolower((string)($env['TAMASYA_NODE_KIND']??''));if($kind==='local')$node='local';elseif(in_array($kind,['hosting','online'],true))$node='online';
    if($node===null){$url=(string)($env['APP_URL']??'');if($url!==''&&isset($v['local_origin'])&&rtrim($url,'/')===rtrim((string)$v['local_origin'],'/'))$node='local';elseif($url!==''&&rtrim($url,'/')===rtrim((string)$v['online_origin'],'/'))$node='online';elseif(in_array((string)($v['apply_target']??'none'),['local','online'],true))$node=(string)$v['apply_target'];}return ['values'=>$env,'node'=>$node,'path'=>$path,'reason'=>'ok'];
}
function tc_pick_secret(string $explicit,array $existing,string $key,callable $valid,callable $generate): array {
    if($explicit!==''&&$valid($explicit))return ['value'=>$explicit,'source'=>'explicit'];$old=(string)($existing[$key]??'');if($old!==''&&$valid($old))return ['value'=>$old,'source'=>'preserved'];return ['value'=>$generate(),'source'=>'generated'];
}
function tc_validate_env_text(string $text): array {
    $env=tc_parse_dotenv_text($text);$errors=[];$warnings=[];$required=['APP_ENV','APP_URL','APP_TIMEZONE','APP_ENCRYPTION_KEY','APP_CREDENTIALS_FILE','APP_EXPECTED_DB_NAME','APP_REQUIRE_EXPECTED_DB_NAME','SECURITY_EVENT_HASH_KEY','CRON_SECRET','ADMIN_WEB_TOOLS_SECRET','BACKUP_DIR','TAMASYA_PROPERTY_ID','TAMASYA_PROPERTY_CODE','TAMASYA_PROPERTY_NAME','TAMASYA_NODE_ID','TAMASYA_NODE_KIND','TAMASYA_NODE_INITIAL_ROLE'];
    foreach($required as $k)if(!isset($env[$k])||trim((string)$env[$k])==='')$errors[]='Missing/empty '.$k;$bad=tc_active_placeholders($text);if($bad)$errors[]='Active placeholder: '.implode(', ',$bad);if(isset($env['APP_ENCRYPTION_KEY'])&&!tc_valid_encryption_key((string)$env['APP_ENCRYPTION_KEY']))$errors[]='APP_ENCRYPTION_KEY invalid.';
    foreach(['SECURITY_EVENT_HASH_KEY','CRON_SECRET','ADMIN_WEB_TOOLS_SECRET'] as $k)if(isset($env[$k])&&!tc_secret_min((string)$env[$k]))$errors[]=$k.' minimal 32 karakter.';if(isset($env['NODE_CLUSTER_ENABLED'])&&$env['NODE_CLUSTER_ENABLED']==='1'&&(!isset($env['NODE_SYNC_SHARED_SECRET'])||!tc_secret_min((string)$env['NODE_SYNC_SHARED_SECRET'])))$errors[]='NODE_SYNC_SHARED_SECRET wajib >=32 karakter saat cluster aktif.';
    if(isset($env['APP_CREDENTIALS_FILE'])){$e=tc_private_path_error((string)$env['APP_CREDENTIALS_FILE'],'APP_CREDENTIALS_FILE');if($e!==null)$errors[]=$e;}if(isset($env['BACKUP_DIR'])){$e=tc_private_path_error((string)$env['BACKUP_DIR'],'BACKUP_DIR');if($e!==null)$errors[]=$e;}if(isset($env['TAMASYA_NODE_INITIAL_ROLE'])&&!in_array(strtolower((string)$env['TAMASYA_NODE_INITIAL_ROLE']),['primary','standby'],true))$errors[]='TAMASYA_NODE_INITIAL_ROLE invalid.';
    if(isset($env['APP_ENV'])&&strtolower((string)$env['APP_ENV'])!=='production')$warnings[]='APP_ENV bukan production.';if(isset($env['APP_DEBUG'])&&(string)$env['APP_DEBUG']!=='0')$warnings[]='APP_DEBUG sebaiknya 0 untuk production.';if(isset($env['ADMIN_WEB_TOOLS_ENABLED'])&&(string)$env['ADMIN_WEB_TOOLS_ENABLED']!=='0')$warnings[]='ADMIN_WEB_TOOLS_ENABLED aktif.';if(isset($env['APP_URL'])){try{tc_origin((string)$env['APP_URL'],'APP_URL',false,true);}catch(Throwable $e){$errors[]=$e->getMessage();}}
    return ['ok'=>!$errors,'errors'=>$errors,'warnings'=>$warnings,'keys'=>count($env),'fingerprints'=>['APP_ENCRYPTION_KEY'=>isset($env['APP_ENCRYPTION_KEY'])?tc_secret_fingerprint((string)$env['APP_ENCRYPTION_KEY']):null,'NODE_SYNC_SHARED_SECRET'=>isset($env['NODE_SYNC_SHARED_SECRET'])&&$env['NODE_SYNC_SHARED_SECRET']!==''?tc_secret_fingerprint((string)$env['NODE_SYNC_SHARED_SECRET']):null,'ADMIN_WEB_TOOLS_SECRET'=>isset($env['ADMIN_WEB_TOOLS_SECRET'])?tc_secret_fingerprint((string)$env['ADMIN_WEB_TOOLS_SECRET']):null]];
}
function tc_validate_env_file(string $file): array {
    if(!is_file($file)||!is_readable($file))return ['ok'=>false,'errors'=>['ENV file tidak ditemukan/readable: '.$file],'warnings'=>[],'keys'=>0,'fingerprints'=>[]];$text=(string)file_get_contents($file);$r=tc_validate_env_text($text);$env=tc_parse_dotenv_text($text);$r['file']=$file;$mode=@fileperms($file);if(is_int($mode)&&(($mode&0777)&0077)!==0)$r['warnings'][]='Permission ENV lebih longgar dari 0600: '.decoct($mode&0777);
    $cred=(string)($env['APP_CREDENTIALS_FILE']??'');if($cred!==''&&tc_path_absolute($cred)){if(!is_file($cred))$r['warnings'][]='Credential file belum ada: '.$cred;else{$cm=@fileperms($cred);if(is_int($cm)&&(($cm&0777)&0077)!==0)$r['warnings'][]='Permission credential lebih longgar dari 0600: '.decoct($cm&0777);}}
    $backup=(string)($env['BACKUP_DIR']??'');if($backup!==''&&tc_path_absolute($backup)){if(!is_dir($backup))$r['warnings'][]='BACKUP_DIR belum ada: '.$backup;elseif(!is_writable($backup))$r['warnings'][]='BACKUP_DIR tidak writable: '.$backup;}$r['warnings']=array_values(array_unique($r['warnings']));return $r;
}

function tc_generate(array $v): array {
    $existingInfo=tc_existing_env($v);$existing=(array)$existingInfo['values'];$existingNode=$existingInfo['node'];$sources=[];
    $pick=tc_pick_secret(tc_value($v,'existing_encryption_key'),$existing,'APP_ENCRYPTION_KEY','tc_valid_encryption_key','tc_random_b64_key');$encryption=$pick['value'];$sources['APP_ENCRYPTION_KEY']=$pick['source'];
    $pick=tc_pick_secret(tc_value($v,'existing_sync_secret'),$existing,'NODE_SYNC_SHARED_SECRET','tc_secret_min',fn()=>tc_random_hex(32));$sync=$pick['value'];$sources['NODE_SYNC_SHARED_SECRET']=$pick['source'];
    $bootstrap='';
    if(!empty($v['fresh_install'])){
        $oldBootstrap=trim((string)($existing['APP_BOOTSTRAP_ADMIN_PASSWORD']??''));
        if(strlen($oldBootstrap)>=12){$bootstrap=$oldBootstrap;$sources['APP_BOOTSTRAP_ADMIN_PASSWORD']='preserved_fresh_install';}
        else{$bootstrap=tc_random_password(28);$sources['APP_BOOTSTRAP_ADMIN_PASSWORD']='generated_fresh_install';}
    }else $sources['APP_BOOTSTRAP_ADMIN_PASSWORD']='disabled_non_fresh';
    $secrets=['encryption_key'=>$encryption,'sync_shared'=>$sync,'bootstrap_password'=>$bootstrap];
    foreach(['local','online'] as $node){$nodeExisting=$existingNode===$node?$existing:[];foreach(['security_hash'=>['SECURITY_EVENT_HASH_KEY',fn()=>tc_random_hex(32)],'cron'=>['CRON_SECRET',fn()=>tc_random_hex(32)],'admin_web'=>['ADMIN_WEB_TOOLS_SECRET',fn()=>tc_random_hex(32)],'rate_salt'=>['PUBLIC_SITE_RATE_LIMIT_SALT',fn()=>tc_random_hex(32)]] as $suffix=>$spec){[$envKey,$generator]=$spec;$pick=tc_pick_secret('',$nodeExisting,$envKey,'tc_secret_min',$generator);$secrets[$node.'_'.$suffix]=$pick['value'];$sources[strtoupper($node).'_'.$envKey]=$pick['source'];}}
    $outputs=[]; $envs=[];
    if($v['mode']!=='single_online'){
        $envs['local']=tc_generate_node_env('local',$v,$secrets); $outputs['server-local/.env']=$envs['local'];
        $outputs['server-local/'.basename($v['local_credential_path'])]=tc_credential_php(['host'=>$v['local_db_host'],'port'=>$v['local_db_port'],'name'=>$v['local_db_name'],'user'=>$v['local_db_user'],'pass'=>$v['local_db_pass']]);
    }
    if($v['mode']!=='single_local'){
        $envs['online']=tc_generate_node_env('online',$v,$secrets); $outputs['server-online/.env']=$envs['online'];
        $outputs['server-online/'.basename($v['online_credential_path'])]=tc_credential_php(['host'=>$v['online_db_host'],'port'=>$v['online_db_port'],'name'=>$v['online_db_name'],'user'=>$v['online_db_user'],'pass'=>$v['online_db_pass']]);
    }
    foreach($envs as $node=>$envText){$vr=tc_validate_env_text($envText);if(!$vr['ok'])throw new RuntimeException('Generated '.strtoupper($node).' ENV gagal self-validation: '.implode('; ',$vr['errors']));}
    $publicRoot=tc_detect_public_root($v); $ht=''; if($publicRoot!==''&&is_file($publicRoot.DIRECTORY_SEPARATOR.'.htaccess'))$ht=(string)file_get_contents($publicRoot.DIRECTORY_SEPARATOR.'.htaccess');foreach(tc_public_files($v,$ht) as $name=>$content)$outputs['public_site/'.$name]=$content;
    $report=['configuratorVersion'=>TAMASYA_CONFIGURATOR_VERSION,'generatedAt'=>date(DATE_ATOM),'mode'=>$v['mode'],'propertyId'=>$v['property_id'],'propertyCode'=>$v['property_code'],'onlineOrigin'=>$v['online_origin'],'publicOrigin'=>$v['public_origin'],'existingEnv'=>['path'=>$existingInfo['path'],'node'=>$existingNode,'reason'=>$existingInfo['reason']],'secretSources'=>$sources,'fingerprints'=>['APP_ENCRYPTION_KEY'=>tc_secret_fingerprint($secrets['encryption_key']),'NODE_SYNC_SHARED_SECRET'=>tc_secret_fingerprint($secrets['sync_shared'])]];$outputs['CONFIG_REPORT.json']=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    if(!empty($v['include_secret_inventory'])){$notes="TAMASYA ".TAMASYA_CONFIGURATOR_VERSION." SECRET RECOVERY SHEET — DO NOT UPLOAD\nGenerated: ".date(DATE_ATOM)."\nMode: {$v['mode']}\nProperty: {$v['property_id']} / {$v['property_name']}\nOnline: {$v['online_origin']}\nPublic: {$v['public_origin']}\n";if($v['mode']!=='single_online')$notes.="Local: {$v['local_origin']}\n";$notes.="\nCORE SHARED KEYS:\nAPP_ENCRYPTION_KEY={$secrets['encryption_key']}\nAPP_ENCRYPTION_KEY_FINGERPRINT=".tc_secret_fingerprint($secrets['encryption_key'])."\nNODE_SYNC_SHARED_SECRET={$secrets['sync_shared']}\nNODE_SYNC_SHARED_SECRET_FINGERPRINT=".tc_secret_fingerprint($secrets['sync_shared'])."\n";foreach(['local'=>'LOCAL','online'=>'ONLINE'] as $node=>$label){if(!isset($envs[$node]))continue;$notes.="\n{$label} NODE KEYS:\nSECURITY_EVENT_HASH_KEY={$secrets[$node.'_security_hash']}\nCRON_SECRET={$secrets[$node.'_cron']}\nADMIN_WEB_TOOLS_SECRET={$secrets[$node.'_admin_web']}\nPUBLIC_SITE_RATE_LIMIT_SALT={$secrets[$node.'_rate_salt']}\n";}$notes.="\nBOOTSTRAP ADMIN PASSWORD (only active on initial Primary when fresh_install=1): {$secrets['bootstrap_password']}\n\nUPLOAD RULES:\n- NEVER upload this file/folder to document root.\n- Store it in a password manager or encrypted vault.\n- Preserve APP_ENCRYPTION_KEY for any existing encrypted database.\n- Upload each .env to corresponding admin_app/.env.\n- Upload DB credential files to the EXACT APP_CREDENTIALS_FILE path.\n- Keep DB credential files and BACKUP_DIR outside public/document root.\n- Keep ADMIN_WEB_TOOLS_ENABLED=0 after commissioning.\n- Remove APP_BOOTSTRAP_ADMIN_PASSWORD from active ENV after bootstrap.\n- Never run first_install.php on a restored standby database.\n";$outputs['DO_NOT_UPLOAD/SECRETS_SAVE_ONCE.txt']=$notes;}
    return ['outputs'=>$outputs,'envs'=>$envs,'secrets'=>$secrets,'secretSources'=>$sources,'publicRoot'=>$publicRoot,'existingEnv'=>$existingInfo];
}

function tc_zip_store(array $files): string {
    $data='';$central='';$offset=0;$count=0;$time=time();$d=getdate($time);$dosTime=(($d['hours']&0x1f)<<11)|(($d['minutes']&0x3f)<<5)|((int)($d['seconds']/2)&0x1f);$dosDate=((max(1980,$d['year'])-1980)<<9)|(($d['mon']&0xf)<<5)|($d['mday']&0x1f);
    foreach($files as $name=>$content){$name=str_replace('\\','/',ltrim((string)$name,'/'));$content=(string)$content;$crc=crc32($content);$size=strlen($content);$nameLen=strlen($name);
        $local=pack('VvvvvvVVVvv',0x04034b50,20,0,0,$dosTime,$dosDate,$crc,$size,$size,$nameLen,0).$name.$content;
        $data.=$local;
        $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,$dosTime,$dosDate,$crc,$size,$size,$nameLen,0,0,0,0,0,$offset).$name;
        $offset+=strlen($local);$count++;
    }
    $end=pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),strlen($data),0);
    return $data.$central.$end;
}
function tc_write_atomic(string $path,string $content,bool $backup=true,int $defaultMode=0600): array {
    $dir=dirname($path); if(!is_dir($dir)){if(!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Gagal membuat folder: '.$dir);}
    if(!is_writable($dir))throw new RuntimeException('Folder tidak writable: '.$dir);
    $backupPath=null; if(is_file($path)&&$backup){$backupPath=$path.'.bak.'.date('Ymd_His').'.'.substr(tc_random_hex(3),0,6);if(!@copy($path,$backupPath))throw new RuntimeException('Gagal membuat backup: '.$backupPath);@chmod($backupPath,$defaultMode);}
    $tmp=tempnam($dir,'.tamasya-'); if($tmp===false)throw new RuntimeException('Gagal membuat temp file.');
    try{if(file_put_contents($tmp,$content,LOCK_EX)===false)throw new RuntimeException('Gagal menulis '.basename($path));@chmod($tmp,$defaultMode);if(!@rename($tmp,$path))throw new RuntimeException('Gagal replace '.basename($path));}finally{if(is_file($tmp))@unlink($tmp);}
    return ['path'=>$path,'backup'=>$backupPath];
}
function tc_inside_docroot(string $path): bool {
    $doc=trim((string)($_SERVER['DOCUMENT_ROOT']??'')); if($doc==='')return false;return tc_path_within($path,$doc);
}
function tc_apply(array $v,array $generated): array {
    $target=$v['apply_target']; if($target==='none')return ['applied'=>false,'files'=>[]]; if(!isset($generated['envs'][$target]))throw new RuntimeException('ENV target tidak tersedia untuk mode ini.');
    $adminRoot=realpath($v['admin_root'])?:$v['admin_root']; if(!is_dir($adminRoot))throw new RuntimeException('Admin root tidak ditemukan: '.$adminRoot);
    $publicRoot=tc_detect_public_root($v);
    $credPath=(string)$v[$target.'_credential_path'];$backupDir=(string)$v[$target.'_backup_dir'];
    $e=tc_private_path_error($credPath,'Credential path target',$adminRoot,$publicRoot);if($e!==null)throw new RuntimeException($e);
    $e=tc_private_path_error($backupDir,'BACKUP_DIR target',$adminRoot,$publicRoot);if($e!==null)throw new RuntimeException($e);
    $credName='server-'.$target.'/'.basename($credPath); if(!isset($generated['outputs'][$credName]))throw new RuntimeException('Generated credential tidak ditemukan.');

    // Create and verify private storage BEFORE changing the active ENV.
    // This prevents .env from pointing at a non-existent backup directory.
    if(!is_dir($backupDir)&&!@mkdir($backupDir,0700,true)&&!is_dir($backupDir))throw new RuntimeException('Gagal membuat BACKUP_DIR private: '.$backupDir);
    @chmod($backupDir,0700);
    if(!is_writable($backupDir))throw new RuntimeException('BACKUP_DIR tidak writable: '.$backupDir);

    // Only after every private-path/storage safety gate passes may active files change.
    $result=[];$result[]=tc_write_atomic(rtrim($adminRoot,"/\\").DIRECTORY_SEPARATOR.'.env',$generated['envs'][$target],true);
    $result[]=tc_write_atomic($credPath,$generated['outputs'][$credName],true); @chmod($credPath,0600);
    if($v['apply_public']){
        $root=$publicRoot; if($root==='')throw new RuntimeException('Public-site root tidak ditemukan untuk apply.');
        foreach(['runtime-config.js','robots.txt','sitemap.xml','.htaccess'] as $name){$key='public_site/'.$name;if(!isset($generated['outputs'][$key])){if($name==='.htaccess')throw new RuntimeException('Base .htaccess tidak ditemukan/marker tidak valid.');continue;}$result[]=tc_write_atomic($root.DIRECTORY_SEPARATOR.$name,$generated['outputs'][$key],true,0644);}
    }
    return ['applied'=>true,'files'=>$result,'target'=>$target,'lockRecommended'=>(bool)($v['lock_after_apply']&&!tc_cli())];
}
function tc_capabilities(): array {
    return [
        'phpVersion'=>PHP_VERSION,'php82Plus'=>PHP_VERSION_ID>=80200,'pdoMysql'=>class_exists('PDO')&&in_array('mysql',PDO::getAvailableDrivers(),true),'curl'=>function_exists('curl_init'),'openssl'=>function_exists('openssl_encrypt'),
        'fileinfo'=>class_exists('finfo'),'mbstring'=>function_exists('mb_strlen'),'zipArchive'=>class_exists('ZipArchive'),'randomBytes'=>function_exists('random_bytes'),'sapi'=>PHP_SAPI
    ];
}
function tc_canonical_manifest(): array {
    static $manifest=null;
    if(is_array($manifest))return $manifest;
    $raw=base64_decode(TAMASYA_CANONICAL_MANIFEST_GZ_B64,true);
    if(!is_string($raw)||$raw==='')throw new RuntimeException('Embedded canonical DB manifest tidak valid.');
    $json=function_exists('gzdecode')?gzdecode($raw):false;
    if(!is_string($json)||$json==='')throw new RuntimeException('zlib/gzdecode diperlukan untuk full DB validation.');
    $decoded=json_decode($json,true);
    if(!is_array($decoded)||empty($decoded['tables'])||empty($decoded['primaryKeys'])||empty($decoded['triggers']))throw new RuntimeException('Embedded canonical DB manifest rusak/tidak lengkap.');
    $manifest=$decoded;return $manifest;
}
function tc_db_property_identity(PDO $pdo,array $v): array {
    try{
        $exists=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='property_settings'")->fetchColumn();
        if($exists!==1)return ['initialized'=>false,'ok'=>true,'message'=>'property_settings belum ada/DB belum diinisialisasi.','mismatches'=>[]];
        $row=$pdo->query("SELECT company_id,property_id,property_code,timezone,currency,country_code FROM property_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return ['initialized'=>false,'ok'=>true,'message'=>'Property identity belum diinisialisasi.','mismatches'=>[]];
        $expected=[
            'company_id'=>strtolower(trim((string)($v['company_id']??''))),
            'property_id'=>strtolower(trim((string)($v['property_id']??''))),
            'property_code'=>strtoupper(trim((string)($v['property_code']??''))),
            'timezone'=>trim((string)($v['timezone']??'')),
            'currency'=>strtoupper(trim((string)($v['currency']??''))),
            'country_code'=>strtoupper(trim((string)($v['country']??''))),
        ];
        $actual=[
            'company_id'=>strtolower(trim((string)($row['company_id']??''))),
            'property_id'=>strtolower(trim((string)($row['property_id']??''))),
            'property_code'=>strtoupper(trim((string)($row['property_code']??''))),
            'timezone'=>trim((string)($row['timezone']??'')),
            'currency'=>strtoupper(trim((string)($row['currency']??''))),
            'country_code'=>strtoupper(trim((string)($row['country_code']??''))),
        ];
        $m=[];foreach($actual as $field=>$dbVal){$cfg=(string)($expected[$field]??'');if($field==='company_id'&&$dbVal===''&&$cfg==='')continue;if($cfg===''||!hash_equals($cfg,$dbVal))$m[$field]=['database'=>$dbVal,'config'=>$cfg];}
        return ['initialized'=>true,'ok'=>!$m,'message'=>!$m?'Property identity PASS.':'Property identity berbeda: '.implode(', ',array_keys($m)).'.','mismatches'=>$m,'database'=>$actual,'config'=>$expected];
    }catch(Throwable $e){return ['initialized'=>false,'ok'=>false,'message'=>'Property identity check gagal: '.$e->getMessage(),'mismatches'=>[]];}
}
function tc_db_probe(array $v,string $kind): array {
    $base=['attempted'=>false,'ok'=>false,'connectionOk'=>false,'identityOk'=>false,'schemaReady'=>false,'releaseOk'=>false,'patchOk'=>false,'propertyOk'=>false,'kind'=>$kind];
    if(!class_exists('PDO')||!in_array('mysql',PDO::getAvailableDrivers(),true))return array_merge($base,['message'=>'pdo_mysql tidak tersedia pada runtime ini. Full DB validation tidak dapat dijalankan.']);
    try{
        $dsn='mysql:host='.$v[$kind.'_db_host'].';port='.$v[$kind.'_db_port'].';dbname='.$v[$kind.'_db_name'].';charset=utf8mb4';
        $pdo=new PDO($dsn,$v[$kind.'_db_user'],$v[$kind.'_db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $actual=trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
        $identityOk=$actual!==''&&strcasecmp((string)$v[$kind.'_db_name'],$actual)===0;
        $serverVersion=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $manifest=tc_canonical_manifest();$expectedTables=array_values((array)$manifest['tables']);
        $rows=$pdo->query("SELECT TABLE_NAME,ENGINE,TABLE_TYPE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")->fetchAll();
        $current=[];$engines=[];$collations=[];
        foreach($rows as $row){$name=(string)($row['TABLE_NAME']??'');if($name==='')continue;$current[$name]=true;if(strtoupper((string)($row['TABLE_TYPE']??''))==='BASE TABLE'){$engines[$name]=strtoupper((string)($row['ENGINE']??''));$collations[$name]=strtolower((string)($row['TABLE_COLLATION']??''));}}
        $missingTables=[];$nonInnoDb=[];$nonUtf8mb4=[];
        foreach($expectedTables as $table){if(empty($current[$table])){$missingTables[]=$table;continue;}if(($engines[$table]??'')!=='INNODB')$nonInnoDb[]=$table.'('.(($engines[$table]??'')?:'UNKNOWN').')';$coll=(string)($collations[$table]??'');if($coll!==''&&!str_starts_with($coll,'utf8mb4_'))$nonUtf8mb4[]=$table.'('.$coll.')';}
        $extraTables=[];foreach(array_keys($current) as $table)if(!in_array($table,$expectedTables,true))$extraTables[]=$table;sort($extraTables,SORT_STRING);
        $columnRows=$pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION")->fetchAll();$presentColumns=[];
        foreach($columnRows as $row){$t=(string)($row['TABLE_NAME']??'');$c=(string)($row['COLUMN_NAME']??'');if($t!==''&&$c!=='')$presentColumns[$t][$c]=true;}
        $missingColumns=[];foreach($expectedTables as $table){if(in_array($table,$missingTables,true))continue;$m=[];foreach((array)($manifest['columns'][$table]??[]) as $c)if(empty($presentColumns[$table][$c]))$m[]=$c;if($m)$missingColumns[$table]=$m;}
        $indexRows=$pdo->query("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX")->fetchAll();$indexes=[];
        foreach($indexRows as $row){$t=(string)($row['TABLE_NAME']??'');$n=(string)($row['INDEX_NAME']??'');$c=(string)($row['COLUMN_NAME']??'');if($t===''||$n==='')continue;if(!isset($indexes[$t][$n]))$indexes[$t][$n]=['nonUnique'=>(int)($row['NON_UNIQUE']??1),'columns'=>[]];if($c!=='')$indexes[$t][$n]['columns'][]=$c;}
        $missingPk=[];$pkMismatch=[];$missingUnique=[];$uniqueMismatch=[];
        foreach($expectedTables as $table){if(in_array($table,$missingTables,true))continue;$epk=array_values((array)($manifest['primaryKeys'][$table]??[]));$apk=$indexes[$table]['PRIMARY']??null;if($epk){if(!is_array($apk))$missingPk[]=$table;elseif((int)($apk['nonUnique']??1)!==0||array_values((array)($apk['columns']??[]))!==$epk)$pkMismatch[]=$table;}
            foreach((array)($manifest['uniqueIndexes'][$table]??[]) as $name=>$cols){$a=$indexes[$table][$name]??null;if(!is_array($a)){$missingUnique[]=$table.'.'.$name;continue;}if((int)($a['nonUnique']??1)!==0||array_values((array)($a['columns']??[]))!==array_values((array)$cols))$uniqueMismatch[]=$table.'.'.$name;}}
        $triggerRows=$pdo->query("SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME")->fetchAll();$triggers=[];
        foreach($triggerRows as $row){$name=(string)($row['TRIGGER_NAME']??'');if($name==='')continue;$triggers[$name]=['timing'=>strtoupper((string)($row['ACTION_TIMING']??'')),'event'=>strtoupper((string)($row['EVENT_MANIPULATION']??'')),'table'=>(string)($row['EVENT_OBJECT_TABLE']??'')];}
        $missingTriggers=[];$triggerMismatch=[];foreach((array)$manifest['triggers'] as $name){if(!isset($triggers[$name])){$missingTriggers[]=$name;continue;}if((array)($manifest['triggerSignatures'][$name]??[])!==$triggers[$name])$triggerMismatch[]=$name;}
        $markerPresent=false;if(isset($current['schema_migrations'])){$st=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');$st->execute([(string)$manifest['migrationMarker']]);$markerPresent=(int)$st->fetchColumn()===1;}
        $release=null;if(isset($current['schema_release_state'])){try{$release=$pdo->query("SELECT current_release,patch_level,maintenance_required,updated_at FROM schema_release_state WHERE id='system_default' LIMIT 1")->fetch()?:null;}catch(Throwable $ignored){$release=null;}}
        $releaseOk=is_array($release)&&(string)($release['current_release']??'')===TAMASYA_SCHEMA_RELEASE_EXPECTED&&(int)($release['maintenance_required']??1)===0;
        $patchOk=is_array($release)&&(string)($release['patch_level']??'')===TAMASYA_PATCH_LEVEL_EXPECTED;
        $prop=tc_db_property_identity($pdo,$v);$propertyOk=!$prop['initialized']||!empty($prop['ok']);
        $schemaReady=$identityOk&&!$missingTables&&!$nonInnoDb&&!$nonUtf8mb4&&!$missingColumns&&!$missingPk&&!$pkMismatch&&!$missingUnique&&!$uniqueMismatch&&!$missingTriggers&&!$triggerMismatch&&$markerPresent;
        $ok=$schemaReady&&$releaseOk&&$patchOk&&$propertyOk;
        $expectedUnique=0;foreach((array)$manifest['uniqueIndexes'] as $u)$expectedUnique+=count((array)$u);
        $summary=['expectedTables'=>count($expectedTables),'actualTables'=>count($current),'expectedPrimaryKeys'=>count((array)$manifest['primaryKeys']),'expectedUniqueIndexes'=>$expectedUnique,'expectedTriggers'=>count((array)$manifest['triggers']),'actualTriggers'=>count($triggers),'migrationMarker'=>(string)$manifest['migrationMarker'],'markerPresent'=>$markerPresent];
        $issues=[];
        if(!$identityOk)$issues[]='DB identity berbeda';if($missingTables)$issues[]='missing table='.count($missingTables);if($nonInnoDb)$issues[]='non-InnoDB='.count($nonInnoDb);if($nonUtf8mb4)$issues[]='non-utf8mb4='.count($nonUtf8mb4);if($missingColumns)$issues[]='missing columns';if($missingPk||$pkMismatch)$issues[]='PRIMARY KEY issue';if($missingUnique||$uniqueMismatch)$issues[]='UNIQUE issue';if($missingTriggers||$triggerMismatch)$issues[]='trigger issue';if(!$markerPresent)$issues[]='migration marker missing';if(!$releaseOk)$issues[]='release/maintenance mismatch';if(!$patchOk)$issues[]='patch mismatch';if(!$propertyOk)$issues[]='property identity mismatch';
        return array_merge($base,[
            'attempted'=>true,'ok'=>$ok,'connectionOk'=>true,'identityOk'=>$identityOk,'schemaReady'=>$schemaReady,'releaseOk'=>$releaseOk,'patchOk'=>$patchOk,'propertyOk'=>$propertyOk,'database'=>$actual,'serverVersion'=>$serverVersion,'summary'=>$summary,'releaseState'=>$release,'propertyIdentity'=>$prop,
            'details'=>['missingTables'=>$missingTables,'nonInnoDb'=>$nonInnoDb,'nonUtf8mb4'=>$nonUtf8mb4,'missingColumns'=>$missingColumns,'missingPrimaryKeys'=>$missingPk,'primaryKeyMismatches'=>$pkMismatch,'missingUniqueIndexes'=>$missingUnique,'uniqueIndexMismatches'=>$uniqueMismatch,'missingTriggers'=>$missingTriggers,'triggerSignatureMismatches'=>$triggerMismatch,'extraTablesAllowed'=>$extraTables],
            'message'=>$ok?'FULL DB VALIDATION PASS — canonical FINAL12 siap.':'FULL DB VALIDATION FAIL — '.implode('; ',$issues).'.'
        ]);
    }catch(Throwable $e){return array_merge($base,['attempted'=>true,'message'=>'DB validation gagal: '.$e->getMessage()]);}
}



/** Read and validate the private DB credential using one canonical parser. */
function tc_read_db_credential_file(string $credentialPath): array {
    $errors=[];$db=['host'=>'','port'=>3306,'name'=>'','user'=>'','pass'=>''];
    if($credentialPath===''||!tc_path_absolute($credentialPath))return ['ok'=>false,'db'=>$db,'errors'=>['credential path kosong/tidak absolut']];
    if(!is_file($credentialPath))return ['ok'=>false,'db'=>$db,'errors'=>['credential file tidak ditemukan: '.$credentialPath]];
    if(!is_readable($credentialPath))return ['ok'=>false,'db'=>$db,'errors'=>['credential file tidak readable: '.$credentialPath]];
    try{$credential=(static function(string $file): array {$x=include $file;return is_array($x)?$x:[];})($credentialPath);}catch(Throwable $e){return ['ok'=>false,'db'=>$db,'errors'=>['credential PHP gagal dibaca: '.$e->getMessage()]];}
    $db=[
        'host'=>trim((string)($credential['host']??'')),
        'port'=>(int)($credential['port']??3306),
        'name'=>trim((string)($credential['name']??'')),
        'user'=>trim((string)($credential['user']??'')),
        'pass'=>(string)($credential['pass']??''),
    ];
    if($db['host']==='')$errors[]='host kosong';
    if($db['name']==='')$errors[]='name/DB Name kosong';
    if($db['user']==='')$errors[]='user/DB User kosong';
    if($db['port']<1||$db['port']>65535)$errors[]='port invalid';
    return ['ok'=>!$errors,'db'=>$db,'errors'=>$errors];
}
function tc_commission_state_path(array $runtime): string {
    $cred=(string)($runtime['credentialPath']??'');$db=(string)($runtime['db']['name']??'');$property=(string)($runtime['env']['TAMASYA_PROPERTY_ID']??'');
    $id=substr(hash('sha256',strtolower($db).'|'.strtolower($property)),0,20);
    return dirname($cred).DIRECTORY_SEPARATOR.'.tamasya-commissioning-'.$id.'.json';
}
function tc_commission_state_read(array $runtime): array {
    $path=tc_commission_state_path($runtime);if(!is_file($path)||!is_readable($path))return [];
    $x=json_decode((string)file_get_contents($path),true);if(!is_array($x))return [];
    $db=(string)($runtime['db']['name']??'');$property=(string)($runtime['env']['TAMASYA_PROPERTY_ID']??'');
    if(!hash_equals(strtolower((string)($x['database']??'')),strtolower($db)))return [];
    if(!hash_equals(strtolower((string)($x['propertyId']??'')),strtolower($property)))return [];
    return $x;
}
function tc_commission_state_write(array $runtime,string $phase,array $extra=[]): array {
    $manifest=tc_canonical_manifest();$payload=array_merge([
        'version'=>TAMASYA_CONFIGURATOR_VERSION,
        'uiBuild'=>TAMASYA_CONFIGURATOR_UI_BUILD,
        'database'=>(string)($runtime['db']['name']??''),
        'propertyId'=>(string)($runtime['env']['TAMASYA_PROPERTY_ID']??''),
        'canonicalSha256'=>(string)($manifest['sqlSha256']??''),
        'phase'=>$phase,
        'updatedAt'=>date(DATE_ATOM),
    ],$extra);
    $path=tc_commission_state_path($runtime);tc_write_atomic($path,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",false,0600);@chmod($path,0600);return $payload+['path'=>$path];
}
function tc_commission_phase_rank(string $phase): int {
    static $r=['import_started'=>10,'schema_imported'=>20,'schema_validated'=>30,'first_install_started'=>40,'first_install_done'=>50,'bootstrap_cleared'=>60,'complete'=>70];return $r[$phase]??0;
}
/**
 * Strictly assess whether a canonical database with no commissioning journal can
 * be adopted as an INTERRUPTED FRESH install. This is intentionally stricter
 * than ordinary schema validation: no operational/business rows, no property
 * identity, no staff, config not seeded, PRIMARY production ENV, and bootstrap
 * password still present. It never mutates the database.
 */
function tc_assess_safe_fresh_adoption(PDO $pdo,array $env,array $probe): array {
    $issues=[];$details=[];
    if(strtolower(trim((string)($env['APP_ENV']??'')))!=='production')$issues[]='APP_ENV bukan production';
    if(strtolower(trim((string)($env['TAMASYA_NODE_INITIAL_ROLE']??'')))!=='primary')$issues[]='node bukan PRIMARY';
    if(empty($probe['schemaReady'])||empty($probe['releaseOk'])||empty($probe['patchOk']))$issues[]='canonical schema/release/patch belum PASS';
    $prop=(array)($probe['propertyIdentity']??[]);
    if(!empty($prop['initialized']))$issues[]='property identity sudah terinisialisasi';
    try{
        $staffCount=(int)$pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn();
        $propertyCount=(int)$pdo->query("SELECT COUNT(*) FROM property_settings")->fetchColumn();
        $configRows=(int)$pdo->query("SELECT COUNT(*) FROM config WHERE id='system_default'")->fetchColumn();
        $seeded=$configRows===1?(int)$pdo->query("SELECT COALESCE(is_seeded,0) FROM config WHERE id='system_default' LIMIT 1")->fetchColumn():-1;
        $details['staffCount']=$staffCount;$details['propertySettingsCount']=$propertyCount;$details['configSystemDefaultRows']=$configRows;$details['configSeeded']=$seeded;
        if($staffCount!==0)$issues[]='staff sudah berisi '.$staffCount.' row';
        if($propertyCount!==0)$issues[]='property_settings sudah berisi '.$propertyCount.' row';
        if($configRows!==1)$issues[]='config system_default tidak tepat 1 row';
        if($seeded!==0)$issues[]='config system_default sudah seeded/invalid';
    }catch(Throwable $e){$issues[]='freshness counter gagal: '.$e->getMessage();}
    try{
        $unexpected=tc_scan_fresh_business_tables($pdo);$details['unexpectedBusinessRows']=$unexpected;
        $details['ignoredPreBootstrapNoiseRows']=tc_scan_prebootstrap_noise_tables($pdo);
        if($unexpected){$parts=[];foreach($unexpected as $t=>$c)$parts[]=$t.'='.$c;$issues[]='data aplikasi/bisnis ditemukan: '.implode(', ',$parts);}
    }catch(Throwable $e){$issues[]='freshness policy scan gagal: '.$e->getMessage();}
    $bootstrap=trim((string)($env['APP_BOOTSTRAP_ADMIN_PASSWORD']??''));$details['bootstrapPasswordAvailable']=strlen($bootstrap)>=12;
    if(strlen($bootstrap)<12)$issues[]='APP_BOOTSTRAP_ADMIN_PASSWORD tidak tersedia/minimal 12 karakter';
    return ['ok'=>!$issues,'issues'=>$issues,'details'=>$details];
}

/** Detect active server state. This is the authority for which wizard stage must open. */
function tc_detect_active_setup_state(string $adminRoot): array {
    $root=realpath($adminRoot)?:tc_path_normalize($adminRoot);$envPath=rtrim($root,'/\\').DIRECTORY_SEPARATOR.'.env';
    $base=['code'=>'new','step'=>1,'message'=>'Belum ada konfigurasi aktif. Mulai setup baru dari Tahap 1.','severity'=>'info','envPath'=>$envPath];
    if(!is_file($envPath)||!is_readable($envPath))return $base;
    $envValidation=tc_validate_env_file($envPath);$env=tc_parse_dotenv_file($envPath);
    if(!$env)return ['code'=>'env_unreadable','step'=>5,'message'=>'File .env aktif ada tetapi tidak dapat dibaca/parse.','severity'=>'err','envPath'=>$envPath];
    $credPath=trim((string)($env['APP_CREDENTIALS_FILE']??''));$backupPath=trim((string)($env['BACKUP_DIR']??''));$publicRoot=trim((string)($env['TAMASYA_PUBLIC_SITE_ROOT']??''));
    $pathIssues=[];$e=tc_private_path_error($credPath,'APP_CREDENTIALS_FILE',$root,$publicRoot);if($e!==null)$pathIssues[]=$e;$e=tc_private_path_error($backupPath,'BACKUP_DIR',$root,$publicRoot);if($e!==null)$pathIssues[]=$e;
    if($pathIssues)return ['code'=>'private_path_unsafe','step'=>4,'message'=>implode(' ',array_unique($pathIssues)).' Perbaiki Tahap 4 lalu Apply ulang; database tidak perlu diulang.','severity'=>'err','envPath'=>$envPath];
    if($backupPath===''||!is_dir($backupPath))return ['code'=>'private_storage_missing','step'=>4,'message'=>'BACKUP_DIR aktif aman tetapi folder belum ada: '.$backupPath.'. Perbaiki/Apply ulang; configurator akan membuat folder private otomatis.','severity'=>'err','envPath'=>$envPath];
    if(!is_writable($backupPath))return ['code'=>'private_storage_not_writable','step'=>4,'message'=>'BACKUP_DIR aktif tidak writable: '.$backupPath.'. Perbaiki permission/path lalu Apply ulang.','severity'=>'err','envPath'=>$envPath];
    if(empty($envValidation['ok']))return ['code'=>'env_invalid','step'=>5,'message'=>'ENV aktif invalid: '.implode('; ',(array)($envValidation['errors']??[])),'severity'=>'err','envPath'=>$envPath];
    $cr=tc_read_db_credential_file($credPath);
    if(empty($cr['ok']))return ['code'=>'credential_invalid','step'=>4,'message'=>'Credential aktif invalid: '.implode('; ',(array)$cr['errors']).'. Perbaiki Tahap 4 lalu Apply ulang.','severity'=>'err','envPath'=>$envPath,'credentialPath'=>$credPath];
    $db=(array)$cr['db'];$expected=trim((string)($env['APP_EXPECTED_DB_NAME']??''));
    if($expected===''||strcasecmp($expected,(string)$db['name'])!==0)return ['code'=>'db_name_mismatch','step'=>4,'message'=>'APP_EXPECTED_DB_NAME tidak sama dengan DB credential aktif. Perbaiki Tahap 4 lalu Apply ulang.','severity'=>'err','envPath'=>$envPath,'credentialPath'=>$credPath];
    if(!class_exists('PDO')||!in_array('mysql',PDO::getAvailableDrivers(),true))return ['code'=>'pdo_missing','step'=>4,'message'=>'pdo_mysql tidak tersedia pada PHP aktif.','severity'=>'err','envPath'=>$envPath];
    try{
        $dsn='mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'].';charset=utf8mb4';
        $pdo=new PDO($dsn,$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>8,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $actual=trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
        if($actual===''||strcasecmp($actual,$expected)!==0)return ['code'=>'selected_db_mismatch','step'=>4,'message'=>'Database yang dipilih MySQL tidak sama dengan APP_EXPECTED_DB_NAME.','severity'=>'err','envPath'=>$envPath];
    }catch(Throwable $e){return ['code'=>'db_connect_failed','step'=>4,'message'=>'Koneksi database aktif gagal: '.$e->getMessage().' Perbaiki credential pada Tahap 4.','severity'=>'err','envPath'=>$envPath,'credentialPath'=>$credPath];}
    $kind=strtolower((string)($env['TAMASYA_NODE_KIND']??''))==='local'?'local':'online';
    $probeValues=['property_id'=>(string)($env['TAMASYA_PROPERTY_ID']??''),'property_code'=>(string)($env['TAMASYA_PROPERTY_CODE']??''),'property_name'=>(string)($env['TAMASYA_PROPERTY_NAME']??''),'company_id'=>(string)($env['TAMASYA_COMPANY_ID']??''),'timezone'=>(string)($env['APP_TIMEZONE']??''),'currency'=>(string)($env['TAMASYA_PROPERTY_CURRENCY']??''),'country'=>(string)($env['TAMASYA_PROPERTY_COUNTRY']??'')];
    $probeValues[$kind.'_db_host']=$db['host'];$probeValues[$kind.'_db_port']=$db['port'];$probeValues[$kind.'_db_name']=$db['name'];$probeValues[$kind.'_db_user']=$db['user'];$probeValues[$kind.'_db_pass']=$db['pass'];
    $tableCount=tc_database_base_table_count($pdo);
    if($tableCount===0)return ['code'=>'db_empty','step'=>6,'message'=>'Konfigurasi aktif valid dan database masih kosong. Siap Fresh Commissioning.','severity'=>'ok','envPath'=>$envPath,'tableCount'=>0];
    $probe=tc_db_probe($probeValues,$kind);$prop=(array)($probe['propertyIdentity']??[]);
    if(!empty($probe['schemaReady'])&&!empty($probe['releaseOk'])&&!empty($probe['patchOk'])){
        if(!empty($prop['initialized'])&&!empty($prop['ok'])){
            try{$adminCount=(int)$pdo->query("SELECT COUNT(*) FROM staff WHERE role='admin' AND status='active'")->fetchColumn();$seeded=(int)$pdo->query("SELECT COALESCE(is_seeded,0) FROM config WHERE id='system_default' LIMIT 1")->fetchColumn();}catch(Throwable $e){$adminCount=0;$seeded=0;}
            if($adminCount>=1&&$seeded===1)return ['code'=>'commissioned','step'=>6,'message'=>'Server/database sudah berhasil di-bootstrap. Lanjut login/property setup; jangan Fresh Install ulang.','severity'=>'ok','envPath'=>$envPath,'tableCount'=>$tableCount];
        }
        $runtimeStub=['credentialPath'=>$credPath,'db'=>$db,'env'=>$env];$journal=tc_commission_state_read($runtimeStub);
        if($journal&&tc_commission_phase_rank((string)($journal['phase']??''))>=10)return ['code'=>'commission_resume','step'=>6,'message'=>'Canonical schema sudah ada dari commissioning yang tercatat. Proses aman untuk dilanjutkan/resume dari tahap terakhir.','severity'=>'warn','envPath'=>$envPath,'tableCount'=>$tableCount,'journal'=>$journal];
        $adopt=tc_assess_safe_fresh_adoption($pdo,$env,$probe);
        if(!empty($adopt['ok']))return ['code'=>'fresh_canonical_unbootstrapped','step'=>6,'message'=>'Canonical schema PASS dan database masih fresh/unbootstrapped menurut Freshness Policy. Telemetry/infrastruktur pre-bootstrap tidak dianggap data bisnis. Aman untuk diadopsi dan dilanjutkan ke First Install.','severity'=>'warn','envPath'=>$envPath,'tableCount'=>$tableCount,'adoption'=>$adopt];
        return ['code'=>'canonical_without_journal','step'=>6,'message'=>'Database canonical terdeteksi tanpa commissioning journal, tetapi TIDAK lolos safe-adoption: '.implode('; ',(array)($adopt['issues']??[])).'. Tidak ada bootstrap otomatis.','severity'=>'err','envPath'=>$envPath,'tableCount'=>$tableCount,'adoption'=>$adopt];
    }
    return ['code'=>'db_partial','step'=>4,'message'=>'Database berisi table tetapi canonical validation belum PASS. Jangan lanjut/repair otomatis; untuk fresh install gunakan database kosong baru.','severity'=>'err','envPath'=>$envPath,'tableCount'=>$tableCount];
}

/**
 * Fresh commissioning support for the SAME unified configurator.
 *
 * Important design rule: this layer does NOT generate ENV, credentials, or
 * application secrets. tc_generate()/tc_apply() remain the single authority.
 * These helpers only consume the already-applied .env + private credential and
 * complete a NEW/EMPTY database on the active Primary node.
 */
function tc_runtime_from_applied_env(string $adminRoot): array {
    $adminRoot=realpath($adminRoot)?:tc_path_normalize($adminRoot);
    if(!is_dir($adminRoot))throw new RuntimeException('Admin root commissioning tidak ditemukan: '.$adminRoot);
    $envPath=rtrim($adminRoot,'/\\').DIRECTORY_SEPARATOR.'.env';
    $envValidation=tc_validate_env_file($envPath);$env=tc_parse_dotenv_file($envPath);
    $credentialPath=trim((string)($env['APP_CREDENTIALS_FILE']??''));$backupPath=trim((string)($env['BACKUP_DIR']??''));$publicRoot=trim((string)($env['TAMASYA_PUBLIC_SITE_ROOT']??''));
    $e=tc_private_path_error($credentialPath,'APP_CREDENTIALS_FILE',$adminRoot,$publicRoot);if($e!==null)throw new RuntimeException($e);
    $e=tc_private_path_error($backupPath,'BACKUP_DIR',$adminRoot,$publicRoot);if($e!==null)throw new RuntimeException($e);
    if(empty($envValidation['ok']))throw new RuntimeException('ENV aktif gagal validasi: '.implode('; ',(array)($envValidation['errors']??[])));
    if(strtolower((string)($env['APP_ENV']??''))!=='production')throw new RuntimeException('Fresh commissioning production membutuhkan APP_ENV=production.');
    if(strtolower((string)($env['TAMASYA_NODE_INITIAL_ROLE']??''))!=='primary')throw new RuntimeException('Fresh commissioning hanya boleh dijalankan pada node PRIMARY aktif, bukan standby.');
    $cr=tc_read_db_credential_file($credentialPath);
    if(empty($cr['ok']))throw new RuntimeException('Credential database aktif invalid: '.implode('; ',(array)$cr['errors']).'.');
    $db=(array)$cr['db'];
    $expected=trim((string)($env['APP_EXPECTED_DB_NAME']??''));
    if($expected===''||strcasecmp($expected,(string)$db['name'])!==0)throw new RuntimeException('APP_EXPECTED_DB_NAME tidak sama dengan DB credential aktif.');
    if(!class_exists('PDO')||!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_mysql tidak tersedia; fresh commissioning database tidak dapat dijalankan.');
    $dsn='mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'].';charset=utf8mb4';
    $pdo=new PDO($dsn,$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>8,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $actual=trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    if($actual===''||strcasecmp($actual,$expected)!==0)throw new RuntimeException('Database safety gate gagal: selected DB tidak sama dengan APP_EXPECTED_DB_NAME.');
    $kind=strtolower((string)($env['TAMASYA_NODE_KIND']??''))==='local'?'local':'online';
    $v=['property_id'=>(string)($env['TAMASYA_PROPERTY_ID']??''),'property_code'=>(string)($env['TAMASYA_PROPERTY_CODE']??''),'property_name'=>(string)($env['TAMASYA_PROPERTY_NAME']??''),'company_id'=>(string)($env['TAMASYA_COMPANY_ID']??''),'timezone'=>(string)($env['APP_TIMEZONE']??''),'currency'=>(string)($env['TAMASYA_PROPERTY_CURRENCY']??''),'country'=>(string)($env['TAMASYA_PROPERTY_COUNTRY']??'')];
    $v[$kind.'_db_host']=$db['host'];$v[$kind.'_db_port']=$db['port'];$v[$kind.'_db_name']=$db['name'];$v[$kind.'_db_user']=$db['user'];$v[$kind.'_db_pass']=$db['pass'];
    return ['adminRoot'=>$adminRoot,'envPath'=>$envPath,'env'=>$env,'envValidation'=>$envValidation,'credentialPath'=>$credentialPath,'db'=>$db,'pdo'=>$pdo,'kind'=>$kind,'probeValues'=>$v];
}
function tc_first_admin_recovery_status(string $adminRoot): array {
    try{
        $runtime=tc_runtime_from_applied_env($adminRoot);$pdo=$runtime['pdo'];
        $admins=$pdo->query("SELECT id,username,last_login_at,require_password_change FROM staff WHERE role='admin' AND status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $propertyRows=(int)$pdo->query("SELECT COUNT(*) FROM property_settings")->fetchColumn();
        $setupStatus=$propertyRows===1?(string)$pdo->query("SELECT setup_status FROM property_settings LIMIT 1")->fetchColumn():'unknown';
        $seeded=(int)$pdo->query("SELECT COALESCE(is_seeded,0) FROM config WHERE id='system_default' LIMIT 1")->fetchColumn();
        $issues=[];
        if(count($admins)!==1)$issues[]='admin aktif harus tepat 1';
        if($propertyRows!==1)$issues[]='property_settings harus tepat 1 row';
        if($seeded!==1)$issues[]='config belum seeded';
        $admin=$admins[0]??[];
        if($admin&&trim((string)($admin['last_login_at']??''))!=='')$issues[]='admin sudah pernah login sukses';
        if($admin&&(int)($admin['require_password_change']??0)!==1)$issues[]='flag require_password_change bukan bootstrap state';
        return ['eligible'=>!$issues,'issues'=>$issues,'admin'=>$admin,'setupStatus'=>$setupStatus,'runtime'=>$runtime];
    }catch(Throwable $e){return ['eligible'=>false,'issues'=>[$e->getMessage()],'admin'=>[],'setupStatus'=>'unknown'];}
}

/**
 * One-time recovery for the very first admin access only.
 * Protected by configurator TC4 + CSRF and automatically unavailable after
 * the admin has ever logged in successfully. It never writes the new password
 * to .env or the recovery sheet; plaintext is rendered once, then only the
 * password hash remains in staff.password.
 */
function tc_reset_first_admin_password(string $adminRoot): array {
    $status=tc_first_admin_recovery_status($adminRoot);
    if(empty($status['eligible']))throw new RuntimeException('Recovery admin pertama ditolak: '.implode('; ',(array)($status['issues']??[])).'.');
    $runtime=(array)$status['runtime'];$pdo=$runtime['pdo'];$admin=(array)$status['admin'];$staffId=(string)($admin['id']??'');$username=(string)($admin['username']??'admin');
    if($staffId==='')throw new RuntimeException('ID admin bootstrap tidak ditemukan.');
    $password=tc_random_password(28);$hash=password_hash($password,PASSWORD_DEFAULT);if(!is_string($hash)||$hash==='')throw new RuntimeException('Gagal membuat password hash admin recovery.');
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $st=$pdo->prepare("UPDATE staff SET password=?,failed_login_count=0,login_locked_until=NULL,password_changed_at=CURRENT_TIMESTAMP,require_password_change=1 WHERE id=? AND status='active' AND role='admin' AND last_login_at IS NULL");
        $st->execute([$hash,$staffId]);if($st->rowCount()!==1)throw new RuntimeException('Admin berubah state saat recovery; tidak ada perubahan password yang diterapkan.');
        // Before first successful login there is only one legitimate bootstrap account.
        // Clear stale login buckets so a previous 429 cannot immediately mask the new password.
        $pdo->exec("DELETE FROM rate_limits WHERE scope_key LIKE 'login:%'");
        if($owns)$pdo->commit();
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['username'=>$username,'password'=>$password,'staffId'=>$staffId];
}

function tc_database_base_table_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
}
function tc_sql_statements(string $sql): array {
    if(preg_match('/^\\s*DELIMITER\\b/im',$sql))throw new RuntimeException('Canonical SQL memakai DELIMITER yang tidak didukung browser importer ini. Gunakan canonical SQL release yang cocok.');
    $out=[];$buf='';$quote=null;$lineComment=false;$blockComment=false;$len=strlen($sql);
    for($i=0;$i<$len;$i++){
        $ch=$sql[$i];$next=$i+1<$len?$sql[$i+1]:'';
        if($lineComment){if($ch==="\n"){$lineComment=false;$buf.="\n";}continue;}
        if($blockComment){if($ch==='*'&&$next==='/'){$blockComment=false;$i++;$buf.=' ';}continue;}
        if($quote!==null){$buf.=$ch;if($ch==='\\'&&$quote!=='`'&&$next!==''){$buf.=$next;$i++;continue;}if($ch===$quote){if($next===$quote){$buf.=$next;$i++;continue;}$quote=null;}continue;}
        if($ch==='-'&&$next==='-'&&($i+2>=$len||ctype_space($sql[$i+2]))){$lineComment=true;$i++;continue;}
        if($ch==='#'){$lineComment=true;continue;}
        if($ch==='/'&&$next==='*'){$blockComment=true;$i++;continue;}
        if($ch==="'"||$ch==='"'||$ch==='`'){$quote=$ch;$buf.=$ch;continue;}
        if($ch===';'){$stmt=trim($buf);if($stmt!=='')$out[]=$stmt;$buf='';continue;}
        $buf.=$ch;
    }
    $tail=trim($buf);if($tail!=='')$out[]=$tail;
    if($quote!==null||$blockComment)throw new RuntimeException('Canonical SQL parser mendeteksi quote/comment tidak tertutup.');
    return $out;
}
function tc_import_canonical_sql(PDO $pdo): array {
    if(tc_database_base_table_count($pdo)!==0)throw new RuntimeException('Import canonical ditolak: database tidak kosong. Configurator tidak pernah DROP/TRUNCATE database.');
    $path=__DIR__.DIRECTORY_SEPARATOR.'database_setup.sql';
    $sql=@file_get_contents($path);if(!is_string($sql)||$sql==='')throw new RuntimeException('database_setup.sql tidak ditemukan/readable.');
    $manifest=tc_canonical_manifest();$expectedHash=strtolower((string)($manifest['sqlSha256']??''));$actualHash=hash('sha256',$sql);
    if($expectedHash===''||!hash_equals($expectedHash,$actualHash))throw new RuntimeException('SHA-256 database_setup.sql tidak sama dengan canonical manifest embedded. Import ditolak.');
    $statements=tc_sql_statements($sql);if(count($statements)<100)throw new RuntimeException('Canonical SQL terlihat tidak lengkap; jumlah statement terlalu sedikit.');
    @set_time_limit(180);
    foreach($statements as $idx=>$stmt){
        try{$pdo->exec($stmt);}catch(Throwable $e){try{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}catch(Throwable $ignored){}throw new RuntimeException('Import canonical gagal pada statement #'.($idx+1).' dari '.count($statements).': '.$e->getMessage().'. Database mungkin sudah partial; jangan lanjut first install. Drop/recreate database kosong lalu ulangi commissioning.');}
    }
    $tables=tc_database_base_table_count($pdo);
    return ['sqlPath'=>$path,'sqlSha256'=>$actualHash,'statementCount'=>count($statements),'baseTables'=>$tables];
}
/**
 * Freshness policy for an interrupted fresh install.
 *
 * Canonical SQL seeds only seedTables. A small, explicit set of infrastructure
 * tables may legitimately receive rows BEFORE first_install (for example error
 * telemetry from API health/setup requests, rate-limit state, or cluster
 * heartbeat state). Those rows are NOT evidence that hotel business data has
 * been initialized and therefore must not poison safe-resume.
 *
 * Every other canonical table remains blocking/fail-closed: any row there means
 * the DB is no longer considered pristine enough for automatic first_install.
 * This classification is versioned with the configurator and validated by
 * self-test so a typo/unknown table cannot silently weaken the gate.
 */
function tc_freshness_policy(): array {
    return [
        'seedTables'=>[
            'categories','config','hotel_operational_settings','node_sync_settings',
            'schema_migrations','schema_release_state','subcategories',
        ],
        'preBootstrapNoiseTables'=>[
            // Request/error observability can be written merely by opening API
            // endpoints while applicationDataReady=false.
            'runtime_request_events','security_events','rate_limits','request_operation_receipts',
            'data_integrity_issues','system_alerts',
            // Cluster/sync infrastructure may initialize heartbeat/nonce state
            // before the first hotel administrator exists on a dual-node setup.
            'node_cluster_events','node_cluster_members','node_cluster_state',
            'node_sync_nonces','node_sync_receipts','node_sync_runs',
        ],
    ];
}
function tc_freshness_policy_validate(): array {
    $manifest=array_values(array_map('strval',(array)(tc_canonical_manifest()['tables']??[])));
    $known=array_fill_keys($manifest,true);$policy=tc_freshness_policy();$issues=[];$seen=[];
    foreach(['seedTables','preBootstrapNoiseTables'] as $bucket){
        foreach((array)($policy[$bucket]??[]) as $table){
            $table=(string)$table;
            if(!isset($known[$table]))$issues[]=$bucket.' berisi table non-canonical: '.$table;
            if(isset($seen[$table]))$issues[]='table muncul di lebih dari satu freshness bucket: '.$table;
            $seen[$table]=$bucket;
        }
    }
    return ['ok'=>!$issues,'issues'=>$issues,'policy'=>$policy];
}
function tc_scan_fresh_business_tables(PDO $pdo): array {
    $policy=tc_freshness_policy();
    $nonBlocking=array_fill_keys(array_merge((array)$policy['seedTables'],(array)$policy['preBootstrapNoiseTables']),true);
    $unexpected=[];
    foreach((array)tc_canonical_manifest()['tables'] as $table){
        $table=(string)$table;if(isset($nonBlocking[$table]))continue;
        $quoted='`'.str_replace('`','``',$table).'`';
        $count=(int)$pdo->query('SELECT COUNT(*) FROM '.$quoted)->fetchColumn();
        if($count>0)$unexpected[$table]=$count;
        if(count($unexpected)>=20)break;
    }
    return $unexpected;
}
function tc_scan_prebootstrap_noise_tables(PDO $pdo): array {
    $noise=[];
    foreach((array)(tc_freshness_policy()['preBootstrapNoiseTables']??[]) as $table){
        $table=(string)$table;$quoted='`'.str_replace('`','``',$table).'`';
        $count=(int)$pdo->query('SELECT COUNT(*) FROM '.$quoted)->fetchColumn();
        if($count>0)$noise[$table]=$count;
    }
    return $noise;
}
function tc_first_install_from_applied_env(array $runtime): array {
    /** @var PDO $pdo */$pdo=$runtime['pdo'];$env=(array)$runtime['env'];$probe=tc_db_probe((array)$runtime['probeValues'],(string)$runtime['kind']);
    if(empty($probe['schemaReady'])||empty($probe['releaseOk'])||empty($probe['patchOk']))throw new RuntimeException('First install ditolak: canonical schema/release/patch belum PASS.');
    $prop=(array)($probe['propertyIdentity']??[]);
    if(!empty($prop['initialized'])){
        if(empty($prop['ok']))throw new RuntimeException('Property identity database sudah terisi tetapi tidak cocok dengan ENV.');
        $adminCount=(int)$pdo->query("SELECT COUNT(*) FROM staff WHERE role='admin' AND status='active'")->fetchColumn();
        $seeded=(int)$pdo->query("SELECT COALESCE(is_seeded,0) FROM config WHERE id='system_default' LIMIT 1")->fetchColumn();
        if($adminCount<1||$seeded!==1)throw new RuntimeException('Database terlihat pernah bootstrap tetapi state admin/config tidak lengkap. Jangan jalankan ulang otomatis; audit database terlebih dahulu.');
        return ['performed'=>false,'alreadyInitialized'=>true,'adminCount'=>$adminCount];
    }
    $unexpected=tc_scan_fresh_business_tables($pdo);if($unexpected){$parts=[];foreach($unexpected as $t=>$c)$parts[]=$t.'='.$c;throw new RuntimeException('First install dihentikan: ditemukan data aplikasi/bisnis yang membuktikan database bukan fresh: '.implode(', ',$parts));}
    $password=trim((string)($env['APP_BOOTSTRAP_ADMIN_PASSWORD']??''));if(strlen($password)<12)throw new RuntimeException('APP_BOOTSTRAP_ADMIN_PASSWORD aktif tidak tersedia/minimal 12 karakter. Jangan generate secret baru di luar configurator.');
    $username=trim((string)($env['APP_BOOTSTRAP_ADMIN_USERNAME']??'admin'));$name=trim((string)($env['APP_BOOTSTRAP_ADMIN_NAME']??'Administrator Utama'));
    if($username===''||!preg_match('/^[A-Za-z0-9._-]{3,50}$/',$username))throw new RuntimeException('APP_BOOTSTRAP_ADMIN_USERNAME tidak valid.');if($name==='')throw new RuntimeException('APP_BOOTSTRAP_ADMIN_NAME kosong.');
    $propertyName=trim((string)($env['TAMASYA_PROPERTY_NAME']??''));$propertyId=strtolower(trim((string)($env['TAMASYA_PROPERTY_ID']??'')));$propertyCode=strtoupper(trim((string)($env['TAMASYA_PROPERTY_CODE']??'')));$companyId=strtolower(trim((string)($env['TAMASYA_COMPANY_ID']??'')));
    $currency=strtoupper(trim((string)($env['TAMASYA_PROPERTY_CURRENCY']??'')));$country=strtoupper(trim((string)($env['TAMASYA_PROPERTY_COUNTRY']??'')));$locale=trim((string)($env['TAMASYA_PROPERTY_LOCALE']??'id-ID'));$invoice=strtoupper(trim((string)($env['TAMASYA_INVOICE_PREFIX']??$propertyCode)));$timezone=trim((string)($env['APP_TIMEZONE']??''));
    if($propertyName===''||strtoupper($propertyName)==='NAMA HOTEL')throw new RuntimeException('TAMASYA_PROPERTY_NAME belum valid.');if($propertyId===''||$propertyId==='default'||!preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$propertyId))throw new RuntimeException('TAMASYA_PROPERTY_ID belum valid.');if($propertyCode===''||!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/',$propertyCode))throw new RuntimeException('TAMASYA_PROPERTY_CODE belum valid.');if($companyId!==''&&!preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$companyId))throw new RuntimeException('TAMASYA_COMPANY_ID invalid.');if(!preg_match('/^[A-Z]{3}$/',$currency))throw new RuntimeException('Currency property invalid.');if(!preg_match('/^[A-Z]{2}$/',$country))throw new RuntimeException('Country property invalid.');if(!preg_match('/^[A-Za-z]{2,3}[-_][A-Za-z]{2,4}$/',$locale))throw new RuntimeException('Locale property invalid.');$invoice=preg_replace('/[^A-Z0-9_-]+/','',$invoice)??'';if($invoice===''||strlen($invoice)>30)throw new RuntimeException('Invoice prefix invalid.');try{new DateTimeZone($timezone);}catch(Throwable $e){throw new RuntimeException('APP_TIMEZONE invalid.');}
    $id='s_'.bin2hex(random_bytes(12));$hash=password_hash($password,PASSWORD_DEFAULT);if(!is_string($hash)||$hash==='')throw new RuntimeException('Password admin tidak dapat di-hash.');
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare("INSERT INTO staff(id,name,username,password,role,status,password_changed_at,require_password_change) VALUES (?,?,?,?, 'admin','active',CURRENT_TIMESTAMP,1)");$st->execute([$id,$name,$username,$hash]);
        $ps=$pdo->prepare("INSERT INTO property_settings(id,company_id,property_id,property_code,property_name,timezone,currency,country_code,locale,invoice_prefix,accounting_basis,tax_setup_mode,payment_setup_mode,setup_status,setup_version) VALUES ('system_default',?,?,?,?,?,?,?,?,?,'cash','pending','pending','identity_ready',1)");$ps->execute([$companyId!==''?$companyId:null,$propertyId,$propertyCode,$propertyName,$timezone,$currency,$country,$locale,$invoice]);
        $pdo->prepare("UPDATE config SET is_seeded=1 WHERE id='system_default'")->execute();
        $canonicalSourceChecksum=hash_file('sha256',__DIR__.DIRECTORY_SEPARATOR.'database_setup.sql');
        if(!is_string($canonicalSourceChecksum)||!preg_match('/^[a-f0-9]{64}$/',$canonicalSourceChecksum))throw new RuntimeException('Checksum canonical database_setup.sql tidak dapat dihitung.');
        $pdo->prepare("UPDATE schema_release_state SET current_release=?,patch_level=?,source_checksum=?,migration_run_id=COALESCE(NULLIF(migration_run_id,''),?),maintenance_required=0,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([TAMASYA_SCHEMA_RELEASE_EXPECTED,TAMASYA_PATCH_LEVEL_EXPECTED,$canonicalSourceChecksum,'configurator_'.substr($canonicalSourceChecksum,0,16)]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['performed'=>true,'alreadyInitialized'=>false,'adminId'=>$id,'username'=>$username,'propertyId'=>$propertyId];
}
function tc_clear_bootstrap_from_env(string $envPath): array {
    $text=@file_get_contents($envPath);if(!is_string($text)||$text==='')throw new RuntimeException('ENV aktif tidak dapat dibaca untuk membersihkan bootstrap password.');$env=tc_parse_dotenv_text($text);$had=trim((string)($env['APP_BOOTSTRAP_ADMIN_PASSWORD']??''))!=='';
    if(!$had)return ['changed'=>false,'path'=>$envPath,'backup'=>null];
    $new=tc_set_env($text,'APP_BOOTSTRAP_ADMIN_PASSWORD','',true);$write=tc_write_atomic($envPath,$new,true,0600);$check=tc_validate_env_file($envPath);if(empty($check['ok']))throw new RuntimeException('ENV setelah membersihkan bootstrap password gagal validasi: '.implode('; ',(array)($check['errors']??[])));return ['changed'=>true,'path'=>$envPath,'backup'=>$write['backup']??null];
}
function tc_ensure_backup_dir_from_env(array $runtime): array {
    $env=(array)$runtime['env'];$dir=trim((string)($env['BACKUP_DIR']??''));$publicRoot=trim((string)($env['TAMASYA_PUBLIC_SITE_ROOT']??''));$e=tc_private_path_error($dir,'BACKUP_DIR aktif',(string)$runtime['adminRoot'],$publicRoot);if($e!==null)throw new RuntimeException($e);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Gagal membuat BACKUP_DIR private: '.$dir);@chmod($dir,0700);if(!is_writable($dir))throw new RuntimeException('BACKUP_DIR tidak writable: '.$dir);return ['path'=>$dir,'writable'=>true];
}
function tc_fresh_commission(string $adminRoot): array {
    $runtime=tc_runtime_from_applied_env($adminRoot);/** @var PDO $pdo */$pdo=$runtime['pdo'];$steps=[];$tableCount=tc_database_base_table_count($pdo);$journal=tc_commission_state_read($runtime);$manifest=tc_canonical_manifest();
    if($tableCount===0){
        $journal=tc_commission_state_write($runtime,'import_started',['baseTablesBefore'=>0]);
        $steps['canonicalImport']=tc_import_canonical_sql($pdo);
        $journal=tc_commission_state_write($runtime,'schema_imported',['sqlSha256'=>$steps['canonicalImport']['sqlSha256']??null,'baseTablesAfter'=>$steps['canonicalImport']['baseTables']??null]);
    }else{
        $phase=(string)($journal['phase']??'');$sha=(string)($journal['canonicalSha256']??'');$expectedSha=(string)($manifest['sqlSha256']??'');
        $resume=$journal&&tc_commission_phase_rank($phase)>=10&&$sha!==''&&hash_equals($expectedSha,$sha);
        $probeBefore=tc_db_probe((array)$runtime['probeValues'],(string)$runtime['kind']);
        if(empty($probeBefore['schemaReady'])||empty($probeBefore['releaseOk'])||empty($probeBefore['patchOk']))throw new RuntimeException('Database berisi table tetapi canonical schema/release/patch belum PASS. Configurator tidak repair/overwrite otomatis; untuk fresh install recreate database kosong.');
        if(!$resume){
            $adopt=tc_assess_safe_fresh_adoption($pdo,(array)$runtime['env'],$probeBefore);
            if(empty($adopt['ok']))throw new RuntimeException('Fresh commissioning ditolak: database canonical tidak memiliki journal dan gagal safe-adoption: '.implode('; ',(array)($adopt['issues']??[])).'.');
            $journal=tc_commission_state_write($runtime,'schema_imported',['adoptedCanonicalFresh'=>true,'baseTablesAfter'=>$tableCount,'reason'=>'strict safe-adoption of canonical unbootstrapped database']);
            $steps['canonicalImport']=['skipped'=>true,'reason'=>'strict safe-adoption; canonical schema already present','adopted'=>true,'baseTables'=>$tableCount,'sqlSha256'=>$expectedSha];
        }else{
            $steps['canonicalImport']=['skipped'=>true,'reason'=>'durable commissioning resume','baseTables'=>$tableCount,'sqlSha256'=>$expectedSha];
        }
    }
    $probe=tc_db_probe((array)$runtime['probeValues'],(string)$runtime['kind']);
    if(empty($probe['schemaReady'])||empty($probe['releaseOk'])||empty($probe['patchOk']))throw new RuntimeException('Canonical validation setelah import belum PASS: '.(string)($probe['message']??'unknown'));
    $steps['canonicalValidation']=$probe;tc_commission_state_write($runtime,'schema_validated');
    tc_commission_state_write($runtime,'first_install_started');
    $steps['firstInstall']=tc_first_install_from_applied_env($runtime);
    tc_commission_state_write($runtime,'first_install_done',['performed'=>!empty($steps['firstInstall']['performed'])]);
    $steps['bootstrapCleanup']=tc_clear_bootstrap_from_env((string)$runtime['envPath']);
    tc_commission_state_write($runtime,'bootstrap_cleared',['changed'=>!empty($steps['bootstrapCleanup']['changed'])]);
    $runtime2=tc_runtime_from_applied_env($adminRoot);$steps['backupDir']=tc_ensure_backup_dir_from_env($runtime2);$finalProbe=tc_db_probe((array)$runtime2['probeValues'],(string)$runtime2['kind']);$prop=(array)($finalProbe['propertyIdentity']??[]);$adminCount=(int)$runtime2['pdo']->query("SELECT COUNT(*) FROM staff WHERE role='admin' AND status='active'")->fetchColumn();$setup=$runtime2['pdo']->query("SELECT setup_status,ready_at FROM property_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:null;$adminWeb=(string)($runtime2['env']['ADMIN_WEB_TOOLS_ENABLED']??'0');
    $finalOk=!empty($finalProbe['ok'])&&!empty($prop['initialized'])&&!empty($prop['ok'])&&$adminCount>=1&&$adminWeb==='0'&&empty($runtime2['envValidation']['errors']);if(!$finalOk)throw new RuntimeException('Final commissioning gate belum PASS setelah bootstrap.');
    $steps['final']=['ok'=>true,'database'=>$runtime2['db']['name'],'propertyId'=>$runtime2['env']['TAMASYA_PROPERTY_ID']??'','adminCount'=>$adminCount,'setupStatus'=>is_array($setup)?($setup['setup_status']??null):null,'propertyReady'=>is_array($setup)&&($setup['setup_status']??'')==='ready','adminWebToolsEnabled'=>$adminWeb,'envFile'=>$runtime2['envPath'],'credentialFile'=>$runtime2['credentialPath'],'backupDir'=>$steps['backupDir']['path'],'probe'=>$finalProbe];
    $steps['commissioningJournal']=tc_commission_state_write($runtime2,'complete',['adminCount'=>$adminCount,'setupStatus'=>$steps['final']['setupStatus']]);
    return $steps;
}
function tc_parse_dotenv_file(string $file): array { if(!is_file($file)||!is_readable($file))return [];return tc_parse_dotenv_text((string)file_get_contents($file)); }
/** Recover browser wizard values from the already-applied ENV after a commissioning error.
 *  Secrets/passwords are intentionally NOT restored into form fields. */
function tc_recovery_values_from_active(string $adminRoot): array {
    $v=tc_defaults();$root=realpath($adminRoot)?:tc_path_normalize($adminRoot);$v['admin_root']=$root;
    $env=tc_parse_dotenv_file(rtrim($root,'/\\').DIRECTORY_SEPARATOR.'.env');if(!$env)return $v;
    $kind=strtolower((string)($env['TAMASYA_NODE_KIND']??''))==='local'?'local':'online';$cluster=(string)($env['NODE_CLUSTER_ENABLED']??'0')==='1';
    $v['mode']=$cluster?'dual':($kind==='local'?'single_local':'single_online');$v['initial_primary']=strtolower((string)($env['TAMASYA_NODE_INITIAL_ROLE']??''))==='primary'?$kind:($kind==='local'?'online':'local');
    foreach(['APP_TIMEZONE'=>'timezone','TAMASYA_PROPERTY_ID'=>'property_id','TAMASYA_PROPERTY_CODE'=>'property_code','TAMASYA_PROPERTY_NAME'=>'property_name','TAMASYA_COMPANY_ID'=>'company_id','TAMASYA_COMPANY_NAME'=>'company_name','TAMASYA_PROPERTY_CURRENCY'=>'currency','TAMASYA_PROPERTY_COUNTRY'=>'country','TAMASYA_PROPERTY_LOCALE'=>'locale','TAMASYA_INVOICE_PREFIX'=>'invoice_prefix','TAMASYA_CLUSTER_ID'=>'cluster_id'] as $ek=>$vk)if(isset($env[$ek]))$v[$vk]=(string)$env[$ek];
    $stripApi=static function(string $url): string {$url=trim($url);return preg_replace('~/api\.php$~','',$url)??$url;};
    $v['online_url']=$stripApi((string)($env['VITE_TAMASYA_ONLINE_API_URL']??($kind==='online'?($env['APP_URL']??''):'https://app.nolink.my.id')));
    $v['public_url']=trim((string)($env['PUBLIC_SITE_URL']??$v['public_url']));
    $v['local_url']=$stripApi((string)($env['VITE_TAMASYA_LOCAL_API_URL']??($kind==='local'?($env['APP_URL']??''):$v['local_url'])));
    $selfNode=(string)($env['TAMASYA_NODE_ID']??'');$peerNode=(string)($env['NODE_CLUSTER_PEER_ID']??'');if($kind==='local'){$v['local_node_id']=$selfNode;$v['online_node_id']=$peerNode;}else{$v['online_node_id']=$selfNode;$v['local_node_id']=$peerNode;}
    $v['fresh_install']=trim((string)($env['APP_BOOTSTRAP_ADMIN_PASSWORD']??''))!==''?'1':'0'; // commissioned ENV has bootstrap cleared; recovery must NOT re-enable Fresh Install.
    $v['preserve_existing_env_secrets']='1';$v['include_secret_inventory']='1';$v['apply_target']=$kind;$v['apply_public']=trim((string)($env['TAMASYA_PUBLIC_SITE_ROOT']??''))!==''?'1':'0';$v['lock_after_apply']='1';
    $v['public_site_root']=(string)($env['TAMASYA_PUBLIC_SITE_ROOT']??'');$v[$kind.'_credential_path']=(string)($env['APP_CREDENTIALS_FILE']??'');$v[$kind.'_backup_dir']=(string)($env['BACKUP_DIR']??'');$v[$kind.'_trusted_proxies']=(string)($env['APP_TRUSTED_PROXIES']??'');$v[$kind.'_db_name']=(string)($env['APP_EXPECTED_DB_NAME']??'');
    $credPath=trim((string)($env['APP_CREDENTIALS_FILE']??''));if($credPath!==''&&tc_path_absolute($credPath)&&!tc_path_within($credPath,$root)&&!tc_inside_docroot($credPath)&&is_file($credPath)&&is_readable($credPath)){
        $cr=tc_read_db_credential_file($credPath);$cred=(array)($cr['db']??[]);
        foreach(['host'=>'db_host','port'=>'db_port','name'=>'db_name','user'=>'db_user'] as $ck=>$suffix)if(isset($cred[$ck])&&(string)$cred[$ck]!=='')$v[$kind.'_'.$suffix]=(string)$cred[$ck];
    }
    $v['local_db_pass']='';$v['online_db_pass']='';$v['existing_encryption_key']='';$v['existing_sync_secret']='';$v['setup_key']='';return $v;
}
function tc_setup_key_path(): string { return __DIR__.DIRECTORY_SEPARATOR.TAMASYA_CONFIGURATOR_SETUP_KEY_FILE; }
function tc_setup_key_record(): array { $file=tc_setup_key_path();if(!is_file($file)||!is_readable($file))return [];$x=json_decode((string)file_get_contents($file),true);return is_array($x)?$x:[]; }
function tc_setup_key_status(): array {
    $r=tc_setup_key_record();$process=(string)(getenv('TAMASYA_CONFIGURATOR_SETUP_KEY')?:'');$dot=tc_parse_dotenv_file(__DIR__.DIRECTORY_SEPARATOR.'.env');return ['keyFile'=>tc_setup_key_path(),'keyFileActive'=>isset($r['sha256'])&&preg_match('/^[a-f0-9]{64}$/',(string)$r['sha256'])===1,'fingerprint'=>$r['fingerprint']??null,'createdAt'=>$r['createdAt']??null,'processEnvActive'=>tc_secret_min($process),'dotenvSetupKeyActive'=>isset($dot['TAMASYA_CONFIGURATOR_SETUP_KEY'])&&tc_secret_min((string)$dot['TAMASYA_CONFIGURATOR_SETUP_KEY']),'legacyHashActive'=>TAMASYA_CONFIGURATOR_SETUP_KEY_SHA256!==''];
}
function tc_generate_setup_key(bool $rotate=false): array {
    $file=tc_setup_key_path();if(is_file($file)&&!$rotate)throw new RuntimeException('Setup key file sudah ada. Gunakan --rotate-setup-key untuk mengganti.');$key='TC4-'.tc_random_token(36);$record=['sha256'=>hash('sha256',$key),'fingerprint'=>tc_secret_fingerprint($key),'createdAt'=>date(DATE_ATOM),'version'=>TAMASYA_CONFIGURATOR_VERSION];tc_write_atomic($file,json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",$rotate,0600);@chmod($file,0600);return ['key'=>$key,'fingerprint'=>$record['fingerprint'],'file'=>$file,'rotated'=>$rotate];
}
function tc_key_valid(string $incoming): bool {
    if(!tc_secret_min($incoming))return false;$hashes=[];if(TAMASYA_CONFIGURATOR_SETUP_KEY_SHA256!=='')$hashes[]=TAMASYA_CONFIGURATOR_SETUP_KEY_SHA256;$record=tc_setup_key_record();if(isset($record['sha256'])&&preg_match('/^[a-f0-9]{64}$/',(string)$record['sha256']))$hashes[]=(string)$record['sha256'];$process=(string)(getenv('TAMASYA_CONFIGURATOR_SETUP_KEY')?:'');if(tc_secret_min($process))$hashes[]=hash('sha256',$process);$dot=tc_parse_dotenv_file(__DIR__.DIRECTORY_SEPARATOR.'.env');if(isset($dot['TAMASYA_CONFIGURATOR_SETUP_KEY'])&&tc_secret_min((string)$dot['TAMASYA_CONFIGURATOR_SETUP_KEY']))$hashes[]=hash('sha256',(string)$dot['TAMASYA_CONFIGURATOR_SETUP_KEY']);$incomingHash=hash('sha256',$incoming);foreach(array_unique($hashes) as $h)if(hash_equals((string)$h,$incomingHash))return true;return false;
}
function tc_https(): bool {
    if(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')return true;if((string)($_SERVER['SERVER_PORT']??'')==='443')return true;$xfp=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''));$remote=(string)($_SERVER['REMOTE_ADDR']??'');$trust=(string)(getenv('TAMASYA_CONFIGURATOR_TRUST_PROXY')?:'')==='1';if($xfp==='https'&&($trust||($remote!==''&&tc_private_host($remote))))return true;return false;
}
function tc_web_host_private(): bool {
    $host=(string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??'');$host=preg_replace('/:\\d+$/','',$host)??$host;$remote=(string)($_SERVER['REMOTE_ADDR']??'');return tc_private_host($host)&&($remote===''||tc_private_host($remote));
}
function tc_ui_script(): string {
    return <<<'JS'
(function(){
  function q(sel, root){ return (root || document).querySelector(sel); }
  function qa(sel, root){ return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function textValue(form, name){ var el=q('[name="'+name+'"]',form); return el ? String(el.value || '').trim() : ''; }
  function initWizard(){
    var form=q('form[data-wizard="1"]');
    if(!form) return;
    var steps=qa('.wizard-step',form);
    var pills=qa('.step-pill',form);
    var progress=q('.progress-fill',form);
    var progressText=q('.progress-text',form);
    var requestedStep=Number(form.getAttribute('data-initial-step') || '1');
    if(!Number.isFinite(requestedStep)) requestedStep=1;
    requestedStep=Math.max(1,Math.min(steps.length,requestedStep));
    var current=requestedStep;
    var maxVisited=form.getAttribute('data-has-errors')==='1' ? steps.length : requestedStep;

    function updateNodeVisibility(){
      var mode=textValue(form,'mode');
      qa('[data-node-card]',form).forEach(function(card){
        var node=card.getAttribute('data-node-card');
        card.hidden=(mode==='single_online' && node==='local') || (mode==='single_local' && node==='online');
      });
      var primary=q('[name="initial_primary"]',form);
      if(primary){
        if(mode==='single_online') primary.value='online';
        if(mode==='single_local') primary.value='local';
      }
      var note=q('[data-mode-note]',form);
      if(note){
        if(mode==='single_online') note.textContent='Single Online: hanya konfigurasi Hosting/Online yang dipakai. Bagian Local disembunyikan agar lebih sederhana.';
        else if(mode==='single_local') note.textContent='Single Local: hanya konfigurasi Local yang dipakai. Bagian Hosting/Online disembunyikan.';
        else note.textContent='Dual: Local dan Hosting sama-sama dikonfigurasi. Pastikan hanya satu node menjadi PRIMARY.';
      }
      refreshReview();
    }

    function validateStep(n){
      var step=q('.wizard-step[data-step="'+n+'"]',form);
      if(!step) return true;
      var fields=qa('input,select,textarea',step);
      for(var i=0;i<fields.length;i++){
        var el=fields[i];
        if(el.disabled || el.type==='hidden' || el.offsetParent===null) continue;
        if(!el.checkValidity()){
          el.reportValidity();
          try{ el.focus({preventScroll:true}); }catch(e){ el.focus(); }
          el.scrollIntoView({behavior:'smooth',block:'center'});
          return false;
        }
      }
      return true;
    }

    function showStep(n, scroll){
      n=Math.max(1,Math.min(steps.length,n));
      current=n;
      maxVisited=Math.max(maxVisited,n);
      steps.forEach(function(step){
        var active=Number(step.getAttribute('data-step'))===n;
        step.hidden=!active;
        step.classList.toggle('active',active);
      });
      pills.forEach(function(pill){
        var pn=Number(pill.getAttribute('data-step-pill'));
        pill.classList.toggle('active',pn===n);
        pill.classList.toggle('done',pn<n);
        pill.disabled=pn>maxVisited;
        pill.setAttribute('aria-current',pn===n?'step':'false');
      });
      if(progress) progress.style.width=((n/steps.length)*100)+'%';
      if(progressText) progressText.textContent='Tahap '+n+' dari '+steps.length;
      refreshReview();
      if(scroll!==false){
        var top=form.getBoundingClientRect().top+window.pageYOffset-16;
        window.scrollTo({top:Math.max(0,top),behavior:'smooth'});
      }
    }

    function addReviewRow(box,label,value){
      var row=document.createElement('div'); row.className='review-row';
      var k=document.createElement('div'); k.className='review-key'; k.textContent=label;
      var v=document.createElement('div'); v.className='review-value'; v.textContent=value || '—';
      row.appendChild(k); row.appendChild(v); box.appendChild(row);
    }
    function refreshReview(){
      var box=q('[data-review-box]',form); if(!box) return;
      box.textContent='';
      var mode=textValue(form,'mode');
      var modeLabel=mode==='single_online'?'Single Online':(mode==='single_local'?'Single Local':'Dual Local + Hosting');
      addReviewRow(box,'Deployment',modeLabel);
      addReviewRow(box,'Initial Primary',textValue(form,'initial_primary')==='online'?'Hosting / Online':'Local');
      addReviewRow(box,'Fresh Install',q('[name="fresh_install"][type="checkbox"]',form) && q('[name="fresh_install"][type="checkbox"]',form).checked ? 'YA — database baru/kosong' : 'TIDAK');
      addReviewRow(box,'Property',textValue(form,'property_name')+' ('+textValue(form,'property_id')+')');
      addReviewRow(box,'Admin/API Online',textValue(form,'online_url'));
      addReviewRow(box,'Website Publik',textValue(form,'public_url'));
      if(mode!=='single_online'){
        addReviewRow(box,'DB Local',textValue(form,'local_db_name'));
        addReviewRow(box,'Credential Local',textValue(form,'local_credential_path'));
      }
      if(mode!=='single_local'){
        addReviewRow(box,'DB Online',textValue(form,'online_db_name'));
        addReviewRow(box,'Credential Online',textValue(form,'online_credential_path'));
      }
      var apply=textValue(form,'apply_target');
      addReviewRow(box,'Apply ke server ini',apply==='none'?'Tidak — hanya generate bundle':(apply==='online'?'ENV Online':'ENV Local'));
    }

    form.addEventListener('click',function(ev){
      var next=ev.target.closest('[data-next-step]');
      if(next){ ev.preventDefault(); if(validateStep(current)) showStep(current+1,true); return; }
      var prev=ev.target.closest('[data-prev-step]');
      if(prev){ ev.preventDefault(); showStep(current-1,true); return; }
      var pill=ev.target.closest('[data-step-pill]');
      if(pill){
        ev.preventDefault();
        var target=Number(pill.getAttribute('data-step-pill'));
        if(target<=maxVisited) showStep(target,true);
      }
    });
    form.addEventListener('change',function(){ updateNodeVisibility(); });
    form.addEventListener('input',refreshReview);
    updateNodeVisibility();
    showStep(requestedStep,false);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initWizard); else initWizard();
})();
JS;
}
function tc_web_headers(): void {
    $scriptHash=base64_encode(hash('sha256',tc_ui_script(),true));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if(tc_https())header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'sha256-".$scriptHash."'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=UTF-8');
}
function tc_session_start(): void { if(session_status()===PHP_SESSION_ACTIVE)return;session_set_cookie_params(['httponly'=>true,'secure'=>tc_https(),'samesite'=>'Strict']);session_start(); }
function tc_web_gate(): void {
    tc_web_headers(); $lock=__DIR__.DIRECTORY_SEPARATOR.TAMASYA_CONFIGURATOR_LOCK_FILE; if(is_file($lock)){http_response_code(404);echo '<!doctype html><meta charset="utf-8"><h1>404 Not Found</h1>';exit;}
    if(!tc_https()&&!tc_web_host_private()){http_response_code(403);echo '<!doctype html><meta charset="utf-8"><h1>HTTPS required</h1><p>Configurator web hanya menerima HTTPS pada host publik. HTTP hanya untuk localhost/private LAN.</p>';exit;}
    tc_session_start();
}
function tc_bootstrap_csrf(): string { if(empty($_SESSION['tc_bootstrap_csrf']))$_SESSION['tc_bootstrap_csrf']=bin2hex(random_bytes(24));return (string)$_SESSION['tc_bootstrap_csrf']; }
function tc_bootstrap_csrf_ok(): bool { return isset($_SESSION['tc_bootstrap_csrf'])&&hash_equals((string)$_SESSION['tc_bootstrap_csrf'],(string)($_POST['bootstrap_csrf']??'')); }
function tc_auth_post(): void {
    $now=time();$key=(string)($_POST['setup_key']??'');if($key===''&&tc_session_auth()){if(!tc_csrf_ok()){http_response_code(403);throw new RuntimeException('CSRF session tidak valid.');}$_SESSION['tc_authorized_until']=$now+1800;return;}$blocked=(int)($_SESSION['tc_auth_blocked_until']??0);if($blocked>$now){http_response_code(429);throw new RuntimeException('Terlalu banyak percobaan setup key. Coba lagi setelah '.($blocked-$now).' detik.');}if(!tc_key_valid($key)){$fails=(int)($_SESSION['tc_auth_failures']??0)+1;$_SESSION['tc_auth_failures']=$fails;if($fails>=5)$_SESSION['tc_auth_blocked_until']=$now+min(300,30*($fails-4));http_response_code(403);throw new RuntimeException('Setup key salah.');}session_regenerate_id(true);unset($_SESSION['tc_auth_failures'],$_SESSION['tc_auth_blocked_until']);$_SESSION['tc_authorized_until']=$now+1800;$_SESSION['tc_csrf']=bin2hex(random_bytes(24));
}
function tc_session_auth(): bool { return isset($_SESSION['tc_authorized_until'],$_SESSION['tc_csrf']) && (int)$_SESSION['tc_authorized_until']>=time(); }
function tc_csrf_ok(): bool { return tc_session_auth() && hash_equals((string)$_SESSION['tc_csrf'],(string)($_POST['csrf']??'')); }
function tc_render(string $title,string $body,int $status=200): void {
    http_response_code($status);
    $css=<<<'CSS'
:root{--bg:#f5f7fb;--card:#fff;--text:#172033;--muted:#667085;--line:#d9deea;--brand:#2457d6;--brand-soft:#eef3ff;--ok:#eaf8ef;--ok-line:#9bd6ad;--warn:#fff7df;--warn-line:#e7c96a;--err:#fff0f0;--err-line:#e6a4a4;--shadow:0 8px 28px rgba(24,39,75,.08)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:var(--bg);color:var(--text);max-width:1180px;margin:0 auto;padding:26px 18px 56px;line-height:1.5}h1{font-size:clamp(24px,3vw,34px);line-height:1.15;margin:0 0 12px}h2{margin-top:28px}p{margin:8px 0 12px}a{color:var(--brand)}code{word-break:break-word}pre{white-space:pre-wrap;word-break:break-word;background:#111827;color:#f8fafc;padding:14px;border-radius:10px;overflow:auto}
fieldset{border:1px solid var(--line);border-radius:14px;padding:18px;margin:0;background:var(--card);box-shadow:var(--shadow)}legend{font-weight:800;padding:0 8px;color:#1d2b4f}.section-help{color:var(--muted);margin:0 0 16px;padding:10px 12px;background:#f8fafc;border-left:4px solid #b7c6ea;border-radius:8px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 20px}.field{min-width:0}.field label,.select-field label{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 6px;font-weight:700;color:#25324b}.field input,.select-field select{width:100%;min-height:44px;border:1px solid #b9c2d3;border-radius:9px;background:#fff;color:var(--text);padding:10px 12px;font:inherit;outline:none;transition:border-color .15s,box-shadow .15s}.field input:focus,.select-field select:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(36,87,214,.13)}.field input::placeholder{color:#98a2b3}.help,.muted{color:var(--muted);font-size:.91rem;margin-top:5px}.req,.opt{font-size:.72rem;font-weight:800;padding:2px 7px;border-radius:99px;white-space:nowrap}.req{color:#9b1c1c;background:#fee2e2}.opt{color:#475467;background:#eef2f6}.checkbox-card{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--line);border-radius:10px;background:#fafbfe;margin-top:12px}.checkbox-card input{margin-top:4px;transform:scale(1.12)}.checkbox-card strong{display:block}.checkbox-card small{display:block;color:var(--muted);margin-top:3px}.server-card{border:1px solid #cfd6e5;border-radius:12px;padding:16px;background:#fbfcff;margin-top:14px}.server-card h3{margin:0 0 6px;font-size:1.05rem}.server-card[hidden]{display:none!important}
.ok,.err,.warn,.info{padding:13px 15px;border-radius:10px;margin:12px 0;border:1px solid}.ok{background:var(--ok);border-color:var(--ok-line)}.err{background:var(--err);border-color:var(--err-line)}.warn{background:var(--warn);border-color:var(--warn-line)}.info{background:var(--brand-soft);border-color:#b9c9f5}.step-shell{margin-top:18px}.wizard-head{position:sticky;top:0;z-index:10;background:rgba(245,247,251,.96);backdrop-filter:blur(8px);padding:12px 0 10px;margin-bottom:14px;border-bottom:1px solid #e3e7ef}.progress-row{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:8px}.progress-text{font-weight:800;font-size:.92rem}.progress-track{height:7px;background:#e3e8f2;border-radius:99px;overflow:hidden}.progress-fill{height:100%;width:16.666%;background:var(--brand);transition:width .2s ease}.step-pills{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:7px;margin-top:11px}.step-pill{border:1px solid #d3d9e6;background:#fff;color:#4b5565;border-radius:9px;padding:8px 6px;font:inherit;font-size:.8rem;cursor:pointer;min-height:42px}.step-pill.active{background:var(--brand);border-color:var(--brand);color:#fff;font-weight:800}.step-pill.done{background:#eef7f0;border-color:#acd3b6;color:#245b32}.step-pill:disabled{opacity:.45;cursor:not-allowed}.wizard-step{animation:fadeIn .16s ease}.wizard-step[hidden]{display:none!important}@keyframes fadeIn{from{opacity:.4;transform:translateY(4px)}to{opacity:1;transform:none}}.stage-title{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px}.stage-num{display:grid;place-items:center;min-width:34px;height:34px;border-radius:50%;background:var(--brand);color:#fff;font-weight:900}.stage-title h2{font-size:1.2rem;margin:0}.stage-title p{color:var(--muted);margin:2px 0 0}.wizard-actions{display:flex;justify-content:space-between;gap:12px;margin-top:16px;padding:14px 0 4px}.wizard-actions .right{margin-left:auto}.btn,button{font:inherit;cursor:pointer;border-radius:9px;padding:10px 15px;border:1px solid #b9c2d3;background:#fff;color:#24324b;font-weight:700}.btn-primary,button[type=submit].primary{background:var(--brand);border-color:var(--brand);color:#fff}.btn:hover,button:hover{filter:brightness(.98)}.review-box{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff}.review-row{display:grid;grid-template-columns:minmax(150px,.38fr) 1fr;border-top:1px solid #e7eaf0}.review-row:first-child{border-top:0}.review-key{background:#f8fafc;font-weight:700;padding:10px 12px}.review-value{padding:10px 12px;word-break:break-word}.next-after-generate{border-left:4px solid var(--brand);padding:11px 13px;background:#f3f6ff;border-radius:8px;margin:12px 0}.form-note{font-size:.9rem;color:var(--muted)}
@media(max-width:820px){body{padding:16px 10px 40px}.grid{grid-template-columns:1fr}.step-pills{grid-template-columns:repeat(3,1fr)}.wizard-head{top:0}.review-row{grid-template-columns:1fr}.review-key{padding-bottom:3px}.review-value{padding-top:3px}.wizard-actions{position:sticky;bottom:0;background:rgba(245,247,251,.96);padding:10px 4px;z-index:8}}
@media(max-width:480px){.step-pills{grid-template-columns:repeat(2,1fr)}fieldset{padding:14px}.wizard-actions button{flex:1}.wizard-actions{gap:8px}}
CSS;
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.tc_h($title).'</title><style>'.$css.'</style></head><body><h1>'.tc_h($title).'</h1>'.$body.'<script>'.tc_ui_script().'</script></body></html>';exit;
}
function tc_input(string $name,string $label,array $v,string $type='text',bool $required=false,string $help=''): string {
    $val=(string)tc_array_get($v,$name,'');$req=$required?' required':'';$badge=$required?'<span class="req">WAJIB</span>':'';
    $html='<div class="field" data-field="'.tc_h($name).'"><label for="'.tc_h($name).'"><span>'.tc_h($label).'</span>'.$badge.'</label><input id="'.tc_h($name).'" name="'.tc_h($name).'" type="'.tc_h($type).'" value="'.($type==='password'?'':tc_h($val)).'"'.$req.' autocomplete="off">';
    if($help!=='')$html.='<div class="help">'.tc_h($help).'</div>';
    return $html.'</div>';
}
function tc_select_field(string $name,string $label,array $options,string $selected,string $help='',bool $required=true): string {
    $badge=$required?'<span class="req">WAJIB</span>':'';$req=$required?' required':'';$html='<div class="select-field"><label for="'.tc_h($name).'"><span>'.tc_h($label).'</span>'.$badge.'</label><select id="'.tc_h($name).'" name="'.tc_h($name).'"'.$req.'>';
    foreach($options as $value=>$text)$html.='<option value="'.tc_h((string)$value).'"'.($selected===(string)$value?' selected':'').'>'.tc_h((string)$text).'</option>';
    $html.='</select>';if($help!=='')$html.='<div class="help">'.tc_h($help).'</div>';return $html.'</div>';
}
function tc_step_actions(int $step,int $total=6): string {
    $html='<div class="wizard-actions">';if($step>1)$html.='<button type="button" data-prev-step>&larr; Kembali</button>';if($step<$total)$html.='<button class="btn-primary right" type="button" data-next-step>Lanjut ke Tahap '.($step+1).' &rarr;</button>';return $html.'</div>';
}

function tc_web_login(array $errors=[],int $status=200): void {
    $ks=tc_setup_key_status();$errorHtml='';if($errors)$errorHtml='<div class="err"><strong>Login configurator gagal.</strong><ul><li>'.implode('</li><li>',array_map('tc_h',$errors)).'</li></ul></div>';
    $bootstrap='';if(tc_web_host_private()&&!$ks['keyFileActive']){$bootstrap='<form method="post"><input type="hidden" name="action" value="bootstrap_setup_key"><input type="hidden" name="bootstrap_csrf" value="'.tc_h(tc_bootstrap_csrf()).'"><button type="submit">GENERATE SETUP KEY BARU (LOCAL/PRIVATE)</button></form>';}
    $help=$ks['keyFileActive']?'Masukkan TC4 yang sudah Anda simpan. Fingerprint aktif: '.($ks['fingerprint']??'-').'.':'Shared hosting: buka generate_setup_key.php, ikuti File Manager Owner-Proof, lalu simpan TC4. VPS/SSH: php tamasya_configurator.php --generate-setup-key.';
    $body='<div class="ok"><strong>UI BUILD:</strong> <code>'.tc_h(TAMASYA_CONFIGURATOR_UI_BUILD).'</code></div>'.$errorHtml.'<div class="info"><strong>Login configurator terpisah dari wizard.</strong> Setelah TC4 benar, server akan membaca kondisi setup aktif dan otomatis membuka tahap yang tepat. Refresh tidak lagi mengembalikan setup ke Tahap 1 selama konfigurasi aktif sudah ada.</div>'.$bootstrap;
    $body.='<form method="post" autocomplete="off"><input type="hidden" name="action" value="login"><fieldset><legend>Buka Configurator</legend><div class="grid">'.tc_input('setup_key','Setup Key TC4',[],'password',true,$help).'</div><div class="wizard-actions"><button class="primary right" type="submit">MASUK / BUKA STATUS SETUP</button></div></fieldset></form>';
    tc_render('TAMASYA Configurator Login',$body,$status);
}

function tc_web_form(array $v,array $errors=[],int $status=200,int $initialStep=1,array $state=[]): void {
    $caps=tc_capabilities();$errorHtml='';if($errors)$errorHtml='<div class="err"><strong>Ada yang perlu diperbaiki.</strong><ul><li>'.implode('</li><li>',array_map('tc_h',$errors)).'</li></ul><div class="form-note">Gunakan tombol tahap di atas untuk kembali ke bagian yang perlu diperbaiki. Nilai yang sudah diisi tetap dipertahankan.</div></div>';
    $capClass=$caps['php82Plus']&&$caps['openssl']&&$caps['pdoMysql']?'ok':'warn';
    $cap='<div class="'.$capClass.'"><strong>Status server:</strong> PHP '.tc_h($caps['phpVersion']).' &nbsp;|&nbsp; pdo_mysql '.($caps['pdoMysql']?'OK':'TIDAK ADA').' &nbsp;|&nbsp; cURL '.($caps['curl']?'OK':'TIDAK ADA').' &nbsp;|&nbsp; OpenSSL '.($caps['openssl']?'OK':'TIDAK ADA').'.'.(!$caps['pdoMysql']?'<br><strong>STOP:</strong> Fresh commissioning membutuhkan pdo_mysql. Aktifkan dulu dari PHP Selector/cPanel.':'').'</div>';
    $mode=tc_value($v,'mode','dual');$primary=tc_value($v,'initial_primary','local');$alreadyAuth=tc_session_auth();
    $authHidden=$alreadyAuth?'<input type="hidden" name="csrf" value="'.tc_h((string)$_SESSION['tc_csrf']).'">':'';
    $stateHtml='';if($state){$sev=(string)($state['severity']??'info');$cls=in_array($sev,['ok','warn','err','info'],true)?$sev:'info';$stateHtml='<div class="'.$cls.'"><strong>STATUS SETUP SERVER: '.tc_h(strtoupper((string)($state['code']??'unknown'))).'</strong><br>'.tc_h((string)($state['message']??''));$noise=(array)($state['adoption']['details']['ignoredPreBootstrapNoiseRows']??[]);if($noise){$parts=[];foreach($noise as $t=>$c)$parts[]=tc_h((string)$t).'='.tc_h((string)$c);$stateHtml.='<br><small><strong>Telemetry/infrastruktur pre-bootstrap (tidak memblokir):</strong> '.implode(', ',$parts).'</small>';}$stateHtml.='</div>';if(in_array((string)($state['code']??''),['db_empty','commission_resume','fresh_canonical_unbootstrapped'],true)){$stateHtml.='<form method="post"><input type="hidden" name="action" value="fresh_commission"><input type="hidden" name="csrf" value="'.tc_h((string)$_SESSION['tc_csrf']).'"><label class="checkbox-card"><input type="checkbox" name="confirm_fresh" value="FRESH_EMPTY_DB" required><span><strong>Konfirmasi Fresh Commissioning</strong><small>Saya memastikan ini database fresh yang dibuat untuk property ini dan recovery secret sudah disimpan.</small></span></label><button type="submit" class="primary">LANJUTKAN / RESUME FRESH COMMISSIONING</button></form>';}if((string)($state['code']??'')==='commissioned'){$recovery=tc_first_admin_recovery_status(__DIR__);if(!empty($recovery['eligible'])){$stateHtml.='<div class="warn"><strong>Belum pernah login admin pertama?</strong> Jika recovery sheet sudah tidak cocok akibat Generate berulang saat commissioning, gunakan recovery satu kali ini. Fitur otomatis hilang setelah login sukses pertama.</div><form method="post"><input type="hidden" name="action" value="recover_first_admin"><input type="hidden" name="csrf" value="'.tc_h((string)$_SESSION['tc_csrf']).'"><label class="checkbox-card"><input type="checkbox" name="confirm_admin_recovery" value="RESET_FIRST_ADMIN" required><span><strong>Reset password admin bootstrap satu kali</strong><small>Membuat password random baru, membersihkan lock/rate-limit login, dan tetap mewajibkan ganti password setelah login.</small></span></label><button type="submit" class="primary">BUAT PASSWORD LOGIN ADMIN BARU</button></form>';}}}
    $body='<div class="ok"><strong>UI BUILD:</strong> <code>'.tc_h(TAMASYA_CONFIGURATOR_UI_BUILD).'</code></div><p class="info"><strong>State-driven wizard:</strong> tahap aktif ditentukan oleh kondisi server, bukan oleh refresh/session browser. ENV/credential/key/database tetap memakai engine PROD4 yang sama.</p>'.$cap.$stateHtml.$errorHtml;
    $body.='<form method="post" autocomplete="off" data-wizard="1" data-has-errors="'.($errors?'1':'0').'" data-initial-step="'.max(1,min(6,$initialStep)).'"><input type="hidden" name="action" value="generate">'.$authHidden;
    $body.='<div class="wizard-head"><div class="progress-row"><span class="progress-text">Tahap 1 dari 6</span><span class="muted">Selesaikan tahap aktif lalu klik Lanjut</span></div><div class="progress-track"><div class="progress-fill"></div></div><div class="step-pills">';
    foreach([1=>'Akses & Mode',2=>'Identitas Hotel',3=>'URL & Cluster',4=>'Database',5=>'Apply',6=>'Periksa & Generate'] as $n=>$label)$body.='<button type="button" class="step-pill'.($n===1?' active':'').'" data-step-pill="'.$n.'"'.($n>1?' disabled':'').'>'.$n.'. '.tc_h($label).'</button>';
    $body.='</div></div><div class="step-shell">';

    $body.='<section class="wizard-step active" data-step="1"><div class="stage-title"><span class="stage-num">1</span><div><h2>Mode Deployment</h2><p>TC4 sudah diverifikasi pada layar login. Tahap ini hanya menentukan cara server digunakan.</p></div></div><fieldset><legend>Session Configurator</legend><div class="ok"><strong>Setup Key terverifikasi.</strong> Session browser aktif. Jika session habis, Anda cukup login TC4 lagi; configurator akan membaca status server dan kembali ke tahap yang sesuai.</div></fieldset><fieldset style="margin-top:14px"><legend>Mode Server</legend><div class="grid">'.tc_select_field('mode','Deployment',['single_online'=>'Single Online — hanya hosting/public server','single_local'=>'Single Local — hanya server LAN/local','dual'=>'Dual — Local + Hosting'],$mode,'Shared hosting biasa: pilih Single Online.').tc_select_field('initial_primary','Initial Primary',['online'=>'Hosting / Online menjadi PRIMARY','local'=>'Local menjadi PRIMARY'],$primary,'Pada Single Online otomatis diarahkan ke Online; pada Single Local ke Local.').'</div><div class="info" data-mode-note></div><input type="hidden" name="fresh_install" value="0"><label class="checkbox-card"><input type="checkbox" name="fresh_install" value="1"'.(tc_checkbox($v,'fresh_install',true)?' checked':'').'><span><strong>Fresh Install</strong><small>Centang hanya untuk server + database BARU/KOSONG. Configurator akan membuat bootstrap admin password pada initial PRIMARY dan nanti menyelesaikan schema + first install.</small></span></label></fieldset>'.tc_step_actions(1).'</section>';

    $body.='<section class="wizard-step" data-step="2" hidden><div class="stage-title"><span class="stage-num">2</span><div><h2>Identitas Hotel</h2><p>Isi identitas permanen property. Nilai ini akan ikut masuk ke ENV dan database.</p></div></div><fieldset><legend>Identitas Property</legend><div class="section-help"><strong>Tip:</strong> Property ID dan Property Code jangan sering diganti setelah hotel mulai production. Gunakan kode yang singkat dan unik.</div><div class="grid">'.
        tc_input('timezone','Timezone IANA',$v,'text',true,'Contoh Indonesia Timur/Bali: Asia/Makassar. WIB: Asia/Jakarta.').
        tc_input('property_id','Property ID',$v,'text',true,'ID internal permanen. Contoh: hotel-makassar-01. Huruf kecil + angka + tanda - direkomendasikan.').
        tc_input('property_code','Property Code',$v,'text',true,'Kode singkat property untuk identifikasi. Contoh: MKS01.').
        tc_input('property_name','Nama Hotel',$v,'text',true,'Nama hotel yang akan tampil di aplikasi.').
        tc_input('invoice_prefix','Invoice Prefix',$v,'text',false,'Kosong = otomatis memakai Property Code. Contoh: MKS01.').
        tc_input('company_id','Company ID',$v,'text',false,'Kosongkan untuk hotel independen. Isi ID group/company yang sama jika hotel merupakan cabang.').
        tc_input('company_name','Company Name',$v,'text',false,'Nama perusahaan/group. Boleh kosong untuk hotel independen.').
        tc_input('currency','Currency',$v,'text',true,'Indonesia: IDR.').
        tc_input('country','Country',$v,'text',true,'Kode negara ISO. Indonesia: ID.').
        tc_input('locale','Locale',$v,'text',true,'Format bahasa/angka. Indonesia: id-ID.').
    '</div></fieldset>'.tc_step_actions(2).'</section>';

    $body.='<section class="wizard-step" data-step="3" hidden><div class="stage-title"><span class="stage-num">3</span><div><h2>URL, Node & Secret Existing</h2><p>Masukkan alamat aplikasi. URL Online/Public harus berupa origin tanpa path file.</p></div></div><fieldset><legend>URL & Cluster</legend><div class="section-help"><strong>Benar:</strong> https://app.domain.com &nbsp; <strong>Salah:</strong> https://domain.com/admin_app. Document root subdomain Admin sebaiknya langsung menunjuk folder admin_app.</div><div class="grid">'.
        tc_input('online_url','URL Admin/API Online',$v,'url',true,'Contoh: https://app.hotelanda.com — tanpa /admin_app dan tanpa nama file.').
        tc_input('public_url','URL Website Publik',$v,'url',true,'Contoh: https://www.hotelanda.com atau https://hotelanda.com.').
        tc_input('local_url','URL App Local/LAN',$v,'text',true,'Untuk Single Online nilai ini tidak menjadi node aktif. Untuk Local/Dual contoh: http://192.168.1.10.').
        tc_input('cluster_id','Cluster ID',$v,'text',false,'Fresh deployment: kosongkan agar configurator membuat otomatis. Dual node property yang sama harus memakai Cluster ID yang sama.').
        tc_input('local_node_id','Local Node ID',$v,'text',false,'Fresh: kosongkan = otomatis. Pada Dual harus berbeda dengan Hosting Node ID.').
        tc_input('online_node_id','Hosting Node ID',$v,'text',false,'Fresh: kosongkan = otomatis. Pada Dual harus berbeda dengan Local Node ID.').
        tc_input('existing_encryption_key','APP_ENCRYPTION_KEY existing',$v,'password',false,'DATABASE FRESH: kosongkan. DATABASE EXISTING: key lama wajib dipertahankan.').
        tc_input('existing_sync_secret','NODE_SYNC_SHARED_SECRET existing',$v,'password',false,'Deployment fresh: kosongkan. Cluster existing: pertahankan secret lama.').
    '</div><input type="hidden" name="preserve_existing_env_secrets" value="0"><label class="checkbox-card"><input type="checkbox" name="preserve_existing_env_secrets" value="1"'.(tc_checkbox($v,'preserve_existing_env_secrets',true)?' checked':'').'><span><strong>Auto-preserve secret existing</strong><small>Biarkan ON. Jika .env existing cocok dengan property/node, configurator mempertahankan secret lama agar tidak terjadi key mismatch.</small></span></label><input type="hidden" name="include_secret_inventory" value="0"><label class="checkbox-card"><input type="checkbox" name="include_secret_inventory" value="1"'.(tc_checkbox($v,'include_secret_inventory',true)?' checked':'').'><span><strong>Sertakan SECRETS_SAVE_ONCE.txt</strong><small>Sangat direkomendasikan. Simpan file hasilnya di password manager/vault dan jangan upload ke public_html.</small></span></label></fieldset>'.tc_step_actions(3).'</section>';

    $body.='<section class="wizard-step" data-step="4" hidden><div class="stage-title"><span class="stage-num">4</span><div><h2>Database & Penyimpanan Private</h2><p>Isi hanya node yang dipakai. Wizard menyembunyikan node yang tidak relevan untuk Single Online/Single Local.</p></div></div>'.($initialStep===4?'<div class="warn"><strong>Mode Perbaikan:</strong> nilai non-secret dari Generate terakhir dipulihkan. Demi keamanan password database tidak disimpan untuk form; <strong>masukkan ulang DB Password</strong>, lalu lanjutkan ke Apply dan VALIDASI + GENERATE.</div>':'');
    foreach(['local'=>'LOCAL SERVER','online'=>'HOSTING / ONLINE SERVER'] as $node=>$legend){$body.='<div class="server-card" data-node-card="'.$node.'"><h3>'.$legend.'</h3><div class="section-help"><strong>Untuk node aktif:</strong> DB Host, Port, Name, User, Password, Credential Path dan Backup Dir wajib diisi. Trusted proxies boleh kosong. Credential dan backup wajib berada di luar document root/public_html.</div><div class="grid">'.
        tc_input($node.'_db_host','DB Host',$v,'text',false,$node==='online'?'cPanel umumnya localhost. Gunakan host provider jika berbeda.':'Biasanya localhost atau IP DB server Local.').
        tc_input($node.'_db_port','DB Port',$v,'number',false,'MySQL/MariaDB umumnya 3306.').
        tc_input($node.'_db_name','DB Name',$v,'text',false,'Nama database harus persis. Pada cPanel sering memiliki prefix username.').
        tc_input($node.'_db_user','DB User',$v,'text',false,'User harus sudah di-assign ke database dengan privilege instalasi yang diperlukan.').
        tc_input($node.'_db_pass','DB Password',$v,'password',false,'Password MySQL/MariaDB. Tidak ditampilkan kembali di browser.').
        tc_input($node.'_credential_path','Private credential absolute path',$v,'text',false,'Contoh cPanel: /home/USERNAME/tamasya-private/hotel01/'.$node.'/db-credentials.php').
        tc_input($node.'_backup_dir','Private backup absolute dir',$v,'text',false,'Contoh: /home/USERNAME/tamasya-private/hotel01/'.$node.'/backups').
        tc_input($node.'_trusted_proxies','Trusted proxies',$v,'text',false,'Kosongkan bila tidak ada reverse proxy. Jangan isi IP sembarangan.').
    '</div></div>';}
    $body.=tc_step_actions(4).'</section>';

    $body.='<section class="wizard-step" data-step="5" hidden><div class="stage-title"><span class="stage-num">5</span><div><h2>Apply ke Server Ini</h2><p>Tentukan apakah hasil ENV/config langsung dipasang ke server tempat configurator sedang berjalan.</p></div></div><fieldset><legend>Target Apply</legend><div class="grid">'.
        tc_input('admin_root','Admin App Root',$v,'text',true,'Absolute path folder admin_app saat ini. Biasanya terdeteksi otomatis.').
        tc_input('public_site_root','Public Site Root',$v,'text',false,'Absolute path folder public_site bila berada pada server yang sama.').
        tc_select_field('apply_target','Apply ENV target',['none'=>'Jangan apply — hanya generate bundle','local'=>'Apply ENV Local ke server ini','online'=>'Apply ENV Online ke server ini'],tc_value($v,'apply_target','none'),'Single Online: pilih Apply ENV Online. Fresh Commissioning memerlukan .env aktif hasil Apply.').
    '</div><input type="hidden" name="apply_public" value="0"><label class="checkbox-card"><input type="checkbox" name="apply_public" value="1"'.(tc_checkbox($v,'apply_public',false)?' checked':'').'><span><strong>Apply public-site runtime</strong><small>ON hanya jika Public Site Root benar. Configurator dapat memasang runtime config, robots, sitemap dan .htaccess.</small></span></label><input type="hidden" name="lock_after_apply" value="0"><label class="checkbox-card"><input type="checkbox" name="lock_after_apply" value="1"'.(tc_checkbox($v,'lock_after_apply',true)?' checked':'').'><span><strong>Lock browser configurator setelah commissioning</strong><small>Direkomendasikan ON. Pada Fresh Primary, configurator tetap memberi kesempatan Fresh Commissioning sebelum lock final.</small></span></label><div class="warn"><strong>Safety:</strong> file existing dibackup dengan suffix timestamp sebelum overwrite. Credential DB ditulis ke private path yang Anda tentukan.</div></fieldset>'.tc_step_actions(5).'</section>';

    $body.='<section class="wizard-step" data-step="6" hidden><div class="stage-title"><span class="stage-num">6</span><div><h2>Periksa & Generate</h2><p>Pastikan ringkasan ini benar. Password dan secret sengaja tidak pernah ditampilkan pada ringkasan.</p></div></div><div class="review-box" data-review-box></div><div class="next-after-generate"><strong>Setelah tombol Generate:</strong> download ZIP + SECRETS_SAVE_ONCE terlebih dahulu. Jika Fresh Install + Apply ke PRIMARY berhasil, halaman berikutnya akan menampilkan tombol <strong>LANJUTKAN FRESH COMMISSIONING</strong>.</div><div class="warn"><strong>Database Fresh:</strong> database target harus benar-benar kosong. Jangan import database_setup.sql manual jika Anda akan memakai Unified Fresh Commissioning.</div><div class="wizard-actions"><button type="button" data-prev-step>&larr; Kembali</button><button class="primary right" type="submit">VALIDASI + GENERATE</button></div></section>';
    $body.='</div></form><p class="form-note">Alternatif CLI/VPS: <code>php tamasya_configurator.php</code>. UI browser ini hanya mengubah tampilan dan alur pengisian; engine ENV/credential/database tetap sama.</p>';
    tc_render('TAMASYA Unified Configurator '.TAMASYA_CONFIGURATOR_VERSION,$body,$status);
}
function tc_web(): void {
    tc_web_gate(); $action=(string)($_POST['action']??'form');
    if($action==='bootstrap_setup_key'){if(!tc_web_host_private())tc_render('Forbidden','<div class="err">Generate setup key dari browser hanya diizinkan pada localhost/private LAN.</div>',403);if(!tc_bootstrap_csrf_ok())tc_render('Forbidden','<div class="err">Bootstrap CSRF tidak valid.</div>',403);try{$r=tc_generate_setup_key(false);}catch(Throwable $e){tc_render('Setup Key','<div class="err">'.tc_h($e->getMessage()).'</div>',409);}$body='<div class="ok"><strong>SETUP KEY BARU — SIMPAN SEKARANG.</strong><pre>'.tc_h($r['key']).'</pre><p>Fingerprint: <code>'.tc_h($r['fingerprint']).'</code></p></div><div class="warn">Plaintext key tidak disimpan oleh configurator. File runtime hanya menyimpan SHA-256. Simpan key di password manager.</div><p><a href="'.tc_h((string)($_SERVER['PHP_SELF']??'tamasya_configurator.php')).'">Kembali ke configurator</a></p>';unset($_SESSION['tc_bootstrap_csrf']);tc_render('TAMASYA Setup Key Generated',$body);}
    if($action==='login'){
        try{tc_auth_post();}catch(Throwable $e){tc_web_login([$e->getMessage()],403);}
        header('Location: '.(string)($_SERVER['PHP_SELF']??'tamasya_configurator.php'),true,303);exit;
    }
    if(!tc_session_auth())tc_web_login();
    if($action==='recover_first_admin'){
        if(!tc_csrf_ok())tc_render('Unauthorized','<div class="err">Session recovery admin tidak valid/expired.</div>',403);
        if((string)($_POST['confirm_admin_recovery']??'')!=='RESET_FIRST_ADMIN')tc_render('Recovery Admin Ditolak','<div class="err">Konfirmasi recovery admin pertama wajib.</div>',400);
        $state=tc_detect_active_setup_state(__DIR__);if((string)($state['code']??'')!=='commissioned')tc_render('Recovery Admin Ditolak','<div class="err">Server belum commissioned; recovery admin pertama tidak boleh digunakan.</div>',409);
        try{$r=tc_reset_first_admin_password(__DIR__);}catch(Throwable $e){tc_render('Recovery Admin Gagal','<div class="err">'.tc_h($e->getMessage()).'</div>',409);}
        $body='<div class="ok"><strong>PASSWORD ADMIN BARU — SIMPAN SEKARANG.</strong><p>Username: <code>'.tc_h((string)$r['username']).'</code></p><pre>'.tc_h((string)$r['password']).'</pre></div><div class="warn"><strong>Password ini hanya ditampilkan sekali.</strong> Login menggunakan password di atas. Setelah login sukses, aplikasi tetap meminta penggantian password. Recovery configurator otomatis tidak tersedia lagi setelah login sukses pertama.</div><p><a href="'.tc_h((string)($_SERVER['PHP_SELF']??'tamasya_configurator.php')).'">Kembali ke status configurator</a></p>';
        tc_render('TAMASYA First Admin Access Recovered',$body);
    }
    if($action==='lock'){
        if(!tc_csrf_ok())tc_render('Unauthorized','<div class="err">Session lock tidak valid/expired.</div>',403);
        $lockState=tc_detect_active_setup_state(__DIR__);
        if((string)($lockState['code']??'')!=='commissioned')tc_render('Lock Ditolak','<div class="err"><strong>LOCK DITOLAK.</strong> Server belum berstatus commissioned: '.tc_h((string)($lockState['message']??'unknown')).'</div><p>Selanjutnya login kembali dan selesaikan Fresh Commissioning. Lock hanya boleh setelah admin/property bootstrap selesai.</p>',409);
        $lock=__DIR__.DIRECTORY_SEPARATOR.TAMASYA_CONFIGURATOR_LOCK_FILE;
        if(file_put_contents($lock,"Locked ".date(DATE_ATOM)." after commissioning. Delete this file via File Manager or run --unlock in CLI to re-enable.\n",LOCK_EX)===false)tc_render('Lock gagal','<div class="err">Tidak dapat membuat lock file.</div>',500);
        $_SESSION=[]; if(session_status()===PHP_SESSION_ACTIVE)@session_destroy();
        tc_render('Configurator Locked','<div class="ok">Browser configurator sudah dikunci dan secret hasil generation di session sudah dibersihkan. Endpoint berikutnya akan 404 sampai lock file dihapus via File Manager atau CLI --unlock.</div>');
    }
    if($action==='finish'){
        if(!tc_csrf_ok())tc_render('Unauthorized','<div class="err">Session finish tidak valid/expired.</div>',403);
        $_SESSION=[]; if(session_status()===PHP_SESSION_ACTIVE)@session_destroy();
        tc_render('Configurator Finished','<div class="ok">Secret/output sementara di session browser sudah dihapus. Jika configurator tidak diperlukan lagi, hapus file PHP ini dari webroot atau gunakan LOCK setelah apply.</div>');
    }
    if($action==='download' || $action==='download_bundle'){
        if(!tc_csrf_ok()||!isset($_SESSION['tc_outputs'])||!is_array($_SESSION['tc_outputs']))tc_render('Unauthorized','<div class="err">Session download tidak valid/expired.</div>',403);
        $outputs=$_SESSION['tc_outputs'];
        if($action==='download_bundle'){$zip=tc_zip_store($outputs);header_remove('Content-Type');header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="TAMASYA_GENERATED_CONFIG_'.date('Ymd_His').'.zip"');header('Content-Length: '.strlen($zip));echo $zip;exit;}
        $name=(string)($_POST['file']??'');if(!isset($outputs[$name]))tc_render('Not Found','File generated tidak ditemukan.',404);$content=(string)$outputs[$name];header_remove('Content-Type');header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.basename($name).'"');header('Content-Length: '.strlen($content));echo $content;exit;
    }
    if($action==='edit_database'){
        if(!tc_csrf_ok())tc_render('Unauthorized','<div class="err">Session perbaikan database tidak valid/expired.</div>',403);
        $state=tc_detect_active_setup_state(__DIR__);$resume=tc_recovery_values_from_active(__DIR__);
        tc_web_form($resume,[],200,4,$state);
    }
    if($action==='fresh_commission'){
        if(!tc_csrf_ok())tc_render('Unauthorized','<div class="err">Session commissioning tidak valid/expired.</div>',403);
        if((string)($_POST['confirm_fresh']??'')!=='FRESH_EMPTY_DB')tc_render('Fresh Commissioning Ditolak','<div class="err">Konfirmasi database BARU/KOSONG wajib.</div>',400);
        $before=tc_detect_active_setup_state(__DIR__);$eligible=in_array((string)($before['code']??''),['db_empty','commission_resume','fresh_canonical_unbootstrapped'],true);
        if(!$eligible)tc_render('Fresh Commissioning Ditolak','<div class="err">State server saat ini tidak mengizinkan Fresh Commissioning: '.tc_h((string)($before['message']??'unknown')).'</div>',409);
        try{$result=tc_fresh_commission(__DIR__);}catch(Throwable $e){$state=tc_detect_active_setup_state(__DIR__);$resume=tc_recovery_values_from_active(__DIR__);tc_web_form($resume,['Fresh Commissioning STOP: '.$e->getMessage()],409,(int)($state['step']??4),$state);}
        if(isset($_SESSION['tc_commission'])&&is_array($_SESSION['tc_commission']))$_SESSION['tc_commission']['done']=true;$final=(array)($result['final']??[]);$import=(array)($result['canonicalImport']??[]);$first=(array)($result['firstInstall']??[]);$cleanup=(array)($result['bootstrapCleanup']??[]);
        $body='<div class="ok"><strong>FRESH SERVER COMMISSIONING PASS.</strong> ENV/credential tetap berasal dari generator configurator yang sama; canonical database dan bootstrap admin sekarang selesai.</div>';
        $body.='<ul><li>Database: <code>'.tc_h((string)($final['database']??'')).'</code></li><li>Canonical schema: '.(!empty($import['skipped'])?'sudah ada, divalidasi ulang':'di-import oleh configurator').' — '.tc_h((string)($import['baseTables']??107)).' table terdeteksi</li><li>First install: '.(!empty($first['performed'])?'dijalankan sekali':'sudah terinisialisasi, tidak diulang').'</li><li>Bootstrap password ENV: '.(!empty($cleanup['changed'])?'sudah dikosongkan otomatis':'sudah kosong').'</li><li>Admin aktif: '.tc_h((string)($final['adminCount']??0)).'</li><li>Property setup status: <code>'.tc_h((string)($final['setupStatus']??'unknown')).'</code></li><li>ADMIN_WEB_TOOLS_ENABLED: <code>'.tc_h((string)($final['adminWebToolsEnabled']??'?')).'</code></li><li>Backup dir private: <code>'.tc_h((string)($final['backupDir']??'')).'</code></li></ul>';
        if(empty($final['propertyReady']))$body.='<div class="warn"><strong>Server commissioning selesai, tetapi property belum READY.</strong> Login admin lalu selesaikan <code>property-setup.html</code> sebelum transaksi production.</div>';
        $body.='<h2>Langkah akhir</h2><p>Pastikan <code>DO_NOT_UPLOAD/SECRETS_SAVE_ONCE.txt</code> sudah disimpan privat. Setelah itu lock configurator.</p><form method="post"><input type="hidden" name="action" value="lock"><input type="hidden" name="csrf" value="'.tc_h((string)$_SESSION['tc_csrf']).'"><button type="submit">LOCK CONFIGURATOR SEKARANG</button></form>';
        tc_render('TAMASYA Fresh Commissioning PASS',$body);
    }
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        $state=tc_detect_active_setup_state(__DIR__);$values=(string)($state['code']??'new')==='new'?tc_defaults():tc_recovery_values_from_active(__DIR__);
        tc_web_form($values,[],200,(int)($state['step']??1),$state);
    }
    try{tc_auth_post();}catch(Throwable $e){tc_web_login([$e->getMessage()],403);}
    $check=tc_validate_input($_POST); if(!$check['ok'])tc_web_form($check['values'],$check['errors']); $v=$check['values'];
    try{$generated=tc_generate($v);$apply=tc_apply($v,$generated);}catch(Throwable $e){tc_web_form($v,[$e->getMessage()]);}
    $_SESSION['tc_outputs']=$generated['outputs'];$_SESSION['tc_values_summary']=['mode'=>$v['mode'],'property'=>$v['property_id']];$resumeValues=$v;foreach(['setup_key','local_db_pass','online_db_pass','existing_encryption_key','existing_sync_secret'] as $secretField)$resumeValues[$secretField]='';$_SESSION['tc_last_form_values']=$resumeValues;
    $activeRole='';if(!empty($apply['applied'])&&isset($generated['envs'][$apply['target']])){$activeParsed=tc_parse_dotenv_text((string)$generated['envs'][$apply['target']]);$activeRole=strtolower((string)($activeParsed['TAMASYA_NODE_INITIAL_ROLE']??''));}
    $_SESSION['tc_commission']=['fresh'=>!empty($v['fresh_install']),'applied'=>!empty($apply['applied']),'target'=>$apply['target']??null,'primary'=>$activeRole==='primary','adminRoot'=>(string)$v['admin_root'],'done'=>false];
    $probes=[];if($v['mode']!=='single_online')$probes['local']=tc_db_probe($v,'local');if($v['mode']!=='single_local')$probes['online']=tc_db_probe($v,'online');
    $csrf=(string)$_SESSION['tc_csrf'];$rows='';foreach(array_keys($generated['outputs']) as $name){$rows.='<li><code>'.tc_h($name).'</code> <form method="post" style="display:inline"><input type="hidden" name="action" value="download"><input type="hidden" name="csrf" value="'.tc_h($csrf).'"><input type="hidden" name="file" value="'.tc_h($name).'"><button type="submit">Download</button></form></li>';}
    $probeHtml='<ul>';foreach($probes as $node=>$p){$probeHtml.='<li><strong>'.strtoupper($node).':</strong> '.tc_h((string)$p['message']);if(isset($p['summary'])&&is_array($p['summary'])){$sm=$p['summary'];$probeHtml.='<br><small>Tables '.tc_h((string)($sm['actualTables']??'?')).'/'.tc_h((string)($sm['expectedTables']??'?')).' | PK expected '.tc_h((string)($sm['expectedPrimaryKeys']??'?')).' | UNIQUE expected '.tc_h((string)($sm['expectedUniqueIndexes']??'?')).' | Triggers '.tc_h((string)($sm['actualTriggers']??'?')).'/'.tc_h((string)($sm['expectedTriggers']??'?')).' | marker '.(!empty($sm['markerPresent'])?'PASS':'FAIL').'</small>';}if(!empty($p['details'])){$d=$p['details'];$brief=[];foreach(['missingTables','nonInnoDb','nonUtf8mb4','missingColumns','missingPrimaryKeys','primaryKeyMismatches','missingUniqueIndexes','uniqueIndexMismatches','missingTriggers','triggerSignatureMismatches'] as $k)if(!empty($d[$k]))$brief[]=$k.'='.count((array)$d[$k]);if($brief)$probeHtml.='<br><small>'.tc_h(implode(' | ',$brief)).'</small>';}$probeHtml.='</li>';}$probeHtml.='</ul>';
    $dbAllOk=count($probes)>0;foreach($probes as $p){if(empty($p['ok'])){$dbAllOk=false;break;}}
    $dbGateHtml=$dbAllOk?'<div class="ok"><strong>DATABASE PRODUCTION GATE PASS.</strong> DB reachable dari server ini dan canonical FINAL12/release/property checks lulus.</div>':(!empty($v['fresh_install'])?'<div class="warn"><strong>DATABASE BELUM READY.</strong> Untuk fresh install ini normal bila database masih kosong. Setelah CONFIG APPLY sukses pada PRIMARY, lanjutkan tombol FRESH COMMISSIONING di halaman ini; configurator yang sama akan import canonical SQL, validasi, bootstrap admin, dan membersihkan bootstrap password.</div>':'<div class="err"><strong>DATABASE PRODUCTION GATE FAIL.</strong> Jangan buka traffic production. Perbaiki hasil DB validation di bawah sampai PASS.</div>');
    $secretSourceHtml='<div class="ok"><strong>Secret policy:</strong> APP_ENCRYPTION_KEY '.tc_h((string)($generated['secretSources']['APP_ENCRYPTION_KEY']??'unknown')).' | NODE_SYNC_SHARED_SECRET '.tc_h((string)($generated['secretSources']['NODE_SYNC_SHARED_SECRET']??'unknown')).' | Bootstrap '.tc_h((string)($generated['secretSources']['APP_BOOTSTRAP_ADMIN_PASSWORD']??'unknown')).'. Fingerprint encryption: <code>'.tc_h(tc_secret_fingerprint((string)$generated['secrets']['encryption_key'])).'</code></div>';
    $applyHtml=$apply['applied']?'<div class="ok"><strong>CONFIG APPLY SUKSES ke '.tc_h(strtoupper((string)$apply['target'])).'.</strong> Backup timestamp dibuat untuk file existing. Download bundle/recovery terlebih dahulu. Jika ini fresh Primary, selesaikan FRESH COMMISSIONING sebelum lock.</div>':'<div class="ok">Input/config generation sukses. Belum ada file server yang diubah.</div>';
    $repairButton=!empty($apply['applied'])?'<h2>Perbaiki konfigurasi database</h2><div class="info"><strong>Tidak perlu mengulang Tahap 1–3.</strong> Gunakan tombol ini bila DB Host/Name/User/Password/credential perlu diperbaiki. Nilai non-secret tetap dipertahankan; password harus diketik ulang.</div><form method="post"><input type="hidden" name="action" value="edit_database"><input type="hidden" name="csrf" value="'.tc_h($csrf).'"><button type="submit">PERBAIKI DATABASE / BUKA TAHAP 4</button></form>':'';
    $activeStateAfter=tc_detect_active_setup_state((string)$v['admin_root']);$commissionEligible=in_array((string)($activeStateAfter['code']??''),['db_empty','commission_resume','fresh_canonical_unbootstrapped'],true);$commissionButton=$commissionEligible?'<h2>Fresh server commissioning</h2><div class="warn"><strong>DATABASE FRESH:</strong> jangan pindah ke PHP lain. Tombol ini memakai .env + credential yang baru saja dibuat oleh configurator ini, lalu import canonical <code>database_setup.sql</code>, full validation, first install, cleanup bootstrap password, dan final gate.</div><form method="post"><input type="hidden" name="action" value="fresh_commission"><input type="hidden" name="csrf" value="'.tc_h($csrf).'"><label><input type="checkbox" name="confirm_fresh" value="FRESH_EMPTY_DB" required> Saya memastikan database target adalah database BARU/KOSONG dan bundle secret sudah disimpan.</label><br><button type="submit">LANJUTKAN FRESH COMMISSIONING</button></form>':'';$stateCodeAfter=(string)($activeStateAfter['code']??'unknown');$lockButton=($apply['applied']&&!empty($apply['lockRecommended'])&&$stateCodeAfter==='commissioned')?'<h2>Server commissioning sudah selesai</h2><form method="post"><input type="hidden" name="action" value="lock"><input type="hidden" name="csrf" value="'.tc_h($csrf).'"><button type="submit">LOCK CONFIGURATOR SEKARANG</button></form>':'';
    $stateAfterHtml='<div class="'.(in_array((string)($activeStateAfter['severity']??''),['ok','warn','err','info'],true)?tc_h((string)$activeStateAfter['severity']):'info').'"><strong>STATUS AKTIF SETELAH APPLY: '.tc_h(strtoupper($stateCodeAfter)).'</strong><br>'.tc_h((string)($activeStateAfter['message']??''));$noiseAfter=(array)($activeStateAfter['adoption']['details']['ignoredPreBootstrapNoiseRows']??[]);if($noiseAfter){$parts=[];foreach($noiseAfter as $t=>$c)$parts[]=tc_h((string)$t).'='.tc_h((string)$c);$stateAfterHtml.='<br><small><strong>Telemetry/infrastruktur pre-bootstrap (tidak memblokir):</strong> '.implode(', ',$parts).'</small>';}$stateAfterHtml.='</div>';
    $finishLabel=$stateCodeAfter==='commissioned'?'SELESAI + HAPUS SECRET SESSION':'KELUAR SEMENTARA — SETUP BELUM SELESAI';
    $finishButton='<form method="post"><input type="hidden" name="action" value="finish"><input type="hidden" name="csrf" value="'.tc_h($csrf).'"><button type="submit">'.tc_h($finishLabel).'</button></form>';
    $secretWarning=!empty($v['include_secret_inventory'])?'<div class="warn"><strong>Simpan DO_NOT_UPLOAD/SECRETS_SAVE_ONCE.txt secara privat.</strong> Jangan upload file itu ke document root. Setelah bootstrap, hapus APP_BOOTSTRAP_ADMIN_PASSWORD dari ENV aktif.</div>':'<div class="warn">Secret inventory tidak disertakan. Pastikan APP_ENCRYPTION_KEY dan secret penting sudah tersimpan aman dari ENV hasil generate.</div>';
    $body=$applyHtml.$secretSourceHtml.$dbGateHtml.'<p><strong>Property:</strong> '.tc_h($v['property_id']).' — '.tc_h($v['property_name']).'<br><strong>Online:</strong> '.tc_h($v['online_origin']).'<br><strong>Public:</strong> '.tc_h($v['public_origin']).'</p><h2>Full database validation dari server ini</h2>'.$probeHtml.'<h2>Download bundle</h2><form method="post"><input type="hidden" name="action" value="download_bundle"><input type="hidden" name="csrf" value="'.tc_h($csrf).'"><button type="submit">DOWNLOAD SEMUA CONFIG (.ZIP)</button></form><h2>Atau download per file</h2><ul>'.$rows.'</ul>'.$secretWarning.$stateAfterHtml.$repairButton.$commissionButton.$lockButton.'<h2>'.($stateCodeAfter==='commissioned'?'Selesai tanpa lock':'Keluar sementara').'</h2>'.$finishButton;
    tc_render('TAMASYA Config Generated',$body);
}

function tc_prompt(string $label,string $default='',bool $secret=false): string {
    $suffix=$default!==''?' ['.$default.']':'';fwrite(STDOUT,$label.$suffix.': ');$hidden=false;
    if($secret&&DIRECTORY_SEPARATOR!=='\\'&&function_exists('stream_isatty')&&@stream_isatty(STDIN)&&function_exists('shell_exec')){$r=@shell_exec('stty -echo 2>/dev/null');$hidden=true;}
    try{$line=fgets(STDIN);}finally{if($hidden){@shell_exec('stty echo 2>/dev/null');fwrite(STDOUT,"\n");}}
    if($line===false)return $default;$v=trim($line);return $v===''?$default:$v;
}
function tc_cli_help(): void {
    echo "TAMASYA Unified Configurator ".TAMASYA_CONFIGURATOR_VERSION."\n\n";
    echo "Interactive: php tamasya_configurator.php\n";
    echo "Capabilities: php tamasya_configurator.php --capabilities\n";
    echo "Canonical DB manifest: php tamasya_configurator.php --manifest-summary\n";
    echo "Freshness policy: php tamasya_configurator.php --freshness-policy\n";
    echo "Generate web setup key: php tamasya_configurator.php --generate-setup-key\n";
    echo "Rotate web setup key: php tamasya_configurator.php --rotate-setup-key\n";
    echo "Setup key status: php tamasya_configurator.php --setup-key-status\n";
    echo "Validate existing ENV: php tamasya_configurator.php --validate-env=/absolute/path/.env\n";
    echo "Self test: php tamasya_configurator.php --self-test\n";
    echo "Setup state from active server: php tamasya_configurator.php --setup-state [--admin-root=/absolute/admin_app]\n";
    echo "Fresh commissioning from active applied ENV: php tamasya_configurator.php --fresh-commission [--admin-root=/absolute/admin_app]\n";
    echo "Unlock browser: php tamasya_configurator.php --unlock\n";
    echo "Non-interactive accepts --key=value for form field names plus --output-dir=/path and optional --apply=local|online --apply-public.\n";
}
function tc_cli_args(array $argv): array { $out=[];foreach($argv as $a){if(!str_starts_with($a,'--')||!str_contains($a,'='))continue;[$k,$v]=explode('=',substr($a,2),2);$out[str_replace('-','_',$k)]=$v;}return $out; }
function tc_cli_collect(array $args): array {
    if(isset($args['mode']))return array_merge(tc_defaults(),$args);
    $v=tc_defaults(); echo "\n=== TAMASYA UNIFIED CONFIGURATOR ===\nTekan Enter untuk menerima nilai default. Password tidak dikirim ke mana pun.\n\n";
    $v['mode']=tc_prompt('Mode dual / single_online / single_local','dual');$v['timezone']=tc_prompt('Timezone IANA','Asia/Makassar');$v['property_id']=tc_prompt('Property ID');$v['property_code']=tc_prompt('Property Code');$v['property_name']=tc_prompt('Nama Hotel');$v['company_id']=tc_prompt('Company ID (opsional)');$v['company_name']=tc_prompt('Company Name (opsional)');$v['invoice_prefix']=tc_prompt('Invoice prefix (kosong=Property Code)');
    $v['online_url']=tc_prompt('Online Admin/API URL','https://app.nolink.my.id');$v['public_url']=tc_prompt('Public Site URL','https://tamasya.nolink.my.id');$v['local_url']=tc_prompt('Local App URL','http://192.168.1.10');$v['initial_primary']=tc_prompt('Initial primary local / online','local');$v['fresh_install']=in_array(strtolower(tc_prompt('Fresh install? y/n','y')),['y','yes','1','true'],true)?'1':'0';
    foreach(['local'=>'LOCAL','online'=>'ONLINE'] as $n=>$label){if(($v['mode']==='single_online'&&$n==='local')||($v['mode']==='single_local'&&$n==='online'))continue;echo "\n-- $label DATABASE --\n";$v[$n.'_db_host']=tc_prompt('DB host',$n==='local'?'127.0.0.1':'localhost');$v[$n.'_db_port']=tc_prompt('DB port','3306');$v[$n.'_db_name']=tc_prompt('DB name');$v[$n.'_db_user']=tc_prompt('DB user');$v[$n.'_db_pass']=tc_prompt('DB password','',true);$v[$n.'_credential_path']=tc_prompt('Private credential absolute path');$v[$n.'_backup_dir']=tc_prompt('Private backup absolute dir');$v[$n.'_trusted_proxies']=tc_prompt('Trusted proxies (opsional)');}
    $v['existing_encryption_key']=tc_prompt('Existing APP_ENCRYPTION_KEY (kosong=auto-preserve/generate baru)','',true);$v['existing_sync_secret']=tc_prompt('Existing NODE_SYNC_SHARED_SECRET (kosong=auto-preserve/generate baru)','',true);$v['public_site_root']=tc_prompt('Public site root absolute (opsional)');return $v;
}
function tc_self_test(): array {
    $checks=[];$checks['php82Plus']=PHP_VERSION_ID>=80200;$checks['randomBytes']=function_exists('random_bytes');$checks['openssl']=function_exists('openssl_encrypt');$manifestTables=array_values((array)(tc_canonical_manifest()['tables']??[]));$requiredR4=['lost_found_items','maintenance_cancellation_reviews','operational_entity_links','operational_incidents','guest_service_requests','room_operational_holds'];$checks['manifest']=count($manifestTables)>=100&&!array_diff($requiredR4,$manifestTables);$k=tc_random_b64_key();$checks['encryptionKey']=tc_valid_encryption_key($k);$token=tc_random_token(32);$checks['token']=tc_secret_min($token);$checks['pathGuard']=tc_path_within('/var/www/app/private/x','/var/www/app')&&!tc_path_within('/srv/private/x','/var/www/app');$checks['privatePathRejectsCpanelPublicHtml']=empty(tc_private_path_guard('/home/demo/public_html/tamasya-private/x')['ok']);$checks['privatePathAllowsHomePrivate']=!empty(tc_private_path_guard('/home/demo/tamasya-private/x')['ok']);$checks['privateSuggestionEscapesPublicHtml']=!str_contains(strtolower(str_replace('\\','/',tc_suggest_private_base('/home/demo/public_html/admin_app','hotel-01'))),'/public_html/');
    $sample="APP_ENV=production\nAPP_URL=http://127.0.0.1\nAPP_TIMEZONE=Asia/Makassar\nAPP_ENCRYPTION_KEY=".$k."\nAPP_CREDENTIALS_FILE=/srv/private/db.php\nAPP_EXPECTED_DB_NAME=test_db\nAPP_REQUIRE_EXPECTED_DB_NAME=1\nSECURITY_EVENT_HASH_KEY=".tc_random_hex(32)."\nCRON_SECRET=".tc_random_hex(32)."\nADMIN_WEB_TOOLS_SECRET=".tc_random_hex(32)."\nBACKUP_DIR=/srv/private/backups\nTAMASYA_PROPERTY_ID=hotel-01\nTAMASYA_PROPERTY_CODE=H01\nTAMASYA_PROPERTY_NAME=Hotel\nTAMASYA_NODE_ID=hotel-01-local\nTAMASYA_NODE_KIND=local\nTAMASYA_NODE_INITIAL_ROLE=primary\nNODE_CLUSTER_ENABLED=0\nAPP_DEBUG=0\n";$checks['envValidator']=tc_validate_env_text($sample)['ok'];$sql=@file_get_contents(__DIR__.DIRECTORY_SEPARATOR.'database_setup.sql');$m=tc_canonical_manifest();$checks['canonicalSqlHash']=is_string($sql)&&$sql!==''&&hash_equals((string)($m['sqlSha256']??''),hash('sha256',$sql));$checks['canonicalSqlParser']=is_string($sql)&&count(tc_sql_statements($sql))>=100;$fp=tc_freshness_policy_validate();$checks['freshnessPolicy']=$fp['ok'];$checks['freshnessRuntimeTelemetryAllowed']=in_array('runtime_request_events',(array)(tc_freshness_policy()['preBootstrapNoiseTables']??[]),true);$checks['freshnessBusinessStillBlocking']=!in_array('bookings',(array)(tc_freshness_policy()['preBootstrapNoiseTables']??[]),true)&&!in_array('transactions',(array)(tc_freshness_policy()['preBootstrapNoiseTables']??[]),true)&&!in_array('staff',(array)(tc_freshness_policy()['preBootstrapNoiseTables']??[]),true);return ['ok'=>!in_array(false,$checks,true),'checks'=>$checks,'version'=>TAMASYA_CONFIGURATOR_VERSION];
}
function tc_cli_main(array $argv): void {
    if(in_array('--help',$argv,true)||in_array('-h',$argv,true)){tc_cli_help();return;}
    if(in_array('--capabilities',$argv,true)){echo json_encode(tc_capabilities(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";return;}
    if(in_array('--manifest-summary',$argv,true)){$m=tc_canonical_manifest();$u=0;foreach((array)$m['uniqueIndexes'] as $x)$u+=count((array)$x);echo json_encode(['release'=>TAMASYA_SCHEMA_RELEASE_EXPECTED,'patch'=>TAMASYA_PATCH_LEVEL_EXPECTED,'tables'=>count((array)$m['tables']),'primaryKeys'=>count((array)$m['primaryKeys']),'uniqueIndexes'=>$u,'triggers'=>count((array)$m['triggers']),'migrationMarker'=>$m['migrationMarker']??null,'canonicalSqlSha256'=>$m['sqlSha256']??null],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";return;}
    if(in_array('--freshness-policy',$argv,true)){$p=tc_freshness_policy_validate();$policy=(array)($p['policy']??[]);$canonical=(array)(tc_canonical_manifest()['tables']??[]);$nonBlocking=array_unique(array_merge((array)($policy['seedTables']??[]),(array)($policy['preBootstrapNoiseTables']??[])));echo json_encode(['ok'=>$p['ok'],'issues'=>$p['issues'],'seedTables'=>$policy['seedTables']??[],'preBootstrapNoiseTables'=>$policy['preBootstrapNoiseTables']??[],'blockingCanonicalTables'=>count($canonical)-count($nonBlocking)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";return;}
    if(in_array('--generate-setup-key',$argv,true)){echo json_encode(tc_generate_setup_key(false),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";return;}
    if(in_array('--rotate-setup-key',$argv,true)){echo json_encode(tc_generate_setup_key(true),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";return;}
    if(in_array('--setup-key-status',$argv,true)){echo json_encode(tc_setup_key_status(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";return;}
    if(in_array('--self-test',$argv,true)){$r=tc_self_test();echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";if(!$r['ok'])exit(2);return;}
    $preArgs=tc_cli_args($argv);if(isset($preArgs['validate_env'])){$r=tc_validate_env_file((string)$preArgs['validate_env']);echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";if(!$r['ok'])exit(2);return;}
    if(in_array('--setup-state',$argv,true)){
        $adminRoot=isset($preArgs['admin_root'])?trim((string)$preArgs['admin_root']):__DIR__;
        if($adminRoot===''||!tc_path_absolute($adminRoot))throw new RuntimeException('--admin-root harus path absolut bila diberikan.');
        echo json_encode(tc_detect_active_setup_state($adminRoot),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";return;
    }
    if(in_array('--fresh-commission',$argv,true)){
        $adminRoot=isset($preArgs['admin_root'])?trim((string)$preArgs['admin_root']):__DIR__;
        if($adminRoot===''||!tc_path_absolute($adminRoot))throw new RuntimeException('--admin-root harus path absolut bila diberikan.');
        $r=tc_fresh_commission($adminRoot);$final=(array)($r['final']??[]);$import=(array)($r['canonicalImport']??[]);$first=(array)($r['firstInstall']??[]);$cleanup=(array)($r['bootstrapCleanup']??[]);
        echo "FRESH SERVER COMMISSIONING: PASS\n";
        echo "Database: ".(string)($final['database']??'')."\n";
        echo "Canonical import: ".(!empty($import['skipped'])?'resume':'executed')."; base tables=".(string)($import['baseTables']??'')."; sha256=".(string)($import['sqlSha256']??'')."\n";
        echo "First install: ".(!empty($first['performed'])?'performed':(!empty($first['alreadyInitialized'])?'already-initialized':'not-performed'))."; admin=".(string)($first['username']??'admin')."\n";
        echo "Bootstrap password cleanup: ".(!empty($cleanup['changed'])?'cleared':'already-empty')."\n";
        echo "Property setup status: ".(string)($final['setupStatus']??'unknown')."; READY=".(!empty($final['propertyReady'])?'yes':'no')."\n";
        echo "ADMIN_WEB_TOOLS_ENABLED: ".(string)($final['adminWebToolsEnabled']??'unknown')."\n";
        if(empty($final['propertyReady']))echo "NEXT: login admin dan selesaikan property-setup.html sebelum traffic production.\n";
        return;
    }
    if(in_array('--unlock',$argv,true)){$f=__DIR__.DIRECTORY_SEPARATOR.TAMASYA_CONFIGURATOR_LOCK_FILE;if(is_file($f)&&!unlink($f))throw new RuntimeException('Gagal menghapus lock file.');echo "Browser configurator unlocked.\n";return;}
    $args=tc_cli_args($argv);$v=tc_cli_collect($args);if(isset($args['apply']))$v['apply_target']=$args['apply'];if(in_array('--apply-public',$argv,true))$v['apply_public']='1';if(in_array('--no-lock',$argv,true))$v['lock_after_apply']='0';if(in_array('--no-preserve-existing-secrets',$argv,true))$v['preserve_existing_env_secrets']='0';if(in_array('--no-secret-inventory',$argv,true))$v['include_secret_inventory']='0';
    $check=tc_validate_input($v);if(!$check['ok']){fwrite(STDERR,"VALIDATION FAIL:\n- ".implode("\n- ",$check['errors'])."\n");exit(2);} $v=$check['values'];$generated=tc_generate($v);
    $outputDir=isset($args['output_dir'])?$args['output_dir']:(__DIR__.DIRECTORY_SEPARATOR.'generated-config-'.date('Ymd-His'));if(!tc_path_absolute($outputDir))$outputDir=__DIR__.DIRECTORY_SEPARATOR.$outputDir;if(!is_dir($outputDir)&&!mkdir($outputDir,0700,true)&&!is_dir($outputDir))throw new RuntimeException('Gagal membuat output dir: '.$outputDir);
    foreach($generated['outputs'] as $name=>$content){$path=$outputDir.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$name);$dir=dirname($path);if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($path,$content,LOCK_EX);if(str_contains($name,'.env')||str_contains($name,'credentials')||str_contains($name,'SECRETS'))@chmod($path,0600);}
    $zip=tc_zip_store($generated['outputs']);$zipPath=$outputDir.DIRECTORY_SEPARATOR.'TAMASYA_GENERATED_CONFIG.zip';file_put_contents($zipPath,$zip,LOCK_EX);$apply=tc_apply($v,$generated);
    echo "\nGENERATION PASS\nOutput: $outputDir\nBundle: $zipPath\nMode: {$v['mode']}\nProperty: {$v['property_id']}\n";if($apply['applied'])echo "Applied current server target: {$apply['target']}\n";echo "Capabilities: PHP ".PHP_VERSION.", pdo_mysql=".(tc_capabilities()['pdoMysql']?'yes':'no').", curl=".(tc_capabilities()['curl']?'yes':'no').", openssl=".(tc_capabilities()['openssl']?'yes':'no')."\n";
    $dbGate=true;$dbChecked=0;
    if($v['mode']!=='single_online'){ $p=tc_db_probe($v,'local'); echo "Local DB validation: {$p['message']}\n";$dbChecked++;if(empty($p['ok']))$dbGate=false;}
    if($v['mode']!=='single_local'){ $p=tc_db_probe($v,'online'); echo "Online DB validation: {$p['message']}\n";$dbChecked++;if(empty($p['ok']))$dbGate=false;}
    if($dbChecked>0&&$dbGate)echo "DATABASE PRODUCTION GATE: PASS\n";
    elseif(!empty($v['fresh_install']))echo "DATABASE PRODUCTION GATE: NOT READY (fresh install; import/finalize then rerun until PASS)\n";
    else echo "DATABASE PRODUCTION GATE: FAIL — do not open production traffic.\n";
    echo "Secret source APP_ENCRYPTION_KEY: ".($generated['secretSources']['APP_ENCRYPTION_KEY']??'unknown')."; NODE_SYNC_SHARED_SECRET: ".($generated['secretSources']['NODE_SYNC_SHARED_SECRET']??'unknown')."\n";if(!empty($v['include_secret_inventory']))echo "IMPORTANT: save DO_NOT_UPLOAD/SECRETS_SAVE_ONCE.txt privately; never upload it to web root.\n";
}

try { if(tc_cli())tc_cli_main($_SERVER['argv']??[]); else tc_web(); }
catch(Throwable $e){ if(tc_cli()){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);} tc_render('Configurator Error','<div class="err">'.tc_h($e->getMessage()).'</div>',500); }
