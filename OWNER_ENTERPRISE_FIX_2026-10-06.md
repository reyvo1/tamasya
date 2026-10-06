# Perbaikan Owner — Enterprise dan seluruh akses baca

Build: `20261006-owner-enterprise-r7`.
Status: **SOURCE/UNIT CHECKS PASS — FULL GITHUB UAT PENDING**.
Paket ini melanjutkan R6; aplikasi produksi dan database hotel tidak diubah dari sesi ini.

## Hasil pemeriksaan

- Kode R6 sudah memasukkan Owner ke halaman dan proyeksi baca Enterprise. `requireRoles` juga sudah membolehkan Owner pada GET. Jadi daftar role di satu handler bukan bukti bahwa API produksi menolak Owner.
- Namun URL impor registry navigasi, skrip Growth, dan skrip Enterprise masih memakai versi lama walaupun isinya telah berubah. Browser yang menyimpan versi lama berpotensi memakai pembatas lama. R7 memperbarui URL aset terkait, build, dan precache secara konsisten. Penyebab di server/browser produksi belum diverifikasi langsung.
- Registry modul masih menerapkan pembatas tab lama pada Owner jika dipanggil dengan daftar izin lama. Owner sekarang memperoleh seluruh kartu modul yang sama dengan Admin, termasuk Enterprise, tanpa bergantung pada deny flag akun lama.
- Growth Overview masih menghilangkan hitungan procurement, channel, dan payment untuk Owner; angka modul kini memakai lingkup baca Admin.
- Akses baca Website CMS masih mensyaratkan capability `manage_public_website`, padahal Owner tidak mendapat izin mengubah. GET `website-cms-data` kini dapat dibaca Owner, tanpa memberi capability tersebut.
- Sejumlah aksi Enterprise menggunakan label Inggris atau kontrol dinamis yang belum ditandai sebagai perubahan: posting invoice, alokasi, snapshot campaign, sinkron tagihan, dan pembuatan GRN. Semuanya kini ditandai dan diblokir untuk Owner.

## Perilaku akhir

Owner tetap beridentitas `owner`. Ia dapat membuka kartu Enterprise, semua tab, memilih folio, mencari tamu, membaca saldo/tagihan/pembayaran, rincian PR/PO/GRN/invoice, AP Aging, ringkasan akuntansi, forecast, health, dan metadata adapter. Detail PO, GRN dan supplier invoice memiliki tombol baca terpisah dari tombol pembuat/pembayaran. Invoice folio yang sudah ada tetap dapat dibuka/cetak.

Formulir perubahan tetap terlihat dengan kontrol perubahan dinonaktifkan. Simpan, hapus, alokasi, posting, pembayaran, pembuatan invoice, redeem, pengiriman campaign, dan pengaturan adapter tidak dapat digunakan Owner. Client Growth/Enterprise menolak mutasi sebelum koneksi/probe; API tetap menolak POST/PUT/PATCH/DELETE dengan `403 OWNER_READ_ONLY`, termasuk jika route dipanggil langsung. Izin Admin/Manager/Finance untuk menulis tidak ditambah ke Owner.

Growth dan Enterprise mengikuti feature flag serta kesiapan schema yang sama dengan Admin. Owner tidak dapat mengaktifkan modul yang belum aktif. Jika Admin juga melihat “Terkunci”, itu status aktivasi modul, bukan batas role Owner. Pesan gagal membaca AP Aging/adapter sekarang ditampilkan, sehingga kegagalan tidak terlihat sebagai daftar kosong.

## Validasi dan penggunaan

- Suite Owner PHP: 38 pemeriksaan lulus, termasuk guard route langsung, preset akun lama, proyeksi baca, dan fungsi Growth Overview dengan fixture PDO baca saja.
- Suite Owner JavaScript: 10 pemeriksaan lulus, termasuk registry modul, client transport, kontrol dinamis dan kesesuaian URL build/precache.
- Gate lengkap: syntax, checksum, dan 32 suite source/unit. Fixture unit tidak menjalankan aplikasi hotel atau mengimpor backup.
- UAT GitHub diperluas: klik kartu Enterprise sebagai Owner, verifikasi respons bootstrap/schema, semua tab, rincian sumber, tombol perubahan, Website, forecast, AP Aging, serta percobaan mutasi langsung dan pembandingan keadaan tabel sebelum/sesudah. Browser/API/database UAT ini disiapkan dan belum dijalankan dalam sesi lokal.

Gunakan paket R7 utuh pada kedua node hybrid saat menjalankan UAT/deployment Anda; jangan mencampur aset R6 dan R7. Sesudah update, muat ulang aplikasi dan login ulang akun Owner agar sesi serta aset terbaru dipakai. Pastikan versi kedua node sama dan Growth/Enterprise memang sudah diaktifkan melalui prosedur aktivasi yang disertakan. Perubahan ini tidak memerlukan penggantian role Owner menjadi Admin atau perubahan data transaksi.
