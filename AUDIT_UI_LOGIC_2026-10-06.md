# Audit logika tampilan TAMASYA — 6 Oktober 2026

Build kandidat: `20261006-ui-logic-r5`.
Status: **31 suite sumber/unit lulus; UAT browser GitHub dan hasil pemasangan masih menunggu.**

## Jawaban untuk keluhan utama

### Target Rp100 juta

Rp100.000.000 bukan angka yang dipatok dalam kode. Nilai itu tersimpan pada pengaturan `config.target_investment` dalam backup 5 Oktober; nilai bawaan program adalah 0. Kesalahan programnya adalah label pengaturan menyebut bulanan, tetapi Dashboard membandingkan seluruh riwayat, termasuk penerimaan bruto yang bukan pendapatan bersih.

Mengikuti konteks Anda tentang pendapatan hotel setahun, R5 memberi satu arti yang jelas: **Target Pendapatan Tahunan**. Nilainya dapat diubah oleh Admin; 0 berarti belum diatur. Pembandingnya pendapatan yang diakui pada 1 Januari sampai hari ini dalam tahun hotel berjalan, tanpa PBJT dan sebelum beban operasional. Ini bukan laba dan bukan ukuran pengembalian modal investasi. Penerimaan yang pajaknya belum diketahui ditandai dan tidak ditebak sebagai pendapatan final.

Nilai lama tidak dibagi atau dikali otomatis. Rp100 juta pada pengaturan tetap Rp100 juta, dengan arti tahunan yang kini tertulis jelas. Jika target lama memang pernah diisi sebagai target bulanan, Admin perlu meninjau nominalnya. Field API/database tetap bernama `targetInvestment`/`target_investment` untuk kompatibilitas backup dan sinkronisasi; tidak diperlukan migrasi tabel.

### Saldo kas fisik dan saldo bank

**Saldo Kas Fisik · Semua Tanggal** adalah saldo awal kas fisik ditambah transaksi tunai masuk dikurangi transaksi tunai keluar, termasuk pemindahan uang antara bank dan kas. Ini adalah saldo berdasarkan catatan, bukan penghitungan uang di laci secara otomatis.

**Saldo Bank/QRIS/Kartu · Semua Tanggal** menjumlahkan mutasi pada semua akun non-tunai. Saldo pembukaan rekening perlu ada dalam transaksi pembukaan; pengaturan saldo awal kas fisik tidak otomatis menjadi saldo awal bank.

**Saldo gabungan = saldo kas fisik + saldo seluruh akun bank/QRIS/kartu.** Penerimaan bruto mencakup uang masuk, termasuk komponen pajak, titipan/deposito, atau pengembalian biaya; jadi penerimaan bruto bukan laba. Laba operasional berasal dari pendapatan diakui dikurangi beban diakui.

Kartu atas menu Keuangan memakai seluruh tanggal transaksi yang termuat. Filter di bawah mengubah daftar dan grafik. Laporan memakai periode yang dipilih; saldo awal periode membawa saldo sebelum tanggal mulai, dan saldo akhir membawa seluruh mutasi sampai tanggal akhir. R5 menjelaskan perbedaan ini di dalam UI.

### Penjelasan angka screenshot dari backup

Angka berikut dihitung kembali dari 500 transaksi backup 5 Oktober, tanpa impor SQL atau koneksi ke database produksi. Ini bukti atas backup, bukan klaim keadaan live sesudah Anda memperbaiki transaksi.

| Angka | Nilai |
|---|---:|
| Penerimaan bruto semua saluran | Rp94.954.600,30 |
| Pengeluaran semua saluran | Rp88.107.250,00 |
| Saldo kas fisik, saldo awal 0 | −Rp5.907.650,00 |
| Saldo bank/QRIS/kartu | Rp12.755.000,30 |
| Saldo gabungan | Rp6.847.350,30 |
| Pendapatan diakui 2026 sampai 6 Oktober, tanpa PBJT dan sebelum beban | Rp86.178.509,48 |
| Pencapaian target tahunan Rp100 juta pada backup | 86,2% |

Kas fisik yang negatif tidak otomatis membuktikan uang hilang. Catatan menunjukkan pengeluaran tunai lebih besar daripada saldo awal dan pemasukan tunai. Perlu mencocokkan saldo awal, akun pembayaran biaya, mutasi bank ke kas, dan uang fisik. R5 menampilkan penjelasan ketika saldo kas tercatat negatif; tidak mengubah nominal transaksi untuk membuatnya terlihat positif.

## Temuan dan perbaikan

| Bagian | Temuan pada sumber sebelumnya | Perbaikan R5 |
|---|---|---|
| Target Dashboard | Label bulanan, pembanding seluruh tanggal; istilah investasi/omzet/pendapatan bersih bercampur | Target pendapatan tahunan, periode dan angka pembanding terlihat; pajak belum lengkap ditandai |
| Ringkasan Dashboard | Penerimaan pembukaan saldo dapat masuk sebagai pemasukan; penghitung target memakai bruto | Ringkasan mengikuti semantic transaksi dan leg pembayaran yang sama dengan Keuangan; pembukaan saldo memengaruhi saldo, bukan pendapatan |
| Keuangan | Saldo kas fisik berdiri sendiri sementara penerimaan/pengeluaran gabungan; arti filter tidak dijelaskan | Tambah saldo bank, rumus kas fisik, penjelasan seluruh tanggal versus hasil filter, serta petunjuk saldo kas negatif |
| Grafik harian Keuangan | Memotong ke 15 tanggal terakhir meskipun filter lebih panjang | Seluruh tanggal hasil filter masuk ke grafik |
| Arus kas Laporan | Pendanaan dipatok 0; gaji bisa memakai beban bersih sebagai pembayaran kas; biaya perawatan dikelompokkan sebagai investasi | Ringkasan langsung dari penerimaan, pengeluaran, mutasi lain, saldo awal/akhir, dan saldo per akun; pengelompokan aktivitas yang belum dapat dibuktikan tidak dinyatakan nol |
| Neraca lokal | Badge Balanced tetap; laba ditahan dihitung sebagai angka penyeimbang; gaji terutang dipatok 0 | Dinyatakan Neraca Proyeksi/Estimasi Manajemen; saldo awal kas tidak disebut modal pemilik; angka penyeimbang tidak disebut laba ditahan; kewajiban belum terpetakan tidak dinyatakan nol |
| Penyusutan | Inventaris memakai 60 bulan dengan sisa 10%, Neraca memakai 10% per tahun; aset tanpa tanggal pembelian ditampilkan bernilai 0 di Inventaris | Satu rumus estimasi 60 bulan dan sisa 10%; tanpa tanggal, biaya perolehan tetap terlihat dan umur ditandai belum tersedia; Neraca memakai tanggal akhir laporan |
| PBJT pada Neraca proyeksi | Berpotensi menghitung pajak tidak diketahui menggunakan aturan saat ini | Hanya snapshot pajak tersimpan yang valid masuk; pajak belum diketahui tidak direkonstruksi secara otomatis |
| Laporan resmi | Pelepasan deposito berpotensi menambah penerimaan uang lagi; split ke piutang teknis dapat dianggap saldo bank | Pelepasan deposito tidak membentuk arus uang baru; piutang teknis tetap bukan bank |
| Unduhan Owner | Tombol tersedia, tetapi pembuat snapshot laporan resmi masih menolak role Owner | Owner dapat membuat/unduh snapshot; pengiriman email dan seluruh mutasi tetap diblokir |
| Shift | Label selisih bulan ini menjumlahkan semua shift yang termuat | Hanya shift tertutup pada bulan hotel berjalan; label menyatakan data termuat karena API membatasi riwayat |
| Waktu dan rupiah | Sebagian periode mengikuti zona perangkat; pecahan rupiah disembunyikan pada ringkasan | Helper tanggal utama mengikuti zona hotel; bulan aset/laporan/tanggal Dashboard konsisten; formatter ringkasan mempertahankan hingga dua desimal |
| Cuti | Midnight lokal dibanding tanggal awal UTC, sehingga pada hari pertama bisa masih tertulis Akan Cuti | Membandingkan tanggal kalender hotel; hari awal dan akhir termasuk Sedang Cuti |
| Koneksi Lokal | Ringkasan kamar tidak mempunyai penghitung dirty | Kamar menunggu Housekeeping/QC ikut terlihat, terpisah dari kamar tersedia |

## Cakupan penelusuran sumber UI

| Area | Hasil pemeriksaan sumber |
|---|---|
| Dashboard, Keuangan, Grafik, Laporan, Pajak, Inventaris, Shift, Jurnal | Rumus, periode, jenis saldo, sumber snapshot, dan label diperiksa; temuan di atas diperbaiki |
| Front Office/Reservasi/Kamar | Status mengikuti lifecycle; total, pembayaran, sisa, extras mengikuti data server. Estimasi tagihan open-ended adalah tampilan, keputusan transaksi tetap divalidasi server |
| SDM: staf, gaji, absensi, cuti, simpanan | Aliran data, filter, ringkasan dan pengiriman mutasi diperiksa; tanggal bersama dan status cuti diperbaiki. Simpanan memakai saldo/tersedia/dicadangkan dari server |
| POS | Penjualan, diskon, pembayaran dan periode bisnis memakai kebijakan/validasi server serta regresi yang sudah ada; uang penjualan bukan laba setelah HPP |
| Growth | KPI memakai periode; occupancy/ADR/RevPAR dibedakan dari penerimaan uang. Angka posting mentah sudah berlabel bukan laba/kas; tidak ditemukan alasan mengubahnya dalam audit ini |
| Enterprise | Accounting Summary mengambil posted journal, terpisah dari Neraca Proyeksi lokal; forecast sudah berlabel perkiraan, bukan jaminan pendapatan |
| Memo, Website/Digital, Layanan Tamu, Konfigurasi, Database, Setup, Multi-property | Penelusuran aliran baca/mutasi, role dan sumber angka. Tidak ada perhitungan target Rp100 juta lain yang perlu dihapus; aktivasi fitur tidak diubah |
| Offline/dua server/Owner | Cache diperbarui; pembatasan Owner dan sumber transaksi tetap; tidak ada migrasi atau penulisan ke produksi saat audit |

Penelusuran sumber bukan pembuktian setiap kombinasi tombol, role, transaksi historis, kegagalan jaringan, dan data produksi terkini. Modul yang tidak berubah tidak dinyatakan bebas seluruh bug. Tampilan setelah pemasangan dan alur dua server tetap harus menjalani UAT GitHub sebelum final produksi.

## Validasi dan pemakaian

- `scripts/verify-release.sh`: pemeriksaan checksum/syntax dan 31 suite sumber/unit.
- Suite logika UI baru: 11 pemeriksaan perilaku, termasuk pergantian tahun zona hotel, refund, piutang, split, pembukaan saldo, transfer internal, estimasi aset, shift, hari awal cuti, dan kamar dirty.
- Regresi PHP laporan diperluas: pelepasan deposito, split piutang teknis, snapshot Owner, dan penolakan role terbatas.
- UAT browser yang sudah ada diperluas untuk melihat target tahunan, dua saldo, overflow, serta benar-benar mengunduh PDF sebagai Owner. 44 kasus disiapkan; tidak dijalankan lokal, sesuai permintaan Anda.
- Paket R5 menyertakan R4/R4.1/R4.2. Gunakan build sama pada kedua server setelah UAT lulus. Database/backup produksi tidak diubah dan `database_setup.sql` tidak perlu diimpor untuk perubahan ini.
