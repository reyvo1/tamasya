# Akar kegagalan GitHub UAT R8 dan perbaikan R9

Build kandidat: `20261007-hybrid-identity-r9`.
Run yang diperiksa: `37493544183`, commit `ee8a7c20d71c34919ed8669731f3e8fdbaf7afec`.
Sumber: arsip pengguna `logs_101561175979.zip` (155 entri) dan artifact `tamasya-enterprise-full-complete-uat-evidence-php-8.5` dari run yang sama.

## Bukti hasil R8

- Semua source job PHP 8.2–8.5 dan VPS/realtime/SaaS lulus.
- Browser PHP 8.4 dan 8.5 masing-masing 46/46 lulus, termasuk mobile, angka ringkasan, audit shift, Owner dan unduhan.
- Assertion bisnis pada kedua job hotel: 808/813 lulus. Lima kegagalan berasal dari dua akar: satu identitas Telegram dan empat memo dua-node. Empat kegagalan memo merupakan rantai dari create yang ditolak, bukan empat kegagalan mirror terpisah.

## Telegram: kontrak hasil pencarian identitas

`php-server.log` mengungkap referensi `D4FE8FA226`: `tamasyaTelegramSimulationHotelData(): Argument #2 ($staff) must be of type ?array, false given` pada route baris 6933. Perbaikan R8 menjaga proyeksi untuk null tetapi tesnya hanya memasukkan null buatan; resolver sebenarnya mengembalikan false ketika binding maupun akun legacy tidak ditemukan.

Resolver `findActiveStaffByTelegramUserId` kini memiliki kontrak `?array`. ID kosong, akun tidak ditemukan, dan ID legacy duplikat semuanya menghasilkan null. Akun terverifikasi tetap menang atas legacy, query tetap menyaring binding/staf aktif, dan role tetap berasal dari server. Semua pengguna resolver memakai kontrak ini, termasuk pesan, callback dan pencarian ulang keyboard. Identitas tak terikat tetap tidak mendapat proyeksi hotel.

Tes unit mengeksekusi resolver sebenarnya dengan PDO double yang mengembalikan false, kemudian melewatkan hasil ke helper proyeksi. Kasus kosong, tidak ditemukan, duplikat, binding terverifikasi, legacy unik dan role asli tercakup. UAT menambahkan callback menu identitas asing dengan role admin palsu; assertion pesan sebelumnya tetap dipertahankan.

## Memo hybrid: endpoint tidak terdaftar untuk forwarding

`two-node-b.log` menunjukkan `POST internal-memos` ditolak 409 pada stage `policy:node_guard`, sebelum route memo dijalankan. `growth_internal_memos` sudah ada dalam canonical snapshot, tetapi endpoint `internal-memos` tidak ada pada daftar `tamasyaNodeSyncAllowedAction`; guard lokal menolaknya sebagai operasi tanpa kontrak replay.

Endpoint kini masuk kontrak hanya untuk POST/PUT dan command create/update/archive/restore, termasuk default command yang sama dengan route. Request mengikuti jalur signed forwarding, operation receipt, pemeriksaan actor, epoch/fencing, lease dan Primary yang sudah ada. Tidak ada bypass ke penulisan lokal Standby. Ketika Primary tidak tersedia pada flexible cluster, respons tetap 503. Konfigurasi/staf serta command/method tidak didukung tetap diblokir. Primary tetap menerapkan izin Admin/Finance; Owner tidak mendapat izin tulis.

Tes unit memakai guard nyata untuk semua command, default, method terlarang, command tidak dikenal, dan state draining/split-brain. UAT dua-node tetap memeriksa pembuatan memo melalui Standby, mirror, promosi, archive melalui Primary lama dan mirror balik; ditambah receipt retry tidak membuat memo ganda dan memo tidak ditulis lokal saat Primary mati. Detail penolakan dicatat dalam evidence.

## Validasi dan batas bukti

Source/unit, checksum dan browser inventory lock diperiksa pada kandidat sebelum push. Full browser/API/MySQL/Docker/dua-node dijalankan oleh GitHub; tidak dijalankan secara lokal. R9 belum dinyatakan UAT penuh lulus hanya berdasarkan source/unit. Tidak ada perubahan database produksi, aktivasi layanan eksternal, atau pengurangan assertion/gate UAT.
