# R14 — Reservasi beberapa kamar, Telegram, dan audit ukuran backup

## Temuan dari source dan keputusan

Fondasi Growth sudah mempunyai `growth_group_reservations` dan `growth_group_booking_links`, tetapi pembuatan parent dan penautan child terpisah, terbatas pada Admin/Manager, dan berada pada menu Growth. Pembuatan parent belum menjalankan workflow booking/check-in per kamar. Karena itu menu Growth yang terlihat sebelumnya belum sama dengan reservasi banyak kamar sekali submit oleh resepsionis.

R14 menggunakan tabel tersebut dan tetap **1 booking = 1 kamar**. Entry operasional berada di Denah Kamar dan Reservasi: **Reservasi Beberapa Kamar**, **Check-in Beberapa Kamar**, dan **Daftar Grup**. Tidak diperlukan migrasi SQL baru. Kontak/catatan parent disimpan sebagai metadata berformat `multi-room-v1` di kolom existing `master_notes`; grup lama tetap dibaca dan dikelola melalui workflow lama.

## Hubungan engine aktual

| Fungsi | Source / engine yang digunakan |
|---|---|
| Booking/check-in baru | `createCanonicalBookingWorkflow`, finance/030 |
| Bentrok interval dan blocker | `tamasyaR3AssertStayWindowNoOverlap`, `getRoomOperationalBlockers`, eligibility R3 |
| Grup dan corporate | Growth/105 + dua tabel group existing |
| Master/individual/split | Enterprise/107, guest/master folio, charge routing dan transaction allocations |
| DP/pelunasan | `recordBookingDeposit`; cash/transfer/QRIS, akun aktif, shift, snapshot PBJT, audit |
| Checkout/pembatalan/refund | Route hotel_booking/020 dan financial/operational workflows existing |
| Pindah/perpanjang kamar | Workflow existing pada Detail kamar; identitas booking/link tetap sama |
| Housekeeping/kunci | Projection dan lifecycle existing front_office/040; tidak mengubah semua kamar sekaligus |
| Accounting | Canonical posting + journal projection + commit fencing existing |
| Telegram | Webhook/080, binding staf, permission callback, compact token, state dan notifier existing |
| Hybrid | Action di whitelist node, parent/child melalui writer dan commit authority existing |

## Perubahan perilaku

Parent receipt diklaim terlebih dahulu. Parent, booking child, link, pajak/DP/deposito yang diminta, audit dan alokasi folio dibuat dalam satu transaksi. Canonical child workflow sekarang dapat bergabung dengan transaksi group; penggunaan single-room tetap memakai batas transaksi sebelumnya. Gagal pada child mana pun membatalkan seluruh batch. Operation ID dan payload/actor/device fingerprint tetap melindungi retry.

Penghuni boleh berbeda, kosong memakai nama pemesan. Tanggal umum dapat dioverride per kamar. Harga per kamar adalah nilai bruto termasuk PBJT; bila mengganti tanggal kamar, staf harus menyesuaikan harga. Server selalu memeriksa interval kembali pada submit. Kontak, penghuni dan catatan dapat diedit; corporate assignment tersedia pada API. Billing dipilih saat awal. Pergantian billing setelah booking dibuat diblokir; gunakan koreksi folio yang diaudit oleh Admin/Finance.

Pembayaran grup dibagi proporsional menurut sisa tagihan, memakai pembagian sen dengan sisa terbesar. Total uang persis sama dengan jumlah pembayaran canonical per kamar; folio hanya mengalokasikan transaksi tersebut, tidak memposting uang/jurnal kedua. Refund tetap berasal dari transaksi canonical. Invoice terbit tetap berupa snapshot immutable; perubahan yang membuat alokasi tidak cocok atau pembatalan kamar yang tercakup invoice ditolak sampai Finance melakukan void/koreksi resmi.

Check-in sebagian, checkout sebagian, pembatalan, perpanjangan, pindah kamar dan kunci mengikuti child. Detail grup menyajikan status, periode, tagihan, dibayar dan sisa per kamar serta total grup. Kamar hanya boleh dilepas tanpa pembatalan bila masih reserved, belum dibayar, individual billing, dan tidak tercakup invoice. Booking/folio/riwayat keuangan tetap ada. Grup baru tidak dapat diubah lewat editor metadata/link Growth lama karena itu dapat merusak billing otomatis.

Telegram: menu Kamar & Tamu mempunyai pembuatan reservasi/check-in multi-kamar, pilihan kamar berpaginasi, penghuni, Individual/Master/Split, detail, tambah kamar dan DP/pembayaran dengan metode/akun eksplisit. Draft memakai nonce dan expiry; submit/retry memakai receipt canonical. Tombol checkout/perpanjang tetap masuk workflow Telegram sebelumnya. Notifikasi batch dibuat sebagai ringkasan grup; perubahan child menambahkan konteks grup pada notifikasi existing, dengan dedup per request. Pembayaran tidak diasumsikan sudah diterima hanya karena reservasi dibuat.

Owner dapat membaca daftar/detail tetapi tidak membuat, membayar, mengedit, melepas atau check-in. Admin/Manager/Receptionist mengikuti permission Rooms. Finance membaca; koreksi alokasi folio tetap melalui Enterprise. Feature memerlukan parent Growth dan group/corporate enabled. Master/Split juga memerlukan Enterprise folio dan schema yang sudah dipasang secara resmi. Mode offline tetap memakai writer server aktif; transaksi grup tidak dibuat di cache browser.

## Responsivitas

Modal memakai viewport portal di atas header/nav, scroll badan modal, header/tutup tetap tersedia, grid responsif, angka dapat membungkus, dan tabel kamar mempunyai scroll horizontal lokal. Keyboard Escape/focus kembali dan focus trap tersedia. UI browser desktop, tablet 768 px dan mobile 390 px masuk UAT GitHub; bukti screenshot ada pada artifact UAT.

## Audit backup 8 Oktober

Backup SQL: **101.222.877 bytes**, 151 tabel dasar dan 5 trigger. Ukuran ini adalah ukuran berkas SQL, bukan ukuran fisik InnoDB.

| Data INSERT | Bytes perkiraan |
|---|---:|
| request_operation_receipts | 90.573.112 |
| audit_logs | 7.040.112 |
| runtime_request_events | 664.431 |
| transactions | 505.835 |
| communication_outbox | 476.206 |
| journal_lines | 262.957 |
| bookings | 32.507 |

Ada 1.640 receipt dan 1.155 sudah menggunakan gzip/base64. Respons mutation `transactions`, `notifications-read` dan lainnya menyimpan banyak snapshot `db` berulang; secara kumulatif snapshot setelah decode hampir 987 MB. Ini bukan tanda booking bertambah salah. Codec gzip yang transparan sudah ada; mengompres ulang saja tidak dapat menjanjikan pengurangan besar pada DB aktif.

Salinan `.sql.gz` dibuat lossless menjadi **66.817.549 bytes**, sekitar **34% lebih kecil**. SHA-256 isi setelah dekompresi sama persis dengan SQL asli: `cf3ae18f1261c459964c90f00db320e7615de9bb38cdc4e2318621a08b89d60d`. SQL asli tidak diubah, diimpor atau dihapus. Salinan berisi data private dan tidak termasuk ZIP hosting/repository.

`receipt_storage_maintenance.php` adalah alat CLI dengan default **dry-run**. Ia dapat memadatkan receipt besar yang masih mentah dengan codec existing, memverifikasi replay byte-identik, dan tidak mengubah status, hash, action, timestamp atau metadata idempotency. Apply memerlukan referensi backup, pemeriksaan identitas DB, mutation lock/writer authority dan commit fencing. Receipt yang sudah dipadatkan atau tidak menguntungkan tidak ditulis ulang. **Alat ini belum dijalankan pada produksi.**

Retention existing pada maintenance cron tetap 30 hari untuk body dan 120 hari untuk metadata secara default. R14 tidak memperpendek retention atau menghapus transaksi, booking, jurnal maupun audit demi mengecilkan ukuran. Kebijakan tersebut perlu dipilih sesuai kebutuhan rekonsiliasi; backup gzip adalah opsi pengurangan ukuran yang langsung terbukti tanpa kehilangan isi.

## Matrix UAT (expected contract; hasil aktual dicatat di laporan validasi)

| # | Skenario | Evidence |
|---:|---|---|
| 1 | Reservasi single-room lama | core + existing browser |
| 2 | Single direct check-in lama | core/booking finance |
| 3 | Satu pemesan tiga child reserved | multi-room |
| 4 | Satu pemesan tiga direct active | multi-room Telegram |
| 5 | Penghuni berbeda/fallback pemesan | multi-room + browser |
| 6 | Master charge/payment balance | multi-room |
| 7 | Individual guest folio | multi-room |
| 8 | Split charge/payment exact cents | multi-room + unit |
| 9 | DP sebelum check-in | multi-room |
| 10 | Partial check-in | multi-room Telegram |
| 11 | Partial checkout | multi-room |
| 12 | Cancel/refund satu child | multi-room |
| 13 | Tambah kamar dan tanggal berbeda | multi-room |
| 14 | Move child mempertahankan link | multi-room |
| 15 | Interval overlap diblokir | multi-room |
| 16 | Dua proses booking race | multi-room |
| 17 | Pembayaran Telegram QRIS/receipt replay | multi-room |
| 18 | PBJT/jurnal/alokasi tidak ganda | multi-room + existing finance |
| 19 | Satu ringkasan per recipient grup | multi-room |
| 20 | Telegram check-in sebagian | multi-room |
| 21 | UI desktop/tablet/mobile | new browser scenario |
| 22 | Owner read-only API/UI | multi-room + existing Owner browser |
| 23 | Data lama tetap readable | existing historical/core suites |
| 24 | Parent/child flag disabled fail closed | multi-room unit |
| 25 | Gagal child rollback parent/link/uang | multi-room |
| 26 | Invoice immutable dan cancel guard | multi-room |
| 27 | Detach tanpa menghapus booking | multi-room |
| 28 | Receipt storage dry-run + exact replay | multi-room + unit |

Unit/source boleh berjalan lokal. Full MySQL, browser, Telegram simulator, hybrid dua node dan HQ hanya berjalan di GitHub CI dengan DB disposable. Semua suite lama tetap disertakan, PHP source matrix 8.2/8.3/8.4/8.5 dan full DB/UI matrix 8.4/8.5. Status PASS tidak disimpulkan hanya dari lint.
