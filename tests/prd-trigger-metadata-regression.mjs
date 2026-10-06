import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=r=>fs.readFileSync(path.join(root,r),'utf8');
let passed=0;const test=(n,f)=>{f();passed++;console.log(`PASS ${n}`)};
const baseline=read('baseline_support.php');
const verify=read('database_verify.php');
const boundary=read('tests/uat_prd/mysql-bootstrap-runtime-boundary.sh');
const authority=read('tests/uat_prd/mysql-canonical-authority-verify.py');
const readme=read('deploy/README.md');

test('baseline verifier distinguishes trigger metadata invisibility from actual missing triggers',()=>{
  assert.ok(baseline.includes('function tamasyaCanonicalTriggerInspection'));
  assert.ok(baseline.includes('insufficient_trigger_metadata_privilege'));
  assert.ok(baseline.includes("'visibleTriggerCount'=>count($presentTriggerMap)"));
});

test('strict baseline readiness remains fail-closed when trigger verification is unavailable',()=>{
  assert.ok(baseline.includes('&& $triggerVerificationComplete'));
  assert.ok(baseline.includes('metadata trigger canonical tidak dapat diverifikasi'));
  assert.ok(verify.includes("$triggerVerificationComplete=!empty($baseline['triggerVerificationComplete'])"));
  assert.ok(verify.includes('jangan menambahkan privilege TRIGGER ke runtime hanya agar verifier hijau'));
});

test('migration authority verifier compares exact fresh tables and trigger signatures from source SQL',()=>{
  for(const marker of ['missingTables','extraTables','missingTriggers','extraTriggers','triggerSignatureMismatches','parserIssues'])assert.ok(authority.includes(marker),marker);
  assert.ok(authority.includes('no CREATE TABLE statements were parsed from supplied schema files'));
  assert.ok(authority.includes('IF\\s+NOT\\s+EXISTS'));
  assert.ok(boundary.indexOf('mysql-canonical-authority-verify.py') < boundary.indexOf('CREATE USER IF NOT EXISTS'));
});

test('runtime identity is explicitly denied trigger-definition visibility',()=>{
  assert.ok(boundary.includes('SHOW CREATE TRIGGER'));
  assert.ok(boundary.includes('runtime account unexpectedly has trigger-definition visibility'));
});

test('deployment runbook keeps strict schema audit at migration boundary and runtime least privilege intact',()=>{
  assert.ok(readme.includes('Verifikasi strict `database_verify.php`'));
  assert.ok(readme.includes('Akun runtime DML-only tidak diberi `TRIGGER`'));
  assert.ok(readme.includes('bukan bukti trigger hilang'));
});
console.log(`${passed} passed; 0 failed`);
