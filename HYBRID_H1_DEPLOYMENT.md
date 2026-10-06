# Pemasangan TAMASYA Hybrid H1

## Hotel tunggal

Gunakan alur instalasi/upgrade R4.5 yang ada. H1 tidak mengubah schema database hotel. Biarkan `TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED=0` dan `TAMASYA_HQ_BRIDGE_ENABLED=0` jika pusat belum diperlukan. Company ID boleh belum terhubung untuk PMS tunggal; snapshot v2 pusat memerlukan company/property yang valid.

Sebelum mengaktifkan HQ, tetapkan Company ID melalui setup hotel yang sah, sesuai organisasi pemiliknya. ID stabil memakai huruf kecil, angka, titik, underscore, kolon atau tanda minus; maksimal 80 karakter, bukan `default`. Identitas database tetap authority. Jangan memindahkan hotel ke company lain hanya dengan mengganti header request.

## HQ terpisah

1. Buat database MySQL HQ kosong dengan user instalasi tersendiri. Jangan gunakan database hotel.
2. Salin folder `hq` dan file `hybrid_contract.php`, mempertahankan hubungan `../hybrid_contract.php`. Bisa menempatkan `hq` sebagai document root dan kontrak satu tingkat di atasnya. Gunakan HTTPS; validasi sertifikat wajib. Kredensial HQ tidak boleh berisi akses database hotel.
3. Buat JSON konfigurasi **di luar document root** dan di luar direktori aplikasi yang disalin. Batasi izin baca pada user PHP/administrator. Tentukan path absolut lewat process environment `TAMASYA_HQ_CONFIG_FILE`. Pada shared hosting yang membatasi environment, atur melalui panel hosting/PHP-FPM; jangan menaruh file private di public_html.
4. Konfigurasi memiliki bentuk di bawah. Placeholder harus diganti; buat secret random minimal 32 karakter berbeda untuk setiap properti. Token viewer minimal 32 karakter acak URL-safe; hanya hash SHA-256-nya disimpan di config. Bagikan token melalui saluran private kepada pengguna berwenang.

```json
{
  "dsn": "mysql:host=HOST_HQ;dbname=DB_HQ;charset=utf8mb4",
  "username": "USER_HQ",
  "password": "PASSWORD_HQ",
  "properties": {
    "company-a": {
      "hotel-a": {"enabled": true, "secret": "GANTI_DENGAN_SECRET_RANDOM_PRIVATE"}
    }
  },
  "viewers": [
    {
      "enabled": true,
      "tokenSha256": "GANTI_DENGAN_SHA256_TOKEN_VIEWER",
      "companyId": "company-a",
      "propertyIds": ["hotel-a"]
    }
  ]
}
```

5. Jalankan `php hq/install.php --apply-empty-hq-database` dari direktori induk. Installer menolak database yang sudah memiliki tabel. Jika instalasi DDL terputus, periksa tabel parsial; jangan menghapus database lain agar installer berjalan.
6. Setelah pemasangan, gunakan user runtime dengan izin SELECT/INSERT pada hq_snapshots, hq_receipts, hq_nonces, serta SELECT/INSERT/UPDATE pada hq_heads dan hq_property_locks. UPDATE pada tabel pengunci diperlukan MySQL untuk SELECT FOR UPDATE; histori snapshot/receipt tidak diberi UPDATE. Cabut CREATE/DROP/DELETE dan izin database lain. Backup menggunakan akun terpisah. Config viewer malformed/disabled ditolak, bukan mendapat akses wildcard.
7. Lindungi `core.php`, `install.php`, `schema.sql`, directory listing, config dan dotfiles di webserver. Apache memakai `.htaccess` yang disertakan; pada Nginx pasang aturan ekuivalen. PHP development server hanya untuk staging, bukan produksi. Batasi request body endpoint HQ sampai 256 KiB dan rate-limit akses di reverse proxy/hosting. Sinkronkan waktu server; signature berlaku ±300 detik.
8. Buka `/hq/index.html` (atau `/index.html` jika hq menjadi document root), masuk dengan token viewer, pilih properti/periode. Token hanya berada di memori halaman. Logout/reload menghapusnya; tidak ada localStorage/sessionStorage token pada dashboard HQ.

## Hubungkan hotel dan antrean

Siapkan direktori private di luar document root untuk outbox, dapat ditulis user PHP. Per properti diberi namespace hash company/property otomatis. Konfigurasi hotel:

```dotenv
TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED=1
TAMASYA_HQ_BRIDGE_ENABLED=1
TAMASYA_HQ_HUB_URL=https://hq.example.com/hq
TAMASYA_HQ_SHARED_SECRET=SECRET_YANG_SAMA_DENGAN_REGISTRY_PROPERTI_HQ
TAMASYA_HYBRID_OUTBOX_DIR=/path/private/tamasya-outbox
TAMASYA_HQ_ALLOW_WRITEBACK=0
TAMASYA_CROSS_PROPERTY_RESERVATION_ENABLED=0
```

Buka halaman Multi-Property, unduh snapshot v2 dan periksa identitas/checksum/integritas. Pilih **Antrekan ke HQ v2**, kemudian kirim job dari panel antrean. Pending/HTTP 202 berarti belum ACK. Success pengiriman hanya saat status `acknowledged` dan receipt sesuai checksum. Tombol bridge legacy tetap memakai kontrak v1; **jangan gunakan tombol legacy v1 untuk penerima H1**, yang menerima v2 saja.

Dead-letter tetap terlihat. Perbaiki akar masalah (grant/signature/scope/revisi), lalu jadwalkan ulang job dengan operation ID/payload sama. Konflik isi pada operation ID tidak boleh diatasi dengan mengedit file job. Request API requeue: POST `action=multi-property`, `command=requeue-snapshot`, `operationId=<ID job>`, dengan sesi staf berwenang seperti UI.

Field body `operationId` adalah identitas job dan tetap sama pada enqueue/deliver/requeue. Header `X-Tamasya-Operation-ID` milik request command PHP harus berbeda untuk command yang berbeda; retry request command yang identik mempertahankan ID request tersebut. Jangan memakai satu ID request untuk payload enqueue dan deliver yang berbeda. UI mengelola pemisahan ini. Daftar outbox dipaginasi 50 job per halaman (`offset`); seluruh status dan jumlah tetap terlihat.

Tidak ada auto-prune receipt di H1. Untuk jangka panjang, desain retensi harus mempertahankan horizon idempotency. Nonce juga tidak dibersihkan otomatis. Gunakan monitoring ukuran database/outbox; jangan mengaktifkan cron penghapusan tanpa kebijakan retensi yang telah ditinjau.

## Release gate sebelum produksi

- Backup sumber R4.5, database hotel, konfigurasi private, database HQ dan outbox; uji restore.
- Uji identitas company/property dan grant yang nyata, termasuk penolakan lintas perusahaan.
- Cocokkan snapshot ke laporan canonical keuangan/pajak/jurnal pada periode yang sama.
- Uji pending, timeout setelah commit, retry operation yang sama, dead-letter dan pemulihan.
- Uji tenant kedua menggunakan database hotel tersendiri. Fixture agregat sintetis bukan pengganti UAT dua hotel sesungguhnya.
- Jalankan skenario penerapan Primary/Standby/fencing jika topologi tersebut dipakai. Pengujian lokal satu host bukan bukti failover lintas mesin.
- Lakukan benchmark dengan ukuran data/concurrency target. H1 belum membuktikan SLA p95 PRD.

Rollback hotel: kembalikan source R4.5 dan matikan bridge; schema hotel tidak berubah oleh H1. Pertahankan database HQ, receipt dan outbox untuk rekonsiliasi. Jangan menghapusnya sebagai bagian rollback source.
