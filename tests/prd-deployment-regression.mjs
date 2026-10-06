import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=(rel)=>fs.readFileSync(path.join(root,rel),'utf8');
let passed=0;
function test(name,fn){fn();passed++;console.log(`PASS ${name}`);}

const compose=read('deploy/compose.yaml');
const saas=read('deploy/compose.saas.yaml');
const hq=read('deploy/compose.hq.yaml');
const nginx=read('deploy/nginx.conf');
const nginxSaas=read('deploy/nginx-saas.conf');
const nginxHq=read('deploy/nginx-hq.conf');
const dockerfile=read('deploy/Dockerfile');
const storageInit=read('deploy/runtime-storage-init.sh');
const ignore=read('.dockerignore');

const workerCommand='command: [php, service_worker.php, daemon]';

test('VPS worker is opt-in and reuses canonical PHP worker authority',()=>{
  assert.ok(compose.includes('profiles: [workers]'));
  assert.ok(compose.includes(workerCommand));
  assert.ok(compose.includes('read_only: true'));
  assert.ok(compose.includes('no-new-privileges:true'));
  assert.ok(compose.includes('cap_drop: [ALL]'));
});

test('persistent runtime storage is initialized once and never Docker copy-populated by parallel replicas',()=>{
  assert.ok(compose.includes('storage-init:'));
  assert.ok(compose.includes('condition: service_completed_successfully'));
  assert.ok((compose.match(/nocopy: true/g)||[]).length>=2,'state/uploads volumes must use nocopy');
  assert.ok(compose.includes('cap_add: [CHOWN, FOWNER, DAC_OVERRIDE]'));
  assert.ok(storageInit.includes('expected directory but found non-directory'));
  assert.ok(storageInit.includes('refusing symlink path'));
  assert.ok(storageInit.includes('prepare_dir /var/lib/tamasya/outbox 0700'));
  assert.ok(storageInit.includes('prepare_dir /var/lib/tamasya/backups 0700'));
  assert.ok(storageInit.includes('prepare_dir /var/www/tamasya/uploads/public-site 0755'));
});

test('SaaS profile adds horizontal API replica and serializes web startup behind storage init',()=>{
  assert.ok(saas.includes('api2:'));
  assert.ok(saas.includes('storage-init:'));
  assert.ok(saas.includes('condition: service_completed_successfully'));
  assert.ok(saas.includes('api2:')&&saas.includes('condition: service_started'));
  assert.ok(nginxSaas.includes('server api:9000 max_fails=1 fail_timeout=5s;'));
  assert.ok(nginxSaas.includes('server api2:9000 max_fails=1 fail_timeout=5s;'));
});

test('SaaS proxy quarantines failed replicas for later requests but never replays an uncertain request',()=>{
  assert.ok(nginxSaas.includes('fastcgi_next_upstream off;'));
  assert.ok(nginxSaas.includes('may already have committed'));
});

test('HQ remains isolated with optional delivery worker and read-only realtime service',()=>{
  assert.ok(hq.includes('TAMASYA_HQ_CONFIG_FILE: /run/secrets/hq/config.json'));
  assert.ok(hq.includes('profiles: [delivery]'));
  assert.ok(hq.includes('command: [php, hq/delivery_worker.php, daemon]'));
  assert.ok(hq.includes('image: node:22-alpine'));
  assert.ok(hq.includes('user: node'));
  assert.ok(nginxHq.includes('root /var/www/tamasya/hq;'));
  assert.ok(nginxHq.includes('location ~ ^/(api|control_api)\\.php$'));
  assert.ok(nginxHq.includes('location ~ \\.php(?:/|$) { deny all; }'));
});

test('PMS proxy exposes only canonical PHP entries and never replays uncertain writes',()=>{
  assert.ok(nginx.includes('location ~ ^/(?:api|runtime_config|maintenance_cron)\\.php$'));
  assert.ok(nginx.includes('fastcgi_next_upstream off;'));
  assert.ok(nginx.includes('location ~ \\.php(?:/|$) { deny all; }'));
  assert.ok(nginx.includes('location /uploads/ { deny all; }'));
});

test('production image defaults non-root while shipping a dedicated one-shot storage initializer',()=>{
  assert.ok(dockerfile.includes('COPY deploy/runtime-storage-init.sh /usr/local/bin/tamasya-storage-init'));
  assert.ok(dockerfile.includes('chmod 0555 /usr/local/bin/tamasya-storage-init'));
  assert.ok(dockerfile.includes('USER www-data'));
  assert.ok(dockerfile.includes('php-fpm","-F'));
  for(const marker of ['.env','uploads','backups','node_modules','tests','db_credentials.php','private']) {
    assert.ok(ignore.includes(marker),`.dockerignore missing ${marker}`);
  }
});

console.log(`${passed} passed; 0 failed`);
