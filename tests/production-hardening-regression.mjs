import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {createRequire} from 'node:module';
const root=new URL('../',import.meta.url);
const read=p=>fs.readFileSync(new URL(p,root),'utf8');
const policy=createRequire(import.meta.url)(new URL('assets/pos-business-date-policy.js',root).pathname);
let passed=0;
const test=(name,fn)=>{fn();passed++;console.log('PASS '+name);};
test('WITA month start before 08:00 never leaks UTC previous month',()=>{
  assert.deepEqual(policy.monthRange(new Date('2026-09-30T16:10:00Z'),'Asia/Makassar'),{from:'2026-10-01',to:'2026-10-01'});
});
test('Property timezone overrides the workstation date',()=>{
  const now=new Date('2026-09-30T16:10:00Z');
  assert.equal(policy.dateAt(now,'UTC'),'2026-09-30');
  assert.equal(policy.dateAt(now,'Asia/Makassar'),'2026-10-01');
});
test('Reopening next month recomputes the default period',()=>{
  assert.deepEqual(policy.monthRange(new Date('2026-10-31T16:01:00Z'),'Asia/Makassar'),{from:'2026-11-01',to:'2026-11-01'});
});
test('All affected report addons use the shared property date policy',()=>{
  for(const name of ['canonical-report-center','pos-report-archive-addon','growth-pms-link-addon']){
    const src=read('assets/'+name+'.js');assert.ok(src.includes('TamasyaPosBusinessDatePolicy'),name);
    assert.ok(!src.includes('toISOString().slice(0, 10)')&&!src.includes('toISOString().slice(0,10)'),name);
  }
  assert.ok(read('assets/canonical-report-center.js').includes('const period=selectedPagePeriod();'));
});
test('Cold offline precache includes the exact React URL imported by every chunk',()=>{
  const precached=new Set([...read('sw.js').matchAll(/"(\.\/assets\/[^"\n]+)"/g)].map(m=>m[1].replace('./','')));
  for(const file of fs.readdirSync(new URL('assets/chunks/',root)).map(f=>'assets/chunks/'+f).concat('assets/app-core.js')){
    for(const m of read(file).matchAll(/(?:\.\/chunks\/|\.\/|\.\.\/chunks\/)(vendor-react\.js\?v=[^"']+)/g)){
      assert.ok(precached.has('assets/chunks/'+m[1]),file+': '+m[1]);
    }
  }
  const html=read('index.html');
  for(const name of ['property-branding','pos-business-date-policy']){
    const url=html.match(new RegExp('src="\\./(assets/'+name+'\\.js\\?v=[^"]+)"'))?.[1];
    assert.ok(url&&precached.has(url),name);
  }
});
test('Browser CSV keeps numeric negatives and neutralizes whitespace-prefixed text',()=>{
  const src=read('assets/chunks/feature-shared.js');
  const i=src.indexOf('mP=e=>');const j=src.indexOf(',bu=',i);
  const escape=new Function('Yg',src.slice(i,j)+';return mP;')(v=>String(v??''));
  assert.equal(escape(-50000),'"-50000"');
  assert.equal(escape(' =1+1'),'"\' =1+1"');
  assert.equal(escape('-50000'),'"\'-50000"');
});
console.log(`${passed} passed; 0 failed`);
