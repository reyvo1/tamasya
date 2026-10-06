# TAMASYA — perbaikan laporan dan tampilan 6 Oktober 2026

Build: `20261006-report-download-ui-r3`.
Status: **kandidat untuk UAT GitHub; belum dikunci sebagai final produksi**.
Mencakup seluruh perbaikan R2, aktivasi Growth/Enterprise, dan sinkronisasi Memo. Panduan aktivasi dua node tetap ada di `FINAL_CANDIDATE_2026-10-05.md`.

## Temuan dari empat screenshot

1. **PDF/Excel/CSV gagal karena frontend.** Guard respons API hanya mengecualikan unduhan backup. Respons laporan resmi `application/pdf`, XLSX, atau CSV yang valid diubah menjadi error 502 oleh frontend. Ini tidak bergantung pada aktivasi Enterprise.
2. **Periode modal berbeda dari halaman.** Halaman menggunakan 1 Januari–31 Oktober, tetapi modal kembali ke 1–6 Oktober. Ini bisa menghasilkan laporan dengan angka berbeda walaupun server benar.
3. **Label kas gabungan membingungkan.** Ringkasan laporan menggabungkan kaki tunai dan bank, termasuk transfer/QRIS, tetapi sebelumnya diberi label hanya kas. Label kini menyatakan “Tunai + Bank”. Saldo tunai di menu Keuangan tetap khusus uang tunai.
4. **Angka jurnal bukan total pemasukan.** Backup berisi 500 transaksi dan 500 jurnal dengan 1.400 baris akun: pemasukan Rp94.954.600,30 + pengeluaran Rp88.107.250,00 = Rp183.061.850,30. Total debit dan kredit masing-masing tepat sebesar itu. Tidak ada penggandaan pada total tersebut. Transaksi `tx_pay_86f3ad48978eab73f19a08f111fcf` tanggal 1 Oktober memiliki nilai Rp250.000,30; tampilan ringkasan membulatkan rupiah, sedangkan jurnal menampilkan pecahannya. Nilai asli tidak diubah.
5. **Ringkasan jurnal tidak mengikuti pencarian.** Tabel difilter, tetapi jumlah jurnal dan total debit/kredit sebelumnya tetap global. Kini seluruh kartu mengikuti hasil pencarian, dan penjelasan menyatakan bahwa total jurnal mencakup pemasukan serta pengeluaran.
6. **Simbol rusak berasal dari CSS.** Penanda kelompok Operasional, Kontrol, Keuangan, Sistem, serta tanda izin peran berisi karakter hasil encoding yang salah. CSS kini menggunakan escape Unicode atau “Rp”. Warna kuning pada penanda Keuangan adalah warna kelompok navigasi, bukan temuan audit.
7. **Tulisan pilihan lampiran vertikal.** CSS input email juga mengenai checkbox dengan lebar 100%, sehingga label PDF/Excel/CSV terdesak. Checkbox kini memiliki lebar sendiri dan label tetap dalam satu baris.

Pada gambar, gabungan penerimaan dikurangi pengeluaran adalah Rp6.847.350,30. Saldo khusus tunai ditampilkan -Rp5.907.650. Nilai gabungan dan tunai mempunyai cakupan berbeda. Selisih keduanya sekitar Rp12.755.000 berasal dari cakupan bank; nilai persis produksi perlu dicocokkan pada periode yang sama di server pemilik.

Pemilik menyatakan pajak 28 Januari sudah selesai setelah pengaturan pemakaian kategori diperbaiki. Patch ini tidak mengubah transaksi atau snapshot PBJT produksi. Temuan pajak pada backup lama tidak dianggap sebagai kondisi terbaru.

## Perubahan dan batas verifikasi

- Guard API melewatkan file hanya untuk endpoint laporan resmi, format yang diminta, MIME yang sesuai, respons berhasil, dan `Content-Disposition: attachment`. Error JSON, HTML, MIME salah, serta kegagalan upstream tetap ditangani sebagai kegagalan. Unduhan backup yang sudah didukung tetap berjalan.
- Modal mewarisi rentang tanggal lengkap pada halaman Laporan atau filter Keuangan. Jika rentang belum diisi, default dihitung ulang menurut zona properti saat modal dibuka. Jenis laporan dan tanggal tetap dapat diedit di modal; filter kategori/pencarian halaman bukan filter laporan resmi.
- Build, impor chunk, precache, serta URL CSS diperbarui untuk menghindari cache versi lama.
- Suite regresi baru memeriksa byte dan header asli PDF/XLSX/CSV melalui wrapper respons frontend yang benar-benar dikirim, kegagalan API, pemilihan periode, serta total jurnal sesuai pencarian.
- Workflow GitHub menjalankan suite baru pada matrix PHP yang sudah disiapkan. UAT browser menambahkan unduhan nyata melalui tombol modal untuk empat format, kesamaan periode, tata letak checkbox, simbol navigasi, dan hasil pencarian jurnal pada desktop/mobile. Total 44 kasus browser disiapkan; belum dijalankan lokal.
- Verifikasi lokal hanya sumber/unit: 28 suite, sintaks, dan checksum. Tidak ada simulasi penuh aplikasi, impor backup, perubahan database hotel, aktivasi server, atau deploy.

## UAT yang harus GREEN sebelum final lock

Jalankan workflow `.github/workflows/tamasya-enterprise-rc1-uat.yml` pada commit kandidat yang sama. Pertahankan semua gate lama, termasuk laporan/pajak, dua database Primary/Standby, mirror Memo dan failover, HQ, konsistensi jurnal, restore backup, serta error server. Pastikan seluruh browser desktop/mobile lulus, termasuk file yang benar-benar tersimpan ketika tombol unduh ditekan.

Setelah GREEN, deploy kode build yang sama pada kedua server, jalankan prosedur aktivasi Growth/Enterprise bila belum aktif, restart runtime sesuai konfigurasi server, dan cocokkan laporan menggunakan jenis serta periode yang sama. Program tetap menggunakan satu Primary aktif dan forwarding/fencing yang sudah ada.
