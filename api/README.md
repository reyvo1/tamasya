# TAMASYA V137 Backend API

`admin_app/api.php` adalah satu-satunya entry point HTTP publik untuk Admin/API.
File di dalam `api/support/` dan `api/routes/` adalah modul internal dan tidak boleh
dipanggil langsung dari browser, webhook, cron, atau aplikasi klien.

## Kontrak V137

- TAMASYA adalah nama produk; identitas hotel/property berasal dari konfigurasi deployment.
- Satu instalasi property memakai satu database terisolasi.
- Core schema hanya dibuat dari `admin_app/database_setup.sql` pada database BARU/KOSONG.
- Runtime HTTP tidak boleh menjalankan `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, atau auto-repair schema.
- `api/support/010_schema_contract.php` hanya membaca/mengecek kontrak schema.
- Modul opsional Growth/Enterprise dipasang eksplisit melalui `admin_app/optional_modules_install.php` (CLI atau browser terlindungi).
- `admin_app/database_verify.php` adalah verifier read-only untuk baseline fresh V137.

## Struktur

- `support/001_runtime_security.php` — runtime requirements, response guard, secret encryption.
- `support/002_enterprise_hardening.php` — release/schema readiness gate.
- `support/003_property_identity.php` — identitas property dari konfigurasi.
- `support/005_runtime_observability.php` — observability dan schema inspection read-only.
- `support/010_schema_contract.php` — helper kontrak schema read-only.
- `support/015..107_*.php` — domain support modules.
- `routes/*.php` — endpoint handlers per domain.
- `router.php` — dispatcher route.

Daftar modul aktual ada di `MODULARIZATION_MANIFEST.json`.

## Aturan pengembangan

1. Jangan menambahkan DDL/auto-migration ke request runtime.
2. Perubahan core schema harus masuk ke baseline fresh versi berikutnya atau migration eksplisit yang terdokumentasi melalui CLI atau browser terlindungi.
3. Helper baru masuk ke modul support yang sesuai; jangan menumpuk `api.php`.
4. Endpoint baru masuk ke satu route module dan tetap melewati auth/policy/operation-id guard yang berlaku.
5. Jangan hardcode nama hotel, alamat, timezone, bank, pajak, room type, room, staf, atau data property ke core.
6. Jalankan lint seluruh PHP dan syntax check seluruh JavaScript sebelum packaging.
