# TAMASYA Enterprise RC1 — status implementasi

22 September 2026. Melanjutkan baseline H1 sesuai PRD Hybrid Modular Architecture v1.0. Pengguna belum memiliki server staging. **Kandidat rilis untuk staging; belum diterapkan dan belum disertifikasi siap produksi.** Laporan pengujian Enterprise terbaru menjadi acuan, sedangkan dokumen H1/R4 adalah histori baseline.

| Bagian | Implementasi dan bukti lokal | Gate yang belum tertutup |
|---|---|---|
| Core keuangan/operasional | PHP canonical tetap menjadi penulis transaksi; suite transaksi normal, backfill, jurnal, pajak, shift, laporan, replay dan restore | UAT data serta prosedur hotel target |
| Isolasi dan administrasi tenant | Database hotel terpisah, HQ read model, registry perusahaan/properti, role/grant/revoke/suspend, audit immutable, provisioning private | Routing/domain dan provisioning di infrastruktur target |
| Laporan Enterprise | Snapshot immutable, sumber/revisi/checksum, rekonsiliasi dan ekspor JSON/CSV/XLSX/PDF/email/Telegram | Review visual semua variasi laporan; kebijakan konsolidasi khusus perusahaan |
| Async | Worker opsional, outbox immutable, ACK, backoff, DLQ, lock dan restart; pengiriman laporan melalui bridge | Provider email/Telegram nyata dan rekonsiliasi operasional uncertain delivery |
| Realtime/IoT | SSE bertiket, expiry/scope, revision-only, fallback polling; worker perangkat dengan ACK job spesifik | Bridge/perangkat fisik dan kapasitas koneksi target |
| HA hybrid | Dua proses PHP + dua database lokal, mirror, checksum, planned switch dan satu writer; commit fencing epoch/lease/token | Kegagalan mesin/jaringan lintas fault domain, pemulihan primary lama dan DR |
| SaaS deployment | Profil PHP-FPM/Nginx, beberapa API, HQ/realtime/worker, single-writer DB endpoint, TLS DB opsional, private object storage SigV4 | Build/start container, DB quorum/Router/provider, IAM/S3 nyata, autoscaling dan DR |
| Kapasitas | Baseline beban lokal dicatat terpisah; tanpa klaim SLA | Load test representatif di PHP-FPM dan sizing host/database |

Database-per-property meneruskan isolasi yang sudah ada pada aplikasi awal. Ini bukan migrasi diam-diam menuju tabel multi-tenant bersama. Feature opsional tetap dapat dinonaktifkan tanpa mengganti jalur akuntansi/pajak core.

Paket tidak menyediakan server, kredensial cloud, provider pesan, atau perangkat fisik. Gate fase 4–6 PRD mensyaratkan load/failover/DR pada target; konfigurasi dan simulasi lokal tidak menggantikan gate tersebut. Jangan mengubah status ini menjadi “seluruh PRD selesai produksi” hanya karena seluruh pemeriksaan lokal lulus.

Lihat `deploy/README.md` untuk deployment dan `ENTERPRISE_TEST_REPORT.md` untuk angka, batas uji, serta berkas bukti hasil akhir.
