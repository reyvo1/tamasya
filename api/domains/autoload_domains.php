<?php
/**
 * TAMASYA DOMAIN AUTOLOADER — Tahap 1 compatibility loader.
 *
 * Muat file ini SETELAH support lama (path tetap). Saat file mulai dipindah
 * ke api/modules/<domain>/ di Tahap 2, loader ini yang menjaga semua require
 * lama tetap berfungsi: kalau file tidak ada di lokasi lama, dicari di lokasi
 * modul baru (berdasar FUNCTION_MAP.json), dan sebaliknya.
 *
 * Pemakaian di api.php (satu baris, setelah blok require support):
 *   require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR
 *       . 'domains' . DIRECTORY_SEPARATOR . 'autoload_domains.php';
 */

if (!defined('TAMASYA_DOMAIN_LOADER')) {
    define('TAMASYA_DOMAIN_LOADER', true);

    /**
     * Resolve path file support: cek lokasi legacy dulu, lalu modules/<domain>.
     * Mengembalikan absolute path atau null bila tidak ditemukan.
     */
    function tamasyaResolveDomainSupportFile(string $basename): ?string
    {
        static $map = null;
        $apiDir = dirname(__DIR__);

        // 1) Lokasi legacy (support/) — prioritas agar perilaku tak berubah.
        $legacy = $apiDir . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . $basename;
        if (is_file($legacy)) {
            return $legacy;
        }

        // 2) Lokasi modul baru — cari lewat FUNCTION_MAP.json (file -> domain).
        if ($map === null) {
            $mapFile = __DIR__ . DIRECTORY_SEPARATOR . 'MIGRATION_MAP.json';
            $map = is_file($mapFile)
                ? json_decode((string) file_get_contents($mapFile), true) ?: []
                : [];
        }
        $domain = $map[$basename] ?? null;
        if (is_string($domain) && $domain !== '') {
            $modular = $apiDir . DIRECTORY_SEPARATOR . 'modules'
                . DIRECTORY_SEPARATOR . $domain
                . DIRECTORY_SEPARATOR . $basename;
            if (is_file($modular)) {
                return $modular;
            }
        }

        return null;
    }
}
