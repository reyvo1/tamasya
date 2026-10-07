# Audit Growth / Enterprise — R13

Build `20261007-growth-kpi-r13`. Baseline R12 `c9c1f1149df01736013afacea6fce0f2aba0538a`.

## Temuan dan perilaku baru

1. KPI malam sebelumnya berhenti pada tanggal checkout rencana untuk booking yang masih active. Ini dapat menghasilkan nol meskipun kamar masih terisi dan belum checkout. Pembacaan sekarang memperpanjang interval keterisian sampai besok pada booking aktif yang telah jatuh tempo. Booking yang masih sebelum jam checkout terjadwal hari ini tidak dianggap otomatis menjual malam berikutnya. Tidak menambah malam pada tagihan, menerima uang, atau mengubah tanggal sumber. Alert meminta petugas memeriksa perpanjangan/checkout. Status active yang lupa ditutup tetap harus diperbaiki oleh petugas melalui workflow resmi; KPI tidak menganggap tagihan otomatis bertambah.
2. Selisih tanggal PHP `diff()->days` selalu absolut. Booking checkout aktual sebelum awal periode sebelumnya dapat memberikan malam positif meskipun tidak overlap. Interval sekarang memakai batas akhir eksklusif dan hasil nol jika tidak beririsan. LOS dan folio health memakai booking yang benar-benar masuk periode yang sama.
3. Enterprise forecast sebelumnya mengabaikan durasi terbuka aktif setelah placeholder checkout, serta checkout aktual. Growth dan forecast kini memakai helper interval yang sama. Durasi terbuka aktif diasumsikan terisi sampai horizon laporan/forecast; angka forecast tetap estimasi, bukan tagihan/jurnal.
4. Nilai roomCharge/extraCharge tersimpan pada booking lama dapat mengelompokkan perpanjangan Telegram sebagai layanan. Growth folio/KPI dan Enterprise forecast/sumber alokasi folio kini memakai pembacaan bersama dari total net dan extras berjenis room/extension/service. Diskon canonical dihitung satu kali. Layanan tidak menjadi pendapatan kamar ketika nilai roomCharge nol. Tidak mengubah receipt, invoice issued, jurnal, atau alokasi yang sudah disimpan.
5. Dashboard dan Detail KPI memakai tanggal yang sama lewat parameter from/to. Detail dibuka dan dihitung otomatis; periode bawaan halaman langsung tetap awal bulan sampai hari ini. Input periode dan seluruh default tanggal Growth/Enterprise memakai zona waktu properti.
6. Dashboard menolak respons KPI tidak lengkap atau periodenya berbeda; tidak mengubah payload kosong menjadi empat angka nol. Menampilkan jumlah kamar active sekarang, penjelasan sumber booking, dan alert active lewat checkout. Angka nol yang benar tetap ditampilkan dengan keterangan. Transaksi backfill uang saja bukan data malam menginap.
7. Dashboard refresh tiap menit, saat fokus dan setelah mutasi web berhasil. Tab KPI Growth dan laporan ringkasan/health Enterprise diperbarui saat terlihat. Refresh laporan Enterprise tidak menjalankan render ulang seluruh formulir atau mengganti pilihan yang sedang dikerjakan. Respons lama tidak menimpa hasil permintaan yang lebih baru; respons forecast/accounting tidak lengkap menjadi pesan error, bukan nominal nol.

8. Cache bootstrap Growth sebelumnya memakai satu key tanpa scope hotel/staf/role/izin. Cache kini terikat identitas tersebut; cache lama atau JSON rusak ditolak. Bootstrap Enterprise yang gagal menonaktifkan tombol mutasi sampai status node/data berhasil dimuat ulang.

## Cakupan pemeriksaan

Menelusuri bootstrap/feature gate/role, endpoint selection primary, periode KPI, interval sewa, revenue kamar, folio/alokasi, forecast, accounting posted, AP/health/adapter/CRM UI dan pembaruan data. Tidak mengaktifkan provider, mengirim campaign/email/Telegram, memasang ke hosting, mengimpor backup hotel, atau menjalankan simulasi aplikasi/database lokal. Perubahan tidak membutuhkan schema baru. Kedua server harus memakai kode R13 yang sama.

Definisi tetap: denominator KPI adalah seluruh kamar fisik terkonfigurasi, belum dikurangi out-of-order; nilai kamar dialokasikan rata per malam, bukan ledger tarif per malam atau arus kas; short-time/same-day dihitung satu unit malam sesuai baseline. Riwayat pindah tipe kamar belum tersedia per segmen malam. Forecast bukan jaminan pendapatan. Jurnal dan pembayaran canonical tidak diganti dengan angka KPI.

## Validasi

Tambahan 17 tes unit interval/revenue; pengujian UI payload kosong/period mismatch, zona waktu/deep link dan refresh tanpa mereset formulir. UAT GitHub diperluas dengan MySQL fixture checkout awal, active lewat checkout, open-ended dan klasifikasi extension/service; fixture direstore sebelum skenario bisnis berikutnya. Browser desktop/mobile membaca KPI nyata dari API, membuat booking canonical nonzero, memeriksa kesamaan Dashboard/detail pada tanggal hotel yang sama tanpa stub KPI, serta memeriksa read-only dan layout. Seluruh UAT sebelumnya tetap dipertahankan. Status akhir commit dan paket dicatat sesudah GitHub CI selesai.

## Koreksi dari UAT browser

Run `37648466853` meluluskan MySQL Growth/Enterprise dan 50 skenario browser lama. Dua skenario KPI baru meluluskan angka nyata Dashboard/detail dan pembukaan otomatis, lalu gagal pada cleanup fixture: setelah navigasi ke halaman Growth standalone, fetch native tidak lagi memakai wrapper auth PMS. Permintaan cancel fixture kini menyertakan token, scope sesi/hotel, device, app version dan operation ID secara eksplisit. Ini koreksi harness UAT, bukan melonggarkan assertion atau perubahan angka KPI. Seluruh UAT diulang pada commit koreksi.
