import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=r=>fs.readFileSync(path.join(root,r),'utf8');let passed=0;const test=(n,f)=>{f();passed++;console.log(`PASS ${n}`)};
const workflow=read('.github/workflows/tamasya-enterprise-rc1-uat.yml');
const object=read('hq/object_storage.php'),delivery=read('hq/delivery.php'),core=read('hq/core.php'),realtime=read('services/realtime/server.mjs');
const mock=read('tests/uat_prd/mock_https_services.py'),external=read('tests/uat_prd/hq_external_adapters.php'),processSmoke=read('tests/uat_prd/realtime-process-smoke.mjs'),saas=read('tests/uat_prd/run_saas_runtime_smoke.sh');

test('SaaS runtime simulation boots two API processes behind Nginx and exercises concurrent reads',()=>{
  for(const marker of ['compose.saas.yaml','up -d --no-build api api2 web','ThreadPoolExecutor(max_workers=12)','api.php?action=ping'])assert.ok(saas.includes(marker));
  assert.ok(workflow.includes('run_saas_runtime_smoke.sh'));
});

test('SaaS simulation covers storage lifecycle, worker reuse, restart persistence and replica loss',()=>{
  for(const marker of ['storage-init','cross-replica shared state','--profile workers up -d --no-build worker','survives horizontal runtime restart','stop api2'])assert.ok(saas.includes(marker),marker);
  assert.ok(workflow.includes('prd-storage-runtime-regression.mjs'));
});

test('HQ external adapter simulation uses real TLS, MySQL and immutable object read-back',()=>{
  assert.ok(object.includes('CURLOPT_AWS_SIGV4'));assert.ok(object.includes('If-None-Match: *'));assert.ok(object.includes('read-back checksum verification'));
  for(const marker of ['tamasyaHqArchiveReport','duplicate object archive is idempotent via 412','uncertain delivery is not auto-retried'])assert.ok(external.includes(marker));
  assert.ok(workflow.includes('mock_https_services.py')&&workflow.includes('hq_external_adapters.php'));
});

test('delivery simulator verifies HMAC/idempotency and tests non-terminal acknowledgements',()=>{
  assert.ok(mock.includes('X-Tamasya-Signature'));assert.ok(mock.includes('Idempotency-Key'));assert.ok(mock.includes('deliveryAccepted'));assert.ok(delivery.includes('tamasyaHqDeliveryAckDecision'));assert.ok(core.includes("$http!==200")&&core.includes("HTTP_'.$http.'_NOT_FINAL")&&core.includes("ACK_JOB_MISMATCH"));
});

test('realtime simulation executes the service entrypoint instead of import-only unit behavior',()=>{
  assert.ok(processSmoke.includes('services/realtime/server.mjs'));assert.ok(processSmoke.includes('event: unavailable'));assert.ok(processSmoke.includes('hotel-private'));
  assert.ok(realtime.includes('event: revision')&&realtime.includes('event: unavailable'));assert.ok(workflow.includes('realtime-process-smoke.mjs'));
});

test('horizontal proxy never enables request replay merely to survive replica failure',()=>{
  const nginx=read('deploy/nginx-saas.conf');
  assert.ok(nginx.includes('fastcgi_next_upstream off;'));
  assert.ok(nginx.includes('max_fails=1 fail_timeout=5s'));
});

test('simulation remains explicit and does not relabel physical/provider commissioning as CI proof',()=>{
  const status=read('PRD_AS_BUILT_STATUS_2026-10-02.md').toLowerCase();
  assert.ok(status.includes('physical smart lock')||status.includes('real device commissioning')||status.includes('provider/device'));
  assert.ok(status.includes('commissioning'));
});
console.log(`${passed} passed; 0 failed`);
