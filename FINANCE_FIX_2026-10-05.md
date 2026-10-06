# Perbaikan penerimaan, grafik keuangan, dan laporan PBJT

Build awal perbaikan keuangan: `20261005-finance-cash-tax-r1`.

Perbaikan ini sekarang termasuk kandidat `20261005-production-hardening-r2`; status dan panduan terbaru ada di `FINAL_CANDIDATE_2026-10-05.md`.

Perbaikan ini sudah diterapkan pada kode di folder kerja. Belum dipasang ke server produksi atau dikirim ke GitHub. Backup SQL hanya dibaca; transaksi, jurnal, konfigurasi kategori/rekening, dan status pajak di database tidak diubah.

## Mengapa tanggal 28 Januari berbeda

Dalam backup yang diberikan, hanya dua dari 500 transaksi berstatus penerimaan dengan pajak belum ditentukan. Keduanya pada 28 Januari: Toar Rp250.000 dan Bahar Katili Rp220.000. Kode lama mengeluarkan Rp470.000 tersebut dari penerimaan bruto, padahal uangnya tercatat di kas dan jurnal.

Setelah perbaikan, perhitungan kode terhadap backup menghasilkan:

| Ukuran 28 Januari | Nilai |
| --- | ---: |
| Penerimaan bruto semua pembayaran | Rp3.151.000 |
| Penerimaan tunai | Rp2.711.000 |
| Penerimaan bank | Rp440.000 |
| Pembayaran/pengeluaran | Rp1.640.000 |
| Perubahan uang tunai | Rp1.071.000 |
| Perubahan uang tunai dan bank | Rp1.511.000 |
| Penerimaan yang pajaknya belum ditentukan | Rp470.000 |

Pendapatan yang diakui dari snapshot yang lengkap tetap Rp2.437.272,73. Perbaikan tidak mengarang dasar pajak/tarif untuk dua penerimaan yang belum direkonstruksi. Temuan audit PBJT tersebut masih perlu diselesaikan menggunakan bukti transaksi.

## Perubahan program

- Penerimaan bruto mencakup penerimaan dengan pajak belum ditentukan. Angka pengakuan pendapatan tetap mengikuti bukti pajak yang tersedia. Tampilan memberi peringatan tentang penerimaan yang belum lengkap pajaknya.
- Grafik membuka cakupan **Semua: Tunai + Bank/QRIS/Kartu** secara bawaan. Pengguna dapat memilih tunai, semua bank, transfer/rekening bank, QRIS, atau kartu. Pembayaran gabungan dipecah sesuai nominal masing-masing. Piutang teknis, saldo awal, dan mutasi internal tidak menjadi penerimaan operasional baru.
- Grafik harian menampilkan penerimaan dan pembayaran berdampingan agar tinggi batang tidak menjumlahkan dua arah arus uang.
- Laporan arus uang memasukkan penerimaan yang belum lengkap pajaknya sehingga penerimaan dikurangi pembayaran sesuai perubahan saldo pada kasus 28 Januari. Label metode pembayaran dalam ekspor menggunakan daftar rekening yang benar.
- Pembacaan kategori mengisi identitas historis yang kosong tanpa menimpa identitas yang sudah tersimpan. Snapshot PBJT positif yang valid tetap ditampilkan walaupun pemakaian kategori kemudian dipindahkan.
- Transaksi pajak tanpa nomor kamar masuk kelompok **Tanpa nomor kamar**, termasuk dalam total, tanpa menebak kamar. Pengembalian pendapatan mengurangi kelompok terkait.
- Versi kebijakan dan cache aplikasi diperbarui bersama referensi modul agar browser memuat perbaikan.

## Pengaturan rekening yang perlu dipahami

Filter QRIS/kartu mengikuti **jenis rekening**, bukan nama rekening. Dalam backup, rekening bernama “EDC QRIS BCA / Debit & Kredit” masih bertipe `bank`. Nominalnya masuk total semua pembayaran dan semua bank, tetapi tidak dapat otomatis dianggap transaksi QRIS.

Untuk pemisahan QRIS dan kartu yang akurat, data rekening/metode pembayaran harus membedakan kedua jenis tersebut. Jangan mengubah semua transaksi pada rekening campuran menjadi QRIS tanpa memeriksa bukti pembayaran.

## Validasi

Ditambahkan pengujian PHP dan JavaScript yang menjalankan perhitungan serta JSX grafik dari kode produksi: tunai, transfer, QRIS, kartu, pembayaran gabungan, pengeluaran bank, penerimaan pajak belum lengkap, piutang OTA, mutasi internal, saldo awal, deposito jaminan, identitas historis, snapshot PBJT, pengembalian, dan kelompok tanpa nomor kamar. Kasus 28 Januari diuji dengan fixture buatan, bukan data tamu dalam repo.

Audit baca-saja menggunakan backup memverifikasi kesesuaian semantik PHP/JavaScript untuk seluruh 500 transaksi. Pengujian laporan Januari juga mencocokkan total pajak per kelompok dengan snapshot transaksi yang ditampilkan. Hasil ini memverifikasi perhitungan program, bukan menetapkan laporan pajak final.

Seluruh 25 suite regresi lulus (23 suite sebelumnya dan 2 suite baru). Pemeriksaan sintaks PHP/JavaScript, sintaks harness, dan 404 entri checksum rilis juga lulus. UAT browser menyeluruh dengan database produksi tidak dijalankan.
