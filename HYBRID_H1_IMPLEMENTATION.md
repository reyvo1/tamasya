# TAMASYA Hybrid H1 — implementasi awal PRD v1.0

Baseline: aplikasi hasil audit R4.5. H1 menambahkan batas scope, snapshot terverifikasi, read model HQ, dashboard perusahaan, dan outbox agregat. Dokumen PRD adalah roadmap bertahap; H1 bukan pernyataan bahwa seluruh fase VPS/SaaS dan disaster recovery sudah selesai.

## Yang tersedia

- PHP tetap menjalankan seluruh mutasi booking, keuangan, pajak, jurnal, shift dan backfill melalui authority yang ada.
- Database hotel tetap terpisah per properti. Header company/property eksplisit yang tidak cocok ditolak setelah autentikasi. Klien lama tetap terikat pada database server dan opaque hotel scope dari login.
- `GET api.php?action=multi-property&command=snapshot-v2&from=YYYY-MM-DD&to=YYYY-MM-DD` menghasilkan snapshot minimal dari satu transaksi baca MySQL repeatable-read. Company ID dan Property ID wajib terisi.
- Ringkasan keuangan menggunakan fungsi laporan canonical yang sama: pendapatan, beban, laba, PBJT terbentuk/dibayar, PPh dibayar, mutasi kas/bank; tidak ada rumus ledger alternatif pada HQ.
- Snapshot berisi minor units sebagai string bilangan bulat, revisi sumber, checksum SHA-256, occupancy numerator/denominator, dan status integritas. Tidak berisi rincian tamu, staf, booking, rekening atau transaksi.
- HQ menyimpan snapshot immutable, receipt operation ID, nonce dan head per properti/periode dalam database pusat terpisah. Hanya head yang diperbarui; histori snapshot dan receipt tidak ditimpa oleh aplikasi.
- Dashboard `hq/index.html` menampilkan properti yang ada dalam grant server, total per mata uang, occupancy tertimbang, jejak revisi/checksum dan snapshot yang belum tersedia. Tidak ada query ke database cabang saat dashboard direfresh.
- Outbox opsional menyimpan payload sebelum pending ditampilkan, mempertahankan operation ID saat retry, dan menuntut ACK HTTP 200 dengan operation ID serta checksum yang cocok. Timeout/202/ACK yang salah tidak diartikan selesai. Retry memiliki backoff; kegagalan permanen atau lima percobaan masuk dead-letter.
- UI properti tersedia di `multi-property-foundation.html`: unduh snapshot v2, antrekan, lihat status, kirim ulang, dan periksa kapabilitas. Bridge lama v1 tetap tersedia untuk kompatibilitas dan berbeda dari outbox v2.
- Semua fungsi operasional hotel tetap dapat berjalan tanpa HQ, Redis, cron atau worker eksternal.

## Pemetaan PRD dan batas rilis

| Bagian PRD | H1 | Pekerjaan lanjutan |
|---|---|---|
| Fase 0 baseline | R4.5 disimpan; H1 dibangun pada salinan terpisah; pengujian ulang inti | UAT bisnis di lingkungan penerapan sebenarnya |
| Fase 1 isolasi | Database per hotel, opaque scope existing, tambahan header company/property, grant HQ fail-closed | Lifecycle tenant, UI manajemen grant, SSO, role platform/company lengkap |
| Fase 2 enterprise | Snapshot keuangan/pajak/jurnal dan occupancy, read model, checksum, rekonsiliasi, multi-currency terpisah | Breakdown payroll, AR/AP dan procurement khusus; eliminasi intercompany; transfer antarproperti |
| Fase 3 async | Durable outbox khusus agregat, receipt, replay, backoff, dead-letter, kontrak versi | Generic job broker, daemon worker, kredensial service tersendiri, scheduler otomatis |
| Fase 4–6 VPS/realtime/SaaS | Batas authority dan kapabilitas disiapkan; PHP shared hosting tetap didukung | Node/Go/WebSocket/IoT, Redis adapter, autoscaling, HA database, object storage, provisioning SaaS |
| Hybrid Primary/Standby | Guard existing dipakai sebelum pengiriman keluar | Uji partisi jaringan, failover/fencing lintas mesin dan disaster recovery menyeluruh |
| Report parity | Snapshot uang HQ memakai summary canonical; unduhan JSON mempertahankan checksum | Export PDF/XLSX/email/Telegram khusus laporan konsolidasi HQ |
| Performance | Query HQ terbatasi maksimum 50 properti dan 400 hari; ETag; tanpa polling otomatis | Benchmark p95/p99 dan load test multi-node; ETag snapshot properti masih menghitung isi sebelum 304 |

H1 tidak menjalankan deployment produksi. Semua pengujian lokal memakai database/sertifikat/akun staging terpisah. Instalasi HQ dan flag bridge memerlukan konfigurasi operator sesuai panduan.

## Kontrak snapshot dan uang

Versi `tamasya-hq-snapshot-v2`. Semua key wajib tepat sesuai `hybrid_contract.php`; key tambahan ditolak agar payload tidak menyelipkan data pribadi. Uang menggunakan dua angka desimal dari financial core saat ini: `"12345"` berarti 123,45 unit mata uang. Mata uang yang memerlukan presisi berbeda harus melalui revisi kontrak; jangan mengubah skala sepihak.

Checksum: hapus hanya field `checksumSha256`, urutkan key objek secara rekursif (array berurutan tidak diurutkan), encode UTF-8 JSON tanpa whitespace dengan Unicode dan slash tidak di-escape, lalu SHA-256. Payload tidak mengandung float sehingga verifikasi lintas PHP/Python stabil. Field volatile waktu penerimaan disimpan sebagai metadata HQ, di luar snapshot.

Revisi sama dengan checksum berbeda ditolak sebagai konflik sumber. Revisi lebih lama tidak menggantikan head untuk properti/periode yang sama. Setelah restore database hotel yang menurunkan revisi, operator perlu merekonsiliasi sumber dan HQ; jangan memaksa reset head untuk menghilangkan konflik tanpa pemeriksaan.

`PASS` bukan jaminan audit universal. Snapshot mewarisi status consistency guard canonical. Review pajak/backfill, konflik sinkronisasi, atau jurnal tidak seimbang mempertahankan WARNING/FAIL. Rincian consistency guard tetap diperiksa pada properti sumber. Snapshot yang belum tersedia menghasilkan INCOMPLETE; total parsial diberi label, bukan dianggap nol.

Occupancy dihitung `sum(sold) / sum(available)`. ADR/RevPAR tetap estimasi alokasi room charge dari KPI existing; bukan angka pengakuan pendapatan jurnal. Mata uang berbeda tidak dijumlahkan atau dikonversi otomatis. Mutasi kas/bank adalah arus dalam periode, bukan saldo neraca.

## Antrean dan kegagalan

Job khusus `hq.aggregate.snapshot`: job_id, operation_id, company_id, property_id, payload_version, created_at, attempts, status, checksum, snapshot, next_attempt_at, receipt, last_error. Payload dan operation ID tidak diubah oleh retry. Penulisan file menggunakan lock, file sementara, flush/fsync dan rename atomik. Gunakan filesystem lokal dengan dukungan operasi tersebut; jangan gunakan mount yang tidak menjamin locking/rename atomik.

Status: pending → sending → acknowledged, atau pending dengan backoff / dead_letter. Proses mati setelah HQ commit tetapi sebelum ACK disimpan dapat mengulang operation ID yang sama; receiver mengembalikan receipt semula. Crash saat sending juga menggunakan payload semula. Antrean tidak menghapus job secara otomatis. Batas 1.000 job; operator mengarsipkan hanya job acknowledged setelah mencocokkan receipt pusat, saat tidak ada proses pengiriman. Backup direktori outbox bersama konfigurasi dan database HQ; jangan membuang job pending/sending/dead_letter.

Pengiriman H1 dipicu tombol operator. Cron/daemon bukan syarat. Shared hosting tetap berfungsi tanpa pengiriman otomatis. Untuk memasang worker pada fase selanjutnya, worker wajib memakai kontrak PHP/receipt dan tidak memiliki akses menulis ledger hotel.

## Validasi developer

```
php tests/regression.php
node tests/regression.mjs
php tests/finance-regression.php
php tests/hybrid-regression.php
```

Tes integrasi MySQL/HTTPS dan bukti rilis disertakan terpisah dalam paket hasil pengujian. Angka cakupan final dicatat pada laporan hasil H1; jangan memakai angka audit R4.5 sebagai bukti otomatis untuk perubahan H1.
