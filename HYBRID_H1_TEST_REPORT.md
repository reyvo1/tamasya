# TAMASYA Hybrid H1 — hasil implementasi dan pengujian

15 September 2026. Baseline: R4.5; target: implementasi awal PRD TAMASYA Hybrid Modular Architecture v1.0.

**445 pemeriksaan integrasi dan 97 pemeriksaan unit/regresi lulus, tanpa kegagalan tersisa pada rangkaian hasil final.** Paket ini adalah kandidat staging H1. Belum merupakan penyelesaian seluruh roadmap VPS/HA/realtime/SaaS dalam PRD.

## Perubahan aplikasi

Dashboard HQ baru dengan grant company/property, ringkasan keuangan/pajak/jurnal, occupancy tertimbang, pemisahan mata uang, jejak revisi/checksum, dan label snapshot tidak lengkap. Database operasional tetap satu per hotel. HQ mempunyai database read model sendiri dan tidak menulis ledger hotel.

Snapshot v2 memakai summary keuangan canonical, satu transaksi baca MySQL, uang integer minor-unit, validasi schema ketat, checksum, dan status integrity. Antrean private menyimpan payload sebelum pending, mengulang operation ID yang sama, memeriksa ACK, memakai backoff/dead-letter dan mendukung redrive serta pagination. Tidak memerlukan Redis, cron atau worker eksternal untuk operasi hotel.

Header company/property diperiksa server; cache identitas multi-property lintas hotel di browser dihapus; kalender snapshot legacy diperketat dan ID staf penutup periode dikeluarkan dari preview.

## Pengujian nyata yang dilakukan

- SQL bawaan diimpor ke database MySQL lokal terisolasi, first_install dan setup READY dijalankan, lalu rangkaian transaksi inti dijalankan ulang dengan fitur HQ OFF. Pemeriksaan mencakup backfill/transaksi manual, normal booking, split payment, refund, POS, jurnal, pajak, shift, Night Audit, offline replay, konkurensi, laporan dan restore.
- HQ ON diuji dengan request HTTP nyata dan pengiriman HTTPS melalui TLS proxy lokal dengan sertifikat CA staging yang diverifikasi cURL. Sertifikat/secret tidak dimasukkan paket.
- Dua database operasional hotel benar-benar terpisah diuji. Hotel kedua menerima transaksi backfill melalui API canonical; sesi hotel pertama ditolak oleh hotel kedua; kedua snapshot dikirim ke HQ dan total direkonsiliasi. Fixture agregat tambahan menguji perusahaan lain dan mata uang USD tanpa konversi otomatis.
- Pengiriman ulang, signature palsu/kedaluwarsa, payload berubah, nonce replay, revisi lama, konflik revisi, checksum rusak, field ekstra, grant salah, ETag, serta snapshot hilang diuji.
- Fault injection mencakup HTTP 202, ACK salah, koneksi diputus setelah HQ commit, kegagalan permanen, backoff, lima percobaan, dead-letter dan redrive. Pending tidak ditandai ACK; retry setelah commit mendapatkan receipt yang sama.
- Akun runtime HQ dengan izin terbatas berhasil menerima snapshot, tetapi ditolak MySQL saat membaca/mengubah ledger hotel, menimpa histori snapshot, atau menghapus receipt.
- Dashboard diuji melalui browser: login, pilihan dua hotel, laporan, logout, tampilan desktop dan viewport ponsel 390 px. Tidak ada warning/error console pada pemeriksaan akhir. Screenshot adalah data staging, bukan data hotel produksi.

## Hasil per suite

| Suite | Lulus | Gagal |
|---|---:|---:|
| setup | 23 | 0 |
| core | 22 | 0 |
| finance | 24 | 0 |
| operations | 21 | 0 |
| concurrency | 5 | 0 |
| shift-lock | 1 | 0 |
| extended-finance | 50 | 0 |
| allocation | 21 | 0 |
| settlement-sync | 21 | 0 |
| finance-controls | 22 | 0 |
| accounting-report | 35 | 0 |
| tax-edge | 12 | 0 |
| shift-integrity | 16 | 0 |
| report-export | 51 | 0 |
| restore | 8 | 0 |
| hybrid | 59 | 0 |
| hybrid-failure | 26 | 0 |
| second-property | 18 | 0 |
| hybrid-pagination | 3 | 0 |
| hq-permission | 7 | 0 |

Unit/regresi: core-php 44, core-js 11, finance-php 9, hybrid-php 33. Total seluruh assertion yang dihitung: **542**. Setup database kedua juga menjalankan workflow READY; tidak dihitung dua kali dalam total.

## Batas yang tetap terbuka

H1 mengimplementasikan fondasi dan irisan fungsional fase 0–3. Belum mencakup UI lifecycle tenant/SSO, role platform lengkap, worker generik/daemon, realtime Node/Go/IoT, HA dan autoscaling SaaS. Konsolidasi payroll/AR/AP khusus, transfer/eliminasi intercompany, serta PDF/XLSX/email/Telegram khusus HQ memerlukan tahap selanjutnya. Report export inti existing diuji struktur/checksum, bukan inspeksi visual setiap halaman.

Failover Primary/Standby lintas mesin, partisi jaringan, load test SLA PRD, UAT seluruh workflow payroll/procurement, dan upgrade database hotel lama belum dinyatakan lulus. Tidak ada deployment produksi dilakukan. Pengujian transaksi menggunakan API otomatis, bukan klaim semua formulir aplikasi diklik manual.

Status WARNING pada contoh dashboard diwarisi dari consistency guard sumber dan sengaja tidak diubah menjadi PASS. Rincian warning diperiksa pada hotel sumber. Uang memakai skala dua desimal core existing; currency dengan skala berbeda memerlukan versi kontrak lanjutan.

## Berkas dan penerapan

- Paket source lengkap: TAMASYA-HYBRID-H1-2026-09-15.zip.
- Baca HYBRID_H1_IMPLEMENTATION.md untuk pemetaan PRD dan HYBRID_H1_DEPLOYMENT.md untuk konfigurasi hotel/HQ, hak akses dan rollback.
- HQ membutuhkan database terpisah, config private, token/grant, HTTPS dan direktori outbox private. Semua flag default tetap OFF pada paket.
- Database hotel tidak mendapat tabel baru dari H1. SQL baseline hanya untuk instalasi kosong; jangan mengimpor ulang di database berisi transaksi.
- Bukti pengujian memuat hasil final dan harness; tidak memuat credential, token, dump database, file queue, private key atau environment staging.
- Manifest FILE_CHECKSUMS.sha256 di dalam ZIP memverifikasi seluruh berkas selain dirinya; checksum paket ada pada SHA256SUMS-H1.txt. Paket diekstrak ulang untuk lint dan pemeriksaan referensi.
