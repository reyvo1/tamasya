# R10 — tampilan Growth aktif dan format Rupiah

Build `20261007-growth-ui-r10`, lanjutan R9 `22423fe`.

## Temuan dan perbaikan

Screenshot Front Office dan Laporan menunjukkan addon Growth yang menyisipkan panel mengambang ke seluruh halaman SPA. Hash kosong dianggap Dashboard; saran tarif memilih dua input tanggal pertama, sehingga filter laporan ikut terpilih. Ini kesalahan integrasi UI, bukan kesalahan aktivasi Enterprise oleh pengguna.

KPI sekarang dimiliki komponen React Dashboard dan berada dalam alur tata letak. Saran tarif hanya ada di formulir reservasi kamar, menggunakan tipe kamar, tanggal check-in/check-out dan sumber booking aktual. Paket tipe lain tidak dipilih sebagai fallback; beberapa paket harus dipilih pengguna. Permintaan lama dibatalkan saat data berubah atau halaman ditutup. Error pembacaan tidak ditampilkan sebagai KPI nol. Folio memakai ID booking aktual. Widget tetap baca saja; tidak mengubah harga/pajak/pembayaran. Owner memiliki akses baca seperti sebelumnya.

Komponen utama dan halaman POS, Growth, Enterprise memakai formatter Rupiah bersama: bilangan utuh tanpa desimal; pecahan dua digit (`Rp 4.640.000,30`). Pecahan `,3` berarti Rp0,30. Screenshot saja tidak membuktikan penyebab nilai pecahan di database. Formatter tidak mengubah angka transaksi, perhitungan PBJT, jurnal, sinkronisasi dua node atau angka CSV/Excel audit. Angka dihias hanya pada batas tampilan/kuitansi HTML. Penanganan data kosong/invalid menampilkan tanda kosong, bukan saldo nol palsu.

Kartu memakai warna Dashboard, pembungkusan angka, kolom responsif, dan stylesheet widget dimuat bersama view Growth. Tidak ada panel Growth di atas filter daftar kamar/keuangan/laporan. Precache menyertakan dependensi baru untuk impor offline. Anggaran shell awal dan graph modul tetap dibatasi oleh gate lama.

## Validasi

33 suite sumber/unit, termasuk regresi baru format Rupiah, tipe paket, kalender, source/session/scope, error HTTP, pembatalan request dan akses Owner. Full aplikasi/database/browser tidak dijalankan lokal sesuai permintaan pengguna. UAT browser GitHub ditambah skenario Growth aktif di Dashboard/reservasi, filter laporan bersih, nominal besar dengan pecahan, tidak ada mutation request dan harga manual tidak berubah. Skenario audit shift dan containment sebelumnya dipertahankan; inventaris browser minimum dinaikkan ke 18 skenario.

Status UAT commit terbaru dicatat pada artefak rilis sesudah Actions selesai; catatan ini tidak menyatakan aplikasi bebas semua bug.

UAT R10 awal: 46/48 pemeriksaan browser lulus di masing-masing PHP 8.4 dan 8.5. Dua kegagalan skenario baru disebabkan tes mencari KPI Dashboard sebelum membuka Dashboard, padahal tab awal aplikasi adalah Kamar. Tes diperbaiki dengan navigasi Dashboard eksplisit; pemeriksaan visibilitas/nominal/geometri tetap utuh. URL cache POS dan multi properti juga disamakan dengan build R10, termasuk precache offline.

Saran tarif menyebut satuan per malam pada tanggal check-in dan memakai mata uang paket yang dikembalikan API, sehingga tidak tertukar dengan total tagihan seluruh masa menginap. KPI memperbarui data setiap menit/saat fokus dan membersihkan timer saat keluar Dashboard.
