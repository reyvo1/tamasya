import assert from 'node:assert/strict';
import os from 'node:os';
import {spawnSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=r=>fs.readFileSync(path.join(root,r),'utf8');
let passed=0; const test=(n,f)=>{f();passed++;console.log(`PASS ${n}`)};

const smoke=read('tests/uat_prd/run_saas_runtime_smoke.sh');
const helper=read('tests/uat_prd/mysql-authenticated-ready.sh');
const boundary=read('tests/uat_prd/mysql-bootstrap-runtime-boundary.sh');
const authorityVerifier=read('tests/uat_prd/mysql-canonical-authority-verify.py');
const workflow=read('.github/workflows/tamasya-enterprise-rc1-uat.yml');
const deployReadme=read('deploy/README.md');
const mysqlDigest='mysql:8.4@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242';

test('PRD runtime never treats mysqladmin ping as authenticated initialization readiness',()=>{
  assert.ok(!/docker exec[^\n]*mysqladmin\s+ping/.test(smoke));
  const prdJob=workflow.slice(workflow.indexOf('prd-deployment-profile-simulation:'), workflow.indexOf('mysql-comprehensive-uat:'));
  assert.ok(!/docker exec[^\n]*mysqladmin\s+ping/.test(prdJob));
});

test('readiness helper requires an authenticated TCP SELECT against the expected database',()=>{
  assert.ok(helper.includes('--protocol=TCP'));
  assert.ok(helper.includes('-h127.0.0.1'));
  assert.ok(helper.includes("-e 'SELECT 1'"));
  assert.ok(helper.includes('MYSQL_PWD'));
  assert.ok(helper.includes("grep -qx '1'"));
});

test('readiness helper is bounded, detects dead containers and emits diagnostic logs on timeout',()=>{
  assert.ok(helper.includes('ATTEMPTS="${5:-60}"'));
  assert.ok(helper.includes("state=\"$(docker inspect -f '{{.State.Status}}'"));
  assert.ok(helper.includes('docker logs "$CONTAINER"'));
  assert.ok(helper.includes('exit 72'));
});

test('bootstrap boundary waits for DBA authentication and later for runtime authentication',()=>{
  assert.ok(boundary.includes('"$READY" "$CONTAINER" "$DB_NAME" root "$ROOT_PASS" 60 1'));
  assert.ok(boundary.includes('"$READY" "$CONTAINER" "$DB_NAME" "$RUNTIME_USER" "$RUNTIME_PASS" 30 1'));
});

test('runtime boundary rejects wrong credentials after account provisioning',()=>{
  assert.ok(boundary.includes('MYSQL_PWD=definitely-wrong'));
  assert.ok(boundary.includes('unexpectedly accepted a wrong password'));
});

test('canonical runtime smoke separates runtime table visibility from migration-authority trigger proof',()=>{
  assert.ok(smoke.includes('TABLE_COUNT'));
  assert.ok(smoke.includes('[[ "$TABLE_COUNT" -eq 113 ]]'));
  assert.ok(smoke.includes('RUNTIME_VISIBLE_TRIGGERS'));
  assert.ok(smoke.includes('not used as schema authority'));
  assert.ok(smoke.includes('mysql-bootstrap-runtime-boundary.sh'));
  assert.ok(boundary.includes('mysql-canonical-authority-verify.py'));
  assert.ok(authorityVerifier.includes('triggerSignatureMismatches'));
});

test('PRD auxiliary MySQL image is digest pinned while still overrideable for controlled updates',()=>{
  assert.ok(smoke.includes(mysqlDigest));
  assert.ok(smoke.includes('TAMASYA_PRD_MYSQL_IMAGE'));
  assert.ok(workflow.includes(mysqlDigest));
});

test('deployment runbook documents authenticated readiness and separates it from migration authority',()=>{
  assert.ok(deployReadme.includes('Database readiness dan authority contract'));
  assert.ok(deployReadme.includes('`SELECT 1`'));
  assert.ok(deployReadme.includes('mysqladmin ping'));
  assert.ok(deployReadme.includes('migration/DBA'));
  assert.ok(deployReadme.includes('runtime'));
});

test('ZIP copies without executable bits still enter the bootstrap validator through bash',()=>{
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'tamasya-mode-unit-'));try{
  const file=path.join(dir,'bootstrap.sh');fs.writeFileSync(file,boundary,{mode:0o600});
  const run=spawnSync('bash',[file,'unit-container','unsafe-db-name','unit-root','unit-user','unit-pass','SELECT,INSERT,UPDATE,DELETE','property','database_setup.sql'],{encoding:'utf8'});
  assert.equal(run.status,64);assert.ok(run.stderr.includes('unsafe database identifier'));assert.ok(!run.stderr.includes('Permission denied'));
  assert.ok(smoke.includes('bash tests/uat_prd/mysql-bootstrap-runtime-boundary.sh'));
  assert.ok(workflow.includes('bash tests/uat_rc1/run_two_node_complete.sh'));
  assert.ok(workflow.includes('bash tests/uat_rc1/run_hq_multi_property_complete.sh'));
  assert.equal((boundary.match(/bash "\$READY"/g)||[]).length,2);
 }finally{fs.rmSync(dir,{recursive:true,force:true});}
});
console.log(`${passed} passed; 0 failed`);
