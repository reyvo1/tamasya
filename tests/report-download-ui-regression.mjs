import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const core=read('assets/app-core.js');
const helper=core.slice(core.indexOf('function tamasyaAllowsApiDownload('),core.indexOf('async function tj('));
const normalize=core.slice(core.indexOf('async function tj('),core.indexOf('const CK=',core.indexOf('async function tj(')));
const wrapper=core.slice(core.indexOf('j=async(w,S)=>'),core.indexOf(',oeR=async',core.indexOf('j=async(w,S)=>'))).slice(2);
const context=vm.createContext({URL,Response,Headers,Y_:()=> 'Invalid JSON',b:()=>{},wx:r=>r.headers.get('X-Tamasya-Request-ID')||''});
vm.runInContext(helper+normalize+';globalThis.wrap='+wrapper+';',context);
let passed=0;
async function test(name,fn){await fn();passed++;console.log('PASS '+name);}
const formats={pdf:['application/pdf','%PDF-1.4\nbytes'],xlsx:['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',new Uint8Array([80,75,3,4,0,255])],csv:['text/csv; charset=UTF-8','Tanggal,Nominal\n2026-01-28,3151000']};
for(const [format,[mime,body]] of Object.entries(formats)){
 await test(format+' bytes and audit headers survive the actual API response wrapper',async()=>{
  for(const url of ['/api/canonical-report?format='+format,'/api.php?from=2026-01-28&action=canonical-report&format='+format]){
   const response=new Response(body,{headers:{'Content-Type':mime,'Content-Disposition':'attachment; filename="report.'+format+'"','X-Tamasya-Report-ID':'report-uat','X-Tamasya-Report-SHA256':'abc','X-Tamasya-Request-ID':'request-uat'}});
   const expected=new Uint8Array(await response.clone().arrayBuffer());
   const actual=await context.wrap(response,url);assert.equal(actual,response);
   assert.deepEqual(new Uint8Array(await actual.arrayBuffer()),expected);
   assert.equal(actual.headers.get('X-Tamasya-Report-ID'),'report-uat');
   assert.equal(actual.headers.get('X-Tamasya-Report-SHA256'),'abc');
  }
 });
}
await test('Existing schema and backup downloads remain unchanged',async()=>{
 for(const action of ['download-schema','download-backup','download-json-backup']){
  const response=new Response('backup',{headers:{'Content-Type':'application/octet-stream'}});
  assert.equal(await context.wrap(response,'/api.php?action='+action),response);
 }
});
await test('JSON report and normal JSON API retain their structured responses',async()=>{
 for(const action of ['canonical-report&format=json','hotel-data']){
  const response=new Response('{"success":true}',{headers:{'Content-Type':'application/json'}});
  assert.equal((await (await context.wrap(response,'/api.php?action='+action)).json()).success,true);
 }
});
await test('Report JSON errors preserve the actual failure and request ID',async()=>{
 const response=new Response('{"success":false,"error":"Snapshot tidak lengkap"}',{status:422,headers:{'Content-Type':'application/json','X-Tamasya-Request-ID':'request-error'}});
 const actual=await context.wrap(response,'/api.php?action=canonical-report&format=pdf');
 assert.equal(actual.status,422);const body=await actual.json();assert.equal(body.error,'Snapshot tidak lengkap');assert.equal(body.requestId,'request-error');
});
await test('HTML, wrong file types and unapproved API downloads still fail closed',async()=>{
 const cases=[['/api/canonical-report?format=pdf','text/html','attachment; filename="report.pdf"'],['/api/canonical-report?format=pdf','text/csv','attachment; filename="report.pdf"'],['/api/canonical-report?format=pdf','application/pdf',''],['/api/hotel-data?format=pdf','application/pdf','attachment; filename="report.pdf"'],['/api/canonical-report-email?format=pdf','application/pdf','attachment; filename="report.pdf"']];
 for(const [url,mime,disposition] of cases){
  const actual=await context.wrap(new Response('unexpected',{headers:{'Content-Type':mime,'Content-Disposition':disposition}}),url);
  assert.equal(actual.status,502);assert.equal((await actual.json()).success,false);
 }
 const response=await context.wrap(new Response('upstream error',{status:503,headers:{'Content-Type':'application/pdf','Content-Disposition':'attachment; filename="error.pdf"'}}),'/api/canonical-report?format=pdf');
 assert.equal(response.status,503);assert.equal((await response.json()).success,false);
});
await test('Malformed JSON never becomes a successful download',async()=>{
 const actual=await context.wrap(new Response('{broken',{headers:{'Content-Type':'application/json'}}),'/api/canonical-report?format=json');
 assert.equal(actual.status,502);assert.equal((await actual.json()).success,false);
});
await test('Official modal inherits selected report and finance periods, with a fresh default for empty filters',()=>{
 const source=read('assets/canonical-report-center.js');
 const fn=source.slice(source.indexOf('function selectedPagePeriod(){'),source.indexOf('  const esc='));
 let values={};const scope=vm.createContext({document:{querySelector:selector=>({value:selector.includes('"from"')?values.from:values.to})},periodNow:()=>({from:'2026-10-01',to:'2026-10-06'})});
 vm.runInContext(fn,scope);
 values={from:'2026-01-01',to:'2026-10-31'};assert.equal(JSON.stringify(scope.selectedPagePeriod()),JSON.stringify(values));
 values={from:'',to:''};assert.equal(scope.selectedPagePeriod().from,'2026-10-01');
 values={from:'2026-10-31',to:'2026-01-01'};assert.equal(scope.selectedPagePeriod().from,'2026-10-01');
});
await test('Journal summary sums only entries selected by the actual search',()=>{
 const source=read('assets/chunks/operations.js');const start=source.indexOf('K=()=>{');const end=source.indexOf('return t.jsxs',start);
 const fn=new Function('i','b',source.slice(start+7,end)+'return {L,ee,Y};');
 const i={journalEntries:[{id:'a',description:'Toar'},{id:'b',description:'Other'}],journalLines:[{journal_entry_id:'a',debit:250000,credit:0},{journal_entry_id:'a',debit:0,credit:250000},{journal_entry_id:'b',debit:220000,credit:220000}]};
 assert.equal(fn(i,'').ee,470000);assert.equal(fn(i,'Toar').ee,250000);assert.equal(fn(i,'Toar').Y,250000);assert.equal(fn(i,'missing').ee,0);
});
await test('Navigation badges contain no corrupted glyphs and export checkboxes have their own width',()=>{
 const css=read('assets/ui-core.css');assert.ok(!/content:\s*['"][^'"]*[âÃð�]/.test(css));
 assert.ok(read('assets/canonical-report-center.js').includes('input[type="checkbox"]{width:16px;height:16px;flex:0 0 16px;'));
});
console.log(`${passed} passed; 0 failed`);
