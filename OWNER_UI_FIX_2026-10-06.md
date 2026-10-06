# TAMASYA — Owner seperti Admin dengan akses baca saja

Build: `20261006-owner-readonly-r4`.
Status: **kandidat untuk UAT GitHub; belum final lock produksi**.
Paket ini memuat seluruh perbaikan keuangan, unduhan, Growth/Enterprise dan Memo dari R2/R3.

## Perilaku Owner

Owner dapat membuka seluruh menu dan submenu seperti Admin, membaca data hotel, transaksi, gaji, absensi, aset, jurnal, pajak, audit, perangkat, sinkronisasi, riwayat backup, Growth/Enterprise dan Memo. Pencarian, filter, navigasi, rincian dan unduhan laporan tetap dapat digunakan. Modul yang belum diaktifkan tetap mengikuti status aktivasi yang sama dengan Admin.

Tombol perubahan dan input formulir perubahan dinonaktifkan. Owner tidak dapat membuat, menyimpan, menghapus, membayar, mengarsipkan, mengganti pengaturan, mengirim laporan lewat email, mempromosikan node atau menjalankan restore. Formulir Growth/Enterprise dan Memo dapat dilihat sebagai formulir baca saja, sehingga fungsi tidak hilang dari tampilan. Identitas akun tetap `owner`; aplikasi tidak menyamarkannya sebagai Admin.

Login, pembaruan sesi dan logout tetap berjalan. Pembatasan baca saja berlaku pada perubahan bisnis; metadata autentikasi dan audit keamanan tetap mengikuti mekanisme server yang ada.

## Masalah yang ditemukan dan diperbaiki

1. Owner sebelumnya mempunyai menu utama lengkap, tetapi proyeksi hotel/operasional masih jatuh ke pembatasan staf lapangan. Akibatnya gaji, absensi, Telegram, jurnal, perangkat, pajak, HK, biaya maintenance dan riwayat backup dapat kosong atau terbatas. Owner kini membaca proyeksi Admin yang sudah disanitasi, tanpa mendapat capability untuk mengubah data. Token pengoperasian smart-lock tetap dimask untuk Owner.
2. Daftar submenu Operasional sebelumnya memperlakukan Owner sebagai role lapangan. Semua submenu dan tombol pembuka diagnostik kini tersedia untuk dibaca.
3. Permission Owner yang lama dapat menyembunyikan menu di frontend atau membawa flag perubahan. Preset Owner kini tetap: semua workspace baca aktif dan capability perubahan mati; preset diterapkan pada login, verifikasi 2FA, refresh sesi dan respons hotel. Role lain mempertahankan hak akses kustomnya.
4. Cakupan GET Simpanan Karyawan dan Absensi diperluas untuk Owner. Flag `canManage` Simpanan tetap false; Owner dapat melihat karyawan lain tetapi tidak menyetor, membayar, menyetujui, atau mengoreksi saldo.
5. Guard frontend dipasang sebelum klien aplikasi dan mencegah permintaan tulis Owner mencapai transport. Callback perubahan dari shell juga ditolak sebelum pembaruan lokal/antrean offline. API tetap mempunyai pengaman server `OWNER_READ_ONLY` untuk POST/PUT/PATCH/DELETE, termasuk ketika frontend dilewati atau request diteruskan antar-node.
6. POS Owner juga tidak dapat mengubah keranjang melalui kartu produk atau tombol plus/minus. Pencarian kategori/produk tetap tersedia.

## Pemeriksaan kosmetik UI

- Simbol encoding rusak, label kas/jurnal dan checkbox PDF/Excel/CSV telah diperbaiki pada R3 dan tetap disertakan.
- Dialog laporan kini memakai ukuran border-box, lebar sesuai ruang yang tersedia, serta kolom `minmax(0,...)`. Padding dan lebar intrinsik input tidak lagi mendorong dialog melewati layar pada ukuran sempit.
- Banner “Mode Owner — hanya melihat” menjelaskan akses sesi. Teks nilai pada input baca saja tetap terlihat, tombol perubahan memakai penanda nonaktif dan kursor yang sesuai, serta banner menyesuaikan layar kecil.
- Navigasi dan filter dipisahkan dari tindakan perubahan, sehingga tab seperti Rekonsiliasi, Maintenance dan Aturan Pajak tetap dapat dibuka.
- UAT desktop/mobile memeriksa banner, seluruh submenu penting, filter tanggal yang tetap aktif, unduhan PDF yang aktif, pengiriman email yang nonaktif, formulir baca saja, checkbox lampiran, simbol dan batas dialog terhadap viewport.

Pemeriksaan lokal menggunakan kode, CSS dan gambar keluhan yang tersedia. Tampilan akhir pada browser produksi belum diamati setelah deploy kandidat ini; karena itu kosmetik seluruh halaman belum dinyatakan lulus visual. Tidak ada simulasi penuh aplikasi atau impor database produksi dilakukan.

## Validasi dan UAT

Pemeriksaan sumber/unit mencakup guard server yang benar-benar menghentikan empat metode perubahan, proyeksi data Owner lengkap, capability tetap tertutup, preset frontend, callback offline, navigasi/filter, precache, dan ukuran dialog. Dua suite baru menambahkan 19 pemeriksaan PHP dan 6 JavaScript; keseluruhan kandidat mempunyai 30 suite sumber/unit.

Workflow `.github/workflows/tamasya-enterprise-rc1-uat.yml` mempertahankan gate lama, termasuk dua server/database, failover, mirror Memo, keuangan/pajak, backup/restore serta 44 kasus browser desktop/mobile. UAT Owner diperluas untuk membandingkan cakupan data Owner dengan Admin dan mencoba mutasi langsung melalui API. Full UAT terbaru belum dijalankan lokal.

Gunakan akun dengan role **Owner** yang sudah tersedia di menu Karyawan. Akun Owner lama mendapatkan preset saat login ulang atau refresh sesi; tidak perlu mengubah transaksi atau melakukan migrasi data hotel untuk patch role ini. Admin tetap membuat/mengubah akun. Terapkan build yang sama pada kedua server setelah UAT GREEN. Aktivasi Growth/Enterprise mengikuti skrip dan panduan dua-node di `FINAL_CANDIDATE_2026-10-05.md`.
