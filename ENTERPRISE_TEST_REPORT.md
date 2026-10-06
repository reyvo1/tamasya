# TAMASYA Enterprise RC1 — laporan hasil pengujian

22 September 2026. **572 pemeriksaan integrasi/artefak dan 126 pemeriksaan unit lulus.** Kandidat staging, belum deployment produksi. Ini kelanjutan H1 dengan worker, realtime, administrasi tenant, laporan HQ, storage dan konfigurasi deployment. Fase 4–6 PRD belum memenuhi seluruh gate produksi karena server target belum tersedia.

## Perbaikan dan kemampuan yang diverifikasi

- Core PHP tetap memegang transaksi/akuntansi/pajak. Regresi 332 pemeriksaan memakai database kosong dari SQL paket yang diekstrak ulang, first-install, wizard READY, transaksi normal, split/refund, POS, backfill valid/tidak valid, alokasi, shift lock, konkurensi, jurnal, pajak, laporan dan restore. Pengujian transaksi memakai API otomatis; bukan klaim semua formulir diklik manual.
- HQ menerima snapshot bertanda tangan, memeriksa scope/revisi/checksum dan menjaga receipt immutable. Angka konsolidasi direkonsiliasi ke laporan sumber. Laporan beku dipakai bersama oleh JSON/CSV/XLSX/PDF/email/Telegram; mata uang berbeda dipisahkan. Ekspor diuji struktur/angka/checksum, bukan review visual semua halaman.
- Control plane memiliki company/property lifecycle, role/grant/revoke dan audit. Database hotel tetap terpisah; runtime HQ tidak mendapat akses ledger hotel. Provisioning menghasilkan bundle private tanpa menjalankan SQL pada server.
- Worker bersamaan, restart, standby suppression, lima percobaan/DLQ, HTTP 202, ACK salah dan koneksi terputus sesudah commit diuji. Email/Telegram memakai bridge HTTPS dengan job/report ACK dan status uncertain tanpa pengiriman ulang otomatis. Tidak ada pesan dikirim kepada penerima nyata.
- SSE bertiket diuji scope, expiry, batas koneksi, revisi dan kegagalan upstream. Realtime hanya memberi notifikasi revisi; pembacaan tetap melalui API berotorisasi. ACK perangkat harus menunjuk job yang benar dan benar-benar completed.
- Dua PHP dan dua database lokal menguji mirror serta planned switch. Ditemukan dan diperbaiki dependency skema agen yang hilang, collation temporary key yang tidak cocok, penulisan kolom generated MySQL, dan timezone agen. Fixture sumber disalin, fixture standby lama dihapus, checksum cocok, primary lama terblokir, tepat satu writer aktif. Commit fencing menolak epoch/token/writer/lease yang berubah.
- Adapter storage SigV4 diuji dengan server TLS lokal yang memverifikasi signature secara independen: scoped key, conditional write, readback checksum, akses yang dicabut dan payload rusak. TLS database diuji pada validasi konfigurasi; koneksi positif ke endpoint TLS database target belum diuji.

## Rincian hasil

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
| hybrid-pagination | 3 | 0 |
| hq-permission | 7 | 0 |
| second-property | 20 | 0 |
| control | 20 | 0 |
| enterprise-worker | 7 | 0 |
| enterprise-report | 24 | 0 |
| delivery | 18 | 0 |
| object-storage | 7 | 0 |
| ha-commit | 24 | 0 |
| ha-pair | 10 | 0 |
| deployment | 9 | 0 |
| ui | 6 | 0 |

Unit: regression.php (44), regression.mjs (11), finance-regression.php (9), hybrid-regression.php (33), realtime-regression.mjs (16), device-contract-regression.php (9), database-tls-regression.php (4). Total seluruh assertion: **698**. Pemeriksaan YAML/provisioning termasuk dalam kelompok integrasi/artefak, bukan klaim container sudah dijalankan.

## Beban dan batas pengujian

Uji snapshot canonical: 40 request, concurrency 4, 2 proses PHP development lokal; p50 **1122.62 ms**, p95 **1723.11 ms**, maksimum **1911.23 ms**. Angka ini bukan SLA PHP-FPM/produksi dan tidak membuktikan target read umum p95 <800 ms terpenuhi. Uji ini mengukur snapshot agregat, bukan seluruh interaksi aplikasi. Optimasi/sizing harus diukur ulang pada dataset dan hardware target.

Pengujian Windows lokal memakai PHP 8.2.12 dan MySQL 8.4.9. Tidak ada Docker/nginx runtime tersedia: YAML diperiksa sintaksnya, sedangkan build image, validasi nginx/FPM, volume/locking lintas host, rolling restart, load balancer dan koneksi TLS DB target belum dijalankan. Compose beberapa API pada satu host bukan HA lintas mesin.

Masih memerlukan lingkungan target: DB single-writer HA/quorum/fencing, partisi jaringan dan pemulihan primary lama, backup/PITR serta RPO/RTO terukur, DR seluruh stack, cloud IAM/S3 asli, provider pesan, perangkat fisik, routing/domain dan uji kapasitas. UAT payroll/procurement seluruh variasi serta kebijakan eliminasi intercompany khusus belum dinyatakan lulus. Status WARNING dari sumber tidak diubah menjadi PASS untuk mempercantik dashboard.

## Artefak dan cara memakai

Paket `TAMASYA-ENTERPRISE-RC1-2026-09-22.zip` berisi source lengkap dan `FILE_CHECKSUMS.sha256`. Baca `ENTERPRISE_IMPLEMENTATION_STATUS.md` dan `deploy/README.md` terlebih dahulu. Dokumen H1/R4 di dalam paket adalah histori baseline; laporan ini menyatakan status rilis terbaru.

SQL fresh hanya untuk database kosong; database hotel existing harus dicadangkan, diuji restore dan di-upgrade melalui jalur migrasi. Credential, environment private, dump database, token, outbox, CA/private key simulasi tidak dimasukkan paket. Bukti pengujian memuat nama assertion, hasil, unit output dan harness; hasil uji yang gagal sebelum perbaikan tidak dipresentasikan sebagai hasil final.

Rangkaian keuangan diuji dari extract kandidat. Setelah itu source executable dibandingkan hash agar identik; penambahan akhir hanya dokumentasi/manifest. ZIP final diekstrak lagi, checksum diverifikasi, lint PHP/JS dan referensi lokal diperiksa.
