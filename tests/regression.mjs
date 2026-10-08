import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
const root=process.argv[2]||path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
let passed=0,failed=0;
async function test(name,fn){try{await fn();passed++;console.log(`PASS ${name}`);}catch(e){failed++;console.log(`FAIL ${name}: ${e.message}`);}}
function worker(scope){
 const events={},puts=[],deletes=[];
 const cache={match:async()=>undefined,put:async(...args)=>puts.push(args),addAll:async()=>{}};
 const context=vm.createContext({URL,Response,Set,encodeURIComponent,
 self:{registration:{scope},location:{origin:'https://hotel.test'},addEventListener:(name,fn)=>events[name]=fn,clients:{claim:async()=>{}},skipWaiting:()=>{}},
 caches:{open:async()=>cache,keys:async()=>['tamasya-cache-'+encodeURIComponent('/other/')+'-r4.3-20260914'],delete:async name=>deletes.push(name),match:cache.match},
 fetch:async()=>({ok:true,type:'basic',headers:new Headers(),clone:()=>({})})});
 vm.runInContext(fs.readFileSync(path.join(root,'sw.js'),'utf8'),context);
 return {context,events,puts,deletes};
}
for(const resource of ['admin.php','webpublic/index.html','uploads/identity/photo.jpg','missing.html'])await test(`SW bypasses ${resource}`,()=>{
 const w=worker('https://hotel.test/app/');let intercepted=false;
 w.events.fetch({request:{url:'https://hotel.test/app/'+resource,method:'GET',headers:new Headers(),mode:'navigate'},respondWith:()=>intercepted=true});
 assert.equal(intercepted,false);
});
await test('SW cache differs between property subfolders',()=>{const a=worker('https://hotel.test/a/'),b=worker('https://hotel.test/b/');assert.notEqual(vm.runInContext('CACHE_NAME',a.context),vm.runInContext('CACHE_NAME',b.context));});
await test('SW activation retains another property cache',async()=>{const w=worker('https://hotel.test/app/');let done;w.events.activate({waitUntil:p=>done=p});await done;assert.equal(w.deletes.length,0);});
await test('SW still handles application entry navigation',async()=>{const w=worker('https://hotel.test/app/');let done;const waits=[];w.events.fetch({request:{url:'https://hotel.test/app/',method:'GET',mode:'navigate',headers:new Headers()},respondWith:p=>done=p,waitUntil:p=>waits.push(p)});assert.ok(done);await done;await Promise.all(waits);assert.equal(w.puts.length,1);});
function guard(status,payload){
 const calls=[],storage=new Map();
 const context=vm.createContext({URL,Headers,Request,Response,URLSearchParams,FormData,Blob,File,ArrayBuffer,Uint8Array,Date,Map,Set,JSON,Math,
 location:new URL('https://hotel.test/app/'),sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},
 window:{fetch:async(input,init)=>{calls.push(init);return new Response(JSON.stringify(payload),{status,headers:{'Content-Type':'application/json'}});}}});
 vm.runInContext(fs.readFileSync(path.join(root,'assets/api-operation-guard.js'),'utf8'),context);
 return {calls,fetch:()=>context.window.fetch('https://hotel.test/app/api.php?action=bookings',{method:'POST',body:'{"value":1}'})};
}
await test('202 response remains retryable with the same operation ID',async()=>{const g=guard(202,{success:true,pending:true});await g.fetch();const second=await g.fetch();assert.equal(second.status,202);assert.equal(g.calls.length,2);assert.equal(g.calls[0].headers.get('X-Tamasya-Operation-ID'),g.calls[1].headers.get('X-Tamasya-Operation-ID'));});
await test('business failure in HTTP 200 does not arm success shield',async()=>{const g=guard(200,{success:false});await g.fetch();await g.fetch();assert.equal(g.calls.length,2);});
await test('successful duplicate is still blocked',async()=>{const g=guard(200,{success:true});await g.fetch();assert.equal((await g.fetch()).status,409);assert.equal(g.calls.length,1);});
await test('503 retry retains the operation ID',async()=>{const g=guard(503,{success:false});await g.fetch();await g.fetch();assert.equal(g.calls.length,2);assert.equal(g.calls[0].headers.get('X-Tamasya-Operation-ID'),g.calls[1].headers.get('X-Tamasya-Operation-ID'));});

await test('compiled app-shared retains operation ID for retryable responses',()=>{
 const source=fs.readFileSync(path.join(root,'assets/chunks/app-shared.js'),'utf8');
 assert.match(source,/status===202\|\|tamasyaResponse\.status===408\|\|tamasyaResponse\.status===425\|\|tamasyaResponse\.status===429\|\|tamasyaResponse\.status>=500/);
});
await test('Enterprise RC1 build ID matches API, SW cache, registration and asset URLs',()=>{
 const build='20261008-r14-mrfix';
 const release=fs.readFileSync(path.join(root,'release_contract.php'),'utf8');
 const guard=fs.readFileSync(path.join(root,'consistency_guard_support.php'),'utf8');
 const sw=fs.readFileSync(path.join(root,'sw.js'),'utf8');
 const index=fs.readFileSync(path.join(root,'index.html'),'utf8');
 const swRegister=fs.readFileSync(path.join(root,'assets/sw-register.js'),'utf8');
 assert.ok(release.includes(build));assert.ok(guard.includes(build));assert.ok(sw.includes(`CACHE_PREFIX}${build}`));
 assert.ok(index.includes(`sw-register.js?v=${build}`));assert.ok(swRegister.includes(`sw.js?v=${build}`));
 for(const rel of ['assets/app-core.js','assets/chunks/app-shared.js','assets/chunks/app-shell.js','assets/chunks/feature-shared.js']){
  assert.ok(sw.includes(`${rel}?v=${build}`),`SW missing build version for ${rel}`);
 }
});
await test('modularization manifest matches every active router module',()=>{
 const manifest=JSON.parse(fs.readFileSync(path.join(root,'api/MODULARIZATION_MANIFEST.json'),'utf8'));
 const router=fs.readFileSync(path.join(root,'api/router.php'),'utf8');
 const routes=[...router.matchAll(/require __DIR__ \. '\/routes\/([^']+)'/g)].map(m=>m[1]);
 assert.deepEqual([...manifest.routeModules].sort(),[...routes].sort());
 for(const rel of routes) assert.ok(fs.existsSync(path.join(root,'api/routes',rel)),`missing route module ${rel}`);
});
await test('domain dropdown stays open and anchored during mobile scroll; outside taps still close it',()=>{
 const shell=fs.readFileSync(path.join(root,'assets/chunks/app-shell.js'),'utf8');
 const source=shell.slice(shell.indexOf('function TamasyaDomainNavGroup'),shell.indexOf('function TamasyaModuleDockNative'));
 const events={},frames=[],openChanges=[],positions=[];let stateIndex=0,refIndex=0;
 const trigger={getBoundingClientRect:()=>({width:130,left:75,top:225,bottom:260}),contains:x=>x==='trigger'};
 const menu={contains:x=>x==='menu-child'};
 const ctx=vm.createContext({document:{body:{dataset:{}},addEventListener:(n,fn)=>events[n]=fn,removeEventListener(){}},window:{innerWidth:390,innerHeight:844,addEventListener:(n,fn)=>events[n]=fn,removeEventListener(){}},requestAnimationFrame:fn=>{frames.push(fn);return frames.length;},cancelAnimationFrame(){},tamasyaVisibleDomainMembers:({members})=>members,TamasyaFloatingLayer:'body-portal',t:{jsx:(type,props)=>({type,props}),jsxs:(type,props)=>({type,props})},f:{useState(initial){const index=stateIndex++;const value=index===0?true:typeof initial==='function'?initial():initial;return [value,next=>{if(index===0)openChanges.push(next);if(index===1)positions.push(typeof next==='function'?next({left:0,top:0,width:220}):next)}]},useRef:()=>({current:refIndex++===0?trigger:menu}),useEffect:fn=>fn()}});
 vm.runInContext(source+';globalThis.view=TamasyaDomainNavGroup({id:"operations",members:[{route:"operations",label:"Operasional"}],allowed:["operations"]});',ctx);
 assert.equal(ctx.view.props.children[1].type,'body-portal');
 events.scroll({target:'nav-rail'});frames.pop()();assert.equal(openChanges.length,0);assert.equal(positions[0].top,266);
 events.pointerdown({target:'menu-child'});assert.equal(openChanges.length,0);
 events.pointerdown({target:'outside'});assert.equal(openChanges[0],false);
 assert.ok(shell.includes("window.removeEventListener('scroll', onScroll, true)"));
});
await test('Growth browser UAT waits for UI commit after API response',()=>{
 const spec=fs.readFileSync(path.join(root,'tests/uat_rc1/browser/rc1-ui.spec.mjs'),'utf8');
 assert.ok(spec.includes('async function waitUiActionSettled(page,responsePromise,expectedStatus=200)'),'UI-settle helper missing');
 assert.match(spec,/command=channel-mapping-save[\s\S]{0,700}waitUiActionSettled\(page,mapping\)/,'channel mapping must wait for post-response UI commit');
 assert.match(spec,/command=payment-intent-create[\s\S]{0,700}waitUiActionSettled\(page,pi\)/,'payment intent must wait for post-response UI commit');
});
await test('Growth mobile layout contains wide data inside the viewport',()=>{
 const css=fs.readFileSync(path.join(root,'assets/growth-suite.css'),'utf8');
 const spec=fs.readFileSync(path.join(root,'tests/uat_rc1/browser/rc1-ui.spec.mjs'),'utf8');
 assert.ok(css.includes('.panel{background:#fff')&&css.includes('min-width:0}'),'Growth panels must be allowed to shrink below intrinsic table width');
 assert.ok(css.includes('.grid.two>*,.form-grid>*{min-width:0}'),'Growth grid children must not expand the page from long IDs/tables');
 assert.ok(css.includes('.table-wrap{overflow:auto;max-width:100%;'),'Growth tables must scroll inside their panel');
 assert.ok(css.includes('.grid.two,.form-grid{grid-template-columns:minmax(0,1fr)}'),'mobile Growth grid must retain a zero minimum track');
 assert.ok(spec.includes('async function expectNoPageHorizontalOverflow(page)'),'browser UAT must assert page-level horizontal containment');
 assert.match(spec,/channel-mapping-save[\s\S]{0,900}expectNoPageHorizontalOverflow\(page\)/,'channel mapping refresh must preserve mobile viewport containment');
});
await test('Growth mobile header actions wrap inside the viewport',()=>{
 const css=fs.readFileSync(path.join(root,'assets/growth-suite.css'),'utf8');
 assert.ok(css.includes('.header-actions{display:flex;align-items:center;gap:10px;min-width:0;max-width:100%}'),'Growth header actions need shrink containment');
 assert.ok(css.includes('.header-actions{width:100%;flex-wrap:wrap}'),'mobile Growth header actions must wrap instead of widening the page');
 assert.ok(css.includes('.header-actions>*{min-width:0;max-width:100%}'),'mobile header action flex children must be shrinkable');
 assert.ok(css.includes('.header-actions .btn,.header-actions .pill{white-space:normal;overflow-wrap:anywhere}'),'long mobile header action labels must wrap inside the viewport');
});

await test('post-green Owner, Memo and Telegram direct-reply contracts stay wired',()=>{
 const policy=fs.readFileSync(path.join(root,'api/support/017_authorization_policy.php'),'utf8');
 const api=fs.readFileSync(path.join(root,'api.php'),'utf8');
 const auth=fs.readFileSync(path.join(root,'api/routes/050_auth_staff.php'),'utf8');
 const support=fs.readFileSync(path.join(root,'api/support/055_public_support_chat.php'),'utf8');
 const tg=fs.readFileSync(path.join(root,'api/routes/080_telegram_webhook.php'),'utf8');
 const memo=fs.readFileSync(path.join(root,'api/routes/118_internal_memos.php'),'utf8');
 const migration=fs.readFileSync(path.join(root,'migrations/V137_OPTIONAL_ENTERPRISE_COMPLETION.sql'),'utf8');
 const installer=fs.readFileSync(path.join(root,'optional_modules_install.php'),'utf8');
 const nav=fs.readFileSync(path.join(root,'assets/navigation-registry.js'),'utf8');
 const app=fs.readFileSync(path.join(root,'assets/app-core.js'),'utf8');
 const sw=fs.readFileSync(path.join(root,'sw.js'),'utf8');
 assert.ok(policy.includes("strtolower(trim((string)($user['role'] ?? ''))) === 'owner'"));
 assert.ok(api.includes('tamasyaEnforceOwnerReadOnly($loggedInStaff, (string)$action)'),'global Owner write guard must run before route handlers');
 assert.ok(auth.includes("'admin','manager','receptionist','finance','owner'"),'Staff API must accept owner role');
 assert.ok(support.includes("'callback_data'=>'support_reply:'.$publicCode"),'website support notification must provide direct Reply button');
 assert.ok(tg.includes("telegram_state='waiting_for_support_reply'")&&tg.includes("$currentState === 'waiting_for_support_reply'"),'Telegram reply state must be server-side and consumed from normal text');
 assert.ok(tg.includes("$expiresAt=(int)($ctx['expiresAt']??0)")&&tg.includes('time()>$expiresAt'),'Telegram reply state must expire fail-closed');
 assert.ok(migration.includes('CREATE TABLE IF NOT EXISTS growth_internal_memos'),'Memo must use official versioned Enterprise migration');
 assert.ok(installer.includes("'growth_internal_memos'"),'optional installer must own Memo schema');
 assert.ok(memo.includes("status='archived'")&&memo.includes("status='active'"),'Memo uses archive/restore, not hard delete');
 assert.ok(memo.includes("writeRequiredEnterpriseAudit"),'Memo mutations must be audited');
 assert.ok(nav.includes("id: 'memo'")&&nav.includes("['admin','finance','owner']"),'Memo module must be visible to Admin/Finance/Owner');
 assert.ok(app.includes('owner:db'),'Owner must receive every desktop tab in the UI permission map');
 assert.ok(app.includes('view_audit_log:n||s||i||o')&&app.includes('view_guest_identity:n||s||l||u||o'),'Owner gets only explicit view capabilities needed for full read-only inspection');
 assert.ok(app.includes('c!=="website"||e==="owner"||s.manage_public_website===!0'),'Owner can view Website tab without receiving website mutation capability');
 const staff=fs.readFileSync(path.join(root,'assets/chunks/staff.js'),'utf8');
 assert.ok(staff.includes('value:"owner",children:"Owner (Lihat Semua · Tidak Bisa Mengubah)"'),'Staff UI must expose the Owner read-only role');
 const growthRoute=fs.readFileSync(path.join(root,'api/routes/110_growth_suite.php'),'utf8');
 const enterpriseRoute=fs.readFileSync(path.join(root,'api/routes/112_enterprise_completion.php'),'utf8');
 const posRoute=fs.readFileSync(path.join(root,'api/routes/065_pos_minibar.php'),'utf8');
 assert.ok(growthRoute.includes("['admin','manager','finance','owner']")&&growthRoute.includes("['admin','owner']"),'Owner must receive full Growth read projections');
 assert.ok(enterpriseRoute.includes("$enterpriseRoles=['admin','manager','finance','owner']")&&enterpriseRoute.includes("['admin','manager','owner']"),'Owner must receive full Enterprise read projections');
 assert.ok(posRoute.includes("['admin','manager','finance','owner']"),'Owner must see POS cost data while mutation flags stay role-restricted');
 assert.ok(sw.includes('./internal-memo.html')&&sw.includes('./assets/internal-memo.js')&&sw.includes('./assets/internal-memo.css'),'Memo surface participates in offline/static cache contract');
 const postGreenUat=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_post_green_features.py'),'utf8');
 assert.ok(postGreenUat.includes('def validate_owner_read_contract(label,status,body,owner_username):'),'Owner UAT must validate each canonical read response shape');
 assert.ok(postGreenUat.includes('def evidence_safe(value):')&&postGreenUat.includes('safe_detail=evidence_safe(detail)'),'Post-green UAT evidence logger must normalize supported container views without changing assertion truth');
 assert.ok(postGreenUat.includes("if label == 'staff':")&&postGreenUat.includes("'contract':'bare-list'"),'Owner UAT must preserve the canonical Staff GET bare-array contract');
 assert.ok(postGreenUat.includes("body.get('success') is not True"),'Owner UAT must fail closed for declared success-envelope endpoints');
 const memoClient=fs.readFileSync(path.join(root,'assets/internal-memo.js'),'utf8');
 assert.ok(memoClient.includes("'X-Tamasya-Operation-ID'=operationId()")||memoClient.includes("h['X-Tamasya-Operation-ID']=operationId()"),'Standalone Memo mutations must carry the mandatory operation id instead of weakening the server 428 guard');
 const telegramUat=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_telegram.py'),'utf8');
 assert.ok(telegramUat.includes("sender='staff' AND channel='telegram' AND message=?")&&telegramUat.includes("'matchingRows':rows"),'Telegram direct-reply UAT must assert the exact persisted staff reply, not an unrelated globally-latest row');
 const browserUat=fs.readFileSync(path.join(root,'tests/uat_rc1/browser/rc1-ui.spec.mjs'),'utf8');
 assert.ok(browserUat.includes("form select option[value=\"owner\"]"),'Owner role browser assertion must target the Staff edit form rather than the independent role filter');
 assert.ok(browserUat.includes("await page.goto('/index.html');await expect(page.locator('#tamasya-pos-menu')).toBeVisible();")&&browserUat.includes("'X-Tamasya-Operation-ID':'browser-owner-denied-'"),'Owner browser UAT must establish the authenticated origin and reach the Owner mutation guard with a valid operation id');
});

await test('root CSP does not require unsafe-inline scripts',()=>{
 const ht=fs.readFileSync(path.join(root,'.htaccess'),'utf8');
 const index=fs.readFileSync(path.join(root,'index.html'),'utf8');
 assert.match(ht,/script-src 'self';/);
 assert.doesNotMatch(index,/<script(?![^>]*\bsrc=)[^>]*>/i);
});

await test('Enterprise Full Complete UAT is additive, two-node, fail-closed and evidence-gated',()=>{
 const workflow=fs.readFileSync(path.join(root,'.github/workflows/tamasya-enterprise-rc1-uat.yml'),'utf8');
 const required=[
  'tests/uat_rc1/scenario_workforce_inventory_complete.py',
  'tests/uat_rc1/scenario_public_guest_journey.py',
  'tests/uat_rc1/scenario_operational_domains_complete.py',
  'tests/uat_rc1/scenario_telegram_parity_complete.py',
  'tests/uat_rc1/scenario_two_node_hybrid_complete.py',
  'tests/uat_rc1/run_two_node_complete.sh',
  'tests/uat_rc1/scenario_hq_multi_property_complete.py',
  'tests/uat_rc1/run_hq_multi_property_complete.sh',
  'tests/uat_rc1/smtp_sink.py',
  'tests/uat_rc1/assert_full_complete.py'
 ];
 for(const rel of required) assert.ok(fs.existsSync(path.join(root,rel)),`missing ${rel}`);
 assert.match(workflow,/mysql-standby:/);assert.match(workflow,/3307:3306/);
 for(const step of [
   'Workforce, payroll, savings and asset lifecycle UAT',
   'Public website guest reservation and support journey UAT',
   'Operational domains, shift handover and real SMTP report UAT',
   'Two-node separate-DB Primary\/Standby failover and convergence UAT',
   'Two-property HQ signed HTTPS aggregation UAT',
   'Assert Enterprise Full Complete evidence contract'
 ]) assert.ok(workflow.includes(step),`workflow missing ${step}`);
 const twoNode=fs.readFileSync(path.join(root,'tests/uat_rc1/run_two_node_complete.sh'),'utf8');
 assert.match(twoNode,/-P3306/);assert.match(twoNode,/-P3307/);
 assert.ok(twoNode.includes('/proc/sys/net/ipv4/ip_local_port_range')&&twoNode.includes('two-node-ports.json'),'two-node UAT must select verified free listener ports outside the current client-ephemeral range and persist port evidence');
 assert.ok(!twoNode.includes('127.0.0.1:38186')&&!twoNode.includes('127.0.0.1:38187'),'two-node UAT must not use collision-prone fixed application ports');
 assert.ok(twoNode.includes('kill -0 \"$(cat \"$pidfile\")\"')&&twoNode.includes('socat TCP-LISTEN:')&&twoNode.includes('reuseaddr,fork')&&twoNode.includes('A_RECOVERY_BACKEND_PORT'),'two-node UAT must fail fast on owned child death and recover the stable public node URL through a reuseaddr proxy backed by a fresh PHP port');
 const exportScenario=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_exports.py'),'utf8');
 assert.ok(exportScenario.includes("e.logname='export-results.json'"),'canonical export UAT must write the exact evidence filename required by the locked Full Complete contract');
 const hqRunner=fs.readFileSync(path.join(root,'tests/uat_rc1/run_hq_multi_property_complete.sh'),'utf8');
 assert.ok(hqRunner.includes('/proc/sys/net/ipv4/ip_local_port_range')&&hqRunner.includes('hq-ports.json'),'HQ Full Complete runner must select verified free listener ports and persist exact port evidence');
 for(const fixed of ['38188','38189','38190','38191']) assert.ok(!hqRunner.includes(fixed),`HQ runner must not use fixed application port ${fixed}`);
 assert.ok(hqRunner.includes('wait_http_child')&&hqRunner.includes('kill -0 "$(cat /tmp/tamasya-efc-hq-tls.pid)"'),'HQ runner must fail fast when an owned PHP/TLS child dies instead of waiting on an unrelated port');
 const twoNodeScenario=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_two_node_hybrid_complete.py'),'utf8');
 assert.match(twoNodeScenario,/node-cluster-switch-primary/);
 const clusterSupport=fs.readFileSync(path.join(root,'node_cluster_support.php'),'utf8');
 assert.ok(clusterSupport.includes('function tamasyaClusterDatasetChecksumMap(): array'),'planned switchover must own an explicit stable business-state checksum policy separate from the broader mirror surface');
 for(const volatile of ["'activity_logs'","'audit_logs'","'request_operation_receipts'"]) assert.ok(clusterSupport.includes(volatile),`switchover checksum policy must exclude expected per-node volatile surface ${volatile}`);
 for(const authField of ["'failed_login_count'","'login_locked_until'","'last_login_at'","'last_login_ip'"]) assert.ok(clusterSupport.includes(authField),`switchover checksum must ignore node-local login telemetry ${authField}`);
 assert.ok(clusterSupport.includes('foreach (tamasyaClusterDatasetChecksumMap() as $table=>$meta)')&&clusterSupport.includes("if (!hash_equals((string)$primaryChecksum['sha256'],(string)$peerChecksum['sha256']))"),'planned switchover must still fail closed on the stable business checksum; the checksum gate must not be bypassed');
 assert.ok(clusterSupport.includes('function tamasyaClusterDatasetChecksumDifferences(array $primary, array $standby): array')&&clusterSupport.includes('Tabel berbeda:'),'checksum mismatch must expose deterministic per-table diagnostics while keeping the switchover fail-closed');
 const apiSource=fs.readFileSync(path.join(root,'api.php'),'utf8');
 assert.ok(apiSource.includes('$isRevisionlessControlMutation')&&apiSource.includes("'push-summary','queue-snapshot','deliver-snapshot','requeue-snapshot'"),'HQ control-plane POSTs must retain mutation receipts/locks but be classified separately from authoritative hotel-data revisions');
 assert.ok(apiSource.includes('$successful && !$isRevisionlessControlMutation && !tamasyaServerRevisionWasBumped()'),'revisionless HQ control-plane operations must never advance server_revision');
 assert.ok(apiSource.includes('if ($successful && !$isRevisionlessControlMutation) {')&&apiSource.includes('tamasyaEnqueueNodeCommand'),'revisionless HQ control-plane operations must not enter the business node-sync outbox');
 const hqFoundation=fs.readFileSync(path.join(root,'api/modules/setup_admin/106_multi_property_foundation.php'),'utf8');
 assert.ok(hqFoundation.includes('$snapshot=tamasyaHybridSnapshot($pdo,$from,$to);')&&!hqFoundation.includes("$snapshot=tamasyaMultiPropertySummaryPreview($pdo,$from,$to);$snapshot['companyName']"),'direct HQ push must use the same strict snapshot-v2 body as the canonical outbox, never the legacy summary preview');
 for(const header of ['X-Tamasya-Company-ID: ','X-Tamasya-Property-ID: ','X-Tamasya-Operation-ID: ','X-Tamasya-Timestamp: ','X-Tamasya-Nonce: ','X-Tamasya-Signature: ']) assert.ok(hqFoundation.includes(header),`direct HQ push missing signed v2 envelope header ${header}`);
 assert.ok(hqFoundation.includes('$canonical=implode(')&&hqFoundation.includes("[$ts,$nonce,$snapshot['companyId'],$snapshot['propertyId'],$operationId,$payloadHash]"),'direct HQ signature must bind timestamp, nonce, company, property, operation ID and exact payload hash');
 const hqScenario=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_hq_multi_property_complete.py'),'utf8');
 assert.ok(hqScenario.includes("op1_transport_a='efc_hq_direct_transport_a_'")&&hqScenario.includes("op1_transport_b='efc_hq_direct_transport_b_'"),'HQ duplicate UAT must reach HQ twice with distinct property transport receipts while preserving one immutable HQ operation ID');
 assert.ok(hqScenario.includes("queue_transport='efc_hq_outbox_queue_'")&&hqScenario.includes("deliver_transport='efc_hq_outbox_deliver_'"),'HQ queue and deliver must use distinct HTTP operation receipts instead of reusing one transport operation ID across different commands');
 const parity=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_telegram_parity_complete.py'),'utf8');
 for(const marker of ['r_extend_confirm:','r_layanan_confirm:','r_transfer_confirm:','hk_set:']) assert.ok(parity.includes(marker),`missing Telegram parity ${marker}`);
 const evidence=fs.readFileSync(path.join(root,'tests/uat_rc1/assert_full_complete.py'),'utf8');
 for(const name of ['enterprise-full-two-node-results.json','enterprise-full-hq-results.json','post-green-feature-results.json','telegram-results.json']) assert.ok(evidence.includes(name),`evidence gate missing ${name}`);
 const setup=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_setup.py'),'utf8');
 assert.ok(setup.includes("('payroll_expense','expense')")&&setup.includes("('maintenance_expense','expense')"),'Full Complete setup must provision payroll and maintenance semantic categories through canonical category APIs');
 const postingAuthority=fs.readFileSync(path.join(root,'api/modules/finance/025_financial_posting_authority.php'),'utf8');
 assert.ok(postingAuthority.includes('function tamasyaAssertOpenShiftCashCanCoverExpense(PDO $pdo,array $tx): void'),'canonical posting authority must own the physical-cash overdraw guard');
 assert.ok(postingAuthority.includes("SELECT id,status,opening_cash FROM shift_sessions WHERE id=? LIMIT 1 FOR UPDATE"),'cash-overdraw guard must lock the exact open shift');
 assert.ok(postingAuthority.includes('tamasyaAssertOpenShiftCashCanCoverExpense($pdo,$tx);'),'every canonical financial insert must pass the cash-overdraw guard');
 const workforce=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_workforce_inventory_complete.py'),'utf8');
 assert.ok(workforce.includes("Full Complete finance catalog owns payroll and maintenance expense semantics"),'workforce UAT must prove finance semantic ownership before posting payroll/maintenance money');
 assert.ok(workforce.includes("'paymentMethod':'transfer','bankAccountId':'sim_bank'"),'Full Complete payroll must exercise canonical bank payment without corrupting the baseline cash-shift fixture');
 assert.ok(workforce.includes("bankAccountId']=='sim_bank' and not salary_tx[0]['shiftSessionId']"),'salary UAT must prove bank payroll stays outside the cash shift');
 assert.ok(workforce.includes("salary_cash_overdraw_rejected")&&workforce.includes("Rejected cash payroll rolls back slip and transaction atomically"),'workforce UAT must prove cash payroll cannot overdraw the physical shift');
 assert.ok(workforce.includes("inventory_maintenance_cash_overdraw_rejected")&&workforce.includes("Rejected cash maintenance rolls back maintenance row and financial transaction atomically"),'inventory UAT must prove cash maintenance cannot overdraw the physical shift');
 assert.ok(workforce.includes("Inventory maintenance persists and posts exact canonical bank expense without contaminating the open cash shift"),'maintenance UAT must prove financial posting without contaminating baseline cash reconciliation');
 const publicJourney=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_public_guest_journey.py'),'utf8');
 assert.ok(publicJourney.includes("cms_op='efc_public_cms_room_'+run")&&publicJourney.includes("operation=cms_op"),'public CMS UAT mutation must satisfy operation-id security instead of weakening HTTP 428');
 assert.ok(publicJourney.includes('def admin_operation(label):')&&publicJourney.includes("hashlib.sha256((run+'|'+label).encode('utf8')).hexdigest()[:40]"),'authenticated public-reservation review UAT must generate valid bounded operation IDs rather than using human labels');
 assert.ok(publicJourney.includes("admin_operation('review_after_conversion')"),'converted public reservation rejection must reach the real 409 route with a valid operation ID');
 const posRouteFull=fs.readFileSync(path.join(root,'api/routes/065_pos_minibar.php'),'utf8');
 const posClientFull=fs.readFileSync(path.join(root,'assets/pos-minibar.js'),'utf8');
 const posHtmlFull=fs.readFileSync(path.join(root,'pos.html'),'utf8');
 assert.ok(posRouteFull.includes("'businessDate'=>date('Y-m-d'),'businessMonthStart'=>date('Y-m-01')"),'POS bootstrap must expose the server/property business date instead of relying on device timezone');
 assert.ok(posRouteFull.includes("throw new DomainException('Produk telah berubah pada perangkat lain. Muat ulang sebelum menyimpan.')"),'stale POS optimistic-concurrency writes must be HTTP 409 business conflicts, never 500');
 const posDatePolicy=fs.readFileSync(path.join(root,'assets/pos-business-date-policy.js'),'utf8');
 assert.ok(posClientFull.includes("businessDate: String(data.businessDate || '')")&&posClientFull.includes('syncSalesDateFilter(false);')&&posDatePolicy.includes('businessMonthStart')&&!posClientFull.includes('const today = new Date(); const first = new Date(today.getFullYear(), today.getMonth(), 1);'),'POS sales filters must follow the server-authoritative business date through explicit rollover policy rather than browser timezone');
 assert.ok(posHtmlFull.includes('pos-business-date-policy.js?v=20261008-r14-mrfix')&&posHtmlFull.includes('pos-minibar.js?v=20261008-r14-mrfix'),'changed POS rollover policy/client must be cache-busted for deployed browsers');
 const browserFull=fs.readFileSync(path.join(root,'tests/uat_rc1/browser/rc1-ui.spec.mjs'),'utf8');
 assert.ok(browserFull.includes("toHaveValue(sale.saleDate)")&&browserFull.includes("inputValue())<=sale.saleDate"),'browser POS lifecycle must prove its report window contains the canonical sale business date');
 const telegramParityFull=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_telegram_parity_complete.py'),'utf8');
 assert.ok(telegramParityFull.includes("ping_status,ping=request('ping','GET')")&&telegramParityFull.includes("datetime.date.fromisoformat(ping_date)")&&!telegramParityFull.includes('today=datetime.date.today()'),'Telegram parity must derive today from the property/server clock, not the CI runner timezone');
 assert.ok(telegramParityFull.includes("'command':'shift-open'")&&telegramParityFull.includes('Telegram parity has exactly one active server shift'),'Telegram parity must establish a real server shift before immediate check-in instead of bypassing the booking shift guard');
 const nodeAgentFull=fs.readFileSync(path.join(root,'node_sync_agent.php'),'utf8');
 const nodeRuntimeSupport="'/api/support/001_runtime_security.php'";
 const nodeAuditSupport="'020_identity_access_audit.php'";
 assert.ok(nodeAgentFull.includes(nodeRuntimeSupport),'standalone node sync agent must load canonical runtime error/audit helpers used by cluster code');
 assert.ok(nodeAgentFull.includes(nodeAuditSupport),'standalone node sync agent must load canonical enterprise audit primitives used by cluster leadership adoption');
 assert.ok(nodeAgentFull.indexOf(nodeRuntimeSupport)<nodeAgentFull.indexOf(nodeAuditSupport),'standalone node sync agent must load runtime security helpers before enterprise audit module dependencies');
 assert.ok(nodeAgentFull.includes("$deploymentIdentity=tamasyaDatabasePropertyIdentity($pdo,true);")&&nodeAgentFull.includes("$GLOBALS['tamasya_property_id']=$validatedPropertyId;"),'standalone node sync agent must establish the validated deployment property identity before required cluster audit writes');
 assert.ok(nodeAgentFull.includes("$GLOBALS['tamasya_runtime_database_name'] = (string)($dbConfig['name'] ?? '');"),'standalone node sync agent must expose its validated runtime database name to shared identity/scope helpers');
 const agentDbConnect=nodeAgentFull.indexOf("[$pdo,$rawError,$stage] = tamasyaConnectDatabase($dbConfig);");
 const agentDbTimezone=nodeAgentFull.indexOf("$pdo->exec('SET time_zone = '.$pdo->quote($agentDatabaseTimezoneOffset));");
 const agentDbSafety=nodeAgentFull.indexOf('tamasyaAssertDatabaseSafety($pdo,$dbConfig);');
 assert.ok(agentDbConnect>=0&&agentDbTimezone>agentDbConnect&&agentDbSafety>agentDbTimezone,'standalone node sync agent must align the MySQL session timezone with APP_TIMEZONE before database safety/cluster mirror work');
 assert.ok(nodeAgentFull.includes("SELECT @@session.time_zone")&&nodeAgentFull.includes("hash_equals($agentDatabaseTimezoneOffset, $activeDatabaseTimezoneOffset)")&&nodeAgentFull.includes("'databaseTimezoneOffset'=>(string)($GLOBALS['tamasya_database_timezone_offset']??'')"),'node sync must verify and expose the exact active MySQL session timezone instead of assuming PHP timezone also changes PDO');
 assert.ok(publicJourney.includes("isinstance(bad,dict) and bad.get('success') is False")&&publicJourney.includes("after_reject==before_reject"),'converted public-reservation rejection must assert the actual 409 body and immutable persisted state');
 const operational=fs.readFileSync(path.join(root,'tests/uat_rc1/scenario_operational_domains_complete.py'),'utf8');
 assert.ok(operational.includes("role IN ('manager','receptionist','finance') ORDER BY id LIMIT 1")&&!operational.includes("role IN ('manager','receptionist','finance') ORDER BY created_at,id LIMIT 1"),'shift handover fixture must order only by real Staff schema columns');
 assert.ok(operational.includes('request_operation_receipts')&&operational.includes("receipt[0]['status']=='completed'"),'shift report retry must prove durable operation receipt completion instead of relying on an undocumented response flag');
 assert.ok(!workflow.includes('wait "$(cat /tmp/tamasya-smtp-sink.pid)"'),'GitHub steps must not wait on a PID created by a different shell');
 assert.ok(workflow.includes('kill -0 "$SMTP_PID"'),'SMTP UAT must verify sink termination safely across GitHub step process boundaries');
 const smtpSink=fs.readFileSync(path.join(root,'tests/uat_rc1/smtp_sink.py'),'utf8');
 assert.ok(smtpSink.includes("default=0")&&smtpSink.includes("'--ready-file'")&&smtpSink.includes('srv.getsockname()[1]'),'SMTP sink must bind an OS-assigned free port and publish the port it actually owns');
 assert.ok(workflow.includes("smtp_sink.py --port 0 --ready-file")&&workflow.includes('echo "EFC_SMTP_PORT=$SMTP_PORT" >> "$GITHUB_ENV"'),'workflow must consume readiness from the exact SMTP child process instead of probing an unrelated fixed port');
 assert.ok(!workflow.includes("connect(('127.0.0.1',38192))")&&!operational.includes('smtp_port=38192'),'Full Complete SMTP UAT must not depend on collision-prone fixed port 38192');
 assert.ok(operational.includes("os.getenv('EFC_SMTP_PORT','').strip()")&&operational.includes('smtp_port=?'),'operational SMTP scenario must configure the application with the exact sink-owned ephemeral port');
 const gridWait=browserFull.indexOf("await expect(workspace.locator('.ui-master-grid')).toBeVisible({timeout:15000});");
 const controlCount=browserFull.indexOf("const workspaceControls=await workspace.locator('button:visible, input:visible, select:visible, textarea:visible').count();");
 assert.ok(gridWait>=0&&controlCount>gridWait,'browser navigation UAT must wait for async Master Data grid render before counting visible controls');
});

console.log(`${passed} passed; ${failed} failed`);process.exitCode=failed?1:0;
