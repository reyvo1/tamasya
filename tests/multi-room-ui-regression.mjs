// Link the actual ES-module graph without starting a browser or evaluating app code.
// Syntax-only checks cannot catch missing named exports in lazy chunks.
import {SourceTextModule,createContext,runInContext} from 'node:vm';
import fs from 'node:fs';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
const context=createContext({}),modules=new Map();
function load(url){
 const name=url.href;if(modules.has(name))return modules.get(name);
 assert.equal(url.protocol,'file:');const module=new SourceTextModule(fs.readFileSync(fileURLToPath(url),'utf8'),{context,identifier:name});modules.set(name,module);return module;
}
for(const path of ['../assets/chunks/multi-room.js','../assets/chunks/rooms.js']){
 const module=load(new URL(path,import.meta.url));if(module.status==='unlinked')await module.link((specifier,ref)=>load(new URL(specifier,ref.identifier)));
 assert.equal(module.status,'linked');console.log('PASS actual static/lazy ES imports and named exports: '+path);
}
console.log('PASS all '+modules.size+' dependency modules linked without app evaluation.');

// Runtime shape guard: the browser crashed with React #137 because the old
// h() helper passed children: [] to void elements such as <link> and <input>.
// Keep this assertion in source checks so invalid JSX cannot sneak through
// when the full Playwright suite is still awaiting database services.
const multiRoomSource=fs.readFileSync(fileURLToPath(new URL('../assets/chunks/multi-room.js',import.meta.url)),'utf8');
const factoryDeclaration=multiRoomSource.match(/^const h=\(tag,props,\.\.\.children\)=>[^;]+;/m);
assert.ok(factoryDeclaration,'Multi-room element helper must be testable');
const jsxContext=createContext({t:{jsx:(tag,props)=>({tag,props})}});
const renderH=runInContext(`${factoryDeclaration[0]} h`,jsxContext);
for(const tag of ['link','input','img','br','hr']){
 const element=renderH(tag,{rel:'stylesheet',href:'mock.css'});
 assert.equal(element.tag,tag);
 assert.equal(Object.hasOwn(element.props,'children'),false,`Void element <${tag}> must not have children`);
}
assert.equal(renderH('button',{type:'button'},'Balas').props.children,'Balas');
assert.equal(renderH('div',{className:'x'},'a','b').props.children.length,2);
console.log('PASS multi-room React void elements render without children; regular elements retain children.');

// Accessibility contract: the viewport portal owns the accessible modal role.
// Nested role=dialog caused both desktop and mobile Playwright strict-mode failures,
// and would expose duplicate modals to assistive technology.
const viewportSource=fs.readFileSync(fileURLToPath(new URL('../assets/chunks/viewport-layer.js',import.meta.url)),'utf8');
assert.match(viewportSource,/role:props\.role\|\|["']dialog["']/,'Viewport layer remains a modal dialog by default');
assert.match(multiRoomSource,/view&&h\(TamasyaViewportLayer,\{[^}]*['"]aria-label['"]:['"]Reservasi grup['"]/, 'Multi-room portal must give its one dialog an accessible name');
assert.match(multiRoomSource,/h\('section',\{className:'mr-dialog'\}/, 'Inner panel must remain a presentational section');
assert.doesNotMatch(multiRoomSource,/h\('section',\{className:'mr-dialog',role:'dialog'/, 'Must not nest a second dialog within the portal dialog');
const browserTest=fs.readFileSync(fileURLToPath(new URL('uat_rc1/browser/rc1-ui.spec.mjs',import.meta.url)),'utf8');
assert.match(browserTest,/getByRole\('dialog'\)\)\.toHaveCount\(1\)/,'E2E must enforce one accessible dialog');
assert.match(browserTest,/dialog\.locator\('\.mr-dialog'\)\.boundingBox\(\)/,'E2E must measure real modal panel, not fullscreen overlay');
console.log('PASS exactly one named accessible multi-room modal with inner-panel containment and strengthened browser assertions.');

assert.match(multiRoomSource,/h\('select',\{[^}]+value:customSource/,'Booking source is a real dropdown with custom entry');
assert.match(multiRoomSource,/Nama sumber lain/,'Configured and typed sources remain available');
assert.match(multiRoomSource,/booked:'Terisi saat ini'/,'Future-selectable occupied rooms explain their present status');
console.log('PASS multi-room explicit source selection and date-based occupied inventory labels.');

const mergeSource=multiRoomSource.slice(multiRoomSource.indexOf('export function tamasyaMultiRoomMergeSelection'),multiRoomSource.indexOf('export function TamasyaMultiRoomPanel')).replace('export ','');
const merge=runInContext(mergeSource+';tamasyaMultiRoomMergeSelection',createContext({}));
const row={guestName:'Guest',dp:'100',method:'qris',bank:'bank',totalAmount:1000};
let result=merge({'14':row},[{number:'14',available:null,totalAmount:null,currentStatus:'booked'}],'reserve');
assert.equal(result['14'].totalAmount,1000);assert.equal(result['14'].guestName,'Guest');
result=merge(result,[{number:'14',available:false,totalAmount:2000,currentStatus:'booked'}],'reserve');
assert.equal(result['14'].totalAmount,2000);assert.equal(result['14'].dp,'100');assert.equal(result['14'].method,'qris');assert.equal(result['14'].bank,'bank');
assert.equal(merge({'14':{...row,manualPrice:true}},[{number:'14',available:true,totalAmount:2000}],'reserve')['14'].totalAmount,1000);
assert.equal(Object.keys(merge({'14':row},[{number:'14',available:false,totalAmount:2000}],'check_in_now')).length,0);
assert.match(multiRoomSource,/disabled:intent==='check_in_now'&&room\.available!==true/);
assert.match(multiRoomSource,/from:mode==='reserve'\?'':today\(\)/);
assert.match(multiRoomSource,/controller\.abort\(\)/);
console.log('PASS reservation selections survive date changes and present occupancy; check-in requires physical readiness.');
