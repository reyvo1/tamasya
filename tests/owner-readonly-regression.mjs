import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';
import vm from 'node:vm';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const policy=createRequire(import.meta.url)('../assets/owner-readonly-policy.js');
globalThis.ownerClientChecks=[];
let passed=0;
function test(name,fn){fn();passed++;console.log('PASS '+name);}
test('Owner business writes are blocked for every HTTP write method before transport',()=>{
 for(const method of ['POST','PUT','PATCH','DELETE'])for(const url of ['/api/transactions','/api.php?action=operations-center','/api.php?action=canonical-report-email','/api.php?action=sync'])assert.equal(policy.requestAllowed('owner',method,url),false);
 for(const method of ['GET','HEAD','OPTIONS'])assert.equal(policy.requestAllowed('owner',method,'/api.php?action=hotel-data'),true);
 for(const action of ['login','verify-2fa','refresh-session','logout'])assert.equal(policy.requestAllowed('owner','POST','/api.php?action='+action),true);
 assert.equal(policy.requestAllowed('admin','POST','/api.php?action=transactions'),true);
});
test('Offline write callbacks never run for Owner; Admin and read callbacks keep their behavior',()=>{
 let writes=0,reads=0;const props={onAddTransaction:()=>writes++,onRoomStatusChange:()=>writes++,onShiftReportSubmit:()=>writes++,onRefreshData:()=>reads++,onNavigate:()=>reads++};
 const owner=policy.componentProps('owner',props);
 for(const key of ['onAddTransaction','onRoomStatusChange','onShiftReportSubmit'])assert.throws(()=>owner[key](),e=>e.code==='OWNER_READ_ONLY');
 assert.equal(writes,0);owner.onRefreshData();owner.onNavigate();assert.equal(reads,2);
 assert.equal(policy.componentProps('admin',props),props);props.onAddTransaction();assert.equal(writes,1);
});
test('Navigation and report filters stay active while write forms, POS cart and changing actions are disabled',()=>{
 for(const label of ['Rekonsiliasi','Maintenance','Aturan Pajak','Buat Folio'])assert.equal(policy.controlIsMutation({label,navigation:true}),false);
 for(const label of ['Cari','Muat Ulang','Detail','Unduh PDF','Cetak Laporan'])assert.equal(policy.controlIsMutation({label}),false);
 for(const label of ['Simpan','Hapus Aset','Bayar','Kirim Laporan','Konfirmasi Checkout','Clock In','Buka Shift'])assert.equal(policy.controlIsMutation({label}),true,label);
 assert.equal(policy.controlIsMutation({writeForm:true}),true);assert.equal(policy.controlIsMutation({explicitMutation:true}),true);
 const source=read('assets/owner-readonly-policy.js');for(const data of ['data-product','data-plus','data-minus'])assert.ok(source.includes(data));
});
test('Owner primary menu preset ignores old deny flags and write capability overrides',()=>{
 const source=read('assets/app-core.js');const start=source.indexOf('const CK=');const end=source.indexOf(';/**',start);
 const ctx=vm.createContext({});vm.runInContext(source.slice(start,end)+';globalThis.menu=_K;globalThis.permissions=PA;globalThis.all=db;',ctx);
 assert.equal(JSON.stringify(ctx.menu('owner',{finance:false,staff:false})),JSON.stringify(ctx.all));
 const perms=ctx.permissions('owner',{desktopTabs:{finance:false},capabilities:{manage_backup:true,approve_sensitive_actions:true}});
 assert.equal(perms.readOnly,true);assert.equal(perms.desktopTabs.finance,true);assert.equal(perms.capabilities.manage_backup,false);assert.equal(perms.capabilities.approve_sensitive_actions,false);assert.equal(perms.capabilities.view_audit_log,true);
});
test('Shared policy and styling load before clients and are precached for offline Owner',()=>{
 const policyUrl='./assets/owner-readonly-policy.js?v=20261007-growth-kpi-r13';const cssUrl='./assets/owner-readonly.css?v=20261007-growth-kpi-r13';
 for(const file of ['index.html','pos.html','growth-suite.html','enterprise-suite.html','internal-memo.html','property-setup.html','multi-property-foundation.html']){
  const html=read(file);assert.ok(html.includes(policyUrl),file);assert.ok(html.includes(cssUrl),file);assert.ok(html.indexOf('<meta charset')<html.indexOf(policyUrl),file);
  const otherScript=html.indexOf('<script',html.indexOf(policyUrl)+policyUrl.length);assert.ok(otherScript<0||html.indexOf(policyUrl)<otherScript,file);
 }
 assert.ok(read('sw.js').includes(policyUrl));assert.ok(read('sw.js').includes(cssUrl));
 assert.ok(read('assets/chunks/app-shell.js').includes('TamasyaOwnerReadOnlyPolicy.componentProps'));
 assert.ok(read('assets/chunks/operations.js').includes('["admin","owner"].includes(e.currentRole))return Yk'));
});
test('Report dialog fits the available width and read-only field values remain legible',()=>{
 const source=read('assets/canonical-report-center.js');assert.ok(source.includes('box-sizing:border-box;min-width:0;width:min(780px,100%)'));
 assert.ok(source.includes('grid-template-columns:minmax(0,2fr) minmax(0,1fr) minmax(0,1fr)'));
 assert.ok(read('assets/owner-readonly.css').includes('-webkit-text-fill-color:currentColor'));
 for(const file of ['assets/growth-suite.js','assets/enterprise-suite.js'])assert.ok(!read(file).includes("querySelectorAll('form').forEach(f=>f.classList.add('role-hidden'))"));
});
// The registry is evaluated as shipped, without a browser/server simulation.
test('Owner module navigation ignores legacy tab denials but preserves deployment feature flags',()=>{
 const ctx=vm.createContext({});vm.runInContext(read('assets/navigation-registry.js').replace(/export\s*\{[\s\S]*$/, '')+';globalThis.cards=tamasyaVisibleModuleCards;',ctx);
 const locked=ctx.cards({role:'owner',allowedTabs:[],permissions:{desktopTabs:{pos:false}},features:{}});
 assert.deepEqual(Array.from(locked,x=>x.id),['pos','memo','growth','enterprise']);
 assert.equal(locked.find(x=>x.id==='enterprise').locked,true);
 const open=ctx.cards({role:'owner',allowedTabs:[],features:{growthSuiteEnabled:true,enterpriseCompletionEnabled:true}});
 assert.equal(open.find(x=>x.id==='enterprise').enabled,true);
 const staff=ctx.cards({role:'receptionist',allowedTabs:['rooms'],features:{growthSuiteEnabled:true,enterpriseCompletionEnabled:true}});
 assert.ok(!staff.some(x=>x.id==='enterprise'));
});
test('Both suite clients reject Owner writes before endpoint probes or transport, allowing GET reads',()=>{
 for(const [file,tail] of [['assets/enterprise-suite.js',";globalThis.client=api;resolve=async()=>({activeApiUrl:'https://unit.invalid/api.php'});"],['assets/growth-suite.js',";globalThis.client=api;resolveEndpoint=async()=>({activeApiUrl:'https://unit.invalid/api.php'});"]]){
  const ctx=vm.createContext({window:{TamasyaOwnerReadOnlyPolicy:policy,addEventListener(){}},sessionStorage:{getItem:k=>({hotel_logged_in:'true',hotel_staff_role:'owner',hotel_device_id:'device'}[k]||'')},localStorage:{getItem:()=> 'device'},URL,location:{href:'https://unit.invalid/'},crypto:{randomUUID:()=> 'unit-operation'},setTimeout,clearTimeout,fetch:()=>{throw Error('Unexpected transport');}});
  vm.runInContext(read(file).replace(/\}\)\(\);\s*$/,tail+'})();'),ctx);
  // Async functions reject before the first await: collect promises below.
  const denied=ctx.client('pr-save',{method:'POST',body:{}});globalThis.ownerClientChecks.push(assert.rejects(denied,e=>e.code==='OWNER_READ_ONLY'));
  let fetched=0;ctx.fetch=async()=>{fetched++;return {ok:true,status:200,text:async()=>'{"success":true,"data":{}}'};};
  globalThis.ownerClientChecks.push(ctx.client('bootstrap').then(r=>{assert.equal(r.success,true);assert.equal(fetched,1)}));
 }
});
test('Dynamically rendered Enterprise write controls are disabled while detail, folio and adapter reads stay enabled',()=>{
 const attrs=['data-post-inv','data-pay-inv','data-camp-approve','data-camp-snapshot','data-camp-send','data-charge-booking','data-pay-tx'];
 const ids=['folio-sync-charges','pr-to-po','create-grn-now','post-grn-now'];
 const make=(label,attr='',id='')=>({tagName:'BUTTON',textContent:label,disabled:false,dataset:{},matches(sel){return sel.split(',').some(x=>x===`#${id}`||(attr&&x===`[${attr}]`))},closest(){return null},hasAttribute(a){return a===attr||a==='id'&&!!id},getAttribute(){return ''},setAttribute(){},removeAttribute(){}});
 const writes=[...attrs.map(a=>make('Post',a)),...ids.map(id=>make('Action','',id))];const reads=['Detail','Buka','Buka/Cetak','Self Test','Cari','Refresh'].map(x=>make(x));
 const elements=[...writes,...reads];const ctx=vm.createContext({sessionStorage:{getItem:k=>k==='hotel_logged_in'?'true':'owner'},document:{documentElement:{dataset:{}},querySelectorAll:()=>elements,addEventListener(){}},addEventListener(){},MutationObserver:class{observe(){}}});vm.runInContext(read('assets/owner-readonly-policy.js'),ctx);
 writes.forEach(x=>assert.equal(x.disabled,true));reads.forEach(x=>assert.equal(x.disabled,false));
 ctx.TamasyaOwnerReadOnlyPolicy.apply();writes.forEach(x=>assert.equal(x.disabled,true));
});
test('Changed Owner navigation and suite URLs use the release version in HTML, imports and offline precache',()=>{
 const build=read('release_contract.php').match(/define\('TAMASYA_BUILD_ID', '([^']+)'/)[1],sw=read('sw.js');
 for(const asset of ['navigation-registry.js','growth-suite.js','enterprise-suite.js'])assert.ok(sw.includes(`./assets/${asset}?v=${build}`));
 assert.ok(read('assets/chunks/app-shell.js').includes(`../navigation-registry.js?v=${build}`));
 for(const suite of ['growth','enterprise'])assert.ok(read(`${suite}-suite.html`).includes(`./assets/${suite}-suite.js?v=${build}`));
});

await Promise.all(globalThis.ownerClientChecks);
console.log(`${passed} passed; 0 failed`);
