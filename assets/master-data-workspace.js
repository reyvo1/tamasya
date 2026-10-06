/* TAMASYA V137 - FIX22 independent Master Data workspace.
 * Presentation/controller only: all writes continue through canonical Finance catalog API.
 * No journal, tax, transaction, booking, Telegram, or database schema logic is duplicated here.
 */
(function () {
  'use strict';

  const VERSION = '20260817-r7-master-data-workspace-fix22';
  const WORKSPACE_ID = 'ui-core-master-data-workspace';
  const SPA_ROUTES = new Set(['dashboard','rooms','finance','report','telegram','staff','config','db_config','inventory','leaves','attendance','operations','activity','website','support','savings','pos','login']);

  let state = { data: null, selectedCategoryId: '', filterType: 'all', search: '', busy: false };
  let dialogResolver = null;

  const role = () => String(sessionStorage.getItem('hotel_role') || sessionStorage.getItem('hotel_staff_role') || '').trim().toLowerCase();
  const loggedIn = () => sessionStorage.getItem('hotel_logged_in') === 'true';
  const canCatalogWrite = () => ['admin','manager','finance'].includes(role());
  const canSemanticBind = () => ['admin','manager'].includes(role());
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

  function appBasePath() {
    let path = window.location.pathname || '/';
    if (path.endsWith('/')) path = path.slice(0, -1);
    if (path.endsWith('/index.html')) path = path.slice(0, -11);
    const last = path.split('/').filter(Boolean).pop() || '';
    if (SPA_ROUTES.has(last)) path = path.slice(0, -(last.length + 1));
    if (!path.endsWith('/')) path += '/';
    return path;
  }

  function apiUrl(action) {
    return window.location.origin + appBasePath() + 'api.php?action=' + encodeURIComponent(action);
  }

  async function readJson(response) {
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.success === false) {
      const message = String(payload?.message || payload?.error || ('HTTP ' + response.status));
      const error = new Error(message); error.status = response.status; error.payload = payload; throw error;
    }
    return payload;
  }

  async function fetchHotelData() {
    return readJson(await fetch(apiUrl('hotel-data'), { method:'GET', cache:'no-store', headers:{'Accept':'application/json'} }));
  }

  async function post(action, payload) {
    const response = await fetch(apiUrl(action), {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify(payload || {})
    });
    return readJson(response);
  }

  function categories() { return Array.isArray(state.data?.categories) ? state.data.categories : []; }
  function subcategories() { return Array.isArray(state.data?.subcategoryCatalog) ? state.data.subcategoryCatalog : []; }
  function bindings() { return Array.isArray(state.data?.financeSemanticBindings) ? state.data.financeSemanticBindings : []; }
  function selectedCategory() { return categories().find(row => String(row.id) === String(state.selectedCategoryId)) || null; }
  function bindingForCategory(categoryId) { return bindings().find(row => String(row.categoryId || '') === String(categoryId || '')) || null; }

  function typeLabel(type) { return type === 'income' ? 'Pemasukan' : type === 'expense' ? 'Pengeluaran' : 'Lainnya'; }
  function typeClass(type) { return type === 'income' ? 'income' : type === 'expense' ? 'expense' : ''; }

  function setBusy(next, message) {
    state.busy = !!next;
    const root = document.getElementById(WORKSPACE_ID);
    if (!root) return;
    root.classList.toggle('is-busy', state.busy);
    const status = root.querySelector('[data-master-status]');
    if (status) status.textContent = message || (state.busy ? 'Memproses…' : '');
    root.querySelectorAll('button,input,select').forEach(el => {
      if (el.closest('.ui-master-dialog')) return;
      if (el.dataset.allowBusy === 'true') return;
      el.disabled = state.busy;
    });
  }

  function toast(message, kind='ok') {
    const root = document.getElementById(WORKSPACE_ID); if (!root) return;
    let box = root.querySelector('.ui-master-toast');
    if (!box) { box = document.createElement('div'); box.className = 'ui-master-toast'; root.appendChild(box); }
    box.className = 'ui-master-toast ' + kind;
    box.textContent = message;
    box.hidden = false;
    clearTimeout(box._hideTimer);
    box._hideTimer = setTimeout(() => { box.hidden = true; }, 3600);
  }

  function renderCategoryRows() {
    const query = state.search.trim().toLowerCase();
    const rows = categories().filter(row => {
      if (state.filterType !== 'all' && row.type !== state.filterType) return false;
      if (query && !String(row.name || '').toLowerCase().includes(query)) return false;
      return true;
    });
    if (!rows.length) return '<div class="ui-master-empty">Belum ada kategori pada filter ini.</div>';
    return rows.map(row => {
      const active = String(row.id) === String(state.selectedCategoryId);
      const bind = bindingForCategory(row.id);
      return '<button type="button" class="ui-master-category-row '+(active?'active ':'')+typeClass(row.type)+'" data-category-id="'+esc(row.id)+'">' +
        '<span class="ui-master-category-main"><strong>'+esc(row.name)+'</strong><small>'+esc(typeLabel(row.type))+'</small></span>' +
        (bind ? '<span class="ui-master-usage-badge">'+esc(bind.label || 'Pemakaian khusus')+'</span>' : '') +
        '</button>';
    }).join('');
  }

  function renderSubRows(category) {
    const rows = subcategories().filter(row => String(row.categoryId) === String(category?.id || ''));
    if (!rows.length) return '<div class="ui-master-empty compact">Belum ada subkategori. Tambahkan hanya jika memang dibutuhkan untuk rincian laporan/transaksi.</div>';
    return rows.map(row => '<div class="ui-master-sub-row">' +
      '<div><strong>'+esc(row.name)+'</strong>'+(row.isSystem?'<small>Terproteksi sistem</small>':'')+'</div>' +
      '<div class="ui-master-row-actions">'+
      (!row.isSystem && canCatalogWrite() ? '<button type="button" data-edit-sub="'+esc(row.id)+'">Ubah</button><button type="button" class="danger" data-archive-sub="'+esc(row.id)+'">Arsipkan</button>' : '') +
      '</div></div>').join('');
  }

  function renderDetail() {
    const category = selectedCategory();
    if (!category) return '<div class="ui-master-detail-empty"><div class="ui-master-empty-icon">☷</div><h3>Pilih kategori</h3><p>Pilih kategori di kiri untuk mengelola nama, subkategori, dan pemakaian otomatisnya.</p></div>';
    const bind = bindingForCategory(category.id);
    const bindHtml = bind
      ? '<div class="ui-master-binding-set"><span>Dipakai otomatis untuk</span><strong>'+esc(bind.label)+'</strong><p>'+esc(bind.description || '')+'</p></div>'
      : '<div class="ui-master-binding-none"><strong>Tidak ada pemakaian otomatis</strong><p>Kategori ini tetap dapat dipakai sebagai kategori transaksi umum sesuai tipenya.</p></div>';
    return '<div class="ui-master-detail-head">' +
      '<div><span class="ui-master-type-pill '+typeClass(category.type)+'">'+esc(typeLabel(category.type))+'</span><h2>'+esc(category.name)+'</h2><p>ID dan kode internal tidak ditampilkan karena bukan bagian yang perlu dikelola operator.</p></div>' +
      '<div class="ui-master-row-actions">' +
      (canCatalogWrite()?'<button type="button" data-edit-category="'+esc(category.id)+'">Ubah Nama</button>':'') +
      (canCatalogWrite()?'<button type="button" class="danger" data-archive-category="'+esc(category.id)+'">Arsipkan</button>':'') +
      '</div></div>' +
      '<section class="ui-master-section"><div class="ui-master-section-head"><div><h3>Pemakaian Sistem</h3><p>Hanya untuk alur yang memang harus diposting otomatis oleh Booking, Payroll, PBJT, POS, atau workflow khusus.</p></div>' +
      (canSemanticBind()?'<button type="button" data-set-usage="'+esc(category.id)+'">Atur Pemakaian</button>':'') + '</div>' + bindHtml + '</section>' +
      '<section class="ui-master-section"><div class="ui-master-section-head"><div><h3>Subkategori</h3><p>Rincian fleksibel milik property. Tidak perlu dibuat jika kategori utama sudah cukup.</p></div>' +
      (canCatalogWrite()?'<button type="button" class="primary" data-add-sub="'+esc(category.id)+'">+ Tambah Subkategori</button>':'') + '</div>' +
      '<div class="ui-master-sub-list">'+renderSubRows(category)+'</div></section>';
  }

  function render() {
    const root = document.getElementById(WORKSPACE_ID); if (!root) return;
    root.classList.toggle('is-busy', state.busy);
    const totalIncome = categories().filter(x => x.type === 'income').length;
    const totalExpense = categories().filter(x => x.type === 'expense').length;
    const totalSub = subcategories().length;
    if (!selectedCategory() && categories().length) state.selectedCategoryId = String(categories()[0].id);
    root.innerHTML = '<div class="ui-master-shell">' +
      '<header class="ui-master-header"><div class="ui-master-breadcrumb">Sistem <span>›</span> Master Data <span>›</span> Kategori & Subkategori</div>' +
      '<div class="ui-master-header-row"><div><h1>Master Data Keuangan</h1><p>Kelola struktur kategori di sini. Form Log Kas hanya memakai master yang sudah dibuat.</p></div><button type="button" class="ui-master-close" data-master-close>← Kembali ke aplikasi</button></div></header>' +
      '<div class="ui-master-summary"><div><strong>'+totalIncome+'</strong><span>Kategori pemasukan</span></div><div><strong>'+totalExpense+'</strong><span>Kategori pengeluaran</span></div><div><strong>'+totalSub+'</strong><span>Subkategori aktif</span></div><div><strong>'+bindings().filter(x=>x.categoryId).length+'</strong><span>Pemakaian otomatis</span></div></div>' +
      '<main class="ui-master-grid"><aside class="ui-master-sidebar"><div class="ui-master-toolbar"><div class="ui-master-filter-tabs">' +
      ['all','income','expense'].map(type => '<button type="button" data-filter-type="'+type+'" class="'+(state.filterType===type?'active':'')+'">'+(type==='all'?'Semua':typeLabel(type))+'</button>').join('') +
      '</div><input type="search" data-master-search placeholder="Cari kategori…" value="'+esc(state.search)+'"></div>' +
      (canCatalogWrite()?'<button type="button" class="ui-master-add-category" data-add-category>+ Tambah Kategori</button>':'') +
      '<div class="ui-master-category-list">'+renderCategoryRows()+'</div></aside>' +
      '<article class="ui-master-detail">'+renderDetail()+'</article></main>' +
      '<footer class="ui-master-footer"><span data-master-status></span><span>Perubahan disimpan melalui API Finance yang sama dan tetap diaudit oleh server.</span></footer>' +
      '</div><div class="ui-master-dialog-layer" hidden></div><div class="ui-master-toast" hidden></div>';
    bindEvents(root);
  }

  function bindEvents(root) {
    root.querySelector('[data-master-close]')?.addEventListener('click', close);
    root.querySelectorAll('[data-category-id]').forEach(btn => btn.addEventListener('click', () => { state.selectedCategoryId = btn.dataset.categoryId || ''; render(); }));
    root.querySelectorAll('[data-filter-type]').forEach(btn => btn.addEventListener('click', () => { state.filterType = btn.dataset.filterType || 'all'; render(); }));
    root.querySelector('[data-master-search]')?.addEventListener('input', e => { state.search = e.target.value || ''; const list=root.querySelector('.ui-master-category-list'); if(list) list.innerHTML=renderCategoryRows(); bindCategoryRowEvents(root); });
    root.querySelector('[data-add-category]')?.addEventListener('click', createCategory);
    root.querySelectorAll('[data-edit-category]').forEach(btn => btn.addEventListener('click', () => editCategory(btn.dataset.editCategory)));
    root.querySelectorAll('[data-archive-category]').forEach(btn => btn.addEventListener('click', () => archiveCategory(btn.dataset.archiveCategory)));
    root.querySelectorAll('[data-add-sub]').forEach(btn => btn.addEventListener('click', () => createSubcategory(btn.dataset.addSub)));
    root.querySelectorAll('[data-edit-sub]').forEach(btn => btn.addEventListener('click', () => editSubcategory(btn.dataset.editSub)));
    root.querySelectorAll('[data-archive-sub]').forEach(btn => btn.addEventListener('click', () => archiveSubcategory(btn.dataset.archiveSub)));
    root.querySelectorAll('[data-set-usage]').forEach(btn => btn.addEventListener('click', () => chooseUsage(btn.dataset.setUsage)));
  }

  function bindCategoryRowEvents(root) {
    root.querySelectorAll('[data-category-id]').forEach(btn => btn.addEventListener('click', () => { state.selectedCategoryId = btn.dataset.categoryId || ''; render(); }));
  }

  function dialog({title, text='', fields=[], actions=[{id:'cancel',label:'Batal'},{id:'ok',label:'Simpan',primary:true}], danger=false}) {
    const root = document.getElementById(WORKSPACE_ID); if (!root) return Promise.resolve(null);
    const layer = root.querySelector('.ui-master-dialog-layer'); if (!layer) return Promise.resolve(null);
    layer.hidden = false;
    layer.innerHTML = '<div class="ui-master-dialog-backdrop"></div><div class="ui-master-dialog" role="dialog" aria-modal="true"><div class="ui-master-dialog-head"><h3>'+esc(title)+'</h3>'+(text?'<p>'+esc(text)+'</p>':'')+'</div><div class="ui-master-dialog-body">' +
      fields.map(f => {
        if (f.type === 'select') return '<label><span>'+esc(f.label)+'</span><select name="'+esc(f.name)+'">'+(f.options||[]).map(o=>'<option value="'+esc(o.value)+'" '+(String(o.value)===String(f.value??'')?'selected':'')+'>'+esc(o.label)+'</option>').join('')+'</select>'+(f.help?'<small>'+esc(f.help)+'</small>':'')+'</label>';
        if (f.type === 'choice') return '<div class="ui-master-choice-field"><span>'+esc(f.label)+'</span><div class="ui-master-choice-list">'+(f.options||[]).map(o=>'<label class="ui-master-choice"><input type="radio" name="'+esc(f.name)+'" value="'+esc(o.value)+'" '+(String(o.value)===String(f.value??'')?'checked':'')+'><div><strong>'+esc(o.label)+'</strong><small>'+esc(o.description||'')+'</small></div></label>').join('')+'</div></div>';
        return '<label><span>'+esc(f.label)+'</span><input name="'+esc(f.name)+'" type="'+esc(f.type||'text')+'" value="'+esc(f.value??'')+'" placeholder="'+esc(f.placeholder||'')+'" '+(f.required?'required':'')+'>'+(f.help?'<small>'+esc(f.help)+'</small>':'')+'</label>';
      }).join('') + '</div><div class="ui-master-dialog-actions">' + actions.map(a=>'<button type="button" data-dialog-action="'+esc(a.id)+'" class="'+(a.primary?'primary ':'')+(a.danger||danger&&a.id==='ok'?'danger':'')+'">'+esc(a.label)+'</button>').join('') + '</div></div>';
    const first = layer.querySelector('input,select,button'); setTimeout(()=>first?.focus(),20);
    return new Promise(resolve => {
      dialogResolver = resolve;
      const finish = value => { layer.hidden=true; layer.innerHTML=''; dialogResolver=null; resolve(value); };
      layer.querySelector('.ui-master-dialog-backdrop')?.addEventListener('click', ()=>finish(null));
      layer.querySelectorAll('[data-dialog-action]').forEach(btn => btn.addEventListener('click', () => {
        if (btn.dataset.dialogAction === 'cancel') { finish(null); return; }
        const form = {};
        layer.querySelectorAll('input,select').forEach(el => {
          if (el.type === 'radio') { if (el.checked) form[el.name]=el.value; }
          else form[el.name]=el.value;
        });
        finish({ action:btn.dataset.dialogAction, values:form });
      }));
    });
  }

  async function mutate(action, payload, successText) {
    try {
      setBusy(true, 'Menyimpan perubahan…');
      const result = await post(action, payload);
      state.data = result.db || await fetchHotelData();
      state.busy = false;
      if (!categories().some(x => String(x.id) === String(state.selectedCategoryId))) state.selectedCategoryId = categories()[0]?.id || '';
      render(); toast(String(result.message || successText || 'Perubahan tersimpan.'), 'ok');
      window.dispatchEvent(new CustomEvent('tamasya-master-data-changed', { detail:{ action } }));
      return true;
    } catch (error) {
      setBusy(false, ''); toast(error.message || 'Operasi gagal.', 'error'); return false;
    }
  }

  async function createCategory() {
    const result = await dialog({ title:'Tambah Kategori', text:'Buat master kategori. Nama dapat diubah lagi tanpa mengubah jurnal.', fields:[
      {name:'name',label:'Nama kategori',placeholder:'Contoh: Pendapatan Akomodasi',required:true},
      {name:'type',label:'Jenis transaksi',type:'select',value:'income',options:[{value:'income',label:'Pemasukan'},{value:'expense',label:'Pengeluaran'}]}
    ]});
    const name=result?.values?.name?.trim(); if (!name) return;
    const ok=await mutate('categories',{name,type:result.values.type},'Kategori ditambahkan.');
    if(ok){const row=categories().find(x=>String(x.name).toLowerCase()===name.toLowerCase()&&x.type===result.values.type);if(row){state.selectedCategoryId=row.id;render();}}
  }

  async function editCategory(id) {
    const row=categories().find(x=>String(x.id)===String(id)); if(!row)return;
    const result=await dialog({title:'Ubah Nama Kategori',text:'Hanya label tampilan yang berubah. Pemakaian accounting tetap sama.',fields:[{name:'name',label:'Nama kategori',value:row.name,required:true}]});
    const name=result?.values?.name?.trim(); if(!name||name===row.name)return;
    await mutate('categories-update',{categoryId:row.id,name},'Nama kategori diperbarui.');
  }

  async function archiveCategory(id) {
    const row=categories().find(x=>String(x.id)===String(id)); if(!row)return;
    const result=await dialog({title:'Arsipkan Kategori?',text:'Kategori tidak akan dipakai untuk transaksi baru. Transaksi lama tetap utuh.',actions:[{id:'cancel',label:'Batal'},{id:'ok',label:'Arsipkan',danger:true}],danger:true});
    if(!result)return;
    await mutate('categories-delete',{categoryId:row.id},'Kategori diarsipkan.');
  }

  async function createSubcategory(categoryId) {
    const category=categories().find(x=>String(x.id)===String(categoryId)); if(!category)return;
    const result=await dialog({title:'Tambah Subkategori',text:'Kategori: '+category.name,fields:[{name:'name',label:'Nama subkategori',placeholder:'Contoh: Laundry, Event, Administrasi',required:true}]});
    const name=result?.values?.name?.trim(); if(!name)return;
    await mutate('subcategories',{categoryId:category.id,subcategoryName:name},'Subkategori ditambahkan.');
  }

  async function editSubcategory(id) {
    const row=subcategories().find(x=>String(x.id)===String(id)); if(!row)return;
    const result=await dialog({title:'Ubah Nama Subkategori',fields:[{name:'name',label:'Nama subkategori',value:row.name,required:true}]});
    const name=result?.values?.name?.trim(); if(!name||name===row.name)return;
    await mutate('subcategories-update',{subcategoryId:row.id,name},'Nama subkategori diperbarui.');
  }

  async function archiveSubcategory(id) {
    const row=subcategories().find(x=>String(x.id)===String(id)); if(!row)return;
    const result=await dialog({title:'Arsipkan Subkategori?',text:'Subkategori tidak akan muncul untuk transaksi baru. Riwayat lama tetap utuh.',actions:[{id:'cancel',label:'Batal'},{id:'ok',label:'Arsipkan',danger:true}],danger:true});
    if(!result)return;
    await mutate('subcategories-delete',{subcategoryId:row.id},'Subkategori diarsipkan.');
  }

  async function chooseUsage(categoryId) {
    const category=categories().find(x=>String(x.id)===String(categoryId)); if(!category)return;
    const options=bindings().filter(x=>x.type===category.type).map(x=>({value:x.systemKey,label:x.label,description:x.description + (x.categoryId && String(x.categoryId)!==String(categoryId) ? ' Saat ini: '+(x.categoryName||'kategori lain')+'.' : '')}));
    if(!options.length){toast('Tidak ada pemakaian sistem untuk jenis kategori ini.','error');return;}
    const current=bindingForCategory(categoryId);
    const result=await dialog({title:'Atur Pemakaian Kategori',text:'Pilih hanya jika kategori ini memang menjadi tujuan posting otomatis suatu workflow. Kode teknis disimpan oleh sistem dan tidak perlu dihafal.',fields:[{name:'usage',label:'Pemakaian otomatis',type:'choice',value:current?.systemKey||'',options}],actions:[{id:'cancel',label:'Batal'},{id:'ok',label:'Terapkan',primary:true}]});
    const systemKey=result?.values?.usage; if(!systemKey)return;
    await mutate('categories-semantic-bind',{categoryId:category.id,systemKey},'Pemakaian kategori diperbarui.');
  }

  function ensureRoot() {
    let root=document.getElementById(WORKSPACE_ID);
    if(!root){root=document.createElement('div');root.id=WORKSPACE_ID;root.className='ui-master-workspace';root.hidden=true;document.body.appendChild(root);}
    return root;
  }

  async function open() {
    if(!loggedIn()) return;
    if(!['admin','manager','finance'].includes(role())) { window.alert('Master Data Keuangan hanya tersedia untuk Administrator, Manajer, atau Keuangan.'); return; }
    const root=ensureRoot(); root.hidden=false; document.body.classList.add('ui-master-workspace-open'); document.body.dataset.uiCoreWorkspace='finance-catalog';
    root.innerHTML='<div class="ui-master-loading"><div class="ui-master-spinner"></div><strong>Membuka Master Data…</strong><span>Membaca kategori dan subkategori dari database.</span></div>';
    try { state.data=await fetchHotelData(); state.selectedCategoryId=state.selectedCategoryId||state.data?.categories?.[0]?.id||''; render(); }
    catch(error){root.innerHTML='<div class="ui-master-loading error"><strong>Master Data tidak dapat dibuka</strong><span>'+esc(error.message||'Gagal membaca database.')+'</span><button type="button" data-master-close>Kembali</button></div>';root.querySelector('[data-master-close]')?.addEventListener('click',close);}
    window.dispatchEvent(new CustomEvent('tamasya-master-workspace-opened',{detail:{workspace:'finance-catalog'}}));
  }

  function close() {
    const root=document.getElementById(WORKSPACE_ID); if(root)root.hidden=true;
    document.body.classList.remove('ui-master-workspace-open'); delete document.body.dataset.uiCoreWorkspace;
    window.dispatchEvent(new CustomEvent('tamasya-master-workspace-closed',{detail:{workspace:'finance-catalog'}}));
  }

  function hideInlineCatalogButtons(scope=document) {
    const candidates=[];
    if(scope && scope.nodeType===1 && scope.matches?.('button')) candidates.push(scope);
    if(scope && scope.querySelectorAll) candidates.push(...scope.querySelectorAll('button'));
    candidates.forEach(btn => {
      if(btn.closest('#'+WORKSPACE_ID)) return;
      const text=String(btn.textContent||'').replace(/\s+/g,' ').trim();
      if(/^\+\s*(Kategori Baru|Subkategori Baru)$/i.test(text)) btn.classList.add('ui-core-inline-catalog-create-hidden');
    });
  }

  let inlineFrame=0;
  const inlineScopes=new Set();
  function scheduleInlineScan(scope) {
    if(scope) inlineScopes.add(scope);
    if(inlineFrame) return;
    inlineFrame=requestAnimationFrame(()=>{
      inlineFrame=0;
      const scopes=[...inlineScopes]; inlineScopes.clear();
      scopes.forEach(hideInlineCatalogButtons);
    });
  }
  const inlineObserver=new MutationObserver((records)=>{
    for(const record of records){
      if(record.type!=='childList') continue;
      for(const node of record.addedNodes||[]){
        if(node && node.nodeType===1) scheduleInlineScan(node);
      }
    }
  });
  function start(){
    ensureRoot();
    hideInlineCatalogButtons(document);
    inlineObserver.observe(document.getElementById('root')||document.body,{childList:true,subtree:true});
    window.addEventListener('tamasya-route-change',()=>scheduleInlineScan(document.getElementById('root')||document.body));
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();

  window.TAMASYA_MASTER_DATA_WORKSPACE=Object.freeze({version:VERSION,open,close,refresh:async()=>{if(!document.getElementById(WORKSPACE_ID)?.hidden){state.data=await fetchHotelData();render();}}});
})();
