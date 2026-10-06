<?php
declare(strict_types=1);

/**
 * Resolve the application source tree used by external-runtime UAT.
 *
 * Production images intentionally exclude tests. CI therefore mounts tests under
 * /opt/tamasya-tests while application code remains in /var/www/tamasya. The
 * harness must never infer the application root from its own mounted location.
 * An explicit TAMASYA_TEST_APP_ROOT wins; local repository execution falls back
 * to the historical repo-relative root.
 *
 * Required application files are verified against FILE_CHECKSUMS.sha256 so an
 * external adapter test cannot accidentally exercise a stale or unrelated tree.
 */
function tamasyaUatResolveApplicationRoot(array $requiredRelativeFiles=[]): string {
    $configured=trim((string)(getenv('TAMASYA_TEST_APP_ROOT') ?: ''));
    $candidate=$configured!=='' ? $configured : dirname(__DIR__,2);
    $real=realpath($candidate);
    if($real===false || !is_dir($real)){
        throw new RuntimeException('TAMASYA_TEST_APP_ROOT_INVALID: application source root does not exist: '.$candidate);
    }
    $root=rtrim($real,DIRECTORY_SEPARATOR);
    $manifestPath=$root.DIRECTORY_SEPARATOR.'FILE_CHECKSUMS.sha256';
    if(!is_file($manifestPath) || !is_readable($manifestPath)){
        throw new RuntimeException('TAMASYA_TEST_APP_ROOT_INVALID: FILE_CHECKSUMS.sha256 missing from application source root.');
    }

    $manifest=[];
    foreach(file($manifestPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line){
        if(!preg_match('/^([a-f0-9]{64})  (.+)$/D',$line,$m)) continue;
        $manifest[$m[2]]=$m[1];
    }
    if(!$manifest){
        throw new RuntimeException('TAMASYA_TEST_APP_ROOT_INVALID: release manifest is empty or malformed.');
    }

    $required=array_values(array_unique(array_merge([
        'release_contract.php',
        'hq/core.php',
        'hq/reporting.php',
        'hq/delivery.php',
        'hq/object_storage.php',
        'hybrid_contract.php',
        'canonical_report_support.php',
    ],array_map(static fn($v)=>ltrim(str_replace('\\','/',trim((string)$v)),'/'),$requiredRelativeFiles))));

    foreach($required as $relative){
        if($relative==='' || str_contains($relative,'..')){
            throw new RuntimeException('TAMASYA_TEST_APP_ROOT_INVALID: unsafe required path.');
        }
        $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
        if(!is_file($path) || !is_readable($path)){
            throw new RuntimeException('TAMASYA_TEST_APP_ROOT_INVALID: required application file missing: '.$relative);
        }
        $expected=$manifest[$relative]??null;
        if(!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected)){
            throw new RuntimeException('TAMASYA_TEST_APP_ROOT_INVALID: required file is not release-manifest covered: '.$relative);
        }
        $actual=hash_file('sha256',$path);
        if(!is_string($actual) || !hash_equals($expected,$actual)){
            throw new RuntimeException('TAMASYA_TEST_APP_ROOT_INVALID: release-manifest mismatch: '.$relative);
        }
    }
    return $root;
}
