# TAMASYA RC1 Post-Green Features V1

Baseline terkunci: GitHub UAT run `36859135053`, commit `b07a310b6ddfc53a9ff6cba59b99a3e1577714ca`.
Baseline membuktikan 24/24 browser dan 513/513 scenario assertions sebelum fitur ini ditambahkan.

## Fitur

1. Telegram bantuan website: notifikasi memiliki tombol `Balas`; callback mengaktifkan state reply server-side selama 15 menit, pesan Telegram berikutnya dikirim ke conversation ID yang terikat lalu state dihapus. Cancel/expiry/oversize fail-closed. `/balas SUP-...` tetap kompatibel.
2. Memo Internal: Admin dan Finance dapat create/update/archive/restore; Owner read-only. Tidak ada hard-delete. Setiap mutasi menulis required enterprise audit. Schema ditambahkan melalui `V137_OPTIONAL_ENTERPRISE_COMPLETION.sql` dan installer resmi.
3. Owner read-only: role Owner dapat melihat seluruh desktop tab dan data read projections POS/Growth/Enterprise, tetapi semua POST/PUT/PATCH/DELETE diblokir global backend dengan `403 OWNER_READ_ONLY` (logout dikecualikan). UI mutation controls juga disembunyikan/dinonaktifkan.

## UAT baru

- Owner canonical login dan Staff API role creation.
- Owner GET lintas hotel-data, staff, support, memo, POS, Growth, Enterprise, provider adapter, multi-property.
- Owner direct API write-bypass probes ke booking, transactions, shift, POS, Growth, Enterprise, Staff dan Memo; semua wajib 403 dan row counts/journal tidak berubah.
- Memo persistence, search, update, archive/restore, Finance write access, Owner read-only, audit trail.
- Telegram exact-thread direct reply, one-shot state clear, no old-thread leakage, cancel, expiry, oversized message rejection, limited-role denial, legacy `/balas` compatibility.
- Browser Admin Memo lifecycle desktop/mobile.
- Browser Owner module visibility + Memo/Growth/Enterprise/POS read-only state + direct mutation 403.
- Staff UI must expose `Owner (Lihat Semua · Tidak Bisa Mengubah)`.

Full GitHub UAT harus dijalankan ulang sebelum post-green feature release dinyatakan final.
