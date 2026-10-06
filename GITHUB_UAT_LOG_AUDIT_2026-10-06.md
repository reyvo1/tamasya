# Audit log GitHub dan kandidat R8

Build baru: `20261006-github-uat-r8`.
Bukti dibaca: `/home/ivo/Downloads/logs_101540705649.zip`, 147 entri log, 7 job.
Run yang diperiksa: 6 Oktober 2026; job hotel pada PHP 8.4/8.5, source pada PHP 8.2–8.5.

## Kesimpulan

Penyebab merah merupakan campuran kesalahan skrip aplikasi/simulator, kemasan harness, pemeriksaan UAT yang salah sasaran, dan kegagalan interaksi/geometri UI mobile. Bukan kesalahan pengaturan Owner oleh pengguna. Log benar-benar memuat kandidat R7: manifest mencatat `OWNER_ENTERPRISE_FIX_2026-10-06.md`, suite Owner PHP 38 PASS dan JavaScript 10 PASS. Source lulus pada keempat versi PHP. Akses Owner Enterprise, accounting, forecast, AP Aging dan adapter lulus; tes browser Owner juga tidak termasuk empat kasus gagal.

Pada masing-masing job hotel: **42/46 tes browser lulus** dan **758/759 assertion skenario lulus**. Satu assertion skenario gagal adalah identitas Telegram tidak terikat. Hasil lulus sebelumnya bukan bukti seluruh UAT lulus.

| Temuan log | Klasifikasi | Perbaikan R8 |
| --- | --- | --- |
| Dua tes login/modul desktop dan mobile gagal saat memeriksa batas angka transaksi | Kesalahan pemeriksaan UAT | Selector sebelumnya mencakup angka daftar transaksi dalam panel bergulir. Log membandingkan baris jauh di bawah viewport dengan dasar panel yang tetap. Pemeriksaan kini mengukur kartu ringkasan keuangan/dashboard yang menjadi tujuan tes; pemeriksaan angka tepat dan overflow halaman tetap berlaku. |
| `Telegram forged role cannot bind an unknown identity` gagal; identitas respons tidak tersedia | Kesalahan pada respons simulator server | Pesan/callback simulasi memanggil proyeksi hotel dengan staf `null`. Proyeksi kemudian memanggil `tamasyaSessionPermissions(array $user)`, yang tidak menerima null. Data simulasi kini diproyeksikan hanya ketika identitas Telegram benar-benar terikat. Identitas tak terikat mendapatkan `db: null`, tanpa membaca database hotel melalui proyeksi pengguna. Field role kiriman browser tetap diabaikan. Tidak ada bukti log bahwa identitas asing berhasil menjadi Admin. |
| Menu Operasional mobile tidak stabil lalu terlepas dari DOM ketika akan diklik | Masalah interaksi UI yang terlihat di log | Dropdown dipindahkan ke portal body, sehingga keluar dari ancestor navigasi bergulir/backdrop. Saat scroll/resize, posisi mengikuti tombol alih-alih langsung menutup menu. Klik di dalam menu portal tetap dibedakan dari klik di luar. |
| Judul audit shift pada mobile gagal pemeriksaan jangkauan/hit-test | Geometri atau waktu penyelesaian scroll/fokus; penyebab visual persis belum dapat dipastikan dari log teks saja | Scroll dialog dan halaman saat dialog aktif dibuat langsung; UAT menunggu posisi akhir judul sambil tetap menuntut judul berada dalam viewport dan benar-benar dapat disentuh. Assertion tidak dihapus. Rerun browser diperlukan untuk memastikan; bila masih gagal, screenshot/trace dari artifact Playwright diperlukan. |
| Job VPS/SaaS berhenti `mysql-bootstrap-runtime-boundary.sh: Permission denied`, exit 126 | Kesalahan kemasan/pemanggilan harness | File shell yang diterima lewat ZIP tidak selalu mempunyai executable bit. Bootstrap dan helper readiness dipanggil melalui `bash`; pemanggilan dua-node/HQ juga menggunakan bash agar tidak menimbulkan kegagalan serupa setelah browser lulus. Tidak ada perubahan privilege database, image, timeout readiness, atau trigger verification. |
| Bukti `enterprise-full-two-node-results.json` dan `enterprise-full-hq-results.json` tidak tersedia | Efek langkah yang dilewati setelah browser gagal | Kedua langkah berada setelah browser dan belum berjalan dalam run ini. Ini bukan bukti failover atau HQ gagal. Evidence gate tetap gagal jika bukti belum ada; tidak dilonggarkan. |

## Perubahan dan pengujian

- `api/modules/comms/050_integrations_telegram_mail.php` dan route webhook: gate proyeksi simulasi untuk staf terikat, digunakan pada pesan dan callback. UAT Telegram sekarang menyimpan status/error ketika gagal dan menuntut role kosong serta tidak ada snapshot hotel untuk identitas tak terikat.
- `assets/chunks/app-shell.js`, `viewport-layer.js`, dan stylesheet responsif: portal menu, posisi saat scroll, penanganan klik di dalam/luar, serta scroll dialog langsung. Tombol Owner tetap mengikuti policy read-only dan API tidak menerima hak tulis baru.
- Harness shell/workflow: gunakan bash untuk pemanggilan eksekusi; daftar file yang disalin ke Docker tetap berisi path file biasa. Pemeriksaan lengkap dan urutan bisnis tidak dihapus.
- Tes unit mengeksekusi helper proyeksi simulasi pada identitas kosong/terikat, event handler dropdown dari sumber yang dikirim, dan validator bootstrap pada salinan file mode 0600. Fixture mode 0600 berhenti pada validasi input sebelum mengakses Docker/database. Tidak menjalankan aplikasi hotel atau simulasi Docker lokal.
- Syntax, checksum dan 32 suite source/unit dijalankan untuk R8; suite Telegram menjadi 64 pemeriksaan. Hasil lengkap disertakan dalam log validasi paket. Full GitHub UAT R8 belum dijalankan dalam sesi ini.

## Langkah berikutnya

Unggah paket R8 utuh ke repo dan jalankan kembali workflow GitHub yang sama. Jangan mencampur versi aset/node R7 dan R8. Pastikan kedua job hotel, job VPS/SaaS, serta bukti browser, Telegram, dua-node dan HQ semuanya berhasil sebelum menyatakan UAT penuh hijau. Database/produksi tidak diubah dari sesi audit ini. Perbaikan Owner, UI, Telegram dan keuangan dari rilis sebelumnya tetap disertakan.
