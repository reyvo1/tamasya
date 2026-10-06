import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=r=>fs.readFileSync(path.join(root,r),'utf8');
let passed=0; const test=(n,f)=>{f();passed++;console.log(`PASS ${n}`)};
const compose=read('deploy/compose.yaml');
const saas=read('deploy/compose.saas.yaml');
const init=read('deploy/runtime-storage-init.sh');
const smoke=read('tests/uat_prd/run_saas_runtime_smoke.sh');
const nginx=read('deploy/nginx-saas.conf');

test('fresh state and uploads volumes disable Docker image copy-up',()=>{
  assert.ok((compose.match(/nocopy: true/g)||[]).length>=2);
  assert.ok(compose.includes('source: state')&&compose.includes('target: /var/lib/tamasya'));
  assert.ok(compose.includes('source: uploads')&&compose.includes('target: /var/www/tamasya/uploads'));
});

test('one-shot initializer is the only service allowed to prepare shared runtime directories',()=>{
  assert.ok(compose.includes('storage-init:'));
  assert.ok(compose.includes('user: "0:0"'));
  assert.ok(compose.includes('restart: "no"'));
  assert.ok(compose.includes('command: [/usr/local/bin/tamasya-storage-init]'));
  assert.ok(compose.includes('cap_add: [CHOWN, FOWNER, DAC_OVERRIDE]'));
  assert.ok(init.includes('TAMASYA runtime storage initialized'));
});

test('initializer rejects path-type and symlink corruption instead of deleting or overwriting it',()=>{
  assert.ok(init.includes('[ -L "$path" ]'));
  assert.ok(init.includes('[ -e "$path" ] && [ ! -d "$path" ]'));
  assert.ok(!init.includes('rm -rf'));
});

test('all horizontal runtime consumers wait for completed storage initialization',()=>{
  assert.ok(compose.includes('condition: service_completed_successfully'));
  assert.ok(saas.includes('storage-init:'));
  assert.ok(saas.includes('api2:'));
});

test('runtime smoke recreates the original parallel-start condition and proves durable shared writes',()=>{
  for(const marker of [
    'up -d --no-build api api2 web',
    'storage-init failed:',
    '.prd-storage-proof',
    'cross-replica shared state',
    'survives horizontal runtime restart',
    '--profile workers up -d --no-build worker'
  ]) assert.ok(smoke.includes(marker),marker);
});

test('runtime smoke proves immutable application root and public-upload sharing',()=>{
  assert.ok(smoke.includes('if touch /var/www/tamasya/.prd-should-not-write'));
  assert.ok(smoke.includes('/uploads/public-site/prd-r3-storage-proof.svg'));
});

test('replica-loss behavior is explicit: passive quarantine yes, upstream request replay no',()=>{
  assert.ok(nginx.includes('max_fails=1 fail_timeout=5s'));
  assert.ok(nginx.includes('fastcgi_next_upstream off;'));
  assert.ok(smoke.includes('stop api2'));
  assert.ok(smoke.includes('without enabling cross-upstream request replay'));
});

console.log(`${passed} passed; 0 failed`);
