<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require $root.'/tests/uat_prd/runtime-source-root.php';
$passed=0;
function check(string $name, callable $fn): void {
    global $passed;
    try { $fn(); $passed++; echo "PASS {$name}\n"; }
    catch (Throwable $e) { fwrite(STDERR,"FAIL {$name}: {$e->getMessage()}\n"); exit(1); }
}

check('local repository fallback resolves exact manifest-covered application root', function() use ($root): void {
    putenv('TAMASYA_TEST_APP_ROOT');
    $resolved=tamasyaUatResolveApplicationRoot();
    if(realpath($root)!==$resolved) throw new RuntimeException("resolved={$resolved} expected=".realpath($root));
});

check('explicit application root resolves when harness is mounted elsewhere', function() use ($root): void {
    putenv('TAMASYA_TEST_APP_ROOT='.$root);
    $resolved=tamasyaUatResolveApplicationRoot(['hq/schema.sql','hq/delivery_schema.sql']);
    if(realpath($root)!==$resolved) throw new RuntimeException('explicit application root mismatch');
});

check('tests-only mount cannot masquerade as application root', function() use ($root): void {
    putenv('TAMASYA_TEST_APP_ROOT='.$root.'/tests');
    try { tamasyaUatResolveApplicationRoot(); }
    catch (RuntimeException $e) {
        if(!str_contains($e->getMessage(),'FILE_CHECKSUMS.sha256')) throw $e;
        putenv('TAMASYA_TEST_APP_ROOT='.$root);
        return;
    }
    throw new RuntimeException('tests-only directory was accepted as application root');
});

check('manifest mismatch fails closed before HQ adapter code executes', function() use ($root): void {
    $tmp=sys_get_temp_dir().'/tamasya-r9-root-'.bin2hex(random_bytes(6));
    mkdir($tmp.'/hq',0770,true);
    foreach(['release_contract.php','hybrid_contract.php','canonical_report_support.php'] as $file){copy($root.'/'.$file,$tmp.'/'.$file);}
    foreach(['core.php','reporting.php','delivery.php','object_storage.php'] as $file){copy($root.'/hq/'.$file,$tmp.'/hq/'.$file);}
    $manifest=[];
    foreach(['release_contract.php','hybrid_contract.php','canonical_report_support.php','hq/core.php','hq/reporting.php','hq/delivery.php','hq/object_storage.php'] as $file){
        $manifest[]=hash_file('sha256',$tmp.'/'.$file).'  '.$file;
    }
    file_put_contents($tmp.'/FILE_CHECKSUMS.sha256',implode("\n",$manifest)."\n");
    file_put_contents($tmp.'/hq/delivery.php',"<?php\n// deliberately changed after manifest\n");
    putenv('TAMASYA_TEST_APP_ROOT='.$tmp);
    try { tamasyaUatResolveApplicationRoot(); }
    catch (RuntimeException $e) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());} rmdir($tmp);
        putenv('TAMASYA_TEST_APP_ROOT='.$root);
        if(!str_contains($e->getMessage(),'release-manifest mismatch')) throw $e;
        return;
    }
    throw new RuntimeException('tampered application file was accepted');
});

check('HQ external harness uses explicit source resolver instead of harness dirname', function() use ($root): void {
    $src=file_get_contents($root.'/tests/uat_prd/hq_external_adapters.php');
    if(!str_contains($src,"require __DIR__.'/runtime-source-root.php';")) throw new RuntimeException('resolver helper not loaded');
    if(!str_contains($src,'tamasyaUatResolveApplicationRoot()')) throw new RuntimeException('application root resolver not used');
    if(str_contains($src,"dirname(__DIR__,2).'/hq/")) throw new RuntimeException('legacy harness-relative application path remains');
});

check('GitHub runtime keeps production image test-free while binding external tests to explicit image root', function() use ($root): void {
    $wf=file_get_contents($root.'/.github/workflows/tamasya-enterprise-rc1-uat.yml');
    foreach([
        'test ! -e tests',
        '-v "$PWD/tests:/opt/tamasya-tests:ro"',
        '-e TAMASYA_TEST_APP_ROOT=/var/www/tamasya',
        'test -r /var/www/tamasya/hq/delivery.php',
        'test -r /var/www/tamasya/hq/object_storage.php',
        'php /opt/tamasya-tests/uat_prd/hq_external_adapters.php',
        '--read-only --tmpfs /tmp:rw,nosuid,nodev,noexec --cap-drop ALL --security-opt no-new-privileges',
    ] as $needle){if(!str_contains($wf,$needle)) throw new RuntimeException('workflow contract missing: '.$needle);}
});

putenv('TAMASYA_TEST_APP_ROOT='.$root);
echo "{$passed} passed; 0 failed\n";
