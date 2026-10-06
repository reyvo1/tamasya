<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('TAMASYA_CONTROL_CONFIG_RAW',true);
require __DIR__.'/core.php';
if (($argv[1]??'')!=='--apply-hq-only') { fwrite(STDERR,"php hq/upgrade_h2.php --apply-hq-only\n"); exit(1); }
$pdo=tamasyaHqPdo(tamasyaHqConfig());
if (!$pdo->query("SHOW TABLES LIKE 'hq_snapshots'")->fetch()) throw new RuntimeException('Database ini bukan HQ H1.');
if ($pdo->query("SHOW TABLES LIKE 'transactions'")->fetch()) throw new RuntimeException('Jangan menjalankan migrasi HQ di database hotel.');
$pdo->exec('CREATE TABLE IF NOT EXISTS hq_reports (checksum char(64) COLLATE ascii_bin PRIMARY KEY, company_id varchar(80) COLLATE utf8mb4_bin NOT NULL, body mediumtext NOT NULL, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY company_reports(company_id,created_at)) ENGINE=InnoDB');
if (!$pdo->query("SHOW COLUMNS FROM hq_property_locks LIKE 'event_revision'")->fetch()) $pdo->exec('ALTER TABLE hq_property_locks ADD COLUMN event_revision bigint unsigned NOT NULL DEFAULT 0');
$pdo->exec((string)file_get_contents(__DIR__.'/control_schema.sql'));
$pdo->exec((string)file_get_contents(__DIR__.'/delivery_schema.sql'));
echo "HQ reports and control-plane schema ready. Apply documented least-privilege runtime grants.\n";
