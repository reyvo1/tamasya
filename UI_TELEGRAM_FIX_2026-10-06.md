# Perapian UI dan audit alur Telegram — R6

Build kandidat: `20261006-layout-telegram-r6`.
Status: kandidat untuk UAT GitHub, belum diterapkan ke server hotel. Full aplikasi/database/browser dan pengiriman Telegram nyata tidak dijalankan lokal.

## Temuan dan perubahan

| Temuan terkonfirmasi dari sumber | Perbaikan |
| --- | --- |
| Enam kartu keuangan dipaksakan ke enam kolom berdasarkan lebar viewport, padahal berada pada panel kanan yang lebih sempit. | Kolom mengikuti ruang kartu yang tersedia, dengan lebar minimum 210px. Dashboard menggunakan minimum 280px. Badge PBJT dan nominal daftar transaksi juga dapat membungkus; DPP/PBJT pada badge menampilkan pecahan aslinya. Angka tetap lengkap, termasuk minus dan desimal; tidak disembunyikan atau dibulatkan untuk menyesuaikan ukuran. |
| Kartu ringkasan jurnal memakai overflow-hidden dan teks/flex item tidak boleh menyusut. | Teks nominal dapat membungkus, isi boleh menyusut, ikon tetap berukuran wajar. |
| Dialog audit shift terperangkap dalam stacking context halaman dengan animasi opacity dan berada di bawah navigasi. | 21 overlay React dipindahkan melalui portal ke body, termasuk audit shift, keuangan, kamar, inventaris, cuti, staf, dan nota. Lapisan dialog berada di atas navigasi; panel dapat di-scroll pada tinggi layar pendek. Fokus awal, siklus Tab dan pemulihan fokus disediakan; scroll latar dikunci selama dialog terbuka. |
| Memo terdesak dan membungkus di bawah judul Modul Hotel. | Kelompok judul/Memo mendapat ruang sendiri; utilitas pindah baris pada layar kecil. |
| Kartu POS, folio, Growth/Enterprise, panel multi-property, memo panjang, notifikasi toast dan form memiliki ukuran minimum yang bisa melampaui layar sempit. | Grid, pembungkusan teks, batas form/dialog/toast dan scroll tabel diperbaiki pada stylesheet masing-masing. Aturan nota cetak dipertahankan. |
| Telegram perpanjangan dan layanan tambahan: tombol atau teks Lunas tidak menawarkan metode pembayaran; workflow menerima default tunai. | Lunas membuka draft, lalu pengguna memilih Tunai/Transfer/QRIS, memilih akun aktif yang sesuai untuk non-tunai, dan menekan konfirmasi akhir. Workflow kini menolak pembayaran lunas tanpa metode eksplisit. Belum Lunas tetap menambah folio tanpa receipt uang. |
| Nama state DP sewa durasi terbuka sama dengan panjar booking aktif; handler panjar JSON menangkap konteks penjualan yang berformat lain. | State DP penjualan dipisahkan, input angka divalidasi, dan tombol lama harus cocok dengan konteks kamar/tamu/nominal. Akun EDC kartu tidak disamarkan sebagai Transfer pada alur tersebut. |
| Preview biaya perpanjangan lewat teks memakai integer sehingga nilai pecahan dapat hilang. | Nominal preview/konteks memakai dua desimal; saat konfirmasi server memeriksa ulang booking, tarif dan pajak dalam transaksi yang terkunci. |
| Beberapa input uang membuang karakter non-digit: -500 menjadi 500, 1.5 menjadi 15, dan pecahan uang hilang. | Parser rupiah bersama mempertahankan pemisah ribuan/tanda koma desimal yang sah dan menolak input ambigu, negatif, teks atau nominal melewati batas. Pesan Telegram mempertahankan desimal. |
| Owner bisa masuk alur cuti pribadi Telegram yang mengubah data. | Callback/pending state mutasi cuti ditolak bagi Owner; guard juga dipasang pada fungsi create/cancel bersama. Riwayat tetap dapat dibaca. |

## Alur pembayaran tambahan Telegram setelah perbaikan

Perpanjang / Tambah Layanan → isi malam / jenis, harga dan qty → Lunas → Tunai / Transfer / QRIS → akun aktif jika diperlukan → konfirmasi biaya dan pembayaran → workflow booking canonical.

Draft terikat pada staf, booking ID, checkout, total booking, nonce dan batas waktu 15 menit. Callback dari draft lain/kedaluwarsa ditolak. Akun nonaktif atau jenis akun yang salah ditolak. Tidak ada akun QRIS tidak otomatis menjadi tunai. Operation ID final stabil per draft, sehingga klik ulang tidak menambah tagihan atau receipt kedua. Receipt tetap memakai validasi pajak, katalog, jurnal, alokasi folio dan shift dari workflow canonical. Lunas pada pesan merujuk ke tambahan tersebut; booking yang sebelumnya berutang tetap dapat mempunyai sisa tagihan.

Sewa, panjar, checkout (termasuk split), pindah kamar, housekeeping, permintaan tamu, patroli, shift, cuti, binding, callback panjang, webhook, broadcast/outbox dan fencing Primary/Standby ditelusuri pada sumber. Guard identitas Telegram User ID, secret webhook, chat privat, role/capability, deduplikasi update dan transaksi rollback sudah ada dan tetap digunakan. Fungsi baru tidak mengirim pesan ke akun Telegram atau menulis database produksi selama pekerjaan ini.

Audit sumber tidak membuktikan semua kombinasi penggunaan Telegram bebas kesalahan. Pengiriman bot nyata, rekening/tax_rules hotel, jaringan dua server, printer dan seluruh tampilan dengan data produksi masih membutuhkan UAT pada commit kandidat yang sama.

## Bukti dan UAT

- Gate sumber/unit mencakup 32 suite; suite baru pembayaran Telegram mempunyai 60 pemeriksaan menggunakan objek PDO dalam memori dan workflow double. Suite ini memeriksa pemilihan/dispatch pembayaran, bukan menjalankan posting database hotel.
- UAT GitHub ditambah untuk extension berbayar lewat cash/transfer/QRIS, receipt/folio/shift/pajak dan replay; layanan tambahan QRIS dan pemisahan state DP penjualan; baseline kasus unpaid, layanan tambahan, transfer dan housekeeping tetap ada.
- 46 kasus browser desktop/mobile disiapkan (belum dieksekusi lokal). UAT browser ditambah untuk nominal `Rp 97.770.600,3` yang tetap utuh dalam kartu, lebar 360/390/1024/1366/1440px, tinggi 768px, judul audit shift tidak tertutup navigasi, scroll catatan panjang dan tombol Tutup yang dapat dijangkau. Periksa ruang dalam kartu, bukan hanya overflow halaman.
- Kartu dan stylesheet utama/standalone yang berubah memakai versi cache R6; stylesheet bersama dan modul portal masuk precache. Hybrid/offline tetap memakai file dan build yang sama pada kedua node.

## Pemakaian

Gunakan ZIP kandidat R6 lengkap, termasuk `.github`, dari rilis yang diberikan. Jalankan workflow **TAMASYA Enterprise Full Complete UAT** pada GitHub. Kandidat mencakup perbaikan R2–R5 sebelumnya. Sesudah seluruh job GREEN pada commit yang sama, terapkan build yang sama pada Primary dan Standby sesuai prosedur rilis hybrid. Jangan mengganti `.env`, kredensial atau database hotel dengan fixture UAT.

Draft Telegram yang terbuka sebelum pergantian build sebaiknya dibatalkan dan diulang dari menu. Tidak ada migrasi database baru untuk R6.
