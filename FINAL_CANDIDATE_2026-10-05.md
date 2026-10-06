> Kandidat terbaru: `20261006-owner-enterprise-r7`. Lihat `OWNER_ENTERPRISE_FIX_2026-10-06.md` untuk akses baca Owner di Enterprise/Growth/Website, pemisahan tombol baca/perubahan, dan pembaruan cache. Semua perbaikan R2–R6 tetap disertakan.

> Riwayat R6: `20261006-layout-telegram-r6`. Lihat `UI_TELEGRAM_FIX_2026-10-06.md` untuk perapian kartu/dialog, audit shift dan pembayaran Telegram. Semua perbaikan R2–R5 tetap disertakan.

> Riwayat R5: `20261006-ui-logic-r5`. Lihat `AUDIT_UI_LOGIC_2026-10-06.md` untuk target tahunan, rumus kas/bank, audit UI dan seluruh perbaikan R5.

> Riwayat R4.2: `20261006-module-layout-r4.2`. Lihat `MODULE_LAYOUT_FIX_2026-10-06.md` untuk susunan Memo dan tiga kartu Modul Hotel. Perbaikan R4/R4.1 dan sebelumnya tetap disertakan.

> Riwayat R4: `20261006-owner-readonly-r4`. Lihat `OWNER_UI_FIX_2026-10-06.md` untuk akses Owner dan kosmetik UI; seluruh perbaikan R2/R3 tetap disertakan.

> Riwayat R3: `20261006-report-download-ui-r3`. Lihat `REPORT_DOWNLOAD_FIX_2026-10-06.md` untuk perbaikan unduhan, periode, label jurnal/kas, dan simbol. Catatan di bawah mencatat R2; panduan aktivasi dua node masih berlaku.

# TAMASYA — kandidat final perbaikan hybrid

Build: `20261005-production-hardening-r2`.
Status: **SOURCE CHECKS PASS — FULL GITHUB UAT PENDING**.
Dasar rilis: arsip final commit `806db1bf924728a9065d0e9159ff14583f743a66` (3 Oktober 2026). Arsip final asli tidak diubah. Folder/paket ini adalah kandidat baru dengan perbaikan keuangan sebelumnya dan perbaikan audit berikut.

## Perbaikan yang masuk

1. Penerimaan yang PBJT-nya belum diketahui tetap masuk bruto dan grafik kas/bank. Kasus backup 28 Januari: semua penerimaan Rp3.151.000, tunai Rp2.711.000, bank Rp440.000; Rp470.000 belum lengkap pajaknya. Pendapatan diakui tetap mengikuti snapshot yang sah. Grafik memiliki pilihan semua, tunai, semua bank, transfer, QRIS dan kartu; split dihitung menurut masing-masing kaki pembayaran.
2. Pengelompokan pajak menjaga identitas transaksi historis dan snapshot pajak yang sah, serta memasukkan transaksi tanpa nomor kamar ke kelompok tersendiri. Pengaturan pemakaian kategori yang sudah diperbaiki pemilik tidak diubah oleh patch.
3. Tanggal bawaan laporan resmi, arsip POS dan KPI mengikuti zona properti. Awal bulan tidak melewati konversi UTC; periode dihitung lagi ketika dialog dibuka. Cache KPI dipisahkan menurut tanggal bisnis. Rentang historis yang dipilih pengguna tetap digunakan.
4. Komunikasi, konfigurasi integrasi, node forwarding/sync, pembacaan bukti dan backup diperbarui untuk deprecation PHP 8.5. Penanganan error tetap ketat; verifikasi TLS tetap aktif. Konstanta driver MySQL modern diprioritaskan dengan fallback PHP lama. Rujukan teknis: https://www.php.net/manual/en/migration85.deprecated.php dan https://www.php.net/manual/en/function.http-get-last-response-headers.php.
5. Impor React dan URL precache kini sama. URL aset yang berubah memakai versi baru agar cache deployment lama tidak dipakai.
6. Ringkasan harian memakai semantik canonical: uang yang diterima tetap terlihat, saldo awal/mutasi internal/piutang teknis tidak menjadi penerimaan eksternal baru, refund mengurangi PBJT, dan tanggal kalender tidak sah ditolak. Nilai bruto dan pendapatan diakui dipisahkan. Statistik kamar/HK diberi metadata `operational_scope=current_snapshot` dan `operational_date`, karena bukan rekonstruksi historis kondisi kamar.
7. CSV canonical, laporan frontend, arsip POS dan gaji menetralkan formula pada teks pengguna, termasuk awalan whitespace. Angka negatif tetap angka; snapshot audit asli tidak berubah.
8. Refresh transaksi memuat katalog kategori/subkategori sekali, bukan lookup ulang per baris dengan ID. Lookup label legacy tetap memakai aturan/collation database dan dicache per label unik. Seluruh histori tetap tersedia; volume histori masih memerlukan kapasitas server yang memadai.
9. CSP pada profil Nginx hotel/VPS diselaraskan dengan kebijakan Apache, termasuk lokasi aset yang memiliki `add_header` sendiri.
10. **Tabel `growth_internal_memos` ditambahkan ke snapshot sinkronisasi hybrid dan pemeriksaan kesiapan Enterprise.** UAT dua node menambahkan pembuatan Memo melalui Standby, kesamaan isi setelah mirror/promosi, archive melalui arah Primary terbalik, dan kesamaan isi sesudah mirror kembali.

## Growth, Enterprise dan Memo

Pemilik mengotorisasi pembukaan Growth/Enterprise pada 5 Oktober 2026. Profil aktivasi ada di `.env.growth-enterprise.example`; installer aman ada di `scripts/activate-growth-enterprise.php`.

Runtime tanpa konfigurasi tetap OFF agar deployment lama dengan schema belum lengkap tidak membuka modul secara diam-diam. Skrip aktivasi secara eksplisit mengaktifkan induk dan modul operasional Growth/Enterprise setelah installer schema berhasil pada database yang namanya diverifikasi. Memo memakai tabel Enterprise, dengan akses Admin/Finance dan Owner read-only. Provider live dan pengiriman campaign tetap memerlukan konfigurasi tersendiri.

Tidak ada database hotel, environment produksi, peran Primary/Standby, secret, atau data pajak yang diubah dalam pekerjaan lokal ini.

## Validasi yang sudah dilakukan

- Seluruh 27 suite regresi sumber/unit lulus pada PHP 8.5.4 dan Node 22.23.3.
- Suite tambahan: 19 pemeriksaan PHP, 6 pemeriksaan JavaScript; termasuk arus uang/refund/PBJT, 1.000 transaksi dengan dua pembacaan katalog, CSV, aktivasi environment, kesiapan Memo dan registry mirror.
- Sintaks PHP/JavaScript, Python, shell dan parsing YAML workflow berhasil.
- Pemeriksaan checksum dilakukan kembali pada paket final yang disiapkan.
- Full aplikasi/database/browser UAT terbaru **belum dijalankan lokal**. Tidak ada klaim paket ini sudah GREEN di GitHub.

## Jalankan UAT GitHub

1. Ekstrak paket kandidat ini dan unggah seluruh isinya, termasuk `.github`, ke checkout repository GitHub. Gunakan branch baru dari baseline final. Jangan menimpa database/kredensial/`.env` server dengan contoh konfigurasi.
2. Jalankan Actions **TAMASYA Enterprise Full Complete UAT** melalui `workflow_dispatch`, atau push ke branch `rc1/**`/`release/**` yang sudah tercakup workflow.
3. Source gate sekarang menjalankan PHP 8.2/8.3/8.4/8.5. Full hotel/API/browser/hybrid UAT berjalan pada PHP 8.4 dan 8.5, masing-masing menggunakan database CI terpisah. Artefak memakai suffix versi PHP agar tidak bertabrakan.
4. Browser menambahkan pemeriksaan nominal grafik tanggal 28, default periode dan pembukaan ulang bulan berikutnya di WITA, serta impor React offline dari precache baru. Tes grafik menggunakan response fixture sintetis; tes jurnal/backfill/booking tetap memakai database CI.
5. Tidak ada tes lama yang dihapus atau assertion kegagalan dilonggarkan. Bukti dua-node ditambah, dan batas minimum bukti dua-node dinaikkan.
6. Simpan SHA commit kandidat dan seluruh artefak/log. Hanya jika semua job GREEN pada commit yang sama, buat final lock baru untuk commit tersebut. GREEN baseline lama tidak otomatis mengesahkan patch ini.

Pemeriksaan sumber lokal yang sama tersedia lewat:

```bash
bash scripts/verify-release.sh
```

Skrip ini tidak membuka aplikasi hotel atau mengimpor database. Tes transport tertentu memakai fixture loopback sementara.

## Aktivasi pada dua server setelah UAT GREEN

Gunakan maintenance window: hentikan aktivitas penulisan pengguna dan worker sinkronisasi; backup database **kedua node** dan environment masing-masing. Terapkan kode build yang sama pada kedua node. Pertahankan identitas hotel/cluster, node ID, URL dan secret yang sudah digunakan.

Pada **masing-masing node**, periksa dahulu (ganti semua placeholder sesuai node):

```bash
php scripts/activate-growth-enterprise.php --expected-db=NAMA_DB_NODE --env-file=/path/private/node.env
```

Kemudian terapkan:

```bash
php scripts/activate-growth-enterprise.php --expected-db=NAMA_DB_NODE --env-file=/path/private/node.env --apply --backup-confirmed=REFERENSI_BACKUP_TERVERIFIKASI
```

Skrip memverifikasi database, menjalankan installer resmi Growth+Enterprise, lalu menulis flag secara atomik pada environment node. Backup environment disimpan privat dengan mode 0600; file environment hasil aktivasi juga 0600. Jalankan sebagai pemilik file yang dapat dibaca runtime PHP. Jika environment diinjeksi Docker/PHP-FPM/systemd/hosting, samakan flag di konfigurasi proses tersebut karena nilai proses mengalahkan file `.env`.

Setelah schema kedua node lengkap dan flag sama: restart runtime sesuai deployment, aktifkan kembali sync, periksa bootstrap Growth/Enterprise pada kedua node dan kesamaan Memo setelah mirror, lalu buka maintenance. Installer tidak mempromosikan node; penulisan tetap mengikuti Primary tunggal, forwarding, fencing dan prosedur pergantian yang sudah ada.

## Data yang masih membutuhkan bukti pengguna

Toar Rp250.000 dan Bahar Katili Rp220.000 pada 28 Januari tetap membutuhkan DPP/PBJT dari bukti transaksi. Program tidak menebak tarif atau menandai pajak sudah direkonstruksi tanpa bukti. Rekening campuran berjenis `bank` masuk cakupan semua pembayaran; pemisahan QRIS/kartu mengikuti jenis rekening yang benar, bukan tebakan berdasarkan nama rekening.
