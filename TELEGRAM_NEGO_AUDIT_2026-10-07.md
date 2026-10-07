# Harga nego web dan Telegram — R12

Build `20261007-telegram-nego-r12`. Memperbaiki ketidaksamaan perpanjangan web/Telegram dan kebutuhan menetapkan harga final saat checkout setelah perpanjangan belum dibayar.

## Perilaku

- Sewa awal: fasilitas nego yang ada tetap dipertahankan.
- Perpanjangan Telegram: pilih malam, harga master atau harga nego total termasuk PBJT, alasan, lalu Lunas atau Belum Bayar. Lunas memilih Tunai/Transfer/QRIS dan akun penerima serta konfirmasi akhir. Belum Bayar menambah folio tanpa penerimaan kas.
- Perpanjangan web: label biaya master jelas sebelum PBJT; opsi harga nego total termasuk PBJT dan alasan. Perhitungan PBJT desimal konsisten dengan server.
- Checkout durasi tetap web/Telegram: opsi harga nego sebelum pelunasan. Input **total tagihan final seluruh booking termasuk PBJT**, bukan hanya nominal sisa pembayaran. Tampilkan tagihan lama, uang diterima, layanan yang tetap, batas minimum, PBJT final, sisa dibayar dan alasan sebelum menyimpan.
- Potongan hanya pada komponen kamar/perpanjangan yang belum dibayar. Layanan/POS, pembayaran lama, alokasi pembayaran dan snapshot komponen yang sudah lunas dipertahankan. Komponen kamar dengan campuran tarif pajak memakai snapshot efektifnya; tidak mengganti histori dengan aturan pajak hari ini.
- Menyimpan nego tidak membuat transaksi penerimaan/refund atau menutup kamar. Pelunasan checkout tetap dijalankan sesudahnya. Pembatalan sebelum simpan tidak mengubah data; pembatalan setelah simpan membatalkan langkah checkout, harga tersimpan tetap berlaku.
- Durasi terbuka tetap menggunakan total tagihan aktual, dengan batas pembayaran yang sudah diterima.

## Pengaman dan cakupan audit Telegram

Menelusuri jalur sewa awal, perpanjangan, layanan ekstra, uang panjar, Tunai/Transfer/QRIS/split, pindah kamar, kunci/checkout, pembatalan draft, idempotensi, serta buka/tutup shift pada baseline R11. Perubahan terfokus pada negosiasi dan pengaman konfirmasi terkait. Semua fitur Telegram tidak diklaim bebas kesalahan hanya dari pembacaan sumber.

Draft nego/perpanjangan terikat staf, booking, nonce, batas waktu dan kutipan harga. Tombol perpanjangan format lama ditolak. Kondisi booking/tarif/PBJT berubah membuat perpanjangan ditolak. Kutipan checkout berubah karena pembayaran, tanggal, total, snapshot atau versi baru membuat harga nego ditolak. Pelunasan Telegram memeriksa ulang identitas booking, total dan sisa pembayaran yang dikonfirmasi. Owner tetap tidak memiliki hak mutasi.

Koreksi tambahan: jalur alokasi receipt durasi terbuka sebelumnya merujuk kategori kamar yang belum diinisialisasi dalam fungsi itu. Kategori sekarang diselesaikan secara eksplisit sebelum membuat alokasi overflow. Tidak ada perubahan schema database. Endpoint web baru ikut readiness, izin tab Front Office, fencing/forwarding dan outbox hybrid yang sudah ada. Dua server harus memakai versi kode sama. Booking yang sudah dirouting ke folio Enterprise memerlukan pelepasan/koreksi routing terlebih dahulu; nego tidak boleh diam-diam membuat invoice folio berbeda dari sumber booking.

## Validasi

Unit perhitungan menguji batas uang diterima, komponen lunas, layanan, PBJT campuran, harga nol/NaN/Inf, pembagian sen dan nominal kecil. Skenario GitHub UAT diperluas tanpa menghapus cek pembayaran/shift sebelumnya: perpanjangan nego belum bayar dan QRIS, web preview/simpan/quote usang, checkout nego Telegram, tombol berulang, pembatalan, receipt lama tidak berubah, satu receipt pelunasan dan jurnal seimbang. Pengujian aplikasi/database penuh dilakukan di GitHub CI, bukan pada database hotel lokal/hosting.

Status UAT final dicatat bersama paket rilis setelah CI commit final selesai. Paket tidak otomatis dipasang ke hosting.
