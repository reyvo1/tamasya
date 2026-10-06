<?php
declare(strict_types=1);
define('TAMASYA_API_ENTRY', true);
require_once dirname(__DIR__).'/release_contract.php';
require_once dirname(__DIR__).'/api/modules/setup_admin/010_schema_contract.php';
require_once dirname(__DIR__).'/api/support/002_enterprise_hardening.php';
$passed=0;$test=function(string $n,callable $f)use(&$passed){$f();$passed++;echo "PASS $n\n";};$expect=function(bool $x,string $m='assertion failed'){if(!$x)throw new RuntimeException($m);};
$checksum=tamasyaCanonicalDatabaseSourceChecksum();$release=['source_checksum'=>$checksum,'migration_run_id'=>'authority_'.substr($checksum,0,16)];
$test('direct runtime trigger metadata remains authoritative when visible',function()use($expect,$release,$checksum){$x=tamasyaRuntimeTriggerVerificationDecision(['visibility'=>'visible','missing'=>[],'reason'=>'all_expected_triggers_visible'],$release,$checksum);$expect($x['ok']===true&&$x['mode']==='runtime_metadata'&&!$x['delegated']);});
$test('visible missing trigger is real drift and fails closed',function()use($expect,$release,$checksum){$x=tamasyaRuntimeTriggerVerificationDecision(['visibility'=>'visible','missing'=>['trg_missing'],'reason'=>'trigger_absence_confirmed'],$release,$checksum);$expect($x['ok']===false&&$x['missingTriggers']===['trg_missing']);});
$test('DML-only runtime delegates trigger integrity only to exact migration authority attestation',function()use($expect,$release,$checksum){$x=tamasyaRuntimeTriggerVerificationDecision(['visibility'=>'hidden','missing'=>[],'reason'=>'least_privilege_trigger_metadata_hidden'],$release,$checksum);$expect($x['ok']===true&&$x['mode']==='migration_authority_attestation'&&$x['delegated']===true&&$x['metadataVisible']===false);});
$test('hidden metadata with stale or absent source checksum fails closed',function()use($expect,$release,$checksum){$bad=$release;$bad['source_checksum']=str_repeat('0',64);$x=tamasyaRuntimeTriggerVerificationDecision(['visibility'=>'hidden','missing'=>[]],$bad,$checksum);$expect($x['ok']===false&&$x['reason']==='canonical_source_attestation_missing_or_mismatch');});
$test('unknown trigger inspection never becomes a delegated PASS',function()use($expect,$release,$checksum){$x=tamasyaRuntimeTriggerVerificationDecision(['visibility'=>'unknown','missing'=>['trg_unknown'],'reason'=>'probe_failed'],$release,$checksum);$expect($x['ok']===false&&$x['mode']==='unverified'&&!$x['delegated']);});
$test('canonical checksum is exact database_setup.sql SHA-256',function()use($expect,$checksum){$actual=hash_file('sha256',dirname(__DIR__).'/database_setup.sql');$expect(is_string($actual)&&hash_equals($actual,$checksum));});
echo "$passed passed; 0 failed\n";
