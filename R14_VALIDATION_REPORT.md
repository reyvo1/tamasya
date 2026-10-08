# R14 — Hasil validasi nyata, 8 Oktober 2026

**Status: kandidat, belum final produksi.**

## Implementasi dan akar masalah

Growth sudah mempunyai parent/link grup, tetapi operasi pembuatan grup dan penautan booking terpisah dan tersembunyi di menu Growth. R14 menyediakan reservasi/check-in beberapa kamar pada halaman Denah Kamar dan menu Kamar & Tamu Telegram. Parent/link memakai tabel Growth existing; child tetap satu booking per kamar. Engine booking, availability, R3 check-in, DP, payment, refund, PBJT, jurnal, folio dan hybrid writer digunakan kembali. Tidak ada migrasi SQL baru.

Master/Split memerlukan modul Enterprise folio dan schema resmi yang aktif. Growth parent serta group/corporate harus enabled. Owner membaca tanpa izin perubahan. Checkout, perpanjangan, pindah, housekeeping dan kunci tetap per booking. Audit desain, pembatasan operasi dan matrix 28 skenario terdapat di [MULTI_ROOM_AUDIT_2026-10-08.md](MULTI_ROOM_AUDIT_2026-10-08.md).

## PASS aktual

- `bash scripts/verify-release.sh`: seluruh checksum source, lint PHP/JavaScript, compile Python, sintaks shell, **38 suite regresi source/unit PASS**, exit 0. PHP lokal 8.5.4; Node 22.23.3. Log `/tmp/r14-source-38-final.log`.
- Unit multi-kamar: 1.529 assertions.
- Link graph ES module nyata: 8 dependency linked, tanpa evaluasi aplikasi/browser. Menemukan dan memperbaiki impor React yang tidak diekspor oleh app-core.
- `git -c core.whitespace=cr-at-eol diff --check`: PASS.
- ZIP: 261 runtime entries; seluruh 260 checksum paket cocok, CRC semua entry lolos; 80 referensi statis HTML cocok. Tidak memuat `.env` aktif, backup pengguna, data upload, log, .git, test atau node_modules.
- Kompresi backup: byte-identik setelah dekompresi; SQL asli tetap utuh.

## Belum dieksekusi / terhalang

Full DB, browser desktop/tablet/mobile, Telegram end-to-end, concurrency dua proses, hybrid dua database, HQ dan matrix PHP R14 **belum dijalankan**. Skenario nyata baru ditambahkan ke `tests/uat_rc1/scenario_multi_room.py` dan `tests/uat_rc1/browser/rc1-ui.spec.mjs`; bukan diklaim PASS. Seluruh suite lama dan matrix PHP 8.2–8.5 dipertahankan. Tidak ada assertion lama dihapus atau dilonggarkan.

GitHub runs [37741752127](https://github.com/reyvo1/tamasya/actions/runs/37741752127), [37742395829](https://github.com/reyvo1/tamasya/actions/runs/37742395829), dan HEAD terbaru [37744189465](https://github.com/reyvo1/tamasya/actions/runs/37744189465) gagal sebelum satu pun step dimulai; empat source matrix job memiliki 0 step, dua dependent job skipped. Anotasi GitHub: “The job was not started because recent account payments have failed or your spending limit needs to be increased.” Ini bukan kegagalan assertion kode. Aktifkan kembali Actions lalu jalankan workflow pada HEAD di bawah. UAT lokal penuh belum diizinkan menurut instruksi sesi sebelumnya; pilihan izin DB disposable/pengaktifan Actions sudah ditanyakan.

Sebelum deployment produksi perlu bukti full UAT hijau. ZIP ini boleh disimpan sebagai kandidat; belum rekomendasi penggantian aplikasi aktif.

## Ukuran database / backup

SQL backup: 101.222.877 bytes. `request_operation_receipts` sekitar 90.573.112 bytes dari INSERT; 1.155 dari 1.640 receipt sudah terkompresi. Jadi ukuran besar terutama replay respons API, bukan jumlah kamar. File SQL berbeda dari ukuran fisik InnoDB.

Salinan gzip lossless: **66.817.549 bytes**, pengurangan sekitar 34%. SHA isi asli/dekompresi: `cf3ae18f1261c459964c90f00db320e7615de9bb38cdc4e2318621a08b89d60d`. SHA berkas gzip: `bd4134682c95f11d404d255ee08066bf76ac4a53a50f87e4f6589786a47aff1f`. Berkas pribadi ini tidak di-push atau dimasukkan ZIP.

`receipt_storage_maintenance.php` default dry-run; apply memerlukan backup dan writer authority. Tidak ada optimasi DB aktif yang sudah diterapkan, tidak ada booking/transaksi/audit historis dihapus. Pemadatan mentah tidak menjanjikan penghematan besar karena banyak receipt sudah gzip.

## Paket dan pemasangan

`TAMASYA-HOSTING-KANDIDAT-R14-20261008.zip` · 2082235 bytes · 261 entries
SHA-256: `372f6a5a2a74cf5c86165054925cf86e2f60679bcd5692e51b1c69292a322e0f`

Tidak ada perubahan schema wajib. Pertahankan konfigurasi `.env` dan database existing; gunakan prosedur update/backup hosting sebelumnya setelah UAT lulus. Terapkan source yang sama pada kedua node hybrid, sesuai writer authority existing. Jangan mengimpor ulang `database_setup.sql` ke DB produksi yang sudah ada. Jangan menjalankan apply alat pemadatan hanya untuk memasang fitur multi-kamar.

## Git

Source kerja: `/home/ivo/Documents/TAMASYA-FINAL-BACKUP-806db1bf9247-2026-10-03/TAMASYA-PRD`
Clone commit/push: `/tmp/tamasya-r14-push`
Baseline R13: `1fc95d6733abfcda9e7cb978af088f9f80fd51b4`
HEAD sudah di-push: `696e8cd4afdd77f111504aca26ee3861da267445`

`git status --short`:
```text
(bersih)
```

`git diff --stat 1fc95d6733abfcda9e7cb978af088f9f80fd51b4 HEAD`:
```text
 .github/workflows/tamasya-enterprise-rc1-uat.yml   |   8 +
 FILE_CHECKSUMS.sha256                              | 145 ++++++------
 MULTI_ROOM_AUDIT_2026-10-08.md                     | 104 +++++++++
 api.php                                            |   3 +
 api/MODULARIZATION_MANIFEST.json                   |   2 +
 api/domains/FUNCTION_MAP.json                      |  25 +++
 api/domains/MIGRATION_MAP.json                     |   4 +-
 .../comms/050_integrations_telegram_mail.php       |   1 +
 .../finance/019_canonical_financial_semantics.php  |   1 +
 api/modules/finance/030_booking_finance.php        |  41 ++--
 .../front_office/043_multi_room_reservations.php   | 243 +++++++++++++++++++++
 .../front_office/044_multi_room_telegram.php       | 104 +++++++++
 api/modules/hr_staff/020_identity_access_audit.php |   1 +
 api/modules/setup_admin/004_property_setup.php     |   3 +-
 api/routes/020_hotel_booking.php                   |  37 ++--
 api/routes/080_telegram_webhook.php                |  17 +-
 api/routes/110_growth_suite.php                    |   6 +-
 assets/app-core.js                                 |   4 +-
 assets/canonical-report-center.js                  |   2 +-
 assets/chunks/app-shared.js                        |   2 +-
 assets/chunks/app-shell.js                         |   8 +-
 assets/chunks/attendance.js                        |   6 +-
 assets/chunks/booking-negotiation.js               |   4 +-
 assets/chunks/booking-receipt.js                   |   8 +-
 assets/chunks/config.js                            |   6 +-
 assets/chunks/dashboard.js                         |   8 +-
 assets/chunks/db-config.js                         |   6 +-
 assets/chunks/feature-shared.js                    |   4 +-
 assets/chunks/finance-detail.js                    |   6 +-
 assets/chunks/finance.js                           |   8 +-
 assets/chunks/growth-widgets.js                    |   4 +-
 assets/chunks/inventory.js                         |   8 +-
 assets/chunks/leaves.js                            |   8 +-
 assets/chunks/local-connect.js                     |   6 +-
 assets/chunks/multi-room.js                        |  91 ++++++++
 assets/chunks/operations-widget.js                 |   4 +-
 assets/chunks/operations.js                        |   6 +-
 assets/chunks/report-detail.js                     |   6 +-
 assets/chunks/report.js                            |   8 +-
 assets/chunks/rooms.js                             |  16 +-
 assets/chunks/savings.js                           |   6 +-
 assets/chunks/staff-detail.js                      |   8 +-
 assets/chunks/staff-permissions.js                 |   6 +-
 assets/chunks/staff.js                             |   6 +-
 assets/chunks/support.js                           |   6 +-
 assets/chunks/telegram.js                          |   6 +-
 assets/chunks/viewport-layer.js                    |   2 +-
 assets/chunks/website.js                           |   6 +-
 assets/growth-pms-link-addon.js                    |   2 +-
 assets/multi-room.css                              |   2 +
 assets/navigation-registry.js                      |   2 +-
 assets/runtime-addon-loader.js                     |   4 +-
 assets/sw-register.js                              |   2 +-
 consistency_guard_support.php                      |   2 +-
 enterprise-suite.html                              |  12 +-
 growth-suite.html                                  |  12 +-
 growth_activation_profile.php                      |   2 +-
 index.html                                         |  26 +--
 internal-memo.html                                 |   6 +-
 multi-property-foundation.html                     |  10 +-
 node_sync_support.php                              |   2 +-
 pos.html                                           |  16 +-
 property-setup.html                                |   6 +-
 receipt_storage_maintenance.php                    |  37 ++++
 release_contract.php                               |   2 +-
 runtime_config.php                                 |   1 +
 scripts/verify-release.sh                          |   4 +-
 sw.js                                              |  68 +++---
 tests/multi-room-regression.php                    |  24 ++
 tests/multi-room-ui-regression.mjs                 |  16 ++
 tests/owner-readonly-regression.mjs                |   2 +-
 tests/prd-performance-regression.mjs               |   4 +-
 tests/prd-pos-business-date-regression.mjs         |   2 +-
 tests/regression.mjs                               |   4 +-
 tests/uat_rc1/assert_full_complete.py              |   1 +
 tests/uat_rc1/browser/rc1-ui.spec.mjs              |  23 +-
 tests/uat_rc1/browser_inventory_lock.py            |   1 +
 tests/uat_rc1/scenario_multi_room.py               | 133 +++++++++++
 78 files changed, 1153 insertions(+), 295 deletions(-)

```

## Semua file berubah dibanding R13

Sebagian file UI/test lama hanya mengganti build/cache marker menjadi `20261008-multiroom-r14`; perubahan business logic utama berada di modul baru 043/044, finance/030, hook audit/commit/notifier, route booking/Telegram/Growth, akses runtime/hybrid, Rooms dan chunk multi-room.

```text
.github/workflows/tamasya-enterprise-rc1-uat.yml
FILE_CHECKSUMS.sha256
MULTI_ROOM_AUDIT_2026-10-08.md
api.php
api/MODULARIZATION_MANIFEST.json
api/domains/FUNCTION_MAP.json
api/domains/MIGRATION_MAP.json
api/modules/comms/050_integrations_telegram_mail.php
api/modules/finance/019_canonical_financial_semantics.php
api/modules/finance/030_booking_finance.php
api/modules/front_office/043_multi_room_reservations.php
api/modules/front_office/044_multi_room_telegram.php
api/modules/hr_staff/020_identity_access_audit.php
api/modules/setup_admin/004_property_setup.php
api/routes/020_hotel_booking.php
api/routes/080_telegram_webhook.php
api/routes/110_growth_suite.php
assets/app-core.js
assets/canonical-report-center.js
assets/chunks/app-shared.js
assets/chunks/app-shell.js
assets/chunks/attendance.js
assets/chunks/booking-negotiation.js
assets/chunks/booking-receipt.js
assets/chunks/config.js
assets/chunks/dashboard.js
assets/chunks/db-config.js
assets/chunks/feature-shared.js
assets/chunks/finance-detail.js
assets/chunks/finance.js
assets/chunks/growth-widgets.js
assets/chunks/inventory.js
assets/chunks/leaves.js
assets/chunks/local-connect.js
assets/chunks/multi-room.js
assets/chunks/operations-widget.js
assets/chunks/operations.js
assets/chunks/report-detail.js
assets/chunks/report.js
assets/chunks/rooms.js
assets/chunks/savings.js
assets/chunks/staff-detail.js
assets/chunks/staff-permissions.js
assets/chunks/staff.js
assets/chunks/support.js
assets/chunks/telegram.js
assets/chunks/viewport-layer.js
assets/chunks/website.js
assets/growth-pms-link-addon.js
assets/multi-room.css
assets/navigation-registry.js
assets/runtime-addon-loader.js
assets/sw-register.js
consistency_guard_support.php
enterprise-suite.html
growth-suite.html
growth_activation_profile.php
index.html
internal-memo.html
multi-property-foundation.html
node_sync_support.php
pos.html
property-setup.html
receipt_storage_maintenance.php
release_contract.php
runtime_config.php
scripts/verify-release.sh
sw.js
tests/multi-room-regression.php
tests/multi-room-ui-regression.mjs
tests/owner-readonly-regression.mjs
tests/prd-performance-regression.mjs
tests/prd-pos-business-date-regression.mjs
tests/regression.mjs
tests/uat_rc1/assert_full_complete.py
tests/uat_rc1/browser/rc1-ui.spec.mjs
tests/uat_rc1/browser_inventory_lock.py
tests/uat_rc1/scenario_multi_room.py

```
