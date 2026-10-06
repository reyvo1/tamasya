# Tata letak Modul Hotel — R4.2

Build: `20261006-module-layout-r4.2`.

Pada tampilan sebelumnya, empat kartu mengisi grid tiga kolom sehingga Enterprise Suite berada sendiri pada baris kedua. Memo Internal kini berada di sebelah judul Modul Hotel sebagai tautan ringkas. POS & Minibar, Growth Suite, dan Enterprise Suite mengisi satu baris dengan lebar seimbang pada desktop. Pada layar kecil, ketiganya tersusun vertikal. Jumlah kartu menyesuaikan hak akses dan mengisi lebar yang tersedia.

Memo tetap memakai URL, identitas elemen, dan pemeriksaan hak akses sebelumnya; tidak ada perubahan fitur, aturan transaksi, database, atau aktivasi Growth/Enterprise. Owner tetap hanya membaca. Pencarian tamu, pemilih mode, dan permintaan tamu berpindah ke baris bawah pada layar menengah agar tidak berdesakan. Fokus keyboard pada tautan modul lebih jelas.

Cache browser dan service worker memakai versi baru agar perubahan juga tersedia pada aplikasi offline. Paket lengkap memuat perbaikan R4 dan hotfix jalur service worker R4.1 yang sudah ada di sumber.

Validasi: 30 suite pemeriksaan sumber/unit melalui scripts/verify-release.sh lulus; kasus browser UAT yang sudah ada diperluas untuk memeriksa Memo hanya muncul sekali, tiga kartu, lebar sejajar desktop, susunan vertikal mobile, dan tidak ada overflow halaman. UAT browser dan penilaian visual hasil pemasangan belum dijalankan lokal, sesuai permintaan pengguna.

Gunakan paket yang sama pada kedua server. Jangan mengimpor database_setup.sql ke database hotel yang sudah terisi hanya untuk pembaruan tata letak ini. Status paket tetap kandidat sampai UAT GitHub lulus.
