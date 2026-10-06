# Audit kode lintas aplikasi TAMASYA — 5 Oktober 2026

Catatan tindak lanjut: temuan kode di bawah telah diperbaiki pada kandidat `20261005-production-hardening-r2`. Validasi dan batas yang masih menunggu UAT/deployment tercantum di `FINAL_CANDIDATE_2026-10-05.md`. Uraian berikut mempertahankan hasil audit sebelum perbaikan.

Kesimpulan audit awal: ada kekurangan tambahan di luar masalah penerimaan 28 Januari. Prioritasnya adalah konsistensi tanggal laporan, kompatibilitas runtime PHP, cache offline, dan ringkasan harian. Temuan baru di bawah belum diperbaiki pada kode aplikasi; permintaan terakhir adalah pemeriksaan saja.

## Batas pemeriksaan

Pemeriksaan ini menelusuri router, dependensi, kebijakan otorisasi, transaksi booking/keuangan, POS, operasional, SDM, komunikasi, hybrid/HQ, ekspor, dan konfigurasi deployment. Inventaris sumber mencakup 25 modul route dan 40 modul/support domain. Pemindaian statis mencakup 121 file PHP di luar tests, 1.213 deklarasi fungsi, 55 file JavaScript, dan 72 referensi impor modul.

Tidak ditemukan referensi file impor JavaScript atau aset HTML lokal yang hilang. Pemindaian pemanggilan fungsi berawalan `tamasya` tidak menemukan fungsi yang tidak dideklarasikan. Pemeriksaan ini tidak mencakup pembuktian semua jalur dinamis atau semua kombinasi kondisi bisnis.

UAT GitHub yang disebut pengguna merupakan bukti pengujian sebelumnya. Setelah pengguna menjelaskan bahwa simulasi tidak perlu diulang, simulasi lokal dihentikan, server sementara dimatikan, dan container database sementara dihapus. Tidak ada klaim bahwa UAT lokal menyeluruh telah selesai. Data hotel tidak diubah. Log yang sudah ada sebelum penghentian dipakai sebagai bukti tambahan untuk temuan kompatibilitas PHP.

## Temuan yang perlu ditindaklanjuti

### 1. Prioritas tinggi: batas tanggal laporan resmi bergeser karena UTC

Lokasi: `assets/canonical-report-center.js:9–10`, pemakaian pada baris 36. Kasus serupa: `assets/pos-report-archive-addon.js:11–12` dan `assets/growth-pms-link-addon.js:184`.

Laporan resmi membuat awal bulan dengan `new Date(tahun, bulan, 1)`, lalu mengubahnya dengan `toISOString().slice(0,10)`. Konstruktor tersebut memakai zona browser, sedangkan `toISOString()` menghasilkan UTC. Untuk browser dengan zona Asia/Makassar, 1 Oktober pukul 00.00 menjadi 30 September pukul 16.00 UTC. Isian “Dari” bawaan dapat menjadi **2026-09-30**, sehingga laporan bulanan mencakup satu hari dari bulan sebelumnya. “Sampai” juga dapat menjadi kemarin ketika laporan dibuka sebelum pukul 08.00 WITA.

Nilai `today` dan `monthStart` pada laporan resmi dibuat sekali saat addon dimuat. Jika halaman dibiarkan melewati pergantian hari/bulan, pembukaan ulang modal dapat memakai nilai lama.

Perbaikan yang disarankan: gunakan tanggal bisnis dari server atau format tanggal dengan timezone properti; hitung ulang nilai bawaan ketika modal dibuka. Tetap pertahankan periode historis yang dipilih pengguna. Perbaikan rollover POS sebelumnya belum mencakup semua addon laporan ini.

### 2. Prioritas tinggi pada PHP 8.5: deprecation berubah menjadi HTTP 500

Lokasi utama: `api/routes/070_config_telegram_admin.php:377` dan `api.php:195–201`.

Runtime requirements menyebut PHP 8.5 sebagai versi yang direkomendasikan, sementara workflow hotel UAT menggunakan PHP 8.4. Kode masih membaca variabel predefined `$http_response_header`, yang deprecated pada PHP 8.5. Error handler mengubah error yang dilaporkan menjadi `ErrorException`.

Log lokal yang sudah terbentuk sebelum simulasi dihentikan merekam HTTP 500 pada action `categories`: pemuatan route konfigurasi yang dilewati router memicu deprecation `$http_response_header`, walaupun request bukan pengujian Gemini. Ini menunjukkan bahwa dampaknya dapat mencapai endpoint yang tidak berkaitan langsung dengan integrasi.

Lokasi lain: `api/modules/comms/050_integrations_telegram_mail.php:75,185`, `node_cluster_support.php:958`. Ada juga pemakaian `curl_close()`, `finfo_close()`, dan konstanta driver `PDO::MYSQL_ATTR_*`, yang perlu diaudit terhadap deprecation PHP 8.5. Pemakaian ini terdapat pada komunikasi, sinkronisasi, pembacaan bukti/identitas, dan TLS database.

Perbaikan yang disarankan: gunakan `http_get_last_response_headers()` pada runtime yang mendukungnya dengan fallback yang aman untuk PHP 8.2/8.3; hapus penutupan objek yang deprecated atau gunakan kompatibilitas versi; sesuaikan konstanta driver dengan tetap mempertahankan verifikasi TLS. Jangan menganggap semua warning aman atau menurunkan validasi bisnis untuk menutupi masalah. Sampai kompatibilitas selesai, klaim dukungan PHP 8.5 belum sesuai dengan perilaku kode.

Rujukan: [PHP 8.5 deprecated features](https://www.php.net/manual/en/migration85.deprecated.php), [deprecation variabel header HTTP](https://wiki.php.net/rfc/deprecations_php_8_5).

### 3. Prioritas tinggi untuk offline: versi vendor React berbeda dengan precache

Lokasi: `assets/app-core.js:1`, `sw.js:5,27`.

Core mengimpor `vendor-react.js?v=20260825-scrollshift-fix31`, sementara service worker melakukan precache untuk `vendor-react.js?v=20261005-finance-cash-tax-r1`. Cache matching memakai URL lengkap; kedua query tersebut adalah key berbeda.

Pada kunjungan pertama, aset awal dapat selesai dimuat sebelum service worker mengontrol halaman. Jika kemudian koneksi putus sebelum URL vendor yang benar masuk runtime cache, precache yang tersedia tidak memenuhi impor core. Pembukaan aplikasi offline dapat gagal walaupun vendor React sudah terlihat dalam daftar precache.

Perbaikan yang disarankan: samakan URL versi vendor yang benar-benar diimpor dengan precache. Ini dapat ditentukan lewat pemeriksaan dependensi tanpa mengulang simulasi aplikasi. Masalah ini sudah ada pada pola versi sebelumnya dan tetap terdapat pada paket saat ini.

### 4. Prioritas menengah: ringkasan harian memakai logika keuangan berbeda

Lokasi: `api/modules/finance/110_daily_summary.php:26–43`; pemanggil `api/routes/090_operations_communications.php:1428–1436`.

Ringkasan harian menjumlahkan semua `amount` berdasarkan `type` dan semua `taxAmount > 0`. Sementara laporan canonical memakai semantik transaksi dan tanda negatif untuk refund pendapatan (`canonical_report_support.php:157–158`).

Akibatnya:

- Pajak pada refund ditambahkan dalam ringkasan harian, seharusnya mengurangi pajak pendapatan.
- Saldo awal, mutasi internal, dan transaksi teknis dapat masuk angka pemasukan/pengeluaran mentah sehingga tidak sebanding dengan arus operasional.
- Untuk tanggal historis, jumlah kamar aktif dan housekeeping yang dikembalikan adalah kondisi sekarang, bukan kondisi pada tanggal yang diminta.

Temuan ini berada pada command `daily-summary`, bukan pernyataan bahwa semua grafik dashboard menggunakan fungsi tersebut.

Perbaikan yang disarankan: gunakan semantik keuangan canonical untuk angka keuangan, validasi tanggal kalender, dan jelaskan atau pisahkan indikator operasional “saat ini” dari laporan historis.

### 5. Prioritas menengah: CSV belum menetralkan formula pada teks pengguna

Lokasi: `canonical_report_support.php:269–295`. Deskripsi transaksi diteruskan ke snapshot pada baris 106. Ekspor CSV frontend lain juga perlu mengikuti kebijakan yang sama.

String deskripsi/nama ditulis sebagai sel CSV tanpa perlakuan untuk awalan formula spreadsheet. `fputcsv` mengamankan struktur CSV, tetapi tidak membuat teks berawalan `=` menjadi teks biasa di Excel/LibreOffice. Contohnya, deskripsi `=1+1` dapat dibaca sebagai formula saat file dibuka.

Perbaikan yang disarankan: bedakan angka yang sah dari teks pengguna, netralkan awalan formula pada teks saat ekspor CSV, dan pertahankan angka negatif sebagai angka. Snapshot audit sumber tetap menyimpan teks asli. Tidak ada klaim bahwa eksploitasi telah terjadi.

Rujukan: [OWASP CSV Injection](https://community.owasp.org/attacks/CSV_Injection).

### 6. Prioritas menengah untuk skala: refresh penuh membaca seluruh histori dan query per transaksi

Lokasi: `api/support/070_data_projection_scope.php:314–340`, `api/modules/finance/0185_finance_catalog_identity.php:198–224`.

`hotel-data` membaca seluruh transaksi dan alokasi aktif. Enrichment kategori/subkategori dilakukan dalam loop transaksi dan menjalankan lookup SQL tersendiri. Ketika kedua identitas perlu dicari, N transaksi menghasilkan sekitar 2N lookup tambahan, selain query utama. Paginated finance ledger belum menghilangkan beban refresh penuh ini.

Ini adalah kekurangan desain performa yang terlihat dari kode, bukan hasil pengukuran timeout server produksi. Dampaknya bertambah saat histori hotel membesar atau banyak perangkat melakukan refresh.

Perbaikan yang disarankan: muat katalog sebagai map sekali per request atau gunakan join/batch lookup; pisahkan refresh operasional dari arsip historis tanpa menghilangkan histori dari perhitungan laporan.

## Catatan konfigurasi dan pengemasan

- Profil Nginx belum memuat CSP yang setara dengan `.htaccess`. Periksa header pada reverse proxy target sebelum menganggap perlindungan browser Apache juga berlaku pada VPS.
- Dua file JSON hasil audit keuangan yang dibuat dalam percakapan ini sudah dipindahkan keluar document root ke direktori private audit. JSON di root dapat dilayani langsung oleh konfigurasi web, sehingga hasil audit berisi angka keuangan tidak semestinya ikut paket publik. Tidak ada bukti kedua file tersebut pernah dipasang ke server publik.
- PHP 8.4 pada workflow dapat menjelaskan mengapa temuan kompatibilitas PHP 8.5 tidak muncul di UAT sebelumnya. Fixture yang tidak mencakup refund pada ringkasan harian, tanggal lokal awal bulan, dan cold-start offline juga tidak membuktikan jalur tersebut sudah benar.

## Bagian yang memiliki pagar penting di kode

Pemeriksaan menemukan pemisahan role/capability/tab pada server, owner read-only, bukti pembayaran/identitas melalui endpoint berotorisasi, operation receipt untuk request yang responsnya hilang, posting/mutasi keuangan canonical, transaksi dan lock pada alur sensitif, kebijakan approval yang mengikat payload/nominal, serta backup yang membawa trigger dan bukti restore. Tidak ditemukan alasan dari penelusuran ini untuk mengganti seluruh arsitektur.

Keberadaan pagar tersebut bukan sertifikasi semua jalur bebas bug. Hasil audit ini adalah daftar pekerjaan konkret sebelum penguncian produksi, dengan prioritas 1–4 dikerjakan terlebih dahulu. Perbaikan penerimaan/grafik/PBJT pada tahap sebelumnya tetap tercatat terpisah di `FINANCE_FIX_2026-10-05.md`.
