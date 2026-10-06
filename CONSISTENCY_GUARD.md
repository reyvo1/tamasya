# TAMASYA Consistency Guard R2 — Flexible Maintenance

Build: `20260910-split-backfill-p0fix1-consistency-guard-r1-flex-maintenance-r2-canonical-report-r3-production-audit-r4`

## Tujuan
Consistency Guard adalah lapisan integritas permanen TAMASYA. Ia mendeteksi ketidaksesuaian transaksi, Split Payment, rekening, pajak, jurnal akuntansi, shift, rekonsiliasi, antrean offline, node sync, serta Primary/Standby. Guard tidak melakukan auto-fix terhadap data bisnis.

R2 didesain environment-agnostic: shared hosting murah tanpa cron, hosting dengan cron, VPS, localhost/desktop, node offline, dan cluster dua server menggunakan engine Guard yang sama. Cron bukan dependency.

## Arti status
- `PASS`: invariant yang diperiksa konsisten.
- `WARNING`: perlu perhatian, tetapi kerusakan data belum terbukti, misalnya peer sementara tidak terjangkau atau antrean sync masih mengejar.
- `FAIL`: invariant dilanggar; evidence harus diperiksa sebelum koreksi.
- `NOT_APPLICABLE`: fitur tersebut memang tidak aktif pada node ini.

## Cara Guard berjalan
1. **Canonical transaction protection** tetap berada di workflow server: validasi Split, rekening, pajak, posting jurnal, idempotency, sync/fencing, dan aturan fail-closed mencegah data yang jelas salah disimpan.
2. **Opportunistic browser trigger** berjalan fire-and-forget ketika pengguna login dan perangkat online. Browser hanya memicu; keputusan apakah scan sudah jatuh tempo dibuat server.
3. **System Health / Telegram** dapat membaca Guard on-demand. Telegram menggunakan menu `Akun & Diagnosa -> Consistency Guard` atau `/integritas` untuk Admin/Manager/Finance.
4. **Node sync agent** memakai scheduler Guard yang sama. Pada Standby/local backup, scan boleh berjalan tetapi persistence diagnostik dan revision bump tidak dilakukan.
5. **Cron/VPS scheduler opsional**: `maintenance_cron.php` tetap tersedia bila lingkungan mendukungnya. File ini juga melakukan backup, retention cleanup, dan deep Guard pada jadwal tetap. Guard tetap aktif walaupun file ini tidak pernah dijadwalkan.

## Scheduler portable
State scheduler menggunakan tabel telemetry yang sudah ada, `runtime_request_events`; tidak ada tabel/kolom/migration baru.

Default:
- light scan: 30 menit;
- deep scan: 12 jam;
- retry backoff setelah gagal: 5 menit;
- browser probe: setiap 5 menit setelah startup delay, tetapi server tetap menolak scan jika belum due;
- deep Primary↔Standby checksum hanya dijalankan ketika deep scan due.

Environment override opsional:
- `TAMASYA_GUARD_OPPORTUNISTIC=0|1`
- `TAMASYA_GUARD_LIGHT_INTERVAL_SECONDS`
- `TAMASYA_GUARD_DEEP_INTERVAL_SECONDS`
- `TAMASYA_GUARD_RETRY_INTERVAL_SECONDS`

Scheduler memiliki advisory lock + due recheck untuk mencegah beberapa tab menjalankan scan bersamaan. Jika request bisnis sedang mempunyai DB transaction aktif, Guard langsung skip dengan `caller_transaction_active`; Guard tidak ikut commit/rollback dan tidak menulis telemetry/evidence di dalam transaksi bisnis tersebut.

## Invariant utama
- Split Payment: Tunai + Transfer/QRIS harus sama dengan nominal transaksi dan kaki transfer harus menunjuk rekening aktif non-cash yang valid.
- Akuntansi: transaksi harus mempunyai jurnal current-version yang debit=credit dan nominal jurnal harus sama dengan transaksi.
- Jurnal Split: akun 1101 harus sama dengan kaki Tunai; akun 1102 harus sama dengan kaki Transfer/QRIS.
- Pajak: workflow live yang taxable memerlukan tax snapshot/rule yang lengkap; Backfill unresolved menjadi warning/review dan tidak ditebak atau diperbaiki diam-diam.
- Allocation: base + tax Kamar/Layanan Extra harus konsisten dengan nominal allocation; unresolved/over-allocation terdeteksi.
- Shift: expected cash memakai kaki kas fisik, sehingga Split hanya menambah bagian Tunai.
- Bank reconciliation: matched amount tidak boleh melebihi kaki bank/QRIS dan tidak boleh menempel ke transaksi cash-only.
- Offline: pending queue lama, conflict, operation fingerprint, ACK/replay, dan node sync state diperiksa.
- Dua server: payload hash outbox, conflict/uncertain state, revision/mutation counter, fencing/lease, dan deep checksum replication surface diperiksa.
- Runtime: error API 5xx/failed 24 jam menjadi signal WARNING untuk investigasi.

## Primary / Standby
Primary aktif adalah satu-satunya node yang boleh menyimpan evidence Guard ke `data_integrity_issues` / `system_alerts`. Persistence memakai primary mutation lock. Standby menjalankan Guard read-only dan tidak bump revision Primary.

Deep checksum membandingkan revision dan deterministic SHA-256 dari replication surface. Perbedaan dengan outbox pending adalah `WARNING`; perbedaan tanpa outbox yang menjelaskan selisih adalah `FAIL`; peer tidak terjangkau adalah `WARNING`, bukan PASS palsu.

## Safety
Guard bersifat diagnostik. Ia tidak mengubah transaksi, booking, tax snapshot, journal, shift, atau payload sync untuk “memperbaiki” temuan. Koreksi tetap harus melalui workflow canonical TAMASYA yang tercatat audit trail.

`maintenance_cron.php` tetap boleh dipakai jika kelak pindah ke VPS/hosting ber-cron, tetapi untuk shared hosting tanpa cron tidak ada konfigurasi tambahan yang wajib dilakukan agar opportunistic Guard bekerja.
