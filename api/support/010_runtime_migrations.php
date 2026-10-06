<?php
/** Auto-extracted from RC4.10.15 api.php. Keep this file internal. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 983: ensureColumnExists */
function ensureColumnExists($pdo, $table, $column, $definition) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '" . str_replace("'", "''", $column) . "'");
        if ($stmt && $stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    } catch (Throwable $e) {}
}

/** Source line 992: schemaMigrationApplied */
function schemaMigrationApplied($pdo, string $version): bool {
    if (!$pdo) return false;
    static $versionCache = [];
    $connectionKey = function_exists('spl_object_id') ? spl_object_id($pdo) : 0;
    if (!array_key_exists($connectionKey, $versionCache)) {
        try {
            // Jalur normal production hanya melakukan satu SELECT ringan. DDL
            // dijalankan hanya bila tabel marker belum ada pada database lama.
            $stmtVersions = $pdo->query("SELECT version FROM schema_migrations");
            if (!$stmtVersions) throw new RuntimeException('schema_migrations unavailable');
            $versions = $stmtVersions->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $versionCache[$connectionKey] = array_fill_keys(array_map('strval', $versions), true);
        } catch (Throwable $e) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(100) PRIMARY KEY,
                    description VARCHAR(255) NOT NULL,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $versions = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $versionCache[$connectionKey] = array_fill_keys(array_map('strval', $versions), true);
            } catch (Throwable $createError) {
                $versionCache[$connectionKey] = [];
            }
        }
    }
    return isset($versionCache[$connectionKey][$version]);
}

/** Source line 1021: runRuntimeMigrations */
function runRuntimeMigrations($pdo) {
    if (!$pdo || schemaMigrationApplied($pdo, '2026.07.security-sync.2')) return;
    try {
        // Critical tables are checked on every request, not only during initial seeding.
        $pdo->exec("CREATE TABLE IF NOT EXISTS sync_operations (
            operation_id VARCHAR(100) PRIMARY KEY,
            staff_id VARCHAR(50) NOT NULL,
            device_id VARCHAR(190) NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id VARCHAR(100) NOT NULL,
            action VARCHAR(20) NOT NULL,
            payload_hash VARCHAR(64) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'processed',
            result_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            INDEX idx_sync_staff(staff_id),
            INDEX idx_sync_device(device_id),
            INDEX idx_sync_entity(entity_type, entity_id),
            INDEX idx_sync_status(status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_bindings (
            telegram_user_id VARCHAR(100) PRIMARY KEY,
            telegram_chat_id VARCHAR(100) NOT NULL,
            staff_id VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_telegram_binding_staff(staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_binding_conflict_archive (
            archive_key CHAR(64) PRIMARY KEY,
            telegram_user_id VARCHAR(100) NOT NULL,
            telegram_chat_id VARCHAR(100) NULL,
            staff_id VARCHAR(50) NOT NULL,
            status VARCHAR(20) NULL,
            verified_at DATETIME NULL,
            archive_reason VARCHAR(100) NOT NULL,
            source_node_id VARCHAR(100) NULL,
            archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tg_binding_archive_staff(staff_id, archived_at),
            INDEX idx_tg_binding_archive_user(telegram_user_id, archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_binding_codes (
            id VARCHAR(80) PRIMARY KEY,
            staff_id VARCHAR(50) NOT NULL,
            code_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            used_telegram_user_id VARCHAR(100) NULL,
            created_by VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tg_bind_staff(staff_id, expires_at, used_at),
            INDEX idx_tg_bind_exp(expires_at, used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Migrasikan legacy Telegram ID hanya jika nilainya unik. ID yang muncul
        // pada lebih dari satu staff ditandai conflict dan tidak boleh mengautentikasi siapa pun.
        $pdo->exec("INSERT INTO telegram_bindings (telegram_user_id, telegram_chat_id, staff_id, status, verified_at)
            SELECT CAST(s.telegram_chat_id AS CHAR), CAST(s.telegram_chat_id AS CHAR), s.id, 'active', CURRENT_TIMESTAMP
            FROM staff s
            WHERE s.telegram_chat_id IS NOT NULL AND TRIM(CAST(s.telegram_chat_id AS CHAR)) <> ''
              AND (SELECT COUNT(*) FROM staff sx WHERE TRIM(CAST(sx.telegram_chat_id AS CHAR))=TRIM(CAST(s.telegram_chat_id AS CHAR)))=1
            ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id), telegram_chat_id=VALUES(telegram_chat_id), status='active'");
        $pdo->exec("UPDATE telegram_bindings tb
            JOIN (SELECT TRIM(CAST(telegram_chat_id AS CHAR)) AS telegram_id FROM staff
                  WHERE telegram_chat_id IS NOT NULL AND TRIM(CAST(telegram_chat_id AS CHAR))<>''
                  GROUP BY TRIM(CAST(telegram_chat_id AS CHAR)) HAVING COUNT(*)>1) dup
              ON dup.telegram_id=tb.telegram_user_id
            SET tb.status='conflict'");
        // Satu staff_id hanya boleh memiliki satu binding. Database lama dapat
        // menyimpan duplikat karena sebelumnya hanya Telegram User ID yang unik.
        // Pertahankan binding terbaru dan pasang unique key untuk menutup race.
        try {
            $idx=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telegram_bindings' AND INDEX_NAME='uq_telegram_binding_staff'");
            if ((int)($idx?$idx->fetchColumn():0)===0) {
                $pdo->exec("INSERT IGNORE INTO telegram_binding_conflict_archive
                    (archive_key,telegram_user_id,telegram_chat_id,staff_id,status,verified_at,archive_reason,source_node_id,archived_at)
                    SELECT SHA2(CONCAT_WS('|',old_tb.telegram_user_id,old_tb.staff_id,COALESCE(DATE_FORMAT(old_tb.verified_at,'%Y-%m-%d %H:%i:%s'),''),'duplicate_staff_binding'),256),
                           old_tb.telegram_user_id,old_tb.telegram_chat_id,old_tb.staff_id,old_tb.status,old_tb.verified_at,
                           'duplicate_staff_binding',".$pdo->quote(function_exists('tamasyaNodeId')?tamasyaNodeId():'runtime-migration').",CURRENT_TIMESTAMP
                    FROM telegram_bindings old_tb
                    JOIN telegram_bindings new_tb ON new_tb.staff_id=old_tb.staff_id
                     AND (COALESCE(new_tb.verified_at,'1970-01-01 00:00:00')>COALESCE(old_tb.verified_at,'1970-01-01 00:00:00')
                          OR (COALESCE(new_tb.verified_at,'1970-01-01 00:00:00')=COALESCE(old_tb.verified_at,'1970-01-01 00:00:00') AND new_tb.telegram_user_id>old_tb.telegram_user_id))");
                $pdo->exec("DELETE old_tb FROM telegram_bindings old_tb
                    JOIN telegram_bindings new_tb ON new_tb.staff_id=old_tb.staff_id
                     AND (COALESCE(new_tb.verified_at,'1970-01-01 00:00:00')>COALESCE(old_tb.verified_at,'1970-01-01 00:00:00')
                          OR (COALESCE(new_tb.verified_at,'1970-01-01 00:00:00')=COALESCE(old_tb.verified_at,'1970-01-01 00:00:00') AND new_tb.telegram_user_id>old_tb.telegram_user_id))");
                $pdo->exec("CREATE UNIQUE INDEX uq_telegram_binding_staff ON telegram_bindings (staff_id)");
            }
        } catch (Throwable $telegramBindingIndexError) {
            error_log(clientExceptionMessage('[Telegram Auth] unique staff binding migration failed',$telegramBindingIndexError));
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS shift_sessions (
            id VARCHAR(100) PRIMARY KEY,
            staff_id VARCHAR(50) NOT NULL,
            staff_name VARCHAR(150) NOT NULL,
            companion_staff_id VARCHAR(50) DEFAULT NULL,
            companion_staff_name VARCHAR(150) DEFAULT NULL,
            shift_date DATE NULL,
            shift_time VARCHAR(20) NULL,
            opening_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
            cash_income DECIMAL(15,2) NOT NULL DEFAULT 0,
            cash_expense DECIMAL(15,2) NOT NULL DEFAULT 0,
            expected_cash DECIMAL(15,2) NOT NULL DEFAULT 0,
            actual_cash DECIMAL(15,2) DEFAULT NULL,
            variance DECIMAL(15,2) DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            notes TEXT NULL,
            opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME DEFAULT NULL,
            INDEX idx_shift_staff_status(staff_id,status),
            INDEX idx_shift_companion_status(companion_staff_id,status),
            INDEX idx_shift_participants_date(staff_id,companion_staff_id,shift_date,status),
            INDEX idx_shift_date_time(shift_date,shift_time),
            INDEX idx_shift_opened(opened_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            scope_key VARCHAR(190) PRIMARY KEY,
            window_start DATETIME NOT NULL,
            hit_count INT NOT NULL DEFAULT 0,
            blocked_until DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rate_blocked(blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) PRIMARY KEY,
            description VARCHAR(255) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_reads (
            notification_id VARCHAR(50) NOT NULL,
            staff_id VARCHAR(50) NOT NULL,
            read_at DATETIME NOT NULL,
            PRIMARY KEY (notification_id, staff_id),
            INDEX idx_notification_reads_staff (staff_id, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT IGNORE INTO notification_reads (notification_id, staff_id, read_at)
            SELECT n.id, s.id, COALESCE(n.timestamp, NOW())
            FROM notifications n CROSS JOIN staff s
            WHERE n.`read` = 1");
        ensureColumnExists($pdo,'sync_operations','device_id','VARCHAR(190) NULL');
        ensureColumnExists($pdo,'sync_operations','payload_hash','VARCHAR(64) NULL');
        ensureColumnExists($pdo,'sync_operations','status',"VARCHAR(30) NOT NULL DEFAULT 'processed'");
        ensureColumnExists($pdo,'sync_operations','result_json','LONGTEXT NULL');
        ensureColumnExists($pdo,'sync_operations','processed_at','DATETIME NULL');
        try { $pdo->exec("CREATE INDEX idx_sync_device ON sync_operations (device_id)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_sync_status ON sync_operations (status,created_at)"); } catch (Throwable $e) {}
    } catch (Throwable $e) {}

    foreach ([
        ['staff','offline_refresh_token','VARCHAR(255) DEFAULT NULL'],
        ['staff','two_factor_code_hash','VARCHAR(255) DEFAULT NULL'],
        ['staff','two_factor_challenge_hash','VARCHAR(64) DEFAULT NULL'],
        ['staff','two_factor_device_id','VARCHAR(190) DEFAULT NULL'],
        ['staff','offline_refresh_expires','DATETIME DEFAULT NULL'],
        ['config','telegram_webhook_secret','VARCHAR(255) DEFAULT NULL'],
        ['config','server_revision','BIGINT NOT NULL DEFAULT 0'],
        ['config','approval_required_sensitive','TINYINT(1) NOT NULL DEFAULT 0'],
        ['config','approval_threshold','DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['rooms','version','INT NOT NULL DEFAULT 1'],
        ['rooms','updatedAt','DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
        ['rooms','updatedBy','VARCHAR(100) DEFAULT NULL'],
        ['rooms','updatedSource','VARCHAR(30) DEFAULT NULL'],
        ['bookings','version','INT NOT NULL DEFAULT 1'],
        ['bookings','updatedAt','DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
        ['bookings','updatedBy','VARCHAR(100) DEFAULT NULL'],
        ['bookings','updatedSource','VARCHAR(30) DEFAULT NULL'],
        ['transactions','version','INT NOT NULL DEFAULT 1'],
        ['transactions','updatedAt','DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
        ['transactions','updatedBy','VARCHAR(100) DEFAULT NULL'],
        ['transactions','updatedSource','VARCHAR(30) DEFAULT NULL'],
        ['transactions','transactionKind',"VARCHAR(40) NOT NULL DEFAULT 'manual'"],
        ['transactions','sourceEntity','VARCHAR(50) DEFAULT NULL'],
        ['transactions','sourceEntityId','VARCHAR(100) DEFAULT NULL'],
        ['transactions','isSystemGenerated','TINYINT(1) NOT NULL DEFAULT 0'],
        ['transactions','operationId','VARCHAR(100) DEFAULT NULL'],
        ['salary_slips','payment_method','VARCHAR(20) DEFAULT NULL'],
        ['salary_slips','bank_account_id','VARCHAR(100) DEFAULT NULL'],
        ['salary_slips','paid_at','DATETIME DEFAULT NULL'],
        ['salary_slips','updated_at','DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
        ['inventory','version','INT NOT NULL DEFAULT 1'],
        ['inventory','updated_at','DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
        ['inventory','updated_by_staff_id','VARCHAR(100) DEFAULT NULL'],
        ['inventory','updated_source','VARCHAR(30) DEFAULT NULL'],
        ['inventory_maintenance','version','INT NOT NULL DEFAULT 1'],
        ['inventory_maintenance','updated_at','DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'],
        ['inventory_maintenance','updated_by_staff_id','VARCHAR(100) DEFAULT NULL'],
        ['inventory_maintenance','updated_source','VARCHAR(30) DEFAULT NULL'],
        ['shift_sessions','shift_date','DATE NULL'],
        ['shift_sessions','shift_time','VARCHAR(20) NULL'],
        ['shift_sessions','cash_income','DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['shift_sessions','cash_expense','DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['shift_sessions','notes','TEXT NULL']
    ] as $migration) {
        ensureColumnExists($pdo, $migration[0], $migration[1], $migration[2]);
    }
    try { $pdo->exec("CREATE UNIQUE INDEX idx_transactions_operation ON transactions (operationId)"); } catch (Throwable $e) {}
    try {
        $pdo->prepare("INSERT INTO schema_migrations (version,description,applied_at)
            VALUES ('2026.07.security-sync.2','Per-user notifications, hardened offline auth, and scoped synchronization',CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
    } catch (Throwable $e) {}
}


/** Additive production biometric attendance schema. Safe for existing databases. */
function runBiometricAttendanceMigrations($pdo): void {
    if (!$pdo || schemaMigrationApplied($pdo,'2026.08.biometric-attendance.1')) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS biometric_devices (
            id VARCHAR(50) PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            serial_number VARCHAR(150) NOT NULL,
            device_type VARCHAR(30) NOT NULL,
            connection_mode VARCHAR(30) NOT NULL DEFAULT 'webhook',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            api_key_hash CHAR(64) NOT NULL,
            webhook_secret_encrypted TEXT NOT NULL,
            location VARCHAR(150) NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_biometric_device_serial(serial_number),
            UNIQUE KEY uq_biometric_device_api_key(api_key_hash),
            INDEX idx_biometric_device_status(status,last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS biometric_device_users (
            id VARCHAR(50) PRIMARY KEY,
            device_id VARCHAR(50) NOT NULL,
            device_user_id VARCHAR(100) NOT NULL,
            staff_id VARCHAR(50) NOT NULL,
            biometric_type VARCHAR(30) NOT NULL,
            enrollment_status VARCHAR(20) NOT NULL DEFAULT 'active',
            enrolled_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_biometric_device_user(device_id,device_user_id),
            INDEX idx_biometric_device_staff(staff_id,enrollment_status),
            CONSTRAINT fk_biometric_device_user_device FOREIGN KEY (device_id) REFERENCES biometric_devices(id) ON DELETE CASCADE,
            CONSTRAINT fk_biometric_device_user_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_biometric_profiles (
            id VARCHAR(50) PRIMARY KEY,
            staff_id VARCHAR(50) NOT NULL,
            biometric_type VARCHAR(30) NOT NULL,
            provider VARCHAR(100) NOT NULL,
            provider_profile_id VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            enrolled_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_staff_biometric_profile(staff_id,biometric_type),
            INDEX idx_staff_biometric_status(status,biometric_type),
            CONSTRAINT fk_staff_biometric_profile_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS biometric_verifications (
            id VARCHAR(120) PRIMARY KEY,
            staff_id VARCHAR(50) NOT NULL,
            method VARCHAR(30) NOT NULL,
            source VARCHAR(40) NOT NULL,
            device_id VARCHAR(50) NULL,
            device_serial VARCHAR(190) NULL,
            provider VARCHAR(100) NULL,
            score DECIMAL(7,5) NULL,
            liveness_passed TINYINT(1) NOT NULL DEFAULT 0,
            evidence_hash CHAR(64) NULL,
            bridge_nonce VARCHAR(190) NULL,
            source_event_id VARCHAR(190) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'verified',
            verified_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            raw_metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_biometric_bridge_nonce(bridge_nonce),
            UNIQUE KEY uq_biometric_source_event(source_event_id),
            INDEX idx_biometric_verification_staff(staff_id,verified_at),
            INDEX idx_biometric_verification_status(status,expires_at),
            CONSTRAINT fk_biometric_verification_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT,
            CONSTRAINT fk_biometric_verification_device FOREIGN KEY (device_id) REFERENCES biometric_devices(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            ['attendance','verification_id','VARCHAR(120) NULL'],
            ['attendance','device_id','VARCHAR(50) NULL'],
            ['attendance','verification_score','DECIMAL(7,5) NULL'],
            ['attendance','evidence_hash','CHAR(64) NULL'],
            ['attendance','source_event_id','VARCHAR(190) NULL'],
            ['attendance','verified_at','DATETIME NULL'],
            ['attendance','clock_out_verification_id','VARCHAR(120) NULL'],
            ['attendance','clock_out_device_id','VARCHAR(50) NULL'],
            ['attendance','clock_out_verification_score','DECIMAL(7,5) NULL'],
            ['attendance','clock_out_verified_at','DATETIME NULL'],
            ['attendance','clock_out_source_event_id','VARCHAR(190) NULL'],
        ] as $migration) ensureColumnExists($pdo,$migration[0],$migration[1],$migration[2]);
        try { $pdo->exec("CREATE UNIQUE INDEX uq_attendance_source_event ON attendance (source_event_id)"); } catch (Throwable $ignored) {}
        try { $pdo->exec("CREATE INDEX idx_attendance_verification ON attendance (verification_id)"); } catch (Throwable $ignored) {}
        try { $pdo->exec("CREATE INDEX idx_attendance_device ON attendance (device_id,verified_at)"); } catch (Throwable $ignored) {}
        try { $pdo->exec("CREATE UNIQUE INDEX uq_attendance_clock_out_source_event ON attendance (clock_out_source_event_id)"); } catch (Throwable $ignored) {}
        try {
            $fk=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='attendance' AND CONSTRAINT_NAME='fk_attendance_staff'");
            if ((int)($fk?$fk->fetchColumn():0)===0) $pdo->exec("ALTER TABLE attendance ADD CONSTRAINT fk_attendance_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE RESTRICT");
        } catch (Throwable $ignored) {}
        $pdo->prepare("INSERT INTO schema_migrations (version,description,applied_at) VALUES ('2026.08.biometric-attendance.1','Production biometric verification, signed device webhook, and attendance evidence linkage',CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE description=VALUES(description)")->execute();
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[biometric migration] failed',$e));
    }
}
