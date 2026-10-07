import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
let passed=0;
const check=async(name,fn)=>{await fn();passed++;console.log('PASS '+name);};
const window={TAMASYA_RUNTIME_CONFIG:{features:{growthSuiteEnabled:true},propertyTimezone:'Asia/Makassar'}};
vm.runInNewContext(read('assets/currency-display.js'),{window,Intl,Number});
const money=window.TamasyaCurrencyDisplay;
await check('Rupiah display preserves cents consistently without changing the source number',()=>{
 for(const [value,expected] of [[4640000.3,'Rp 4.640.000,30'],[4640000,'Rp 4.640.000'],[0,'Rp 0'],[-2641000.3,'Rp -2.641.000,30'],[.3,'Rp 0,30'],[110000.30000004,'Rp 110.000,30'],[1.9999999999,'Rp 2']])assert.equal(money.formatRupiah(value),expected);
 for(const value of [null,undefined,'',NaN,Infinity,'nonsense'])assert.equal(money.formatRupiah(value),'—');
 const row={amount:4640000.3};money.formatRupiah(row.amount);assert.equal(row.amount,4640000.3);
});
const storage=new Map([['hotel_logged_in','true'],['hotel_staff_role','owner'],['hotel_session_token','session'],['hotel_offline_hotel_scope','hotel-a']]);
let response={ok:true,body:{success:true,data:{rate:250000.3}}},calls=[],effects=[],scheduled=[],states=[];
const f={useState:init=>[typeof init==='function'?init():init,state=>states.push(state)],useEffect:effect=>effects.push(effect)};
const context={window,Intl,Number,URL,Date,AbortController,document:{baseURI:'https://hotel.example/app/index.html'},sessionStorage:{getItem:key=>storage.get(key)},f,t:{jsx:(type,props)=>({type,props}),jsxs:(type,props)=>({type,props})},setTimeout:fn=>{scheduled.push(fn);return fn;},clearTimeout:fn=>{scheduled=scheduled.filter(x=>x!==fn);},fetch:async(url,options)=>{calls.push({url,options});return {ok:response.ok,json:async()=>response.body};}};
let source=read('assets/chunks/growth-widgets.js').replace(/import\s*\{f,t\}\s*from\s*['"][^'"]+['"];?/,'').replace(/export\s+/g,'');
vm.runInNewContext(source+';this.api={money,tamasyaGrowthPlansForRoom,tamasyaGrowthStayNights,tamasyaGrowthBookingUrl,tamasyaGrowthRead,useGrowthRead,TamasyaGrowthKpiPanel,TamasyaGrowthRateSuggestion,TamasyaGrowthBookingLinks};',context);
const api=context.api;
await check('Rate quote uses its plan currency and explicitly identifies the nightly check-in tariff',()=>{
 assert.equal(api.money(250000.3,'IDR'),'Rp 250.000,30');
 const usd=api.money(250000.3,'USD');assert.ok(usd.includes('US$'));assert.ok(usd.includes('250.000,30'));assert.ok(!usd.includes('Rp'));
 assert.ok(source.includes('Tarif paket per malam untuk tanggal check-in.'));assert.ok(source.includes("money(rate.rate,rate.plan?.currency||'IDR')"));
});
await check('Dashboard KPI refreshes each minute and on focus, with timers cleaned on exit',()=>{
 let tick,listener,cleared=false;window.TamasyaPosBusinessDatePolicy={dateAt:()=> '2026-01-28'};
 window.addEventListener=(event,fn)=>{assert.equal(event,'focus');listener=fn;};
 window.removeEventListener=(event,fn)=>{assert.equal(fn,listener);listener=null;};
 context.setInterval=(fn,delay)=>{assert.equal(delay,60000);tick=fn;return 7;};context.clearInterval=id=>{assert.equal(id,7);cleared=true;};
 effects=[];states=[];api.TamasyaGrowthKpiPanel();const cleanup=effects[0]();tick();assert.equal(states[0],'2026-01-28');assert.equal(states[1](3),4);listener();assert.equal(states.length,4);cleanup();assert.equal(listener,null);assert.equal(cleared,true);effects=[];states=[];
});
await check('Rate selection never falls back to an unrelated room type',()=>{
 const plans=[{id:'d',active:1,room_type:'Deluxe'},{id:'s',active:1,room_type:'Standard'},{id:'all',active:1,room_type:''},{id:'inactive',active:0,room_type:'Standard'}];
 assert.deepEqual(Array.from(api.tamasyaGrowthPlansForRoom(plans,'standard'),p=>p.id),['s']);
 assert.deepEqual(Array.from(api.tamasyaGrowthPlansForRoom(plans,'Suite'),p=>p.id),['all']);
 assert.equal(api.tamasyaGrowthPlansForRoom(plans.slice(0,2),'Suite').length,0);
});
await check('Stay length uses real calendar dates and rejects missing reversed invalid or excessive dates',()=>{
 assert.equal(api.tamasyaGrowthStayNights('2026-01-28','2026-01-31'),3);
 assert.equal(api.tamasyaGrowthStayNights('2028-02-28','2028-03-01'),2);
 for(const dates of [['2026-02-30','2026-03-03'],['2026-01-28','2026-01-28'],['2026-01-29','2026-01-28'],['','2026-01-28'],['2026-01-01','2027-01-02']])assert.equal(api.tamasyaGrowthStayNights(...dates),null);
});
await check('Actual canonical booking IDs survive folio links and hosting subdirectories',()=>{
 const id='booking_manual_abc/123 & Toar',url=new URL(api.tamasyaGrowthBookingUrl('folio',id));
 assert.equal(url.pathname,'/app/growth-suite.html');assert.equal(url.searchParams.get('bookingId'),id);
 assert.equal(new URL(api.tamasyaGrowthBookingUrl('group',id)).searchParams.get('q'),id);
});
await check('Growth reads preserve room dates source session hotel scope and HTTP errors',async()=>{
 const data=await api.tamasyaGrowthRead('rate-suggestion',{planId:'plan',stayDate:'2026-01-28',lengthOfStay:3,roomType:'Standard',bookingSource:'Traveloka'});
 assert.equal(data.rate,250000.3);const call=calls.at(-1),url=new URL(call.url);
 assert.equal(call.options.method,'GET');assert.equal(call.options.headers.Authorization,'Bearer session');assert.equal(call.options.headers['X-Tamasya-Hotel-Scope'],'hotel-a');
 assert.equal(url.pathname,'/app/api.php');assert.equal(url.searchParams.get('bookingSource'),'Traveloka');assert.equal(url.searchParams.get('lengthOfStay'),'3');
 response={ok:false,body:{success:true,data:{}}};await assert.rejects(()=>api.tamasyaGrowthRead('kpis'),/belum dapat dimuat/);
 response={ok:true,body:{success:false,error:'Fenced node'}};await assert.rejects(()=>api.tamasyaGrowthRead('kpis'),/Fenced node/);
 response={ok:true,body:{success:true,data:{rate:250000.3}}};
});
await check('Unmount cancels pending work and suppresses an already running stale response',async()=>{
 effects=[];scheduled=[];states=[];api.useGrowthRead(true,'rate-suggestion',{stayDate:'2026-01-28'},350);
 const cleanup=effects.pop()();const task=scheduled.pop();task();cleanup();await new Promise(resolve=>setImmediate(resolve));
 assert.equal(calls.at(-1).options.signal.aborted,true);assert.equal(states.length,1);assert.equal(states[0].pending,true);
 effects=[];scheduled=[];api.useGrowthRead(true,'rate-suggestion',{stayDate:'2026-01-29'},350);effects.pop()()();assert.equal(scheduled.length,0);
});
await check('Feature and roles gate widgets while Owner has read-only links',()=>{
 assert.ok(api.TamasyaGrowthBookingLinks({bookingId:'actual_id'}));
 assert.equal(api.TamasyaGrowthRateSuggestion({stayMode:'short_time'}),null);
 assert.equal(api.TamasyaGrowthRateSuggestion({openEnded:true}),null);
 storage.set('hotel_staff_role','staff');assert.equal(api.TamasyaGrowthKpiPanel(),null);assert.equal(api.TamasyaGrowthBookingLinks({bookingId:'actual_id'}),null);
 storage.set('hotel_staff_role','owner');window.TAMASYA_RUNTIME_CONFIG.features.growthSuiteEnabled=false;assert.equal(api.TamasyaGrowthKpiPanel(),null);
 window.TAMASYA_RUNTIME_CONFIG.features.growthSuiteEnabled=true;
});
await check('React owns explicit widget locations and old addon has no DOM or fetch side effects',()=>{
 const dash=read('assets/chunks/dashboard.js'),rooms=read('assets/chunks/rooms.js'),shim=read('assets/growth-pms-link-addon.js');
 assert.ok(dash.includes('t.jsx(TamasyaGrowthKpiPanel,{})'));assert.ok(rooms.includes('roomType:V.type,checkIn:ve,checkOut:pe,bookingSource:lm||"Direct",stayMode:Ee,openEnded:bn'));
 assert.ok(rooms.includes('bookingId:C.id'));assert.ok(rooms.includes('"data-tamasya-reservation-form":"create"'));
 assert.ok(!/MutationObserver|querySelector|appendChild|position:fixed|fetch\(/.test(shim));
 assert.ok(!/querySelector|MutationObserver|innerHTML/.test(source));
 for(const p of ['index.html','pos.html','growth-suite.html','enterprise-suite.html','multi-property-foundation.html'])assert.ok(read(p).includes('currency-display.js'));
 const sw=read('sw.js');for(const p of ['currency-display.js','chunks/growth-widgets.js','growth-widgets.css'])assert.ok(sw.includes(p));
});
console.log(`${passed} passed; 0 failed`);
