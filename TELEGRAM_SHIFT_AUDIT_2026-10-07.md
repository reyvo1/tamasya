# Audit Telegram dan rekonsiliasi shift — R11

Build: `20261007-telegram-shift-r11`. Dasar: R10 commit `cadff211a3c121fc2aa55b683fb45a80c0cff544`.

## Temuan dan perbaikan

1. Resepsionis/Finance sebelumnya tidak bisa menyerahkan penutupan jika selisih melebihi toleransi. Kini kas aktual dan keterangan dicatat, sesi ditutup, transaksi dikunci, dan permintaan pemeriksaan `shift_cash_variance` dibuat atomik. Penutupan tersebut merupakan deklarasi petugas; bukan persetujuan Admin atau transaksi penyesuaian uang.
2. Semua selisih uang kurang/lebih memerlukan keterangan minimal 5 karakter, termasuk yang masih dalam toleransi. Tombol lama “tanpa catatan” tidak dapat melewati pemeriksaan server. Admin/Manager yang menutup selisih di atas toleransi tetap wajib mengisi alasan override.
3. Web sebelumnya memiliki agregasi SQL penutupan tersendiri. Web, Telegram dan preview shift web kini memakai satu perhitungan kaki pembayaran: kas awal + penerimaan tunai − pengeluaran tunai. Transfer/QRIS dan kaki bank pembayaran campuran tidak masuk uang laci; mutasi kas internal tetap memengaruhi uang fisik; akun teknis nonlikuid, forfeiture deposito tanpa pergerakan uang, backfill dan shift-exempt tidak menjadi kas operasional.
4. Preview Telegram dihitung lagi berdasarkan ID sesi sebenarnya. Penutupan menghitung ulang setelah mengunci sesi dan mengaitkan transaksi live yang memenuhi syarat. Sesi malam memakai identitas sesi sehingga tidak terpotong oleh pergantian tanggal. Query gagal atau tanggal/jadwal sesi tidak cocok ditolak, bukan diganti Rp0. Penjumlahan dibulatkan ke dua digit pecahan.
5. Catatan bawaan “kas cocok” dihapus; catatan tanpa selisih memakai “serah terima tanpa catatan tambahan”. Notifikasi mencantumkan kurang/lebih dan status pemeriksaan. Penutupan Telegram kini mencatat audit enterprise before/after yang wajib berhasil dalam transaksi penutupan.
6. Web Pusat Operasional > Shift & Tutup Kas menyediakan Admin/Manager tombol menutup shift petugas dan “Revisi kas fisik” pada shift tertutup. Revisi memerlukan alasan dan nilai kas sebelumnya agar tampilan lama tidak menimpa revisi baru. Nilai aktual/selisih di sesi dan laporan yang sama diperbarui, angka asli tetap dalam audit. Review pending diperiksa dan diselesaikan bersama revisi. Revisi ini tidak mengedit transaksi atau penerimaan QRIS/transfer, tidak membuka ulang shift, tidak mengubah waktu tutup.
7. Web Persetujuan menampilkan permintaan selisih dan mewajibkan keterangan pemeriksaan. Setujui/tolak tidak menghapus angka selisih atau menciptakan uang. Apabila kesalahan berasal dari transaksi pembayaran, gunakan jalur koreksi transaksi/audit yang tersedia; revisi kas fisik hanya untuk koreksi hasil hitung uang laci.
8. Kolom kas aktual web tidak diisi otomatis dari kas seharusnya: petugas harus memasukkan hasil hitung fisik. Laporan manual juga menolak kas aktual kosong/negatif/tidak numerik dan menolak kegagalan perhitungan.
9. Kegagalan broadcast setelah commit tidak lagi diberitakan sebagai kegagalan menyimpan shift. Jalur revisi didaftarkan dalam whitelist operasi hybrid dan pemeriksaan kesiapan properti. Tidak ada perubahan schema DB; approval, shift, laporan dan audit memakai tabel yang sudah direplikasi.

## Jalur operasional yang dimaksud

Telegram: Kas & Shift > Buka Shift > jadwal > pendamping atau sendiri > kas awal. Tutup Shift > pilih sesi server > hitung uang fisik > isi uang fisik > isi keterangan jika kurang/lebih > tersimpan CLOSED. Selisih di atas toleransi petugas tampil “menunggu pemeriksaan” untuk Admin/Manager di web. Night Audit tetap wajib diselesaikan jika aturan hotel mensyaratkannya; tidak dilewati oleh deklarasi selisih.

Admin di web: Pusat Operasional > Persetujuan untuk memeriksa selisih; atau Shift & Tutup Kas > Revisi kas fisik jika angka hasil hitung petugas memang salah. Uang kurang yang benar-benar hilang tetap dicatat kurang; jangan mengubah kas aktual menjadi angka seharusnya tanpa bukti.

Perpanjangan kamar/lunas/layanan tambahan Telegram sudah memakai pemilihan tunai/transfer/QRIS dan rekening aktif yang sesuai; konfirmasi draft meneruskan ke workflow booking canonical. Regresi pembayaran yang sudah ada tetap dijalankan (70 pemeriksaan). Owner tetap read-only, resepsionis hanya boleh menutup sesi yang diikuti; Admin/Manager dapat menutup sesi petugas.

## Validasi

- Ditambahkan `tests/telegram-shift-regression.php`: 30 pemeriksaan nilai kas/digital, split, akun teknis, backfill, selisih kurang/lebih, keterangan wajib, override, role, presisi, sesi malam, sesi tidak cocok dan kegagalan database. Tes unit memakai data dalam memori; tidak menjalankan aplikasi hotel atau mengimpor backup produksi.
- Skenario GitHub Telegram ditambah: buka shift dari akun resepsionis; tutup dengan kurang/lebih dan keterangan; tombol lama tanpa catatan ditolak; review tampil di web; Admin merevisi kas fisik; revisi lama ditolak; persetujuan tidak menghapus selisih. Seluruh skenario lama tetap dipertahankan.
- Seluruh 34 suite source/unit lokal lulus (PHP 8.5.4, Node 22.23.3), termasuk sintaks dan checksum. Full UAT GitHub masih menunggu hasil commit R11. R10 ZIP lama tidak otomatis memuat R11.

## Koreksi dari UAT GitHub pertama R11

Run `37572779047` menemukan satu assertion HTTP revisi lama: data sudah aman karena konflik ditolak, tetapi handler umum Pusat Operasional mengembalikan 400 dan menimpa DomainException 409. Jalur revisi kini menerapkan pemetaan status resmi (409 untuk konflik, 400 untuk input tidak valid, 500 untuk kegagalan internal). Assertion konflik tetap 409, tidak dilonggarkan. Preview KURANG memakai nilai absolut; formatter Telegram menampilkan pecahan dua digit. Kas awal web menolak negatif/non-numerik/nonfinite, tidak mengubahnya diam-diam menjadi 0. UAT diulang pada commit koreksi.

Keterangan Admin/Manager yang memakai kolom alasan untuk selisih dalam toleransi tetap disimpan sebagai catatan laporan, meskipun tidak dihitung sebagai override. Kasus ini ditambahkan ke tes unit.
