# TAMASYA Canonical Report Engine R3

Build ID: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2-canonical-report-r3-production-audit-r4`

## Tujuan
R3 menjadikan laporan resmi sebagai output dari snapshot data canonical server, bukan salinan DOM/tampilan browser. Satu snapshot yang sama menjadi sumber PDF, Excel `.xlsx`, CSV, JSON audit, dan attachment email.

## Format resmi
- PDF standalone A4 landscape, server-generated, multi-page, tanpa ketergantungan Chrome/headless browser.
- Excel `.xlsx` OpenXML asli, multi-sheet, header frozen, filter, lebar kolom adaptif, dan sel angka tetap numeric agar dapat SUM/filter/pivot.
- CSV untuk interoperabilitas.
- JSON untuk audit/integrasi.
- Email dengan body ringkasan dan attachment PDF/XLSX; CSV opsional. SMTP dan PHP `mail()` didukung. Total attachment dibatasi 12 MB agar aman pada shared hosting.

## Jenis laporan
1. Laporan Keuangan Lengkap
2. Register Transaksi
3. Laporan Pajak / PBJT
4. Jurnal Akuntansi
5. Kas & Bank / QRIS
6. Register Reservasi
7. Register Check-out
8. Laporan Shift & Rekonsiliasi Kas
9. Rekonsiliasi Bank/QRIS
10. Backfill & Koreksi Historis
11. Laporan Gaji
12. Laporan Absensi
13. Laporan Inventaris
14. Pemeliharaan Inventaris
15. Night Audit
16. Housekeeping
17. Audit Log

## Integritas
Setiap snapshot membawa:
- Report ID
- periode
- waktu generate
- build ID
- pembuat dan role
- property identity
- Consistency Guard status
- checksum SHA-256 atas isi canonical report

Report ID/checksum sama untuk format berbeda selama data snapshot dan status integrity tidak berubah. Dataset arsip yang terpotong atau query-nya gagal ditolak secara fail-closed; sistem tidak menghasilkan laporan resmi yang terlihat lengkap padahal datanya kurang.

## Split Payment
Transaksi split diproyeksikan sebagai kaki pembayaran terpisah. Contoh transaksi Rp1.000.000 dengan Tunai Rp400.000 + BCA Rp600.000 tetap menjadi dua kaki tersebut pada PDF, Excel, CSV, JSON, ringkasan cash/bank, dan email. `bankAccountId=null` pada one-row split tidak boleh diterjemahkan sebagai 100% Tunai.

## UI
Pada menu Laporan/Keuangan tersedia `Laporan Resmi PDF / Excel`. Tombol cetak browser lama tetap dipertahankan sebagai cetak cepat/preview dan diberi penanda/tooltip bahwa itu bukan jalur dokumen resmi.

## Shared hosting / VPS
Engine hanya membutuhkan PHP yang sudah dipakai TAMASYA. Tidak membutuhkan Node.js server, LibreOffice, Chrome, wkhtmltopdf, headless browser, atau cron. Jadi dapat berjalan di shared hosting murah dan tetap kompatibel saat pindah ke VPS.

## Database
R3 tidak menambah atau mengubah tabel/kolom/migration. Report engine membaca schema TAMASYA yang sudah ada. `database_setup.sql` tetap SHA-256 `87bebefedefd79d1dbf03be2d470bac14e68804ae6bb2e8621e84ba796338daf`.

## Batas verifikasi sandbox
Regression dapat membuktikan perhitungan/proyeksi, struktur PDF/OpenXML, MIME attachment, role/API wiring, schema compatibility, cache graph, dan seluruh regression TAMASYA. Pengiriman email ke provider SMTP nyata, pencetakan pada printer fisik, dan pembukaan file di setiap versi Microsoft Excel tetap merupakan UAT deployment.
