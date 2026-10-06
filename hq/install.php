<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_CONTROL_CONFIG_RAW',true);
require_once __DIR__.'/core.php';
if (($argv[1] ?? '') !== '--apply-empty-hq-database') { fwrite(STDERR,"Pemakaian: php hq/install.php --apply-empty-hq-database\nKonfigurasi private ditunjuk TAMASYA_HQ_CONFIG_FILE. Database HQ harus terpisah dan kosong.\n"); exit(1); }
$pdo=tamasyaHqPdo(tamasyaHqConfig());
if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('Installer hanya menerima database HQ kosong.');
$pdo->exec((string)file_get_contents(__DIR__.'/schema.sql'));
$pdo->exec((string)file_get_contents(__DIR__.'/control_schema.sql'));
$pdo->exec((string)file_get_contents(__DIR__.'/delivery_schema.sql'));
echo "Schema read model dan control plane HQ dipasang. Tidak ada koneksi ke database hotel.\n";
