> Pembaruan: perbaikan kode lokal setelah audit dijelaskan dalam [FINANCE_FIX_2026-10-05.md](FINANCE_FIX_2026-10-05.md). Pernyataan audit tentang kode yang belum diubah merujuk pada tahap pemeriksaan awal. Backup/database tetap tidak diubah.

# Audit tampilan keuangan TAMASYA — 5 Oktober 2026

## Temuan lanjutan — Pajak Januari hanya bernilai pada kamar 14

Keluhan pengguna diperjelas: yang muncul adalah **kamar bernomor 14**, bukan jumlah 14 kamar. Penyebab berhasil direproduksi menggunakan master kategori dari backup, helper PHP pembacaan API asli, classifier finansial asli, dan ekspresi pengelompokan PBJT frontend asli.

### Rantai penyebab

1. Master kategori `cat_room_sale / Sewa Kamar` memiliki `system_key=NULL`, `is_system=0`.
2. Master kategori `cat_051b45cdbbb7d5c108c98b28 / Lain-Lain / Traveloka` memiliki `system_key=room_rental`, `is_system=1`.
3. `api/modules/finance/0185_finance_catalog_identity.php:198`, fungsi `tamasyaEnrichTransactionCatalogIdentity()`, **menimpa** `transactions.categorySystemKey` dengan `categories.system_key` pada saat membaca. Ini juga berlaku untuk transaksi historis yang sudah menyimpan snapshot `room_rental`.
4. `api/support/070_data_projection_scope.php:340` menjalankan helper tersebut sebelum mengirim transaksi dan `financialSemantic` ke browser.
5. `assets/chunks/finance-detail.js:5` memasukkan transaksi ke proyeksi PBJT menggunakan `bE()` / `isTaxableRevenueLike()`. Transaksi manual Sewa Kamar yang penandanya hilang tidak lagi dikenali sebagai transaksi kamar. Snapshot pajak yang tersimpan tidak cukup untuk melewati filter ini.
6. Pada Januari, satu transaksi kategori Lain-Lain / Traveloka yang lolos berada di **kamar 14**, bertanggal **19 Januari 2026**, bruto **Rp255.000**, DPP **Rp231.818,18**, dan pajak tersimpan **Rp23.181,82**. Inilah satu-satunya kamar dengan nilai pajak nonnol pada hasil proyeksi saat ini.

Antarmuka masih membentuk daftar 27 kamar dari master, tetapi hanya kamar 14 memiliki DPP/PBJT nonnol. Pernyataan ini membedakan hasil perhitungan dari tampilan layar langsung; UI deployment belum diakses.

### Angka Januari dari backup

| Pemeriksaan | Hasil |
|---|---:|
| Seluruh transaksi pemasukan Januari | 380 transaksi |
| Transaksi kategori Sewa Kamar Januari | 368 transaksi |
| Nomor kamar berbeda pada transaksi pemasukan Januari | 24 nomor |
| Total pajak tersimpan pada snapshot pemasukan Januari | Rp7.691.818,07 |
| Pajak tersimpan pada transaksi Sewa Kamar yang terlewat | Rp7.668.636,25 |
| PBJT per kamar yang dihitung frontend setelah proyeksi API saat ini | Rp23.181,82 |

Jumlah pajak tersimpan merupakan hasil penjumlahan data yang tersedia, bukan penetapan kewajiban pajak final. Dua penerimaan Rp470.000 masih belum memiliki snapshot pajak dan tidak dimasukkan sebagai tarif asumsi.

### Penilaian

Ini **bukan pembatasan normal karena backfill**. Terdapat pemetaan master kategori yang tidak cocok dengan penggunaan Sewa Kamar, ditambah perilaku pembacaan program yang mengganti identitas historis dan membuat snapshot PBJT yang sudah tersimpan terlewat.

Backup membuktikan keadaan pemetaan saat ini, tetapi belum membuktikan siapa atau tindakan mana yang memindahkannya. Tidak ada kesimpulan bahwa pengguna sengaja salah mengatur kategori.

Perbaikan perlu mencakup pemetaan kategori operasional yang benar, perlindungan identitas historis, dan proyeksi PBJT dari snapshot yang valid. Hanya memindahkan penanda ke Sewa Kamar tanpa memeriksa transaksi Traveloka dapat memindahkan masalah ke kategori lain. Jangan menebak nomor kamar atau pajak untuk melengkapi tampilan.

Ada satu snapshot Sewa Kamar Januari dengan `roomNumber=NULL` dan pajak Rp22.727,27. Sesudah masalah classifier diperbaiki, laporan per kamar masih memerlukan kelompok eksplisit untuk transaksi tanpa nomor kamar agar nominal tersebut tidak hilang dari total. Ini belum menjadi penyebab kamar 14, tetapi merupakan celah tambahan dalam pengelompokan `hn`.

### Bukti reproduksi tambahan

- `/tmp/tamasya-tax-api-projection-audit.php`: menjalankan helper PHP asli dengan PDO pengganti yang hanya membaca master kategori/subkategori dari backup; tidak ada koneksi database atau SQL mutasi.
- `/tmp/tamasya-tax-display-audit.mjs`: menjalankan ekspresi PBJT frontend asli terhadap hasil proyeksi API.
- `AUDIT_TAX_DISPLAY_2026-10-05.json`: hasil terstruktur, tanpa nama tamu.

Pemeriksaan awal pada bagian lain laporan ini membandingkan classifier terhadap snapshot transaksi mentah. Pemeriksaan lanjutan ini menambahkan tahap enrichment katalog API dan membuktikan bahwa perubahan identitas pada tahap pembacaan mengubah pengelompokan pajak/kamar. Penyebab selisih penerimaan Rp470.000 tetap sama karena classifier likuid tidak bergantung pada kategori kamar.

## Kesimpulan

Keluhan angka Rp2.681.000 berhasil direproduksi dari backup dan kode lokal. Untuk 28 Januari 2026, database menyimpan **14 transaksi pemasukan sebesar Rp3.151.000** serta **4 transaksi pengeluaran sebesar Rp1.640.000**. Selisih Rp470.000 berasal dari dua penerimaan tunai dengan snapshot pajak `unresolved`.

Uang tersebut tetap tercatat dalam jurnal. Kekurangan terjadi pada klasifikasi/ringkasan penerimaan di antarmuka, bukan karena transaksi hilang. Grafik keuangan saat ini menghitung leg tunai, bukan gabungan tunai dan seluruh akun bank. Laporan juga menggunakan filter berbeda untuk penerimaan bruto dan perubahan saldo.

Audit ini membaca backup sebagai data tanpa menjalankan SQL, tanpa mengimpor ke database aktif, dan tanpa mengubah transaksi atau kode aplikasi. File ini merupakan hasil pemeriksaan, bukan patch.

## Angka pembanding 28 Januari 2026

| Komponen | Nominal |
|---|---:|
| Penerimaan tunai, termasuk pajak belum teridentifikasi | Rp2.711.000 |
| Penerimaan pada akun BCA Tranfer | Rp220.000 |
| Penerimaan pada akun bernama EDC QRIS BCA / Debit & Kredit | Rp220.000 |
| Total penerimaan seluruh akun | **Rp3.151.000** |
| Penerimaan tunai dengan pajak unresolved | Rp470.000 |
| Total penerimaan yang lolos filter pajak antarmuka | **Rp2.681.000** |
| Penerimaan yang dihitung grafik tunai saat ini | **Rp2.241.000** |
| Pengeluaran, seluruhnya tunai pada tanggal ini | Rp1.640.000 |
| Perubahan saldo tunai | Rp1.071.000 |
| Perubahan saldo seluruh akun likuid | Rp1.511.000 |
| Pendapatan diakui dari snapshot yang tersedia, setelah pajak | Rp2.437.272,73 |

Nama akun EDC tidak membuktikan pembayaran tertentu memakai QRIS atau kartu. Backup menyimpan akun tersebut dengan `type=bank`, sehingga kanal sebenarnya tidak bisa dipastikan hanya dari nama akun.

## Temuan 1 — Penerimaan uang dikeluarkan karena pajaknya belum teridentifikasi

Lokasi: `api/modules/finance/019_canonical_financial_semantics.php:109`, `assets/canonical-business-policy.js:79`, serta penggunaan `xh()` dalam `assets/chunks/finance.js:5`.

`isLiquidExternalIncome` mensyaratkan `!isTaxUnresolvedReceipt`. Akibatnya penerimaan nyata dengan pajak unresolved tidak dihitung dalam ringkasan penerimaan yang memakai flag ini.

| ID transaksi | Referensi arsip | Nominal | Status pajak |
|---|---|---:|---|
| tx_manual_48786406375b1ae27e847388 | 3804 | Rp250.000 | unresolved |
| tx_manual_1003143b264b7bfe83af203a | 3805 | Rp220.000 | unresolved |

Kedua transaksi dibuat pada 1 Oktober 2026 sebagai backfill bertanggal 28 Januari 2026. Jejak audit pencatatan sudah menunjukkan `taxSource=unresolved`; ini bukan perubahan pajak yang dilakukan oleh pemeriksaan ini.

Jurnal masing-masing mendebit akun `1101 / Kas Tunai` sebesar nominal penerimaan dan mengkredit `2199 / Penerimaan Belum Teridentifikasi Pajak`. Dengan demikian, uang masuk dan kewajiban sementara sudah tercatat.

**Pembedaan yang diperlukan:** menahan pengakuan pendapatan/DPP/pajak ketika bukti pajak belum lengkap tetap benar. Mengecualikan uang yang sudah diterima dari total penerimaan bruto membuat ringkasan arus uang tidak cocok dengan jurnal. Jangan memperbaiki tampilan dengan mengisi tarif pajak asumsi atau mengganti status pajak tanpa bukti.

## Temuan 2 — Grafik hanya menghitung tunai

Lokasi: `assets/chunks/finance.js:5`, fungsi `ed()` dan `Zd()`.

Kedua fungsi menjumlahkan `tamasyaCashLeg()`. Fungsi ini mengembalikan nol untuk penerimaan bank biasa dan hanya leg tunai untuk pembayaran split. Selain itu, sumber grafik `wi` sudah mengecualikan penerimaan dengan pajak unresolved melalui `xh()`.

Pada data 28 Januari, grafik menghitung Rp2.241.000: Rp3.151.000 dikurangi Rp470.000 unresolved dan Rp440.000 penerimaan bank.

Tampilan memakai label `Penerimaan Kas Terfilter` dan `Arus Kas per Kategori`, sedangkan seri disebut `Pemasukan`. Label tersebut tidak menjelaskan dengan tegas bahwa cakupannya hanya uang tunai dengan pajak yang lolos filter. Grafik ini tidak bisa dipakai sebagai total pemasukan tunai + transfer + QRIS.

Reproduksi tambahan menggunakan kode grafik asli dan empat fixture valid: tunai Rp110.000, transfer Rp220.000, QRIS Rp330.000, split Rp440.000 dengan tunai Rp100.000 dan transfer Rp340.000. Total penerimaan Rp1.100.000; grafik menghasilkan Rp210.000. Leg bank sebesar Rp890.000 tidak masuk grafik.

## Temuan 3 — Ringkasan penerimaan Laporan tidak cocok dengan perubahan saldo

Lokasi: `assets/chunks/report.js:5`, classifier `za`, hasil `Ya`, dan hasil `Rn`.

`za` mengecualikan penerimaan unresolved. `Ya` memakai classifier tersebut untuk kartu `Total Penerimaan Kas (Bruto)`. Sebaliknya, `Rn` dan perhitungan saldo akun memasukkan penerimaan unresolved.

Hasil eksekusi ekspresi kode asli dengan transaksi tanggal tersebut:

```text
Total Penerimaan Kas (Bruto): 2.681.000
Total Pembayaran:             1.640.000
Penerimaan minus pembayaran:  1.041.000
Perubahan saldo likuid:       1.511.000
Selisih:                        470.000
```

Tidak ada saldo pembuka atau mutasi internal pada fixture tanggal ini yang menjelaskan selisih tersebut. Selisih tepat sama dengan dua penerimaan unresolved. Ringkasan membutuhkan perhitungan penerimaan bruto yang konsisten dengan saldo, dengan informasi pajak belum teridentifikasi ditampilkan terpisah.

## Catatan lain

1. **Klasifikasi akun EDC:** akun bernama EDC QRIS BCA / Debit & Kredit bertipe `bank`. Registry pembayaran di `api/modules/finance/018_canonical_business_policy.php:106` membedakan `transfer → bank`, `qris → edc_qris`, dan `card → edc_card`. Nama akun saja tidak membuatnya menjadi akun QRIS. Penyesuaian master data dan pemisahan kanal perlu mengikuti penggunaan akun sebenarnya; jangan mengubah sejarah pembayaran hanya berdasarkan nama akun.
2. **Periode ringkasan Keuangan:** kartu atas `Pemasukan Riil`, `Pengeluaran`, dan `Saldo Riil Kas` menghitung seluruh `e`; filter tanggal/log membentuk `Er` yang digunakan oleh grafik dan daftar. Kartu atas dan grafik dapat berbeda periode. Ini perlu dijelaskan dalam UI atau disamakan cakupannya bila kartu dimaksudkan mengikuti filter.
3. **Metadata deployment:** backup memiliki 113 tabel dan 5 deklarasi trigger, sesuai jumlah baseline. Namun `schema_release_state.source_checksum` kosong. Ini bukan penyebab selisih Rp470.000, tetapi bukti attestation schema deployment belum lengkap; runtime dengan metadata trigger tersembunyi tidak boleh diasumsikan lolos hanya dari nama release.

## Mengapa UAT dapat lulus

Pemeriksaan browser yang tersedia menguji navigasi dan kontrol, tetapi tidak ditemukan assertion yang membandingkan angka grafik Keuangan dengan gabungan tunai, bank, QRIS, dan penerimaan unresolved.

`tests/uat_rc1/scenario_tax_edges.py:14` menyelesaikan fixture pajak unresolved melalui koreksi. `tests/uat_rc1/scenario_accounting_reports.py:35` secara eksplisit mencatat bahwa fixture tersebut sudah diselesaikan sebelum suite laporan berjalan. Ini membuktikan jalur koreksi pajak, tetapi belum mencakup ketidakkonsistenan tampilan saat uang sudah diterima dan pajaknya masih unresolved.

Kelulusan CI historis dalam dokumen repo tidak diperiksa ulang secara daring pada audit ini. Audit ini tidak mengklaim menjalankan ulang seluruh UAT browser atau integrasi produksi.

## Pemeriksaan tambahan terhadap backup

- 500 transaksi: 471 historical_import dan 29 live_operation; rentang tanggal 1 Januari–2 Oktober 2026.
- Seluruh 500 transaksi memiliki satu jurnal; tanggal jurnal cocok dan total debit maupun kredit sama dengan nominal transaksi.
- Seluruh 500 jurnal seimbang.
- Tidak ditemukan nomor dokumen transaksi duplikat.
- Tidak ditemukan bankAccountId yang mengacu pada akun yang tidak ada.
- Tidak ditemukan snapshot confirmed/manual_override yang DPP + pajaknya berbeda dari nominal di luar toleransi pemeriksaan.
- Hanya dua penerimaan pada 28 Januari yang berstatus unresolved, total Rp470.000.
- Backup ini tidak memiliki transaksi split; skenario split grafik dibuktikan menggunakan fixture sintetis, bukan diklaim sebagai masalah data historis nyata.
- Classifier PHP dan fallback JavaScript dijalankan terhadap seluruh 500 transaksi; flag penerimaan/pengeluaran dan nilai pendapatan/beban yang diperiksa cocok. Masalah filter terjadi pada kedua sisi, bukan hanya cache frontend.

Pemeriksaan keseimbangan dan kecocokan ini tidak membuktikan seluruh transaksi sesuai kuitansi fisik; bukti arsip eksternal belum diberikan.

## Arah perbaikan

1. Pisahkan kontrak penerimaan/pembayaran bruto dari pengakuan pendapatan dan status pajak. Penerimaan fisik tetap masuk arus uang; pendapatan/pajak unresolved tetap suspense.
2. Sediakan grafik total likuid dan rincian tunai/bank/QRIS sesuai identitas akun yang valid. Pembayaran split dijumlahkan sekali dengan leg yang benar.
3. Samakan ringkasan bruto, perubahan saldo, dan periode filter di Keuangan/Laporan; tampilkan nominal yang pajaknya belum teridentifikasi.
4. Tambahkan assertion angka pada frontend untuk data tunai, transfer, QRIS, split, pengeluaran bank, serta pajak unresolved yang belum dikoreksi. Uji angka kartu, grafik, saldo, dan laporan pada periode yang sama.
5. Koreksi snapshot dua transaksi melalui workflow audit hanya setelah bukti pajak tersedia. Perubahan tampilan tidak perlu mengubah data pajak historis.

## Bukti reproduksi lokal

Hasil ringkas tersimpan pada `AUDIT_FINANCE_DISPLAY_2026-10-05.json`.

Harness pemeriksaan sementara membaca dan mengeksekusi ekspresi perhitungan asli, bukan menulis ulang rumus frontend:

- `/tmp/tamasya_parse_backup.py`: parser dump sebagai data.
- `/tmp/tamasya-finance-semantics-audit.php`: classifier PHP terhadap seluruh transaksi.
- `/tmp/tamasya-finance-display-audit.mjs`: ekspresi Keuangan/Laporan dan fixture kanal pembayaran.

File sementara tersebut bergantung pada lokasi backup dan workspace sesi ini. Database aktif dan UI deployment belum diakses.
