import {test, expect} from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const BASE=(process.env.TAMASYA_UAT_BASE_URL||'http://127.0.0.1:38184').replace(/\/$/,'');
const LOGDIR=path.resolve('tests/uat_rc1/logs');
fs.mkdirSync(LOGDIR,{recursive:true});
const username=process.env.APP_BOOTSTRAP_ADMIN_USERNAME||'admin';
const password=process.env.APP_BOOTSTRAP_ADMIN_PASSWORD||'Tamasya-UAT-RC1-Only!';

function writeEvidence(name,info,data){
  fs.writeFileSync(path.join(LOGDIR,`browser-${name}-${info.project.name}.json`),JSON.stringify(data,null,2));
}

async function installAuth(page,request){
  const r=await request.post(`${BASE}/api.php?action=login`,{
    headers:{Origin:BASE,'X-Device-ID':'uat-browser-rc1','Content-Type':'application/json'},
    data:{username,password,offlineSessionScopeId:'offline_browser_rc1'}
  });
  expect(r.status()).toBe(200);
  const s=await r.json();
  expect(s.success).toBe(true);expect(s.token).toBeTruthy();
  expect(s.hotelScopeId).toBeTruthy();expect(s.offlineSessionScopeId).toBeTruthy();
  await page.addInitScript(({token,base,role,permissions,staffId,hotelScopeId,offlineSessionScopeId})=>{
    sessionStorage.setItem('hotel_logged_in','true');
    sessionStorage.setItem('hotel_session_token',token);
    sessionStorage.setItem('hotel_staff_role',role||'admin');
    sessionStorage.setItem('hotel_role',role||'admin');
    sessionStorage.setItem('hotel_staff_id',staffId||'');
    sessionStorage.setItem('hotel_permissions',JSON.stringify(permissions||{}));
    sessionStorage.setItem('hotel_offline_hotel_scope',String(hotelScopeId||'').trim().toLowerCase());
    sessionStorage.setItem('hotel_offline_session_scope',String(offlineSessionScopeId||'').trim().toLowerCase());
    sessionStorage.setItem('tamasya_active_api_url',base+'/api.php');
    localStorage.setItem('hotel_device_id','uat-browser-rc1');
  },{token:s.token,base:BASE,role:s.role,permissions:s.permissions,staffId:s.staffId,hotelScopeId:s.hotelScopeId,offlineSessionScopeId:s.offlineSessionScopeId});
  return s;
}

async function blockExternal(page){
  await page.route('**/*',route=>{
    const u=new URL(route.request().url());
    if(['127.0.0.1','localhost'].includes(u.hostname))return route.continue();
    return route.abort();
  });
}

async function waitLoaded(page){
  const loading=page.locator('#loading');
  if(await loading.count())await loading.waitFor({state:'hidden',timeout:15000}).catch(()=>{});
}

async function waitUiActionSettled(page,responsePromise,expectedStatus=200){
  const response=await responsePromise;
  expect(response.status()).toBe(expectedStatus);
  const loading=page.locator('#loading');
  if(await loading.count())await expect(loading).toBeHidden({timeout:15000});
  return response;
}

async function expectNoPageHorizontalOverflow(page){
  const size=await page.evaluate(()=>({clientWidth:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth,bodyScrollWidth:document.body?.scrollWidth||0}));
  expect(size.clientWidth).toBeGreaterThan(0);
  expect(size.scrollWidth).toBeLessThanOrEqual(size.clientWidth+1);
  expect(size.bodyScrollWidth).toBeLessThanOrEqual(size.clientWidth+1);
}


async function expectMetricContentContained(page){
  const issues=await page.locator('.tamasya-finance-summary > .glass-card:visible .tamasya-metric-value,.tamasya-dashboard-summary > .glass-card:visible .font-display').evaluateAll(values=>values.flatMap(value=>{
    const card=value.closest('.glass-card');const box=card.getBoundingClientRect();const range=document.createRange();range.selectNodeContents(value);
    return Array.from(range.getClientRects()).filter(r=>r.width&&r.height&&(r.left<box.left+1||r.right>box.right-1||r.top<box.top||r.bottom>box.bottom)).map(r=>({text:value.textContent,card:{left:box.left,right:box.right,bottom:box.bottom},textBounds:{left:r.left,right:r.right,bottom:r.bottom}}));
  }));
  expect(issues).toEqual([]);
}

async function ensureBrowserOpenShift(page){
  const result=await page.evaluate(async()=>{
    const token=sessionStorage.getItem('hotel_session_token')||'';
    const scope=sessionStorage.getItem('hotel_offline_session_scope')||'';
    const headers={Accept:'application/json',Authorization:'Bearer '+token,'X-Device-ID':'uat-browser-rc1','X-Tamasya-Offline-Session-Scope':scope};
    const read=async()=>{const r=await fetch('./api.php?action=operations-center',{headers,cache:'no-store'});return {status:r.status,body:await r.json()};};
    let state=await read();
    if(state.status!==200||state.body?.success!==true)return {ok:false,stage:'read-before',...state};
    let open=(state.body?.data?.shiftSessions||[]).find(x=>String(x.status||'').toLowerCase()==='open');
    if(!open){
      const op='browser_shift_'+(globalThis.crypto?.randomUUID?.()||Date.now());
      const r=await fetch('./api.php?action=operations-center',{method:'POST',headers:{...headers,'Content-Type':'application/json','X-Tamasya-Operation-ID':op},body:JSON.stringify({command:'shift-open',openingCash:100000,shiftTime:'siang',notes:'Browser POS UAT shift'})});
      const body=await r.json();
      if(r.status!==200||body?.success!==true)return {ok:false,stage:'open',status:r.status,body};
      state=await read();
      if(state.status!==200||state.body?.success!==true)return {ok:false,stage:'read-after',...state};
      open=(state.body?.data?.shiftSessions||[]).find(x=>String(x.status||'').toLowerCase()==='open');
    }
    return {ok:Boolean(open),shift:open||null};
  });
  expect(result.ok).toBe(true);
  expect(result.shift?.id).toBeTruthy();
  return result.shift;
}

test.beforeEach(async({page,request})=>{
  await blockExternal(page);
  await installAuth(page,request);
});

test('PMS interactive login, session persistence and module dock',async({browser},info)=>{
  const context=await browser.newContext({viewport:info.project.name==='mobile'?{width:390,height:844}:{width:1440,height:1000},isMobile:info.project.name==='mobile',hasTouch:info.project.name==='mobile',serviceWorkers:'block'});
  const page=await context.newPage();
  await blockExternal(page);
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(`${BASE}/index.html`);
  const user=page.getByPlaceholder('Contoh: admin, reps, finance, manager');
  const pass=page.getByPlaceholder('Masukkan password Anda...');
  const submit=page.getByRole('button',{name:'Masuk ke Konsol'});
  await expect(user).toBeVisible();await expect(pass).toBeVisible();await expect(submit).toBeVisible();
  await user.fill(username);await pass.fill(password);
  const login=page.waitForResponse(r=>r.url().includes('action=login')||r.url().endsWith('/api/login'));
  await submit.click();const response=await login;expect(response.status()).toBe(200);
  await expect.poll(()=>page.evaluate(()=>Boolean(sessionStorage.getItem('hotel_session_token')))).toBe(true);
  await page.reload();await expect.poll(()=>page.evaluate(()=>Boolean(sessionStorage.getItem('hotel_session_token')))).toBe(true);
  await expect(page.locator('#tamasya-pos-menu')).toHaveAttribute('href','./pos.html');
  await expect(page.locator('#tamasya-growth-menu')).toHaveAttribute('href','./growth-suite.html');
  await expect(page.locator('#tamasya-enterprise-menu')).toHaveAttribute('href','./enterprise-suite.html');
  await expect(page.locator('.tmd-heading #tamasya-memo-menu')).toHaveAttribute('href','./internal-memo.html');
  await expect(page.locator('#tamasya-memo-menu')).toHaveCount(1);
  await expect(page.locator('#tamasya-module-dock-grid > .tmd-card')).toHaveCount(3);
  await expectNoPageHorizontalOverflow(page);
  const moduleBounds=await page.locator('#tamasya-module-dock-grid > .tmd-card').evaluateAll(cards=>cards.map(card=>{const r=card.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width};}));
  if(info.project.name==='mobile'){
    expect(moduleBounds[1].y).toBeGreaterThan(moduleBounds[0].y);
    expect(moduleBounds[2].y).toBeGreaterThan(moduleBounds[1].y);
  }else{
    expect(Math.max(...moduleBounds.map(r=>r.y))-Math.min(...moduleBounds.map(r=>r.y))).toBeLessThan(2);
    expect(Math.max(...moduleBounds.map(r=>r.width))-Math.min(...moduleBounds.map(r=>r.width))).toBeLessThan(2);
  }
  await page.locator('#tab-dashboard').click();await expect(page.getByText(/^Target Pendapatan Tahun \d{4}$/)).toBeVisible();await expectNoPageHorizontalOverflow(page);
  await page.locator('#tab-finance').click();await expect(page.getByText('Saldo Kas Fisik · Semua Tanggal',{exact:true})).toBeVisible();await expect(page.getByText('Saldo Bank/QRIS/Kartu · Semua Tanggal',{exact:true})).toBeVisible();await expectNoPageHorizontalOverflow(page);await expectMetricContentContained(page);
  expect(errors).toEqual([]);
  writeEvidence('login-module-dock',info,{pass:true,modules:['pos','growth','enterprise']});
  await context.close();
});

test('PRD shared-hosting shell lazy-loads optional addons only when their capability is opened',async({page},info)=>{
  const requested=[];
  page.on('request',request=>{try{requested.push(new URL(request.url()).pathname);}catch{}});
  await page.goto('/index.html');
  await expect(page.locator('#tab-dashboard')).toBeVisible();
  await page.waitForTimeout(500);

  const seen=(suffix)=>requested.some(x=>x.endsWith(suffix));
  // Route-specific and operator-only addons must not inflate the initial dashboard shell.
  for(const suffix of [
    '/assets/canonical-report-center.js',
    '/assets/pos-report-archive-addon.js',
    '/assets/website-cms-guard.js',
    '/assets/website-gps-addon.js',
    '/assets/system-health-addon.js',
    '/assets/employee-self-service.js'
  ]) expect(seen(suffix),`unexpected eager request ${suffix}`).toBe(false);

  await page.locator('#tab-report').click();
  await expect.poll(()=>seen('/assets/canonical-report-center.js')).toBe(true);
  await expect.poll(()=>seen('/assets/pos-report-archive-addon.js')).toBe(true);

  await page.locator('#tab-website').click();
  await expect.poll(()=>seen('/assets/website-cms-guard.js')).toBe(true);
  await expect.poll(()=>seen('/assets/website-gps-addon.js')).toBe(true);

  expect(seen('/assets/system-health-addon.js')).toBe(false);
  await page.evaluate(()=>window.dispatchEvent(new CustomEvent('tamasya-open-system-health')));
  await expect.poll(()=>seen('/assets/system-health-addon.js')).toBe(true);
  await expect.poll(()=>page.evaluate(()=>Boolean(window.TamasyaSystemHealth))).toBe(true);

  writeEvidence('prd-lazy-addons',info,{pass:true,requested:[...new Set(requested.filter(x=>x.includes('/assets/')))]});
});

test('Main PMS navigation: every admin route opens and exposes controls without browser crash',async({page},info)=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/index.html');
  await expect(page.locator('#tab-dashboard')).toBeVisible();
  const visited=[];
  const topRoutes=[];
  for(const id of ['tab-dashboard','tab-finance','tab-report','tab-website']){
    const el=page.locator('#'+id);
    if(await el.count() && await el.isVisible()){
      const route=id.replace('tab-','');topRoutes.push(route);
      await el.click();await page.waitForTimeout(120);
      visited.push({kind:'top',route,controls:await page.locator('main button:visible, main input:visible, main select:visible, main textarea:visible').count()});
    }
  }
  expect(new Set(topRoutes)).toEqual(new Set(['dashboard','finance','report','website']));
  const renderedDomainItems=[];
  for(const domain of ['frontoffice','operations','hr','system']){
    const opener=page.locator(`#domain-${domain}`);
    if(!(await opener.count()) || !(await opener.isVisible()))continue;
    await opener.scrollIntoViewIfNeeded();
    await page.waitForTimeout(40);
    if((await opener.getAttribute('aria-expanded'))!=='true')await opener.click();
    await expect(opener).toHaveAttribute('aria-expanded','true');
    const menu=page.locator('[role="menu"][aria-label]:visible');
    await expect(menu).toHaveCount(1);
    const items=await menu.locator('[role="menuitem"]:visible').evaluateAll(xs=>xs.map(x=>({route:x.getAttribute('data-route')||'',label:(x.textContent||'').trim()})));
    expect(items.length).toBeGreaterThan(0);
    for(const itemDef of items){
      renderedDomainItems.push({domain,...itemDef});
      if((await opener.getAttribute('aria-expanded'))!=='true'){
        await opener.scrollIntoViewIfNeeded();await page.waitForTimeout(40);await opener.click();
        await expect(opener).toHaveAttribute('aria-expanded','true');
      }
      const item=itemDef.route?page.locator(`[role="menu"] [data-route="${itemDef.route}"]`):page.getByRole('menuitem',{name:itemDef.label,exact:true});
      await expect(item).toBeVisible();
      await item.click();await page.waitForTimeout(120);
      const isWorkspace=!itemDef.route;
      if(isWorkspace){
        const workspace=page.locator('#ui-core-master-data-workspace');
        await expect(workspace).toBeVisible();
        // open() exposes the shell before its async hotel-data fetch has rendered
        // the actual Master Data workspace. Wait for the completed grid, not the
        // visible loading shell, so first-load desktop timing cannot race the assertion.
        await expect(workspace.locator('.ui-master-grid')).toBeVisible({timeout:15000});
        await expect(workspace.locator('[data-master-close]')).toBeVisible();
        const workspaceControls=await workspace.locator('button:visible, input:visible, select:visible, textarea:visible').count();
        expect(workspaceControls).toBeGreaterThan(0);
        visited.push({kind:'workspace',domain,route:null,label:itemDef.label,controls:workspaceControls});
        const closeWorkspace=workspace.locator('[data-master-close]');
        await expect(closeWorkspace).toBeVisible();
        await closeWorkspace.click();
        await expect(workspace).toBeHidden();
        await expect(page.locator('#tab-dashboard')).toBeVisible();
      }else{
        visited.push({kind:'route',domain,route:itemDef.route,label:itemDef.label,controls:await page.locator('main button:visible, main input:visible, main select:visible, main textarea:visible').count()});
      }
    }
  }
  const renderedRoutes=renderedDomainItems.filter(x=>x.route).map(x=>x.route);
  const visitedRoutes=visited.filter(x=>x.route).map(x=>x.route);
  for(const route of renderedRoutes)expect(visitedRoutes).toContain(route);
  expect(renderedDomainItems.some(x=>!x.route)).toBe(true); // finance-catalog workspace is also exercised.
  expect(errors).toEqual([]);
  writeEvidence('main-pms-navigation',info,{pass:true,topRoutes,renderedDomainItems,visited});
});

// Every static control in the standalone enterprise pages is exercised at least at wiring level.
// This is intentionally separate from business assertions: a click alone never certifies money/accounting correctness.
const staticPages=[
  ['property-setup.html',20],['pos.html',45],['internal-memo.html',10],['growth-suite.html',80],['enterprise-suite.html',85],['multi-property-foundation.html',8]
];
for(const [file,minControls] of staticPages){
  test(`${file}: complete static control wiring sweep`,async({page},info)=>{
    const errors=[];const serverErrors=[];
    page.on('pageerror',e=>errors.push(e.message));
    page.on('response',r=>{try{const u=new URL(r.url());if(['127.0.0.1','localhost'].includes(u.hostname)&&/\/api\.php$/.test(u.pathname)&&r.status()>=500)serverErrors.push({status:r.status(),url:r.url(),method:r.request().method()});}catch{}});
    page.on('dialog',d=>d.dismiss().catch(()=>{}));
    await page.goto('/'+file);await waitLoaded(page);
    await page.evaluate(()=>{window.print=()=>{window.__uatPrintCount=(window.__uatPrintCount||0)+1;};});
    const controls=await page.locator('button,input:not([type="hidden"]),select,textarea,a[href]').evaluateAll(xs=>xs.map((x,i)=>({
      i,tag:x.tagName.toLowerCase(),id:x.id||null,type:x.getAttribute('type')||null,text:(x.innerText||x.getAttribute('aria-label')||x.getAttribute('placeholder')||'').trim().slice(0,120),
      dataTab:x.getAttribute('data-tab'),dataView:x.getAttribute('data-view'),href:x.getAttribute('href')
    })));
    expect(controls.length).toBeGreaterThanOrEqual(minControls);
    // Trigger every button handler. Invalid/empty forms are allowed to reject; browser crashes are not.
    // Snapshot the actual buttons that exist at load time. Some handlers (notably
    // Internal Memo archive/restore) intentionally re-render their container.
    // Re-querying button:nth(i) after every click can then wait for an index that
    // no longer exists and burn the entire 90s Playwright timeout. ElementHandles
    // keep the original wiring target stable even after DOM replacement, so the
    // sweep still invokes every initially rendered handler without locator races.
    const buttonHandles=await page.locator('button').elementHandles();
    const buttonCount=buttonHandles.length;
    for(const b of buttonHandles){
      await b.evaluate(el=>{try{el.click()}catch(e){window.__uatClickErrors=(window.__uatClickErrors||[]).concat(String(e))}}).catch(()=>{});
      await page.waitForTimeout(15);
      await b.dispose().catch(()=>{});
    }
    // Exercise value/control event bindings without inventing business data.
    await page.locator('input:not([type="hidden"]),select,textarea').evaluateAll(xs=>xs.forEach(el=>{
      try{
        el.dispatchEvent(new Event('focus',{bubbles:true}));
        if(el.type==='checkbox'){el.checked=!el.checked;el.dispatchEvent(new Event('change',{bubbles:true}));el.checked=!el.checked;el.dispatchEvent(new Event('change',{bubbles:true}));}
        else if(el.tagName==='SELECT'&&el.options.length){el.selectedIndex=Math.min(1,el.options.length-1);el.dispatchEvent(new Event('change',{bubbles:true}));}
        el.dispatchEvent(new Event('blur',{bubbles:true}));
      }catch(e){window.__uatControlErrors=(window.__uatControlErrors||[]).concat(String(e))}
    }));
    const localErrors=await page.evaluate(()=>({click:window.__uatClickErrors||[],controls:window.__uatControlErrors||[]}));
    expect(localErrors.click).toEqual([]);expect(localErrors.controls).toEqual([]);expect(errors).toEqual([]);expect(serverErrors).toEqual([]);
    writeEvidence('static-'+file.replaceAll('/','-'),info,{pass:true,file,controls:controls.length,buttons:buttonCount,inventory:controls,serverErrors});
  });
}

test('Staff management exposes Owner read-only role to Admin',async({page},info)=>{
  await page.goto('/index.html');
  const hr=page.locator('#domain-hr');await hr.scrollIntoViewIfNeeded();if((await hr.getAttribute('aria-expanded'))!=='true')await hr.click();await expect(hr).toHaveAttribute('aria-expanded','true');
  const staffItem=page.locator('[role="menu"] [data-route="staff"]');await expect(staffItem).toBeVisible();await staffItem.click();
  const ownerOption=page.locator('form select option[value="owner"]');await expect(ownerOption).toHaveCount(1);await expect(ownerOption).toHaveText(/Owner.*Tidak Bisa Mengubah/i);
  writeEvidence('staff-owner-role-option',info,{pass:true});
});

test('Property setup: save, refresh, finalize and persisted reload',async({page},info)=>{
  await page.goto('/property-setup.html');
  const name=page.locator('#propertyName');await expect(name).toHaveValue(/.+/);
  const original=await name.inputValue();const candidate=`SIMULATION HOTEL ${info.project.name}`;
  await name.fill(candidate);
  const save=page.waitForResponse(r=>r.url().includes('action=property-setup')&&r.url().includes('command=save'));
  await page.locator('#save').click();expect((await save).status()).toBe(200);
  await page.reload();await expect(name).toHaveValue(candidate);
  const refresh=page.waitForResponse(r=>r.url().includes('action=property-setup')&&r.url().includes('command=status'));
  await page.locator('#refresh').click();await waitUiActionSettled(page,refresh);
  await name.fill(original);
  const restore=page.waitForResponse(r=>r.url().includes('action=property-setup')&&r.url().includes('command=save'));
  await page.locator('#save').click();expect((await restore).status()).toBe(200);
  await expect(page.locator('#finalize')).toBeEnabled();
  const finalized=page.waitForResponse(r=>r.url().includes('action=property-setup')&&r.url().includes('command=finalize'));
  await page.locator('#finalize').click();expect((await finalized).status()).toBe(200);
  writeEvidence('property-setup-behavior',info,{pass:true});
});

test('POS UI: tabs, product, cart, sale, receipt, void, stock and category lifecycle',async({page},info)=>{
  await page.goto('/pos.html');await waitLoaded(page);const posShift=await ensureBrowserOpenShift(page);expect(String(posShift.status).toLowerCase()).toBe('open');await page.reload();await waitLoaded(page);await page.evaluate(()=>{window.print=()=>{window.__uatPrintCount=(window.__uatPrintCount||0)+1;};});await expect(page.locator('#product-grid [data-product]').first()).toBeVisible();
  for(const view of ['cashier','products','stock','sales','deliveries']){await page.locator(`[data-view="${view}"]`).click();await expect(page.locator(`#view-${view}`)).toBeVisible();}
  await page.locator('[data-view="products"]').click();await page.locator('#add-product-btn').click();await expect(page.locator('#product-dialog')).toBeVisible();await page.locator('#cancel-product-dialog').click();
  await page.locator('#add-product-btn').click();
  const sku=`UI-${info.project.name}-${Date.now()}`;await page.locator('#product-sku').fill(sku);await page.locator('#product-name').fill('UAT '+sku);await page.locator('#product-cost').fill('1000');await page.locator('#product-price').fill('2000');await page.locator('#product-initial-stock').fill('5');
  const saved=page.waitForResponse(r=>r.url().includes('action=pos-product-save')&&r.request().method()==='POST');await page.locator('#save-product').click();expect((await saved).status()).toBe(200);
  await page.locator('[data-view="cashier"]').click();await page.locator('#product-search').fill(sku);const product=page.locator('#product-grid [data-product]').filter({hasText:sku});await expect(product).toHaveCount(1);await product.click();await expect(page.locator('#checkout-btn')).toBeEnabled();
  page.once('dialog',d=>{expect(d.type()).toBe('confirm');d.accept();});
  const sold=page.waitForResponse(r=>r.url().includes('action=pos-sale-create')&&r.request().method()==='POST');await page.locator('#checkout-btn').click();const sr=await sold;expect(sr.status()).toBe(200);const sale=(await sr.json()).sale;expect(sale?.receiptNumber).toBeTruthy();
  await expect(page.locator('#receipt-dialog')).toBeVisible();await page.locator('#print-receipt').click();await page.locator('#close-receipt').click();
  await page.locator('[data-view="sales"]').click();await expect(page.locator('#sales-to')).toHaveValue(sale.saleDate);expect((await page.locator('#sales-from').inputValue())<=sale.saleDate).toBe(true);
  const listed=page.waitForResponse(r=>r.url().includes('action=pos-sales'));await page.locator('#load-sales').click();expect((await listed).status()).toBe(200);
  const row=page.locator('#sales-table tr').filter({hasText:sale.receiptNumber});await expect(row).toBeVisible();
  const voidDialogs=async d=>{if(d.type()==='prompt')await d.accept('UAT void reversal');else if(d.type()==='confirm')await d.accept();else await d.dismiss();};
  page.on('dialog',voidDialogs);
  try{const voided=page.waitForResponse(r=>r.url().includes('action=pos-sale-void'));await row.getByRole('button',{name:'Void'}).click();expect((await voided).status()).toBe(200);}finally{page.off('dialog',voidDialogs);}
  await page.locator('[data-view="stock"]').click();const option=page.locator('#stock-product option').filter({hasText:sku});const pid=await option.getAttribute('value');await page.locator('#stock-product').selectOption(pid);await page.locator('#stock-delta').fill('2');await page.locator('#stock-reason').fill('UAT browser stock');const adjusted=page.waitForResponse(r=>r.url().includes('action=pos-stock-adjust'));await page.locator('#stock-form button[type="submit"]').click();expect((await adjusted).status()).toBe(200);
  await page.locator('[data-view="products"]').click();await page.locator('#manage-categories-btn').click();const category=`UI Category ${Date.now()}`;await page.locator('#category-name').fill(category);const cs=page.waitForResponse(r=>r.url().includes('action=pos-category-save'));await page.locator('#save-category').click();expect((await cs).status()).toBe(200);await expect(page.locator('#categories-table')).toContainText(category);await page.locator('#cancel-category-dialog').click();
  writeEvidence('pos-lifecycle',info,{pass:true,sku,receipt:sale.receiptNumber});
});

test('Growth Suite UI: all tabs plus active controls and every business form submits',async({page},info)=>{
  await page.goto('/growth-suite.html');await waitLoaded(page);await expect(page.locator('#tabs')).toBeVisible();await expectNoPageHorizontalOverflow(page);
  const ping=await page.evaluate(async()=>{const r=await fetch('./api.php?action=ping',{cache:'no-store'});return {status:r.status,body:await r.json()};});
  expect(ping.status).toBe(200);expect(ping.body.databaseConnected).toBe(true);
  const boundScope=await page.evaluate(()=>sessionStorage.getItem('hotel_offline_hotel_scope')||'');
  expect(String(ping.body.hotelScopeId||'').toLowerCase()).toBe(String(boundScope).toLowerCase());
  await expect(page.locator('#server-pill')).toContainText(/Primary|Gateway/,{timeout:15000});
  for(const tab of ['overview','kpi','rate','group','folio','procurement','integrations']){await expect(page.locator(`[data-tab="${tab}"]`)).toBeVisible();await page.locator(`[data-tab="${tab}"]`).click();await expect(page.locator(`#tab-${tab}`)).toBeVisible();}
  await page.locator('[data-tab="overview"]').click();await expect(page.locator('#tab-overview')).toBeVisible();
  const refresh=page.waitForResponse(r=>r.url().includes('action=growth-suite')&&r.url().includes('command=bootstrap'));await page.locator('#refresh-bootstrap').click();await waitUiActionSettled(page,refresh);
  await page.locator('[data-tab="kpi"]').click();const kpi=page.waitForResponse(r=>r.url().includes('command=kpis'));await page.locator('#load-kpi').click();await waitUiActionSettled(page,kpi);
  await page.locator('[data-tab="rate"]').click();const code=`UIR${Date.now()}`;await page.locator('#rate-code').fill(code);await page.locator('#rate-name').fill('UI Rate');const roomTypeOption=page.locator('#rate-room-type option').filter({hasText:'SIM Deluxe'});await expect(roomTypeOption).toHaveCount(1);const roomTypeValue=await roomTypeOption.getAttribute('value');expect(roomTypeValue).toBeTruthy();await page.locator('#rate-room-type').selectOption(roomTypeValue);await page.locator('#rate-base').fill('200000');await page.locator('#rate-min').fill('150000');await page.locator('#rate-max').fill('300000');const rate=page.waitForResponse(r=>r.url().includes('command=rate-plan-save'));await page.locator('#rate-plan-form button[type="submit"]').click();await waitUiActionSettled(page,rate);
  await expect(page.locator('#suggest-plan option')).not.toHaveCount(1);await page.locator('#suggest-plan').selectOption({index:1});const suggestion=page.waitForResponse(r=>r.url().includes('command=rate-suggestion'));await page.locator('#run-suggestion').click();await waitUiActionSettled(page,suggestion);
  await page.locator('#rule-plan').selectOption({index:1});await page.locator('#rule-name').fill('UI Rule');await page.locator('#rule-value').fill('5');const rule=page.waitForResponse(r=>r.url().includes('command=rate-rule-save'));await page.locator('#rate-rule-form button[type="submit"]').click();await waitUiActionSettled(page,rule);
  await page.locator('#override-plan').selectOption({index:1});await page.locator('#override-rate').fill('210000');const over=page.waitForResponse(r=>r.url().includes('command=rate-override-save'));await page.locator('#rate-override-form button[type="submit"]').click();await waitUiActionSettled(page,over);
  await page.locator('[data-tab="group"]').click();await page.locator('#company-code').fill(`UIC${Date.now()}`);await page.locator('#company-name').fill('UI Corporate');const company=page.waitForResponse(r=>r.url().includes('command=company-save'));await page.locator('#company-form button[type="submit"]').click();await waitUiActionSettled(page,company);
  await page.locator('#group-name').fill('UI Group');await page.locator('#group-room-block').fill('1');const group=page.waitForResponse(r=>r.url().includes('command=group-save'));await page.locator('#group-form button[type="submit"]').click();await waitUiActionSettled(page,group);
  await page.locator('#booking-search').fill('SIM');const bs=page.waitForResponse(r=>r.url().includes('command=booking-search'));await page.locator('#do-booking-search').click();await waitUiActionSettled(page,bs);
  await page.locator('[data-tab="folio"]').click();await page.locator('#folio-search').fill('SIM');const fsr=page.waitForResponse(r=>r.url().includes('command=booking-search'));await page.locator('#folio-search-btn').click();await waitUiActionSettled(page,fsr);
  const folioChoice=page.locator('#folio-search-results [data-select-booking]').first();await expect(folioChoice).toBeVisible();const folio=page.waitForResponse(r=>r.url().includes('action=growth-suite')&&r.url().includes('command=folio'));await folioChoice.click();await waitUiActionSettled(page,folio);await expect(page.locator('#folio-detail .folio-print')).toBeVisible();await expect(page.locator('#print-folio')).toBeEnabled();await page.evaluate(()=>{window.print=()=>{window.__printed=true}});await page.locator('#print-folio').click();expect(await page.evaluate(()=>window.__printed===true)).toBe(true);
  await page.locator('[data-tab="procurement"]').click();await page.locator('#vendor-code').fill(`UIV${Date.now()}`);await page.locator('#vendor-name').fill('UI Vendor');const vendor=page.waitForResponse(r=>r.url().includes('command=vendor-save'));await page.locator('#vendor-form button[type="submit"]').click();await waitUiActionSettled(page,vendor);await page.locator('#po-vendor').selectOption({index:1});await page.locator('#po-item').fill('UI supplies');await page.locator('#po-qty').fill('1');await page.locator('#po-price').fill('1000');const po=page.waitForResponse(r=>r.url().includes('command=po-save'));await page.locator('#po-form button[type="submit"]').click();await waitUiActionSettled(page,po);
  await page.locator('[data-tab="integrations"]').click();const channelCode=`uat-ui-${info.project.name}`;await page.locator('#channel-code').fill(channelCode);await page.locator('#channel-external-room').fill('DELUXE');await page.locator('#channel-internal-room').selectOption({index:1});await page.locator('#channel-external-rate').fill('BAR');const mapping=page.waitForResponse(r=>r.url().includes('command=channel-mapping-save'));await page.locator('#channel-form button[type="submit"]').click();await waitUiActionSettled(page,mapping);await expectNoPageHorizontalOverflow(page);
  const bookingId=await page.evaluate(async()=>{const r=await fetch('./api.php?action=hotel-data',{headers:{Authorization:'Bearer '+sessionStorage.getItem('hotel_session_token'),'X-Device-ID':'uat-browser-rc1','X-Tamasya-Offline-Session-Scope':sessionStorage.getItem('hotel_offline_session_scope')||''}});const d=await r.json();return (d.bookings||[]).find(x=>Number(x.balanceDue)>0)?.id||''});
  expect(bookingId).toBeTruthy();await page.locator('#payment-booking').fill(bookingId);await page.locator('#payment-provider').fill('uat-ui');await page.locator('#payment-amount').fill('1');const pi=page.waitForResponse(r=>r.url().includes('command=payment-intent-create'));await page.locator('#payment-intent-form button[type="submit"]').click();await waitUiActionSettled(page,pi);
  writeEvidence('growth-suite-forms',info,{pass:true});
});

test('Internal Memo UI: admin create, search, archive and restore without hard delete',async({page},info)=>{
  await page.goto('/internal-memo.html');
  await expect(page.locator('#memo-editor-panel')).toBeVisible();
  const title=`Browser Memo ${info.project.name} ${Date.now()}`;
  await page.locator('#memo-title').fill(title);await page.locator('#memo-category').selectOption('finance');await page.locator('#memo-priority').selectOption('high');await page.locator('#memo-body').fill('Browser UAT memo with auditable archive lifecycle.');
  const created=page.waitForResponse(r=>r.url().includes('action=internal-memos')&&r.request().method()==='POST');await page.locator('#memo-form button[type="submit"]').click();expect((await created).status()).toBe(200);await expect(page.locator('#memo-list')).toContainText(title);
  await page.locator('#memo-q').fill(title);await page.locator('#memo-q').press('Enter');await expect(page.locator('#memo-list .memo')).toHaveCount(1);
  const archived=page.waitForResponse(r=>r.url().includes('action=internal-memos')&&r.request().method()==='PUT');await page.getByRole('button',{name:'Arsipkan'}).click();expect((await archived).status()).toBe(200);
  await page.locator('#memo-status').selectOption('archived');await expect(page.locator('#memo-list')).toContainText(title);
  const restored=page.waitForResponse(r=>r.url().includes('action=internal-memos')&&r.request().method()==='PUT');await page.getByRole('button',{name:'Aktifkan Kembali'}).click();expect((await restored).status()).toBe(200);
  await page.locator('#memo-status').selectOption('active');await expect(page.locator('#memo-list')).toContainText(title);
  writeEvidence('memo-lifecycle',info,{pass:true,title});
});

test('Owner UI is all-read and mutation controls stay unavailable',async({page,request,browser},info)=>{
  // Navigate to the application origin first so the beforeEach init script has
  // installed the authenticated Admin session before sessionStorage is read.
  await page.goto('/index.html');await expect(page.locator('#tamasya-pos-menu')).toBeVisible();
  // Create Owner through the same Staff API used by production administration.
  const uname=`browser_owner_${info.project.name}_${Date.now()}`.replace(/[^a-zA-Z0-9_]/g,'_');const pwd='Owner-Browser-UAT!42x';
  const create=await page.evaluate(async({uname,pwd})=>{const r=await fetch('./api.php?action=staff',{method:'POST',headers:{'Content-Type':'application/json',Authorization:'Bearer '+sessionStorage.getItem('hotel_session_token'),'X-Device-ID':'uat-browser-rc1','X-Tamasya-Offline-Session-Scope':sessionStorage.getItem('hotel_offline_session_scope')||'','X-Tamasya-Operation-ID':'browser-owner-create-'+Date.now()},body:JSON.stringify({name:'Browser Owner Read Only',username:uname,password:pwd,role:'owner',salary:0})});return {status:r.status,body:await r.json()};},{uname,pwd});
  expect(create.status).toBe(200);
  const login=await request.post(`${BASE}/api.php?action=login`,{headers:{Origin:BASE,'X-Device-ID':'uat-browser-owner','Content-Type':'application/json'},data:{username:uname,password:pwd,offlineSessionScopeId:'offline_browser_owner_rc1'}});expect(login.status()).toBe(200);const o=await login.json();expect(o.role).toBe('owner');
  const ctx=await browser.newContext({viewport:info.project.name==='mobile'?{width:390,height:844}:{width:1440,height:1000},isMobile:info.project.name==='mobile',hasTouch:info.project.name==='mobile',serviceWorkers:'block'});const op=await ctx.newPage();await blockExternal(op);
  await op.addInitScript(({o,base})=>{sessionStorage.setItem('hotel_logged_in','true');sessionStorage.setItem('hotel_session_token',o.token);sessionStorage.setItem('hotel_staff_role','owner');sessionStorage.setItem('hotel_role','owner');sessionStorage.setItem('hotel_staff_id',o.staffId||'');sessionStorage.setItem('hotel_permissions',JSON.stringify(o.permissions||{}));sessionStorage.setItem('hotel_offline_hotel_scope',String(o.hotelScopeId||'').toLowerCase());sessionStorage.setItem('hotel_offline_session_scope',String(o.offlineSessionScopeId||'').toLowerCase());sessionStorage.setItem('tamasya_active_api_url',base+'/api.php');localStorage.setItem('hotel_device_id','uat-browser-owner');},{o,base:BASE});
  await op.goto('/index.html');await expect(op.locator('#tamasya-pos-menu')).toBeVisible();await expect(op.locator('#tamasya-memo-menu')).toBeVisible();await expect(op.locator('#tamasya-growth-menu')).toBeVisible();await expect(op.locator('#tamasya-enterprise-menu')).toBeVisible();
  await expect(op.locator('.tamasya-owner-banner')).toBeVisible();await expectNoPageHorizontalOverflow(op);
  await expect(op.locator('#tamasya-system-health-launch')).toBeVisible();
  await op.locator('#domain-operations').click();await op.locator('[role="menuitem"][data-route="operations"]').click();
  for(const key of ['calendar','housekeeping','maintenance','shifts','approvals','audit','sessions','sync','journal','reconciliation','tax','guests','monitoring','backup','data_cleanup'])await expect(op.locator(`[data-operation-key="${key}"]`)).toBeVisible();
  await op.locator('[data-operation-key="journal"]').click();await expect(op.getByText('Total debit jurnal',{exact:true})).toBeVisible();
  await op.locator('#tab-finance').click();await op.getByTestId('finance-filter-from').fill('2026-01-28');await expect(op.getByTestId('finance-filter-from')).toBeEnabled();
  await op.locator('#tamasya-report-center-btn').click();await expect(op.locator('.tamasya-report-actions [data-format="pdf"]')).toBeEnabled();await expect(op.locator('[data-r="send"]')).toBeDisabled();
  const ownerDownload=op.waitForEvent('download');await op.locator('.tamasya-report-actions [data-format="pdf"]').click();const ownerPdf=await ownerDownload;expect(ownerPdf.suggestedFilename()).toMatch(/\.pdf$/i);expect(await ownerPdf.failure()).toBeNull();
  const modalBounds=await op.locator('.tamasya-report-modal').boundingBox();expect(modalBounds.x).toBeGreaterThanOrEqual(0);expect(modalBounds.x+modalBounds.width).toBeLessThanOrEqual(info.project.name==='mobile'?390:1440);
  await op.goto('/internal-memo.html');await expect(op.locator('#owner-banner')).toBeVisible();await expect(op.locator('#memo-editor-panel')).toBeVisible();await expect(op.locator('#memo-form button[type=submit]')).toBeDisabled();await expect(op.locator('#memo-q')).toBeEnabled();
  await op.goto('/growth-suite.html');await waitLoaded(op);await expect(op.locator('#tabs')).toBeVisible();await op.locator('#tabs [data-tab="rate"]').click();await expect(op.locator('#rate-plan-form')).toBeVisible();expect(await op.locator('form:visible').count()).toBeGreaterThan(0);expect(await op.locator('form button[type=submit]:enabled').count()).toBe(0);
  await op.goto('/index.html');const enterpriseLink=op.locator('#tamasya-enterprise-menu');await expect(enterpriseLink).toHaveAttribute('href','./enterprise-suite.html');
  const enterpriseBootstrap=op.waitForResponse(r=>r.url().includes('action=enterprise-suite')&&r.url().includes('command=bootstrap'));await enterpriseLink.click();const enterpriseResponse=await enterpriseBootstrap;expect(enterpriseResponse.status()).toBe(200);const enterpriseData=(await enterpriseResponse.json()).data;expect(enterpriseData.features.enabled).toBe(true);expect(enterpriseData.features.schemaReady).toBe(true);await waitLoaded(op);await expect(op.locator('#tabs')).toBeVisible();
  for(const tab of ['overview','folio','ap','crm','health','adapters']){await op.locator(`#tabs [data-tab="${tab}"]`).click();await expect(op.locator(`#tab-${tab}`)).toBeVisible();}
  await op.locator('#tabs [data-tab="folio"]').click();await expect(op.locator('#folio-create-form')).toBeVisible();expect(await op.locator('form button[type=submit]:enabled').count()).toBe(0);await expect(op.locator('#folio-select')).toBeEnabled();await expect(op.locator('#folio-load')).toBeEnabled();await expect(op.locator('#folio-sync-charges')).toBeDisabled();await expect(op.locator('#folio-invoice')).toBeDisabled();
  if(enterpriseData.folios.length){await op.locator('#folio-select').selectOption(enterpriseData.folios[0].id);const detail=op.waitForResponse(r=>r.url().includes('command=folio-detail'));await op.locator('#folio-load').click();expect((await detail).status()).toBe(200);await waitLoaded(op);expect(await op.locator('[data-charge-booking]:enabled,[data-pay-tx]:enabled').count()).toBe(0);}
  await op.locator('#tabs [data-tab="ap"]').click();expect(await op.locator('[data-post-inv]:enabled,[data-pay-inv]:enabled,[data-pr-status]:enabled').count()).toBe(0);
  for(const attr of ['data-po-detail','data-grn-detail','data-sinv-detail']){const button=op.locator(`[${attr}]`).first();if(await button.count()){await expect(button).toBeEnabled();const detail=op.waitForResponse(r=>r.url().includes('action=enterprise-suite')&&/command=(po-detail|grn-detail|supplier-invoice-detail)/.test(r.url()));await button.click();expect((await detail).status()).toBe(200);await waitLoaded(op);await expect(op.locator('#ap-detail')).toContainText('Detail');}}
  await op.locator('#tabs [data-tab="crm"]').click();expect(await op.locator('[data-camp-approve]:enabled,[data-camp-snapshot]:enabled,[data-camp-send]:enabled,[data-voucher-redeem]:enabled').count()).toBe(0);await expect(op.locator('#guest-q')).toBeEnabled();await expect(op.locator('#guest-search')).toBeEnabled();
  await op.goto('/pos.html');await waitLoaded(op);await expect(op.locator('#checkout-btn')).toBeDisabled();await expect(op.locator('#add-product-btn')).toBeDisabled();
  const denied=await op.evaluate(async()=>{const r=await fetch('./api.php?action=internal-memos',{method:'POST',headers:{'Content-Type':'application/json',Authorization:'Bearer '+sessionStorage.getItem('hotel_session_token'),'X-Device-ID':'uat-browser-owner','X-Tamasya-Offline-Session-Scope':sessionStorage.getItem('hotel_offline_session_scope')||'','X-Tamasya-Operation-ID':'browser-owner-denied-'+Date.now()},body:JSON.stringify({command:'create',title:'must fail',body:'must fail'})});return {status:r.status,body:await r.json()};});expect(denied.status).toBe(403);expect(denied.body.code).toBe('OWNER_READ_ONLY');
  writeEvidence('owner-read-only',info,{pass:true,username:uname});await ctx.close();
});

test('Enterprise Suite UI: all tabs, refresh, finance views and representative forms',async({page},info)=>{
  await page.goto('/enterprise-suite.html');await waitLoaded(page);await expect(page.locator('#tabs')).toBeVisible();
  for(const tab of ['overview','folio','ap','crm','health','adapters']){await page.locator(`[data-tab="${tab}"]`).click();await expect(page.locator(`#tab-${tab}`)).toBeVisible();}
  await page.locator('[data-tab="overview"]').click();await expect(page.locator('#tab-overview')).toBeVisible();
  const refresh=page.waitForResponse(r=>r.url().includes('action=enterprise-suite')&&r.url().includes('command=bootstrap'));await page.locator('#refresh').click();expect((await refresh).status()).toBe(200);
  const forecast=page.waitForResponse(r=>r.url().includes('command=revenue-forecast'));await page.locator('#forecast-refresh').click();expect((await forecast).status()).toBe(200);
  const accounting=page.waitForResponse(r=>r.url().includes('command=accounting-summary'));await page.locator('#accounting-refresh').click();expect((await accounting).status()).toBe(200);
  await page.locator('[data-tab="ap"]').click();await page.locator('#pr-dept').fill('UI');await page.locator('#pr-item').fill('UI PR Item');await page.locator('#pr-qty').fill('1');await page.locator('#pr-price').fill('1000');await page.locator('#pr-reason').fill('UI UAT');const pr=page.waitForResponse(r=>r.url().includes('command=pr-save'));await page.locator('#pr-form button[type="submit"]').click();expect((await pr).status()).toBe(200);
  await page.locator('[data-tab="crm"]').click();await page.locator('#guest-q').fill('SIM');const gs=page.waitForResponse(r=>r.url().includes('command=guest-search'));await page.locator('#guest-search').click();expect((await gs).status()).toBe(200);
  const guestButton=page.locator('#guest-results [data-guest]').first();await expect(guestButton).toBeVisible();const guest=await guestButton.getAttribute('data-guest');expect(guest).toBeTruthy();await guestButton.click();await expect(page.locator('#consent-guest')).toHaveValue(guest);await expect(page.locator('#points-guest')).toHaveValue(guest);
  await page.locator('#consent-evidence').fill('UI UAT');const c=page.waitForResponse(r=>r.url().includes('command=consent-save'));await page.locator('#consent-form button[type="submit"]').click();expect((await c).status()).toBe(200);await page.locator('#points-value').fill('5');await page.locator('#points-reason').fill('UI UAT');const p=page.waitForResponse(r=>r.url().includes('command=loyalty-adjust'));await page.locator('#points-form button[type="submit"]').click();expect((await p).status()).toBe(200);
  await page.locator('#segment-code').fill(`UIS${Date.now()}`);await page.locator('#segment-name').fill('UI Segment');const seg=page.waitForResponse(r=>r.url().includes('command=crm-segment-save'));await page.locator('#segment-form button[type="submit"]').click();expect((await seg).status()).toBe(200);await waitLoaded(page);
  await page.locator('#campaign-name').fill('UI Campaign');await page.locator('#campaign-subject').fill('UI');await page.locator('#campaign-message').fill('UI {{guest_name}}');const camp=page.waitForResponse(r=>r.url().includes('command=crm-campaign-save'));await page.locator('#campaign-form button[type="submit"]').click();expect((await camp).status()).toBe(200);
  await page.locator('[data-tab="health"]').click();const health=page.waitForResponse(r=>r.url().includes('action=enterprise-suite')&&r.url().includes('command=bootstrap'));await page.locator('#health-refresh').click();expect((await health).status()).toBe(200);await waitLoaded(page);await page.locator('#health-code').fill(`UI_${Date.now()}`);await page.locator('#health-metric').fill('unbalanced_journal_entries');await page.locator('#health-threshold').fill('0');const hr=page.waitForResponse(r=>r.url().includes('command=health-rule-save'));await page.locator('#health-rule-form button[type="submit"]').click();expect((await hr).status()).toBe(200);await waitLoaded(page);
  await page.locator('[data-tab="adapters"]').click();await page.locator('#adapter-provider').fill(`ui-${Date.now()}`);await page.locator('#adapter-mode').selectOption('sandbox');await page.locator('#adapter-verifier').fill('hmac_sha256');await page.locator('#adapter-active').check();await page.locator('#adapter-config').fill('{"uat":true}');const ad=page.waitForResponse(r=>r.url().includes('command=provider-adapter-save'));await page.locator('#adapter-form button[type="submit"]').click();expect((await ad).status()).toBe(200);
  writeEvidence('enterprise-suite-forms',info,{pass:true});
});

test('Multi-property buttons: preview, snapshot, queue, manifest, contracts, outbox and disabled bridge',async({page},info)=>{
  await page.goto('/multi-property-foundation.html');await expect(page.locator('#status-cards .card')).toHaveCount(5);
  for(const [id,command] of [['refresh','overview'],['preview','summary-preview'],['manifest','manifest'],['contracts','capabilities'],['outbox','outbox']]){const r=page.waitForResponse(x=>x.url().includes('action=multi-property')&&x.url().includes(`command=${command}`));await page.locator('#'+id).click();expect((await r).status()).toBe(200);}
  const [snap,download]=await Promise.all([page.waitForResponse(r=>r.url().includes('command=snapshot-v2')),page.waitForEvent('download'),page.locator('#snapshot-v2').click()]);expect(snap.status()).toBe(200);expect(await download.path()).toBeTruthy();
  const queued=page.waitForResponse(r=>r.url().includes('command=queue-snapshot'));await page.locator('#queue-snapshot').click();expect([200,202]).toContain((await queued).status());
  const push=page.waitForResponse(r=>r.url().includes('command=push-summary'));await page.locator('#push-hq').click();expect((await push).status()).toBe(409);
  writeEvidence('multi-property-controls',info,{pass:true});
});

test('Financial graph reconciles January 28 unknown PBJT with cash, bank and QRIS receipts',async({page},info)=>{
  const make=(id,amount,extra={})=>({id,type:'income',date:'2026-01-28',category:'Room receipt',categorySystemKey:'room_rental',transactionKind:'manual',amount,baseAmount:amount/1.1,taxAmount:amount-amount/1.1,taxRate:10,taxSnapshotStatus:'confirmed',recordOrigin:'historical_import',...extra});
  const transactions=[make('uat-known',2241000),make('uat-unknown-a',250000,{baseAmount:null,taxAmount:null,taxSnapshotStatus:'unresolved'}),make('uat-unknown-b',220000,{baseAmount:null,taxAmount:null,taxSnapshotStatus:'unresolved'}),make('uat-transfer',220000,{bankAccountId:'uat-transfer'}),make('uat-qris',220000,{bankAccountId:'uat-qris'}),{id:'uat-cost',type:'expense',date:'2026-01-28',amount:1640000,taxSnapshotStatus:'not_applicable'}];
  const bankAccounts=[{id:'uat-transfer',name:'UAT transfer',type:'bank',isActive:true},{id:'uat-qris',name:'UAT QRIS',type:'edc_qris',isActive:true}];
  await page.route('**/api.php?**',async route=>{
    const url=new URL(route.request().url());
    if(url.searchParams.get('action')!=='hotel-data')return route.continue();
    const response=await route.fetch();const body=await response.json();
    // Read-only response fixture exercises the actual shipped frontend. No hotel
    // or CI database transaction is replaced by this browser assertion.
    await route.fulfill({response,json:{...body,transactions,bankAccounts}});
  });
  await page.goto('/index.html');await page.locator('#tab-finance').click();
  await page.getByTestId('finance-filter-from').fill('2026-01-28');
  await page.getByTestId('finance-filter-to').fill('2026-01-28');
  await page.getByRole('button',{name:'📊 Grafik Analisis',exact:true}).click();
  const channel=page.locator('#finance-flow-channel');
  const value=()=>page.getByTestId('finance-flow-income').innerText().then(x=>Number(x.replace(/\D/g,'')));
  await expect(channel).toHaveValue('all');await expect.poll(value).toBe(3151000);
  const channels={cash:2711000,bank:440000,transfer:220000,qris:220000,card:0};
  for(const [key,total] of Object.entries(channels)){await channel.selectOption(key);await expect.poll(value).toBe(total);}
  await channel.selectOption('all');
  await expect.poll(()=>page.getByTestId('finance-flow-expense').innerText().then(x=>Number(x.replace(/\D/g,'')))).toBe(1640000);
  writeEvidence('finance-january-28',info,{pass:true,total:3151000,channels,pendingTaxReceipts:470000});
});

test('Official report defaults use property midnight and recompute on next month reopen',async({page},info)=>{
  await page.clock.install({time:new Date('2026-09-30T16:10:00Z')});
  await page.goto('/index.html');await page.locator('#tab-finance').click();
  await page.getByTestId('finance-filter-from').fill('');
  await page.getByTestId('finance-filter-to').fill('');
  await page.evaluate(()=>{window.TamasyaPropertyBranding={...window.TamasyaPropertyBranding,timezone:'Asia/Makassar'};});
  await page.locator('#tamasya-report-center-btn').click();
  await expect(page.locator('[data-r="from"]')).toHaveValue('2026-10-01');
  await expect(page.locator('[data-r="to"]')).toHaveValue('2026-10-01');
  await page.getByRole('button',{name:'Tutup',exact:true}).click();
  await page.clock.setSystemTime(new Date('2026-10-31T16:10:00Z'));
  await page.locator('#tamasya-report-center-btn').click();
  await expect(page.locator('[data-r="from"]')).toHaveValue('2026-11-01');
  await expect(page.locator('[data-r="to"]')).toHaveValue('2026-11-01');
  writeEvidence('report-property-midnight',info,{pass:true,timezone:'Asia/Makassar',initial:'2026-10-01',reopened:'2026-11-01'});
});

test('Fresh service worker precaches the exact React module for offline import',async({browser,request},info)=>{
  const context=await browser.newContext({serviceWorkers:'allow'});
  try{
    const page=await context.newPage();await blockExternal(page);await installAuth(page,request);
    // Memo does not import the PMS React bundle, so the vendor cannot be warmed
    // accidentally before this check of the newly installed precache.
    await page.goto(BASE+'/internal-memo.html');
    const vendor='assets/chunks/vendor-react.js?v=20261007-growth-ui-r10';
    const cached=await page.evaluate(async vendor=>{
      await navigator.serviceWorker.register('./sw.js');await navigator.serviceWorker.ready;
      const cacheKeys=await caches.keys();
      const url=new URL(vendor,location.href).href;
      for(const key of cacheKeys){const cache=await caches.open(key);if(await cache.match(url))return true;}
      return false;
    },vendor);
    expect(cached).toBe(true);
    await expect.poll(()=>page.evaluate(()=>Boolean(navigator.serviceWorker.controller))).toBe(true);
    await context.setOffline(true);
    const exports=await page.evaluate(async vendor=>Object.keys(await import(new URL(vendor,location.href).href)).length,vendor);
    expect(exports).toBeGreaterThan(0);
    writeEvidence('cold-offline-react',info,{pass:true,vendor,exports});
  }finally{await context.close();}
});


test('Official downloads inherit report period and save actual PDF Excel CSV and JSON files',async({page},info)=>{
  await page.goto('/index.html');await page.locator('#tab-report').click();
  await page.locator('[data-tamasya-report-period="from"]').fill('2026-01-01');
  await page.locator('[data-tamasya-report-period="to"]').fill('2026-10-31');
  await page.locator('#tamasya-report-center-btn').click();
  await expect(page.locator('[data-r="from"]')).toHaveValue('2026-01-01');
  await expect(page.locator('[data-r="to"]')).toHaveValue('2026-10-31');
  const checks=await page.locator('.tamasya-report-checks label').evaluateAll(labels=>labels.map(label=>({text:label.textContent.trim(),width:label.getBoundingClientRect().width,height:label.getBoundingClientRect().height,inputWidth:label.querySelector('input').getBoundingClientRect().width})));
  expect(checks.map(c=>c.text)).toEqual(['PDF','Excel','CSV']);
  for(const c of checks){expect(c.width).toBeGreaterThan(35);expect(c.height).toBeLessThan(30);expect(c.inputWidth).toBe(16);}
  const files=[];
  for(const format of ['pdf','xlsx','csv','json']){
    const pending=page.waitForEvent('download');
    await page.locator(`.tamasya-report-actions [data-format="${format}"]`).click();
    const download=await pending;expect(await download.failure()).toBeNull();
    expect(download.suggestedFilename()).toMatch(new RegExp(`^TAMASYA_.*_2026-01-01_2026-10-31_.*\\.${format}$`));
    const file=await download.path();expect(file).toBeTruthy();const body=fs.readFileSync(file);
    expect(body.length).toBeGreaterThan(50);
    if(format==='pdf')expect(body.subarray(0,5).toString()).toBe('%PDF-');
    if(format==='xlsx')expect(body.subarray(0,4)).toEqual(Buffer.from([80,75,3,4]));
    if(format==='csv')expect(body.toString('utf8')).toContain('Report ID');
    if(format==='json'){const snapshot=JSON.parse(body.toString());expect(snapshot.meta.from).toBe('2026-01-01');expect(snapshot.meta.to).toBe('2026-10-31');}
    await expect(page.locator('[data-r="status"]')).toContainText('berhasil dibuat');
    files.push({format,name:download.suggestedFilename(),bytes:body.length});
  }
  writeEvidence('official-ui-downloads',info,{pass:true,from:'2026-01-01',to:'2026-10-31',files,checks});
});

test('Finance period is inherited and Operations badges and journal totals explain their scope',async({page},info)=>{
  await page.goto('/index.html');await page.locator('#tab-finance').click();
  await page.getByTestId('finance-filter-from').fill('2026-01-28');
  await page.getByTestId('finance-filter-to').fill('2026-01-28');
  await page.locator('#tamasya-report-center-btn').click();
  await expect(page.locator('[data-r="from"]')).toHaveValue('2026-01-28');
  await expect(page.locator('[data-r="to"]')).toHaveValue('2026-01-28');
  await page.getByRole('button',{name:'Tutup',exact:true}).click();
  await page.locator('#domain-operations').click();await page.locator('[role="menuitem"][data-route="operations"]').click();
  const financeBadge=page.locator('.tamasya-operations-native-group-mark-finance');
  await expect(financeBadge).toBeVisible();
  expect(await financeBadge.evaluate(el=>getComputedStyle(el,'::before').content)).toBe('"Rp"');
  const system=await page.locator('.tamasya-operations-native-group-mark-system').evaluate(el=>getComputedStyle(el,'::before').content);
  expect(system).toBe('"⚙"');
  await page.locator('[data-operation-key="journal"]').click();
  await expect(page.getByText('Total debit jurnal',{exact:true})).toBeVisible();
  await expect(page.getByText('Total kredit jurnal',{exact:true})).toBeVisible();
  await expect(page.getByText('Total debit dan kredit mencakup seluruh pencatatan jurnal, termasuk pemasukan dan pengeluaran. Angka ini bukan saldo kas atau total pemasukan.',{exact:true})).toBeVisible();
  await page.getByPlaceholder('Cari dokumen, transaksi, deskripsi, tanggal, atau sumber…').fill('uat-nonexistent-journal-r3');
  await expect(page.getByText('Sesuai pencarian',{exact:true})).toBeVisible();
  await expect(page.getByText('Rp 0',{exact:true})).toHaveCount(2);
  writeEvidence('report-scope-and-badges',info,{pass:true,financeBadge:'Rp',systemBadge:system,journalSearchZero:true});
});


test('Layout: exact large amounts stay inside cards and audit shift stays above navigation',async({page},info)=>{
  const amount=97770600.3;
  await page.route('**/api.php?**',async route=>{
    const url=new URL(route.request().url());if(url.searchParams.get('action')!=='hotel-data')return route.continue();
    const response=await route.fetch();const body=await response.json();
    await route.fulfill({response,json:{...body,transactions:[{id:'layout_receipt',type:'income',date:'2026-01-28',amount,categorySystemKey:'room_rental',transactionKind:'manual',taxSnapshotStatus:'confirmed',baseAmount:amount,taxAmount:0,taxRate:0}],shiftReports:[{id:'layout_shift',staffName:'Gina Adam & Aldy Kaangkung',staffId:'layout_staff',shiftDate:'2026-01-28',shiftTime:'siang',startingCash:0,expectedCash:1265000.3,actualPhysicalCash:1265000.3,variance:0,digitalRevenue:amount,transactionsCount:6,createdAt:'2026-01-28 22:37:39',notes:'Catatan audit panjang untuk menguji scroll dialog. '.repeat(150)}]}});
  });
  await page.goto('/index.html');await expect(page.locator('#tab-finance')).toBeVisible();
  const evidence=[];
  for(const width of (info.project.name==='mobile'?[360,390]:[1024,1366,1440])){
    await page.setViewportSize({width,height:768});
    await page.locator('#tab-dashboard').click();await expect(page.locator('.tamasya-dashboard-summary')).toBeVisible();await expectMetricContentContained(page);await expectNoPageHorizontalOverflow(page);
    await page.locator('#tab-finance').click();await expect(page.locator('.tamasya-finance-summary')).toBeVisible();
    await expect(page.locator('.tamasya-finance-summary .tamasya-metric-value').nth(1)).toHaveText('Rp 97.770.600,30');
    await expectMetricContentContained(page);await expectNoPageHorizontalOverflow(page);
    await page.locator('#tab-report').click();
    const dates=page.locator('#financial-report-view input[type="date"]');await dates.nth(0).fill('2026-01-01');await dates.nth(1).fill('2026-01-31');
    await page.getByRole('button',{name:'Shift Jaga Resepsionis'}).click();await page.getByRole('button',{name:'Detail Audit',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Audit Rekonsiliasi Kas Jaga'});await expect(dialog).toBeVisible();
    const heading=dialog.getByText('Audit Rekonsiliasi Kas Jaga',{exact:true});await heading.scrollIntoViewIfNeeded();
    await expect.poll(()=>heading.evaluate(el=>{const r=el.getBoundingClientRect();const hit=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return r.top>=0&&r.bottom<=innerHeight&&Boolean(hit&&(el.contains(hit)||hit.contains(el)));}),{message:'Judul audit harus selesai scroll dan dapat disentuh di atas navigasi'}).toBe(true);
    const panel=dialog.locator(':scope > div').first();expect(await panel.evaluate(el=>el.scrollHeight>el.clientHeight)).toBe(true);
    const close=dialog.getByRole('button',{name:'Tutup',exact:true});await close.scrollIntoViewIfNeeded();await expect(close).toBeInViewport();await close.click();await expect(dialog).toBeHidden();
    evidence.push({width,height:768,exactAmount:true,cardContainment:true,auditHeadingReachable:true,auditFooterReachable:true});
  }
  writeEvidence('layout-card-audit-shift',info,{pass:true,evidence});
});

test('Activated Growth stays in native Dashboard and reservation views with exact Rupiah cents',async({page},info)=>{
 const errors=[],quotes=[],writes=[];page.on('pageerror',error=>errors.push(error.message));
 page.on('request',request=>{if(request.url().includes('action=growth-suite')&&request.method()!=='GET')writes.push(request.url());});
 await page.route('**/api.php?**',async route=>{
   const request=route.request(),url=new URL(request.url()),action=url.searchParams.get('action'),command=url.searchParams.get('command');
   if(action==='hotel-data'){
     const response=await route.fetch(),body=await response.json();
     return route.fulfill({response,json:{...body,rooms:[{id:'growth_ui_room',number:'UI10',type:'Standard',floor:'1',price:400000,status:'available'}],bookings:[]}});
   }
   if(action==='growth-suite'&&request.method()==='GET'){
     let data;
     if(command==='kpis')data={occupancyPct:50,adr:4640000.3,revpar:2320000.3,soldRoomNights:2};
     else if(command==='bootstrap')data={ratePlans:[{id:'ui_standard',name:'UI Standard',room_type:'Standard',active:1},{id:'wrong_type',room_type:'Deluxe',active:1}]};
     else if(command==='rate-suggestion'){quotes.push(Object.fromEntries(url.searchParams));data={rate:999999999.3,stopSell:false};}
     else return route.continue();
     return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({success:true,data})});
   }
   return route.continue();
 });
 await page.goto('/index.html');await expect(page.locator('#tab-dashboard')).toBeVisible();await page.locator('#tab-dashboard').click();
 const kpi=page.locator('#root #tamasya-growth-kpi-mini');await expect(kpi).toBeVisible();await expect(kpi).toContainText('Rp 4.640.000,30');
 await expect.poll(()=>kpi.evaluate(el=>getComputedStyle(el).position)).toBe('relative');
 const textIssues=await kpi.locator('.tamasya-growth-metric strong').evaluateAll(values=>values.filter(value=>{const card=value.closest('.tamasya-growth-metric').getBoundingClientRect(),range=document.createRange();range.selectNodeContents(value);return Array.from(range.getClientRects()).some(r=>r.left<card.left||r.right>card.right||r.bottom>card.bottom);}).map(el=>el.textContent));
 expect(textIssues).toEqual([]);await expectNoPageHorizontalOverflow(page);
 for(const id of ['tab-finance','tab-report']){
   await page.locator('#'+id).click();await expect(page.locator('#tamasya-growth-kpi-mini')).toHaveCount(0);await expect(page.locator('#tamasya-growth-suggest-box')).toHaveCount(0);
 }
 const reportDates=page.locator('#financial-report-view input[type="date"]');await expect(reportDates).toHaveCount(2);
 await reportDates.nth(0).fill('2026-01-01');await reportDates.nth(1).fill('2026-01-31');await expect(page.locator('#tamasya-growth-suggest-box')).toHaveCount(0);
 const opener=page.locator('#domain-frontoffice');await opener.scrollIntoViewIfNeeded();await opener.click();await page.locator('[role="menu"] [data-route="rooms"]').click();
 await expect(page.locator('#room-card-UI10')).toBeVisible();await expect(page.locator('#tamasya-growth-suggest-box')).toHaveCount(0);
 await page.locator('#room-card-UI10').click();await page.getByRole('button',{name:'Reservasi & Check-In Tamu',exact:true}).click();
 const form=page.locator('[data-tamasya-reservation-form="create"]');await expect(form).toBeVisible();
 await form.getByLabel('Tanggal check-in reservasi',{exact:true}).fill('2026-01-28');await form.getByLabel('Tanggal check-out reservasi',{exact:true}).fill('2026-01-31');
 await form.getByLabel('Sumber booking reservasi',{exact:true}).selectOption('Traveloka');
 const price=form.getByLabel('Harga reservasi manual',{exact:true});await price.fill('450000');
 const suggestion=form.locator('#tamasya-growth-suggest-box');await expect(suggestion).toContainText('Rp 999.999.999,30');await expect(suggestion).toContainText('3 malam · Traveloka');
 await expect(price).toHaveValue('450000');
 await expect.poll(()=>quotes.at(-1)?.bookingSource).toBe('Traveloka');expect(quotes.at(-1)).toMatchObject({planId:'ui_standard',roomType:'Standard',stayDate:'2026-01-28',lengthOfStay:'3'});
 await form.getByLabel('Tanggal check-out reservasi',{exact:true}).fill('2026-02-01');await expect.poll(()=>quotes.at(-1)?.lengthOfStay).toBe('4');await expect(price).toHaveValue('450000');
 await expectNoPageHorizontalOverflow(page);expect(errors).toEqual([]);expect(writes).toEqual([]);
 writeEvidence('growth-native-widgets',info,{pass:true,currency:'Rp 4.640.000,30',kpiNormalFlow:true,reportsClean:true,quote:quotes.at(-1),priceUnchanged:true,writes:0});
});
