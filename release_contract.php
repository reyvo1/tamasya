<?php
declare(strict_types=1);

/**
 * TAMASYA V137 canonical release identity contract.
 *
 * This file is intentionally dependency-free so API runtime, installers,
 * preflight, backup, maintenance, and release finalization all report and
 * enforce the exact same release/patch identity.
 */
if (!defined('TAMASYA_APP_RELEASE')) {
    define('TAMASYA_APP_RELEASE', 'V137_FRESH_CANONICAL_MULTI_HOTEL');
}
if (!defined('TAMASYA_SCHEMA_RELEASE')) {
    define('TAMASYA_SCHEMA_RELEASE', TAMASYA_APP_RELEASE);
}
if (!defined('TAMASYA_RELEASE')) {
    define('TAMASYA_RELEASE', TAMASYA_APP_RELEASE);
}
if (!defined('TAMASYA_PATCH_LEVEL')) {
    define('TAMASYA_PATCH_LEVEL', 'V137_P0_SPLIT_BACKFILL_CONSISTENCY_GUARD_R1_FLEX_MAINTENANCE_R2_20260910');
}
if (!defined('TAMASYA_BUILD_ID')) {
    define('TAMASYA_BUILD_ID', '20261007-growth-ui-r10');
}
if (!defined('TAMASYA_HANDOFF_SAFEPOINT')) {
    define('TAMASYA_HANDOFF_SAFEPOINT', 'V137_P0_SPLIT_BACKFILL_CONSISTENCY_GUARD_R1_FLEX_MAINTENANCE_R2_HANDOFF_20260910');
}

function tamasyaCanonicalDatabaseSourceChecksum(): string {
    $path = __DIR__.DIRECTORY_SEPARATOR.'database_setup.sql';
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('database_setup.sql canonical tidak dapat dibaca untuk release attestation.');
    }
    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
        throw new RuntimeException('Checksum database_setup.sql canonical tidak dapat dihitung.');
    }
    return $hash;
}

function tamasyaReleaseContract(): array {
    return [
        'release' => TAMASYA_APP_RELEASE,
        'schemaRelease' => TAMASYA_SCHEMA_RELEASE,
        'patch' => TAMASYA_PATCH_LEVEL,
        'buildId' => TAMASYA_BUILD_ID,
        'handoffSafePoint' => TAMASYA_HANDOFF_SAFEPOINT,
    ];
}
