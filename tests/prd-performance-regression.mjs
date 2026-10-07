import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const read=(rel)=>fs.readFileSync(path.join(root,rel),'utf8');
const index=read('index.html');
const sw=read('sw.js');
const loader=read('assets/runtime-addon-loader.js');
const reportCenter=read('assets/canonical-report-center.js');
const maintenance=read('assets/flexible-maintenance-addon.js');
let passed=0;
function test(name,fn){fn();passed++;console.log(`PASS ${name}`);}

const lazyAssets=[
  'assets/pos-report-archive-addon.js',
  'assets/website-gps-addon.js',
  'assets/system-health-addon.js',
  'assets/canonical-report-center.js',
  'assets/guest-center.js',
  'assets/growth-pms-link-addon.js',
  'assets/employee-self-service.js',
  'assets/website-cms-guard.js',
  'assets/guest-center.css',
  'assets/employee-self-service.css',
  'assets/website-cms-guard.css'
];

test('optional route addons are absent from eager PMS shell',()=>{
  for(const rel of lazyAssets) assert.ok(!index.includes(`./${rel}`),`eager shell still loads ${rel}`);
  assert.ok(index.includes('./assets/runtime-addon-loader.js?v=20261007-growth-ui-r10'),'runtime addon loader missing');
});

test('runtime loader owns every removed optional addon',()=>{
  for(const rel of lazyAssets) assert.ok(loader.includes(rel),`loader missing ${rel}`);
  assert.ok(loader.includes("window.addEventListener('tamasya-route-change'"),'route-driven lazy loading missing');
  assert.ok(loader.includes("window.addEventListener('tamasya-open-system-health'"),'on-demand System Health loader missing');
});

test('initial document asset budget stays below PRD shared-hosting ceiling',()=>{
  const urls=[];
  for(const match of index.matchAll(/<(?:script|link)\b[^>]*(?:src|href)="([^"]+)"[^>]*>/gi)){
    const url=match[1];
    if(!url.startsWith('./')) continue;
    if(url.includes('manifest.json')||url.includes('icon.svg')) continue;
    const rel=url.slice(2).split('?',1)[0];
    const full=path.join(root,rel);
    if(fs.existsSync(full)&&fs.statSync(full).isFile()) urls.push(rel);
  }
  const unique=[...new Set(urls)];
  const total=unique.reduce((sum,rel)=>sum+fs.statSync(path.join(root,rel)).size,0);
  // Uncompressed static bytes requested directly by index.html. Compression reduces wire bytes further.
  assert.ok(total<=780_000,`eager index asset budget ${total} exceeds 780000 bytes`);
  console.log(`INFO eager-index-bytes=${total} files=${unique.length}`);
});

test('initial static module graph stays below shared-hosting budget',()=>{
  const direct=[];
  for(const match of index.matchAll(/<script\b[^>]*type="module"[^>]*src="([^"]+)"[^>]*>/gi)){
    const url=match[1];
    if(url.startsWith('./')) direct.push(url.slice(2).split('?',1)[0]);
  }
  const seen=new Set();
  const walk=(rel)=>{
    if(seen.has(rel))return;
    const full=path.join(root,rel);
    if(!fs.existsSync(full))throw new Error(`module graph missing ${rel}`);
    seen.add(rel);
    const source=fs.readFileSync(full,'utf8');
    const dir=path.posix.dirname(rel);
    for(const m of source.matchAll(/\bimport\s+(?:[^'"()]+?\s+from\s+)?["']([^"']+)["']/g)){
      const spec=m[1];
      if(!spec.startsWith('.'))continue;
      const child=path.posix.normalize(path.posix.join(dir,spec.split('?',1)[0]));
      walk(child);
    }
  };
  direct.forEach(walk);
  const graphBytes=[...seen].reduce((sum,rel)=>sum+fs.statSync(path.join(root,rel)).size,0);
  const directNonModule=[];
  for(const match of index.matchAll(/<(?:script|link)\b[^>]*(?:src|href)="([^"]+)"[^>]*>/gi)){
    const url=match[1];
    if(!url.startsWith('./')||url.includes('manifest.json')||url.includes('icon.svg'))continue;
    const rel=url.slice(2).split('?',1)[0];
    if(!seen.has(rel)&&fs.existsSync(path.join(root,rel)))directNonModule.push(rel);
  }
  const total=graphBytes+[...new Set(directNonModule)].reduce((sum,rel)=>sum+fs.statSync(path.join(root,rel)).size,0);
  assert.ok(total<=970_000,`initial static module graph ${total} exceeds 970000 bytes`);
  console.log(`INFO initial-static-graph-bytes=${total} modules=${seen.size} other=${new Set(directNonModule).size}`);
});

test('service worker keeps optional route addons runtime-cached instead of install-precache',()=>{
  for(const rel of lazyAssets) assert.ok(!sw.includes(`"./${rel}`),`SW install still precaches optional ${rel}`);
  assert.ok(sw.includes('./assets/runtime-addon-loader.js?v=20261007-growth-ui-r10'),'SW must precache the tiny loader needed by offline core');
  assert.ok(sw.includes('STATIC_CACHE_DESTINATIONS')&&sw.includes('stale-while-revalidate'),'static runtime cache contract must remain available');
});

test('canonical report center follows SPA route events without 600ms pathname polling',()=>{
  assert.ok(reportCenter.includes('document.documentElement.dataset.tamasyaRoute'),'report center must derive the SPA route');
  assert.ok(reportCenter.includes("window.addEventListener('tamasya-route-change',scheduleEnsure)"),'report center must react to canonical route event');
  assert.ok(!/setInterval\([^\n]*600\)/.test(reportCenter),'report center must not poll the pathname every 600ms');
});

test('opportunistic maintenance is visibility-aware',()=>{
  assert.ok(maintenance.includes("reason!=='browser-manual-trigger' && document.visibilityState==='hidden'"),'background maintenance must skip hidden tabs');
  assert.ok(maintenance.includes("document.addEventListener('visibilitychange'"),'foreground transition should still trigger run-if-due');
});

console.log(`${passed} passed; 0 failed`);
