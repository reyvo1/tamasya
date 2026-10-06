import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=(rel)=>fs.readFileSync(path.join(root,rel),'utf8');
let passed=0;
function test(name,fn){fn();passed++;console.log(`PASS ${name}`);}

const api=read('api.php');
const packageJson=JSON.parse(read('package.json'));
const realtime=read('services/realtime/server.mjs');
const hqCore=read('hq/core.php');
const hybrid=read('hybrid_contract.php');
const cluster=read('node_cluster_support.php');
const sync=read('node_sync_support.php');
const compose=read('deploy/compose.yaml');
const saas=read('deploy/compose.saas.yaml');
const deployReadme=read('deploy/README.md');

test('PHP remains the canonical shared-hosting API authority',()=>{
  assert.ok(api.includes("define('TAMASYA_API_ENTRY', true)"));
  assert.ok(api.includes("require_once __DIR__ . DIRECTORY_SEPARATOR . 'node_sync_support.php'"));
  assert.ok(api.includes("require_once __DIR__ . '/api/modules/setup_admin/109_hybrid_outbox.php'"));
  assert.equal(Object.keys(packageJson.dependencies||{}).length,0,'PMS runtime must not require Node dependencies');
});

test('realtime service is read-only adjacent capability, not a financial writer',()=>{
  assert.ok(realtime.includes("action','event-revision"));
  assert.doesNotMatch(realtime,/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+(?:INTO\s+)?(?:transactions|journal_entries|journal_lines|bookings)\b/i);
  assert.doesNotMatch(realtime,/mysql|mysqli|PDO/i);
});

test('HQ is a separate scoped read-model boundary',()=>{
  assert.ok(hqCore.includes('TAMASYA_HQ_CONFIG_FILE'),'HQ must require explicit private config path');
  assert.ok(hqCore.includes('di luar document root aplikasi'),'HQ config must be outside document root');
  assert.ok(hybrid.includes('companyId')&&hybrid.includes('propertyId'),'hybrid contract must carry company/property scope');
  assert.ok(deployReadme.includes('database terpisah')&&deployReadme.includes('vhost HQ terpisah'),'deployment guide must preserve HQ database and vhost separation');
});

test('two-node safety keeps explicit revision/fencing/receipt controls',()=>{
  for(const marker of ['leadership_epoch','fencing','server_revision']) assert.ok(cluster.includes(marker),`cluster support missing ${marker}`);
  for(const marker of ['operation_id','payload_hash','base_primary_revision']) assert.ok(sync.includes(marker),`sync support missing ${marker}`);
});

test('VPS and SaaS remain optional deployment profiles over the same PHP core',()=>{
  assert.ok(compose.includes('profiles: [workers]'),'worker must remain opt-in');
  assert.ok(compose.includes('command: [php, service_worker.php, daemon]'),'worker must reuse canonical PHP service worker contract');
  assert.ok(saas.includes('api2:')&&saas.includes('storage-init:')&&saas.includes('condition: service_started'),'SaaS profile must simulate horizontal API nodes behind serialized storage init');
});

console.log(`${passed} passed; 0 failed`);
