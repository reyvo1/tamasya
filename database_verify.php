<?php
declare(strict_types=1);

/**
 * TAMASYA V137 fresh-baseline database verifier.
 *
 * CLI:
 *   php database_verify.php
 * Browser:
 *   enable protected admin web tools in .env, then open database_verify.php.
 *
 * Read-only: never executes DDL or data repair.
 */
require_once __DIR__.'/release_contract.php';
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/baseline_support.php';
require_once __DIR__.'/admin_web_tool_gate.php';

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA Database Verify');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA Database Verify',
            'Verifikasi read-only bahwa database memakai baseline fresh V137 yang sesuai. Tidak ada DDL atau perbaikan data.',
            '',
            'Verifikasi Database'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
}

$config = tamasyaResolveDatabaseConfig(__DIR__);
[$pdo,$connectionError,$connectionStage] = tamasyaConnectDatabase($config);
if (!$pdo instanceof PDO) {
    tamasyaAdminToolEmit([
        'success'=>false,
        'stage'=>$connectionStage,
        'error'=>$connectionError ?: 'Database tidak dapat dihubungkan.'
    ],$isCli?200:503,true);
    exit(2);
}

try {
    $safety=tamasyaAssertDatabaseSafety($pdo,$config);
    $actual=(string)$safety['actual'];
    $baseline=tamasyaCanonicalBaselineStatus($pdo);
    $identity=tamasyaDatabasePropertyIdentity($pdo,false);
    $releaseState=$baseline['releaseState']??null;
    $releaseOk=is_array($releaseState)
        && (string)($releaseState['current_release']??'')===TAMASYA_SCHEMA_RELEASE
        && (int)($releaseState['maintenance_required']??1)===0;
    $identityOk=!($identity['initialized']??false) || !empty($identity['ok']);
    $patchMatchesSource=is_array($releaseState) && (string)($releaseState['patch_level']??'')===TAMASYA_PATCH_LEVEL;
    $ready=!empty($baseline['ready']) && $identityOk && $releaseOk;
    $triggerVerificationComplete=!empty($baseline['triggerVerificationComplete']);
    $report=[
        'success'=>$ready,
        'database'=>$actual,
        'databaseSafety'=>$safety,
        'release'=>TAMASYA_SCHEMA_RELEASE,
        'expectedPatch'=>TAMASYA_PATCH_LEVEL,
        'patchLevelMatchesSource'=>$patchMatchesSource,
        'baseline'=>$baseline,
        'propertyIdentity'=>$identity,
        'releaseStateValid'=>$releaseOk,
        'coreSchemaMutationAllowed'=>false,
        'destructiveActions'=>false,
        'message'=>$ready
            ? 'Database lolos baseline canonical lengkap: core table/kolom, PRIMARY+UNIQUE key, InnoDB, signature trigger, release state, dan identity deployment tidak konflik.'.($patchMatchesSource?' Patch source cocok.':' Patch source belum cocok; jalankan release_hardening_finalize.php sebelum preflight/UAT.')
            : (!$triggerVerificationComplete
                ? 'Verifikasi strict belum lengkap karena credential database ini tidak memiliki visibility metadata trigger. Ini normal untuk akun runtime DML-only; jalankan strict schema verification dengan migration/DBA authority, jangan menambahkan privilege TRIGGER ke runtime hanya agar verifier hijau.'
                : 'Database belum lolos verifikasi. Periksa core table/kolom, PRIMARY/UNIQUE key, signature trigger, engine InnoDB, marker baseline, release state, APP_EXPECTED_DB_NAME, dan identity property.'),
        'optionalModules'=>'Extra table diperbolehkan untuk modul opsional. Gunakan optional_modules_install.php hanya bila modul tersebut memang akan diaktifkan.'
    ];
    tamasyaAdminToolEmit($report,$ready?200:409,!$ready);
    if(!$ready)exit(3);

} catch (Throwable $e) {
    tamasyaAdminToolEmit(['success'=>false,'error'=>$e->getMessage()],$isCli?200:500,true);
    exit(1);
}
