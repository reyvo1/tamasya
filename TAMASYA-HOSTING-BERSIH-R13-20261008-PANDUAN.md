# Paket hosting bersih R13

Build `20261007-growth-kpi-r13`; commit `1fc95d6733abfcda9e7cb978af088f9f80fd51b4`.
UAT GitHub: https://github.com/reyvo1/tamasya/actions/runs/37650035623 — 7/7 job berhasil.
SHA-256 ZIP: `da515ca0464ca2924d7e50c09a94537c8b6780a49bbaab305f057aea01c462c5`.

Paket berisi 254 file runtime dan template. Tidak menyertakan test, folder Git, kredensial aktif, database hotel atau unggahan pengguna. File aplikasi langsung di akar ZIP.

Perbaikan R13: KPI Dashboard dan detail memakai periode hotel yang sama dan detail dimuat otomatis. Booking aktif melewati checkout tetap terbaca setelah waktu jatuh tempo, checkout aktual membatasi malam dengan benar, dan durasi terbuka tetap masuk forecast. Pembacaan biaya perpanjangan, layanan, dan diskon disamakan pada KPI, folio, sumber alokasi Enterprise, dan forecast. Respons laporan tidak lengkap menjadi pesan error; angka nol yang benar diberi keterangan. Laporan terlihat diperbarui otomatis tanpa mereset isian formulir.

Seluruh perbaikan R2–R12, termasuk harga nego Telegram/web, kas kurang/lebih, hak Owner hanya baca, download laporan, UI dan sinkronisasi dua server, disertakan. Tidak ada perubahan schema database.

1. Cadangkan folder aplikasi dan database hosting.
2. Pertahankan `.env`, kredensial database, kunci enkripsi, konfigurasi/identitas node dan folder `uploads` yang sudah ada. File contoh bukan pengganti konfigurasi aktif.
3. Unggah dan ekstrak ZIP pada folder aplikasi yang sekarang (berisi `api.php`/`index.html`), menimpa file kode. Jangan menghapus seluruh folder aplikasi.
4. Kedua server online/offline harus memakai rilis R13 yang sama, dengan konfigurasi node masing-masing. Tidak ada aktivasi modul atau migrasi otomatis.
5. `database_setup.sql` disertakan untuk pemeriksaan baseline. Jangan mengimpornya ulang ke database hotel berisi data.
6. Buka ulang aplikasi; pastikan cache menggunakan build R13.
7. Hapus ZIP setelah ekstraksi.

## KPI dan Enterprise

Dashboard → KPI Hotel Hari Ini → Detail KPI membawa tanggal yang sama. Detail terisi otomatis; ubah Dari/Sampai untuk laporan lain. Halaman Growth langsung tanpa parameter tanggal memakai awal bulan sampai hari ini.

Dashboard menampilkan jumlah kamar active sekarang. Booking active yang lewat checkout rencana dihitung tetap terisi, tetapi program tidak otomatis menambahkan tagihan atau menerima uang. Bila status active memang lupa ditutup, lakukan checkout melalui workflow hotel. Bila tamu masih menginap, masukkan perpanjangan. Jangan memperbaiki KPI dengan menambahkan penerimaan fiktif.

Malam menginap berasal dari booking, bukan dari transaksi backfill uang saja. Denominator adalah kamar fisik; nilai kamar dialokasikan rata per malam dan belum mempunyai histori tarif per segmen malam/tipe kamar. Forecast Enterprise memakai asumsi durasi terbuka aktif sampai akhir horizon; forecast bukan jurnal atau jaminan pendapatan.

Jika respons tidak lengkap, KPI/forecast/accounting menampilkan kesalahan, bukan angka nol buatan. Pasang kode yang sama pada kedua server agar helper dan kontrak respons sesuai.

## Harga nego R12 tetap tersedia

Perpanjangan Telegram/web dapat memilih harga nego termasuk PBJT dan alasan, lalu Lunas atau Belum Bayar. Checkout nego memakai total akhir seluruh booking termasuk PBJT; simpan harga belum mencatat uang masuk. Setelah itu pilih pembayaran Tunai/Transfer/QRIS/split melalui alur biasa.

## Kas dan shift

Telegram: Kas & Shift → Buka Shift → pilih jadwal/pendamping dan masukkan kas awal fisik. Saat tutup, masukkan uang yang benar-benar ada di laci. Jika kurang atau lebih, isi keterangan; program tetap mencatat selisih. Transfer/QRIS tidak masuk uang laci. Selisih di atas toleransi petugas menunggu pemeriksaan Admin/Manager di web.

Web Admin/Manager: Pusat Operasional → Persetujuan untuk memeriksa selisih; Shift & Tutup Kas → Revisi kas fisik untuk memperbaiki angka hasil hitung yang salah, dengan alasan. Persetujuan atau revisi kas tidak membuat uang masuk dan tidak mengubah receipt. Kesalahan transaksi pembayaran harus dikoreksi melalui jalur audit transaksi. Owner hanya membaca.

## Batas dan pemasangan

Booking dengan routing folio Enterprise harus diperbaiki/dilepas routing-nya dahulu agar invoice tidak berbeda. Snapshot/diskon legacy perlu rekonsiliasi audit sebelum nego. Durasi terbuka tetap menggunakan total aktual. Pembatalan draft sebelum simpan tidak mengubah harga; membatalkan checkout setelah menyimpan harga tidak membatalkan harga yang sudah diaudit.

UAT berjalan pada database CI terpisah. Paket ini belum dipasang otomatis ke hosting Anda.
