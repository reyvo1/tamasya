(function () {
  'use strict';

  const VERSION = 'V137-GUEST-CENTER';
  const ACTIVE_STATUSES = new Set(['active', 'reserved']);
  const MUTATION_ROLES = new Set(['admin', 'manager', 'receptionist']);
  const GUEST_SERVICE_ROLES = new Set(['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']);
  const MONEY_ROLES = new Set(['admin', 'manager', 'receptionist', 'finance']);
  let cache = { at: 0, data: null };
  let currentBookingId = '';
  let searchTimer = null;
  let observer = null;
  let scheduled = false;
  let serviceIndicatorInitialized = false;
  let serviceActiveIds = new Set();

  const textOf = (el) => (el && el.textContent ? el.textContent : '').replace(/\s+/g, ' ').trim();
  const esc = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const role = () => String(sessionStorage.getItem('hotel_role') || sessionStorage.getItem('hotel_staff_role') || '').trim().toLowerCase();
  const loggedIn = () => sessionStorage.getItem('hotel_logged_in') === 'true';
  const money = (value) => window.TamasyaCurrencyDisplay.formatRupiah(Math.max(0, Number(value || 0)));
  const dateLabel = (value) => {
    if (!value) return '-';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: String(value).includes('T') || String(value).includes(' ') ? 'short' : undefined });
  };
  function permissions() {
    try { return JSON.parse(sessionStorage.getItem('hotel_permissions') || '{}') || {}; } catch { return {}; }
  }
  function tabAllowed(key) {
    const p = permissions();
    if (p.desktopTabs && Object.prototype.hasOwnProperty.call(p.desktopTabs, key)) return p.desktopTabs[key] === true;
    const fallbacks = {
      rooms: ['admin','manager','receptionist','finance'], finance: ['admin','manager','finance'],
      pos: ['admin','manager','receptionist','finance'], operations: ['admin','manager','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']
    };
    return (fallbacks[key] || []).includes(role());
  }
  function currentRoute() {
    try { if (window.TAMASYA_NAVIGATION?.current) return String(window.TAMASYA_NAVIGATION.current() || ''); } catch {}
    return String(sessionStorage.getItem('hotel_active_tab') || '').replace(/-/g, '_');
  }
  function apiUrl(path) {
    const base = window.location.pathname.replace(/\/(?:dashboard|rooms|finance|report|telegram|staff|config|db_config|inventory|leaves|attendance|operations|activity|website|support|savings|pos|login)\/?$/, '/').replace(/\/index\.html$/, '/');
    const cleanBase = base.endsWith('/') ? base : base.substring(0, base.lastIndexOf('/') + 1);
    return cleanBase + String(path).replace(/^\/+/, '');
  }
  async function hotelData(force) {
    if (!force && cache.data && Date.now() - cache.at < 20000) return cache.data;
    const response = await fetch(apiUrl('api/hotel-data?t=' + Date.now()), { cache: 'no-store', credentials: 'same-origin' });
    if (!response.ok) throw new Error(response.status === 403 ? 'Role ini tidak diizinkan membaca data tersebut.' : 'Data hotel belum dapat dimuat.');
    const data = await response.json();
    cache = { at: Date.now(), data: data && typeof data === 'object' ? data : {} };
    return cache.data;
  }
  function invalidate() { cache.at = 0; }
  function operationId(prefix) { return String(prefix || 'op') + '-' + Date.now() + '-' + Math.random().toString(16).slice(2) + '-' + Math.random().toString(16).slice(2); }
  async function postOperations(command, payload) {
    const response = await fetch(apiUrl('api/operations-center'), { method:'POST', cache:'no-store', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify(Object.assign({ command }, payload || {})) });
    const body = await response.json().catch(() => ({}));
    if (!response.ok || body.success === false) throw new Error(body.error || body.message || 'Operasi gagal diproses server.');
    invalidate();
    window.setTimeout(() => { refreshGuestServiceIndicator(true, false).catch(() => {}); }, 0);
    return body;
  }
  function bookingById(data, id) { return (Array.isArray(data.bookings) ? data.bookings : []).find((b) => String(b.id) === String(id)); }
  function roomByNumber(data, number) { return (Array.isArray(data.rooms) ? data.rooms : []).find((r) => String(r.number) === String(number)); }
  function bookingStatus(b) { return String((b && b.status) || '').toLowerCase(); }
  function sellableRooms(data, booking) {
    return (Array.isArray(data.rooms) ? data.rooms : []).filter((room) => {
      const state = String(room.operationalStatus || room.status || '').toLowerCase();
      const blockers = Array.isArray(room.operationalBlockers) ? room.operationalBlockers.length : 0;
      return String(room.number) !== String(booking.roomNumber) && state === 'available' && room.isSellable !== false && blockers === 0;
    });
  }
  function ensureRoot(id, className) {
    let el = document.getElementById(id);
    if (!el) { el = document.createElement('div'); el.id = id; el.className = className; document.body.appendChild(el); }
    return el;
  }
  function closeOverlay(id) { const el = document.getElementById(id); if (el) el.remove(); }
  function closeAll() { ['ui-guest-center-search-overlay','ui-guest-center-guest-drawer','ui-guest-center-safety-wizard','ui-guest-center-service-form','ui-guest-center-service-queue'].forEach(closeOverlay); }


  function shellUtilityHost() {
    // AUDIT13: the React app shell provides one explicit imperative utility island.
    // Search/service launchers never fall back into the primary navigation row.
    const utility = document.querySelector('#tamasya-module-dock .tmd-head > #ui-core-role-utility-row');
    return utility || null;
  }

  function ensureSearchLauncher() {
    if (!loggedIn()) { closeOverlay('ui-guest-center-search-overlay'); const old = document.getElementById('ui-guest-center-search-launch'); if (old) old.remove(); return; }
    const utility = shellUtilityHost();
    if (!utility) return;
    let host = document.getElementById('ui-guest-center-search-launch');
    if (!host) {
      host = document.createElement('div');
      host.id = 'ui-guest-center-search-launch'; host.className = 'ui-guest-center-search-launch print:hidden';
      host.innerHTML = '<button type="button" aria-label="Cari tamu, kamar, reservasi"><span aria-hidden="true">⌕</span><span>Cari tamu, kamar, reservasi…</span><kbd>Ctrl K</kbd></button>';
      host.querySelector('button').addEventListener('click', openSearch);
    }
    if (host.parentElement !== utility) utility.appendChild(host);
  }

  function openSearch() {
    closeOverlay('ui-guest-center-search-overlay');
    const overlay = ensureRoot('ui-guest-center-search-overlay', 'ui-guest-center-overlay print:hidden');
    overlay.innerHTML = '<div class="ui-guest-center-search-panel" role="dialog" aria-modal="true" aria-label="Pencarian global"><div class="ui-guest-center-search-input"><span>⌕</span><input type="search" autocomplete="off" placeholder="Nama tamu, nomor kamar, ID booking…"><button type="button" aria-label="Tutup">×</button></div><div class="ui-guest-center-search-help">Pencarian hanya menggunakan data yang memang boleh dilihat oleh role akun aktif.</div><div class="ui-guest-center-search-results"><div class="ui-guest-center-empty">Mulai ketik minimal 2 karakter.</div></div></div>';
    overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) closeOverlay('ui-guest-center-search-overlay'); });
    overlay.querySelector('.ui-guest-center-search-input button').addEventListener('click', () => closeOverlay('ui-guest-center-search-overlay'));
    const input = overlay.querySelector('input');
    input.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => renderSearch(input.value), 100); });
    input.focus();
  }

  async function renderSearch(query) {
    const panel = document.querySelector('#ui-guest-center-search-overlay .ui-guest-center-search-results');
    if (!panel) return;
    const q = String(query || '').trim().toLowerCase();
    if (q.length < 2) { panel.innerHTML = '<div class="ui-guest-center-empty">Mulai ketik minimal 2 karakter.</div>'; return; }
    panel.innerHTML = '<div class="ui-guest-center-empty">Mencari…</div>';
    try {
      const data = await hotelData(false);
      const bookings = (Array.isArray(data.bookings) ? data.bookings : []).filter((b) => [b.guestName,b.roomNumber,b.id,b.guestPhone].some((v) => String(v || '').toLowerCase().includes(q))).slice(0, 12);
      const bookingRooms = new Set(bookings.map((b) => String(b.roomNumber)));
      const rooms = (Array.isArray(data.rooms) ? data.rooms : []).filter((r) => !bookingRooms.has(String(r.number)) && [r.number,r.type,r.floor].some((v) => String(v || '').toLowerCase().includes(q))).slice(0, 8);
      const transactions = tabAllowed('finance') ? (Array.isArray(data.transactions) ? data.transactions : []).filter((tx) => [tx.id,tx.description,tx.roomNumber,tx.bookingId].some((v) => String(v || '').toLowerCase().includes(q))).slice(0, 6) : [];
      const html = [];
      bookings.forEach((b) => html.push('<button type="button" class="ui-guest-center-result" data-kind="booking" data-id="'+esc(b.id)+'"><span class="ui-guest-center-result-icon">👤</span><span><strong>'+esc(b.guestName || 'Tamu')+'</strong><small>Booking '+esc(b.id)+' · Kamar '+esc(b.roomNumber)+' · '+esc(bookingStatus(b) || '-')+'</small></span><b>›</b></button>'));
      rooms.forEach((r) => html.push('<button type="button" class="ui-guest-center-result" data-kind="room" data-id="'+esc(r.number)+'"><span class="ui-guest-center-result-icon">🚪</span><span><strong>Kamar '+esc(r.number)+'</strong><small>'+esc(r.type || '-')+' · '+esc(r.operationalStatus || r.status || '-')+'</small></span><b>›</b></button>'));
      transactions.forEach((tx) => html.push('<button type="button" class="ui-guest-center-result" data-kind="transaction" data-id="'+esc(tx.bookingId || tx.roomNumber || '')+'"><span class="ui-guest-center-result-icon">🧾</span><span><strong>'+esc(tx.description || tx.id)+'</strong><small>'+esc(tx.id)+' · '+money(tx.amount)+'</small></span><b>›</b></button>'));
      panel.innerHTML = html.length ? html.join('') : '<div class="ui-guest-center-empty">Tidak ada hasil yang sesuai dengan hak akses akun ini.</div>';
      panel.querySelectorAll('button[data-kind]').forEach((btn) => btn.addEventListener('click', async () => {
        const kind = btn.dataset.kind, id = btn.dataset.id; closeOverlay('ui-guest-center-search-overlay');
        if (kind === 'booking') return openGuestCenter(id);
        if (kind === 'transaction' && id) {
          const dataNow = await hotelData(false); const booking = bookingById(dataNow, id) || (dataNow.bookings || []).find((b) => String(b.roomNumber) === String(id) && ACTIVE_STATUSES.has(bookingStatus(b)));
          if (booking) return openGuestCenter(booking.id);
          return navigateToTab('finance');
        }
        const dataNow = await hotelData(false); const booking = (dataNow.bookings || []).find((b) => String(b.roomNumber) === String(id) && ACTIVE_STATUSES.has(bookingStatus(b)));
        if (booking) return openGuestCenter(booking.id);
        await navigateToRooms(id);
      }));
    } catch (err) { panel.innerHTML = '<div class="ui-guest-center-empty ui-guest-center-error">'+esc(err && err.message ? err.message : 'Pencarian gagal.')+'</div>'; }
  }


  function activeGuestServiceRows(data) {
    return (Array.isArray(data && data.guestServiceRequests) ? data.guestServiceRequests : [])
      .filter((row) => ['open','assigned','in_progress'].includes(String(row && row.status || '').toLowerCase()));
  }

  async function guestServiceIndicatorRows() {
    const response = await fetch(apiUrl('api/operations-center?view=guest-service-indicator&t=' + Date.now()), { cache:'no-store', credentials:'same-origin' });
    const body = await response.json().catch(() => ({}));
    if (!response.ok || body.success === false) throw new Error(body.error || 'Status antrean permintaan tamu belum dapat dibaca.');
    return (Array.isArray(body.rows) ? body.rows : []).filter((row) => ['open','assigned','in_progress'].includes(String(row && row.status || '').toLowerCase()));
  }

  function guestServiceNeedsImmediateAttention(row) {
    if (String(row && row.priority || '').toLowerCase() === 'urgent') return true;
    const needed = String(row && row.needed_at || '').trim();
    if (!needed) return false;
    const ts = new Date(needed).getTime();
    return Number.isFinite(ts) && ts < Date.now();
  }

  function renderGuestServiceIndicator(host, rows) {
    const button = host && host.querySelector('button');
    if (!button) return;
    const count = rows.length;
    const immediate = rows.filter(guestServiceNeedsImmediateAttention).length;
    const open = rows.filter((row) => String(row && row.status || '').toLowerCase() === 'open').length;
    const badge = button.querySelector('[data-service-count]');
    const small = button.querySelector('small');
    host.classList.remove('status-error');
    host.classList.toggle('has-pending', count > 0);
    host.classList.toggle('has-urgent', immediate > 0);
    if (badge) { badge.textContent = String(count); badge.hidden = count < 1; }
    if (small) {
      small.textContent = count < 1
        ? 'Tidak ada antrean aktif'
        : immediate > 0
          ? count + ' aktif · ' + immediate + ' perlu segera'
          : count + ' aktif' + (open > 0 ? ' · ' + open + ' belum diambil' : '');
    }
    button.setAttribute('aria-label', count < 1 ? 'Permintaan Tamu, tidak ada antrean aktif' : 'Permintaan Tamu, ' + count + ' aktif' + (immediate > 0 ? ', ' + immediate + ' perlu segera' : ''));
  }

  async function refreshGuestServiceIndicator(force, notifyNew) {
    const host = document.getElementById('ui-guest-center-service-launch');
    if (!host || !loggedIn() || !GUEST_SERVICE_ROLES.has(role()) || !tabAllowed('operations')) return;
    try {
      const rows = await guestServiceIndicatorRows();
      const nextIds = new Set(rows.map((row) => String(row && row.id || '')).filter(Boolean));
      if (serviceIndicatorInitialized && notifyNew) {
        const newlySeen = rows.filter((row) => { const id=String(row && row.id || ''); return id && !serviceActiveIds.has(id); });
        if (newlySeen.length) {
          const urgent = newlySeen.some(guestServiceNeedsImmediateAttention);
          toast((urgent ? 'Permintaan tamu URGENT baru' : 'Permintaan tamu baru') + ': ' + String(newlySeen[0].request_type || 'Layanan tamu') + (newlySeen.length > 1 ? ' +' + (newlySeen.length - 1) : ''), urgent ? 'danger' : '');
        }
      }
      serviceActiveIds = nextIds;
      serviceIndicatorInitialized = true;
      renderGuestServiceIndicator(host, rows);
    } catch (err) {
      const small = host.querySelector('small');
      if (small) small.textContent = 'Status antrean belum tersedia';
      host.classList.add('status-error');
    }
  }

  function ensureGuestServiceQueueLauncher() {
    const existing = document.getElementById('ui-guest-center-service-launch');
    if (!loggedIn() || !GUEST_SERVICE_ROLES.has(role()) || !tabAllowed('operations')) {
      if (existing) existing.remove();
      serviceIndicatorInitialized = false;
      serviceActiveIds = new Set();
      return;
    }
    // Global operational signal: the queue must stay discoverable while a lobby
    // or field-role user works in Rooms, POS, Inventory, Operations, etc. Do not
    // force staff to remember to revisit the Operations tab to discover work.
    const utility = shellUtilityHost();
    if (!utility) return;
    let host = existing;
    if (!host) {
      host = document.createElement('div');
      host.id='ui-guest-center-service-launch';
      host.className='ui-guest-center-service-launch print:hidden';
      host.innerHTML='<button type="button" title="Permintaan non-teknis; tidak auto-charge"><span class="ui-guest-center-service-icon" aria-hidden="true">☏</span><span class="ui-guest-center-service-copy"><b>Permintaan Tamu</b><small>Memuat antrean…</small></span><span class="ui-guest-center-service-count" data-service-count hidden>0</span></button>';
      host.querySelector('button').addEventListener('click', openGuestServiceQueue);
    }
    if (host.parentElement !== utility) utility.appendChild(host);
    refreshGuestServiceIndicator(false, true).catch(() => {});
  }

  function serviceStatusLabel(value) {
    return ({open:'BARU',assigned:'DIAMBIL',in_progress:'DIKERJAKAN',fulfilled:'SELESAI',cancelled:'DIBATALKAN'})[String(value||'').toLowerCase()] || String(value||'-').toUpperCase();
  }

  function serviceContextLabel(value) {
    return ({in_house_guest:'TAMU MENGINAP',pre_arrival_guest:'PRE-ARRIVAL',non_room_guest:'NON-KAMAR / VISITOR',post_stay_guest:'PASCA CHECKOUT',legacy_unspecified:'LEGACY'})[String(value||'').toLowerCase()] || String(value||'-').toUpperCase();
  }
  function serviceContextLine(row) {
    const type=String(row&&row.context_type||'legacy_unspecified').toLowerCase();
    const guest=String(row&&row.guest_name_snapshot||row&&row.requester_name||'').trim();
    const room=String(row&&row.room_number||'').trim();
    const location=String(row&&row.location_label||'').trim();
    if(type==='in_house_guest') return (guest?guest+' · ':'')+(room?'Kamar '+room:'Kamar belum tersedia');
    if(type==='pre_arrival_guest') return 'Pre-arrival · '+(guest||'Tamu')+(room?' · Kamar '+room:'');
    if(type==='non_room_guest') return 'Non-kamar · '+(guest||'Visitor')+(location?' · '+location:'');
    if(type==='post_stay_guest') return 'Pasca checkout · '+(guest||'Tamu')+(room?' · Kamar terakhir '+room:'');
    return (guest?guest+' · ':'')+(room?'Kamar '+room:'Konteks legacy');
  }

  async function openGuestServiceForm(booking) {
    closeOverlay('ui-guest-center-service-form');
    const overlay=ensureRoot('ui-guest-center-service-form','ui-guest-center-overlay ui-guest-center-service-shell print:hidden');
    overlay.innerHTML='<div class="ui-guest-center-service-form"><div class="ui-guest-center-loading">Memuat konteks tamu…</div></div>';
    overlay.addEventListener('mousedown',(e)=>{if(e.target===overlay)closeOverlay('ui-guest-center-service-form');});
    try {
      const data=await hotelData(false);
      const linked=booking&&booking.id?(bookingById(data,booking.id)||booking):null;
      const linkedStatus=linked?bookingStatus(linked):'';
      if(linked&&!['active','reserved'].includes(linkedStatus)) throw new Error('Permintaan baru hanya dapat dibuat untuk booking active/reserved.');
      const serviceContexts=Array.isArray(data.guestServiceContexts)&&data.guestServiceContexts.length?data.guestServiceContexts:(Array.isArray(data.bookings)?data.bookings:[]);
      const activeBookings=serviceContexts.filter((b)=>bookingStatus(b)==='active').sort((a,b)=>String(a.roomNumber||'').localeCompare(String(b.roomNumber||''),undefined,{numeric:true}));
      const reservedBookings=serviceContexts.filter((b)=>bookingStatus(b)==='reserved').sort((a,b)=>String(a.checkIn||'').localeCompare(String(b.checkIn||'')));
      const canPreArrival=['admin','manager','receptionist'].includes(role());
      const linkedContext=linkedStatus==='active'?'in_house_guest':linkedStatus==='reserved'?'pre_arrival_guest':'';
      const bookingOptions=(rows,pre)=>'<option value="">Pilih '+(pre?'reservasi':'tamu/kamar')+'…</option>'+rows.map((b)=>'<option value="'+esc(b.id)+'">'+(pre?(esc(dateLabel(b.checkIn))+' · '):'')+esc(b.guestName||'Tamu')+' · Kamar '+esc(b.roomNumber||'-')+' · '+esc(b.id)+'</option>').join('');
      const contextSelector=linked
        ? '<input type="hidden" name="contextType" value="'+esc(linkedContext)+'"><input type="hidden" name="bookingId" value="'+esc(linked.id)+'"><div class="ui-guest-center-service-context-summary"><b>'+esc(linkedContext==='in_house_guest'?'Tamu sedang menginap':'Pre-arrival / reservasi')+'</b><span>'+esc(linked.guestName||'Tamu')+' · Kamar '+esc(linked.roomNumber||'-')+' · '+esc(linked.id)+'</span></div>'
        : '<label><span>Konteks permintaan</span><select name="contextType" required><option value="">Pilih siapa/lokasi pemilik request…</option><option value="in_house_guest">Tamu sedang menginap</option>'+(canPreArrival?'<option value="pre_arrival_guest">Tamu reservasi / pre-arrival</option>':'')+'<option value="non_room_guest">Tamu/visitor non-kamar</option></select></label><div data-context-fields></div>';
      overlay.innerHTML='<form class="ui-guest-center-service-form" role="dialog" aria-modal="true"><div class="ui-guest-center-wizard-head"><div><span class="ui-guest-center-eyebrow">Guest Service Request</span><h2>Permintaan Tamu</h2><p>'+(linked?esc(linked.guestName)+' · Kamar '+esc(linked.roomNumber):'Dicatat staf setelah request diterima. Bukan portal self-service tamu.')+'</p></div><button type="button" data-close>×</button></div>'+contextSelector+
        '<div class="ui-guest-center-service-grid"><label><span>Diterima melalui</span><select name="requestChannel"><option value="front_desk">Front desk / lobby</option><option value="phone">Telepon</option><option value="whatsapp">WhatsApp</option><option value="in_person">Disampaikan langsung</option><option value="staff_internal">Diteruskan staf</option><option value="other">Lainnya</option></select></label><label><span>Dibutuhkan pada (opsional)</span><input type="datetime-local" name="neededAt"></label></div>'+ 
        '<label><span>Jenis permintaan (bebas)</span><input name="requestType" required maxlength="120" placeholder="Contoh: Amenitas, Wake-up call, Luggage"></label>'+ 
        '<label><span>Detail kebutuhan</span><textarea name="description" required maxlength="2000" rows="3" placeholder="Contoh: 2 handuk tambahan; wake-up call 05:30; bantu luggage ke lobby"></textarea></label>'+ 
        '<div class="ui-guest-center-service-grid"><label><span>Prioritas</span><select name="priority"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label><label><span>Department / tujuan (opsional, bebas)</span><input name="department" maxlength="80" placeholder="HK, Front Office, Bell, F&B, lainnya"></label></div>'+ 
        '<div class="ui-guest-center-wizard-note"><b>Konteks wajib jelas:</b> tamu menginap mengambil booking+kamar canonical; pre-arrival mengambil reservasi; non-kamar wajib identitas/lokasi. Nomor kamar tidak diketik bebas. Kerusakan teknis gunakan Maintenance; kunci hilang gunakan Key Control; barang tertinggal gunakan Lost & Found. Permintaan ini tidak otomatis menjadi transaksi/Extra.</div>'+ 
        '<button class="ui-guest-center-wizard-submit" type="submit">Simpan Permintaan</button><div class="ui-guest-center-service-error" aria-live="polite"></div></form>';
      overlay.querySelector('[data-close]').addEventListener('click',()=>closeOverlay('ui-guest-center-service-form'));
      const form=overlay.querySelector('form');
      const contextFields=form.querySelector('[data-context-fields]');
      const renderContext=()=>{
        if(!contextFields)return;
        const type=String(form.elements.contextType?.value||'');
        if(type==='in_house_guest') contextFields.innerHTML='<label><span>Tamu / kamar aktif</span><select name="bookingId" required>'+bookingOptions(activeBookings,false)+'</select><small class="ui-guest-center-service-hint">Kamar otomatis mengikuti booking aktif; tidak dapat diketik manual.</small></label>';
        else if(type==='pre_arrival_guest') contextFields.innerHTML='<label><span>Reservasi / pre-arrival</span><select name="bookingId" required>'+bookingOptions(reservedBookings,true)+'</select><small class="ui-guest-center-service-hint">Booking wajib reserved. Kamar mengikuti alokasi reservasi.</small></label>';
        else if(type==='non_room_guest') contextFields.innerHTML='<div class="ui-guest-center-service-grid"><label><span>Nama / identitas peminta</span><input name="requesterName" maxlength="150" placeholder="Contoh: Pak Andi / visitor meeting"></label><label><span>Kontak (opsional)</span><input name="requesterContact" maxlength="190" placeholder="Telepon / WA / keterangan kontak"></label></div><label><span>Lokasi / konteks</span><input name="locationLabel" maxlength="150" placeholder="Contoh: Lobby, restoran, ruang meeting"><small class="ui-guest-center-service-hint">Isi minimal nama/identitas atau lokasi. Jangan gunakan nomor kamar di konteks ini.</small></label>';
        else contextFields.innerHTML='<div class="ui-guest-center-service-hint">Pilih konteks terlebih dahulu.</div>';
      };
      if(contextFields){form.elements.contextType.addEventListener('change',renderContext);renderContext();}
      form.addEventListener('submit',async(e)=>{
        e.preventDefault();const submit=form.querySelector('[type="submit"]'),error=form.querySelector('.ui-guest-center-service-error');error.textContent='';submit.disabled=true;
        const fd=new FormData(form);const contextType=linked?linkedContext:String(fd.get('contextType')||'');
        const payload={requestType:String(fd.get('requestType')||''),description:String(fd.get('description')||''),priority:String(fd.get('priority')||'normal'),department:String(fd.get('department')||''),contextType,bookingId:linked?String(linked.id):String(fd.get('bookingId')||''),roomNumber:'',requesterName:String(fd.get('requesterName')||''),requesterContact:String(fd.get('requesterContact')||''),locationLabel:String(fd.get('locationLabel')||''),requestChannel:String(fd.get('requestChannel')||'front_desk'),neededAt:String(fd.get('neededAt')||''),operationId:operationId('guest-service')};
        if(contextType==='non_room_guest'&&!payload.requesterName.trim()&&!payload.locationLabel.trim()){error.textContent='Isi nama/identitas peminta atau lokasi untuk request non-kamar.';submit.disabled=false;return;}
        try{await postOperations('guest-service-open',payload);closeOverlay('ui-guest-center-service-form');toast('Permintaan tamu tersimpan dengan konteks yang terverifikasi.','');if(linked&&document.getElementById('ui-guest-center-guest-drawer')){closeOverlay('ui-guest-center-guest-drawer');await openGuestCenter(linked.id);}}
        catch(err){error.textContent=err&&err.message?err.message:'Permintaan gagal disimpan.';submit.disabled=false;}
      });
      setTimeout(()=>overlay.querySelector('select[name="contextType"],input[name="requestType"]')?.focus(),0);
    } catch(err) {
      overlay.innerHTML='<section class="ui-guest-center-service-form"><div class="ui-guest-center-wizard-head"><div><span class="ui-guest-center-eyebrow">Guest Service Request</span><h2>Permintaan Tamu</h2></div><button type="button" data-close>×</button></div><div class="ui-guest-center-empty ui-guest-center-error">'+esc(err&&err.message?err.message:'Konteks tamu gagal dimuat.')+'</div></section>';
      overlay.querySelector('[data-close]').addEventListener('click',()=>closeOverlay('ui-guest-center-service-form'));
    }
  }

  async function openGuestServiceQueue() {
    closeOverlay('ui-guest-center-service-queue');
    const overlay=ensureRoot('ui-guest-center-service-queue','ui-guest-center-overlay ui-guest-center-service-shell print:hidden');
    overlay.innerHTML='<section class="ui-guest-center-service-queue" role="dialog" aria-modal="true"><div class="ui-guest-center-wizard-head"><div><span class="ui-guest-center-eyebrow">Operational Service Queue</span><h2>Permintaan Tamu</h2><p>Permintaan non-teknis. Tidak menjadi blocker kamar atau charge otomatis.</p></div><button type="button" data-close>×</button></div><div class="ui-guest-center-service-toolbar"><button type="button" data-new>＋ Permintaan baru</button><button type="button" data-refresh>↻ Perbarui</button></div><div data-list class="ui-guest-center-loading">Memuat antrean…</div></section>';
    overlay.addEventListener('mousedown',(e)=>{if(e.target===overlay)closeOverlay('ui-guest-center-service-queue');});
    overlay.querySelector('[data-close]').addEventListener('click',()=>closeOverlay('ui-guest-center-service-queue'));
    overlay.querySelector('[data-new]').addEventListener('click',()=>openGuestServiceForm(null));
    overlay.querySelector('[data-refresh]').addEventListener('click',()=>{invalidate();openGuestServiceQueue();});
    try {
      const data=await hotelData(true); const rows=(Array.isArray(data.guestServiceRequests)?data.guestServiceRequests:[]).filter((r)=>['open','assigned','in_progress'].includes(String(r.status||'').toLowerCase())).slice(0,100);
      const list=overlay.querySelector('[data-list]');
      if(!rows.length){list.className='ui-guest-center-empty';list.textContent='Tidak ada permintaan tamu aktif.';return;}
      list.className='ui-guest-center-service-list'; list.innerHTML=rows.map((r)=>'<article class="ui-guest-center-service-card" data-id="'+esc(r.id)+'"><header><div><b>'+esc(r.request_type||'Permintaan tamu')+'</b><small>'+esc(serviceContextLine(r))+' · '+esc(r.department||'Tujuan belum ditentukan')+'</small><small>'+esc(serviceContextLabel(r.context_type))+(r.request_channel?' · '+esc(String(r.request_channel).replace(/_/g,' ')):'')+(r.needed_at?' · Dibutuhkan '+esc(dateLabel(r.needed_at)):'')+'</small></div><span class="'+esc(String(r.priority||'normal'))+'">'+esc(serviceStatusLabel(r.status))+' · '+esc(String(r.priority||'normal').toUpperCase())+'</span></header><p>'+esc(r.description||'')+'</p><footer><small>'+esc(dateLabel(r.created_at))+(r.assigned_to?' · PIC '+esc(r.assigned_to):'')+'</small><div class="ui-guest-center-service-actions">'+(String(r.status)==='open'?'<button data-act="assigned">Ambil</button>':'')+(String(r.status)==='assigned'?'<button data-act="in_progress">Mulai</button>':'')+'<button data-act="fulfilled">Selesai</button>'+(['admin','manager','receptionist'].includes(role())?'<button data-act="cancelled" class="danger">Batalkan</button>':'')+'</div></footer></article>').join('');
      list.querySelectorAll('button[data-act]').forEach((btn)=>btn.addEventListener('click',async()=>{
        const card=btn.closest('[data-id]'), id=card?.dataset.id||'', act=btn.dataset.act; if(!id)return; btn.disabled=true;
        try{
          if(act==='assigned'||act==='in_progress') await postOperations('guest-service-progress',{id,status:act});
          else { const note=window.prompt(act==='fulfilled'?'Catatan penyelesaian:':'Alasan pembatalan:',''); if(note===null){btn.disabled=false;return;} await postOperations('guest-service-close',{id,status:act,note}); }
          await openGuestServiceQueue();
        }catch(err){toast(err&&err.message?err.message:'Gagal memproses permintaan.','error');btn.disabled=false;}
      }));
    } catch(err){const list=overlay.querySelector('[data-list]');list.className='ui-guest-center-empty ui-guest-center-error';list.textContent=err&&err.message?err.message:'Antrean gagal dimuat.';}
  }

  async function openGuestCenter(bookingId) {
    closeOverlay('ui-guest-center-guest-drawer'); currentBookingId = String(bookingId || '');
    const drawer = ensureRoot('ui-guest-center-guest-drawer', 'ui-guest-center-drawer-shell print:hidden');
    drawer.innerHTML = '<div class="ui-guest-center-drawer-backdrop"></div><aside class="ui-guest-center-drawer" role="dialog" aria-modal="true" aria-label="Pusat tamu"><div class="ui-guest-center-loading">Memuat Pusat Tamu…</div></aside>';
    drawer.querySelector('.ui-guest-center-drawer-backdrop').addEventListener('click', () => closeOverlay('ui-guest-center-guest-drawer'));
    try {
      const data = await hotelData(false), b = bookingById(data, bookingId);
      if (!b) throw new Error('Booking tidak tersedia untuk role akun ini atau sudah berubah.');
      const room = roomByNumber(data, b.roomNumber) || {};
      const status = bookingStatus(b);
      const canMutate = MUTATION_ROLES.has(role()) && tabAllowed('rooms');
      const balance = Number(b.balanceDue != null ? b.balanceDue : Math.max(0, Number(b.totalAmount || 0) - Number(b.amountPaid || b.downPaymentAmount || 0)));
      const required = Number(b.securityDepositRequiredAmount || 0), received = Number(b.securityDepositReceived || 0), refunded = Number(b.securityDepositRefunded || 0), forfeited = Number(b.securityDepositForfeited || 0);
      const held = Number(b.securityDepositHeld != null ? b.securityDepositHeld : Math.max(0, received - refunded - forfeited));
      const actions = [];
      actions.push(actionButton('open','Buka Reservasi & Kamar','↗','secondary'));
      if (status === 'active' && canMutate) {
        actions.push(actionButton('extra','Tambah Extra / Layanan','＋','safe'));
        actions.push(actionButton('serviceRequest','Permintaan Tamu','☏','safe'));
        actions.push(actionButton('extend','Perpanjang Menginap','＋','safe'));
        actions.push(actionButton('transfer','Pindah Kamar','⇄','warn'));
        actions.push(actionButton('payment','Pembayaran / Deposit','₨','safe'));
        if (tabAllowed('pos')) actions.push(actionButton('pos','POS / Minibar','▣','secondary'));
        actions.push(actionButton('print','Cetak Folio / Nota','▤','secondary'));
        actions.push(actionButton('checkout','Checkout','✓','primary'));
      } else if (status === 'reserved' && canMutate) {
        actions.push(actionButton('serviceRequest','Permintaan Pra-kedatangan','☏','safe'));
        actions.push(actionButton('payment','Pembayaran / Deposit','₨','safe'));
        actions.push(actionButton('print','Cetak Nota Booking','▤','secondary'));
        actions.push(actionButton('open','Buka untuk Check-in','↗','primary'));
      } else if (MONEY_ROLES.has(role()) && tabAllowed('finance')) {
        actions.push(actionButton('finance','Buka Keuangan','₨','secondary'));
      }
      const aside = drawer.querySelector('aside');
      aside.innerHTML = '<div class="ui-guest-center-drawer-head"><div><span class="ui-guest-center-eyebrow">Guest / Folio Action Center · V137</span><h2>'+esc(b.guestName || 'Tamu')+'</h2><p>Kamar '+esc(b.roomNumber || '-')+' · '+esc(b.roomType || room.type || '-')+'</p></div><button type="button" data-close aria-label="Tutup">×</button></div>'+
        '<div class="ui-guest-center-status-row"><span class="ui-guest-center-status '+esc(status)+'">'+esc(status === 'active' ? 'MENGINAP' : status === 'reserved' ? 'RESERVASI' : status.toUpperCase())+'</span><span>'+esc(b.bookingSource || 'Direct')+'</span><span>'+esc(b.id)+'</span></div>'+
        '<div class="ui-guest-center-summary-grid">'+metric('Check-in',dateLabel(b.actualCheckInAt || b.checkIn))+metric('Check-out',dateLabel(b.checkOut))+metric('Total Tagihan',money(b.totalAmount))+metric('Sudah Dibayar',money(b.amountPaid || b.downPaymentAmount))+metric('Sisa Tagihan',money(balance),balance > 0 ? 'warn':'ok')+metric('Deposit Ditahan',money(held),held > 0 ? 'info':'')+'</div>'+
        '<div class="ui-guest-center-safety-line"><strong>Pengaman:</strong> tombol di panel ini tidak membuat transaksi baru. Tindakan finansial/kamar diteruskan ke form resmi TAMASYA dan tetap divalidasi server.</div>'+
        '<section class="ui-guest-center-action-section"><h3>Tindakan</h3><div class="ui-guest-center-actions">'+actions.join('')+'</div></section>'+
        '<section class="ui-guest-center-timeline-section"><div class="ui-guest-center-section-title"><h3>Timeline Tamu</h3><button type="button" data-refresh>Perbarui</button></div><div class="ui-guest-center-timeline">'+timelineHtml(data,b)+'</div></section>'+
        '<div class="ui-guest-center-room-health"><b>Status kamar saat ini</b><span>'+esc(room.operationalStatus || room.status || '-')+'</span>'+(room.operationalBlockerMessage ? '<small>'+esc(room.operationalBlockerMessage)+'</small>' : '')+'</div>';
      aside.querySelector('[data-close]').addEventListener('click', () => closeOverlay('ui-guest-center-guest-drawer'));
      aside.querySelector('[data-refresh]').addEventListener('click', async () => { invalidate(); closeOverlay('ui-guest-center-guest-drawer'); await openGuestCenter(bookingId); });
      aside.querySelectorAll('[data-action]').forEach((btn) => btn.addEventListener('click', () => handleGuestAction(btn.dataset.action, b)));
    } catch (err) {
      drawer.querySelector('aside').innerHTML = '<div class="ui-guest-center-drawer-head"><h2>Pusat Tamu</h2><button type="button" data-close>×</button></div><div class="ui-guest-center-empty ui-guest-center-error">'+esc(err && err.message ? err.message : 'Data tamu gagal dimuat.')+'</div>';
      drawer.querySelector('[data-close]').addEventListener('click', () => closeOverlay('ui-guest-center-guest-drawer'));
    }
  }

  function actionButton(action, label, icon, tone) { return '<button type="button" class="ui-guest-center-action '+tone+'" data-action="'+esc(action)+'"><span>'+esc(icon)+'</span><b>'+esc(label)+'</b></button>'; }
  function metric(label, value, tone) { return '<div class="ui-guest-center-metric '+esc(tone || '')+'"><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong></div>'; }
  function timelineHtml(data, booking) {
    const entries = [];
    const add = (at, icon, title, detail) => { if (at) entries.push({ at: String(at), icon, title, detail }); };
    add(booking.createdAt, '📝', 'Reservasi dibuat', 'Booking '+booking.id+' · Kamar '+booking.roomNumber);
    add(booking.actualCheckInAt, '🔑', 'Check-in', 'Tamu mulai menginap di Kamar '+booking.roomNumber);
    (Array.isArray(booking.extras) ? booking.extras : []).forEach((x) => add(x.createdAt, '＋', 'Extra: '+(x.name || 'Layanan'), money(x.total != null ? x.total : Number(x.price || 0) * Number(x.qty || 1))+' · '+String(x.paymentStatus || '')));
    (Array.isArray(data.transactions) ? data.transactions : []).filter((tx) => String(tx.bookingId || '') === String(booking.id)).forEach((tx) => add(tx.createdAt || tx.date, '₨', tx.description || tx.category || 'Transaksi', money(tx.amount)+' · '+String(tx.transactionKind || tx.type || '')));
    (Array.isArray(data.guestServiceRequests) ? data.guestServiceRequests : []).filter((r) => String(r.booking_id || '') === String(booking.id)).forEach((r) => add(r.created_at, '☏', 'Permintaan: '+String(r.request_type || 'Layanan'), String(r.description || '')+' · '+serviceStatusLabel(r.status)+(r.needed_at?' · dibutuhkan '+dateLabel(r.needed_at):'')));
    add(booking.actualCheckOutAt, '✓', 'Checkout selesai', 'Booking ditutup oleh server.');
    if (!booking.actualCheckOutAt && booking.updatedAt && booking.updatedAt !== booking.createdAt) add(booking.updatedAt, '•', 'Data booking diperbarui', booking.updatedSource ? 'Sumber: '+booking.updatedSource : 'Perubahan terakhir booking.');
    entries.sort((a,b) => new Date(b.at).getTime() - new Date(a.at).getTime());
    if (!entries.length) return '<div class="ui-guest-center-empty">Belum ada histori yang dapat ditampilkan untuk role ini.</div>';
    return entries.slice(0, 30).map((e) => '<div class="ui-guest-center-timeline-item"><span>'+esc(e.icon)+'</span><div><strong>'+esc(e.title)+'</strong><small>'+esc(e.detail || '')+'</small><time>'+esc(dateLabel(e.at))+'</time></div></div>').join('');
  }

  async function handleGuestAction(action, booking) {
    if (action === 'serviceRequest') return openGuestServiceForm(booking);
    if (action === 'transfer') return openSafetyWizard('transfer', booking);
    if (action === 'checkout') return openSafetyWizard('checkout', booking);
    if (action === 'pos') { closeOverlay('ui-guest-center-guest-drawer'); return navigateToTab('pos'); }
    if (action === 'finance') { closeOverlay('ui-guest-center-guest-drawer'); return navigateToTab('finance'); }
    const labels = {
      extra: ['+ Extra / Layanan','Extra / Layanan'], extend: ['Perpanjang Sewa','Perpanjang'], payment: ['Terima Uang Panjar (DP)','Deposit'],
      print: ['Cetak Nota Booking (Thermal)','Cetak Nota Booking'], open: []
    };
    closeOverlay('ui-guest-center-guest-drawer');
    const ok = await navigateToBookingAndAction(booking, labels[action] || []);
    if (!ok && action !== 'open') toast('Booking sudah dibuka. Pilih tindakan '+(labels[action] && labels[action][0] ? labels[action][0] : action)+' pada panel tamu.', 'info');
  }

  function openSafetyWizard(kind, booking) {
    closeOverlay('ui-guest-center-safety-wizard');
    const shell = ensureRoot('ui-guest-center-safety-wizard','ui-guest-center-overlay ui-guest-center-wizard-shell print:hidden');
    shell.innerHTML = '<div class="ui-guest-center-wizard"><div class="ui-guest-center-wizard-head"><div><span class="ui-guest-center-eyebrow">Safety Wizard</span><h2>'+(kind === 'transfer' ? 'Pindah Kamar' : 'Checkout Tamu')+'</h2><p>'+esc(booking.guestName)+' · Kamar '+esc(booking.roomNumber)+'</p></div><button type="button" data-close>×</button></div><div class="ui-guest-center-loading">Menjalankan preflight aman…</div></div>';
    shell.addEventListener('mousedown', (e) => { if (e.target === shell) closeOverlay('ui-guest-center-safety-wizard'); });
    shell.querySelector('[data-close]').addEventListener('click', () => closeOverlay('ui-guest-center-safety-wizard'));
    renderSafetyWizard(kind, booking).catch((err) => {
      const body = shell.querySelector('.ui-guest-center-loading'); if (body) body.outerHTML = '<div class="ui-guest-center-empty ui-guest-center-error">'+esc(err && err.message ? err.message : 'Preflight gagal.')+'</div>';
    });
  }

  async function renderSafetyWizard(kind, booking) {
    const shell = document.getElementById('ui-guest-center-safety-wizard'); if (!shell) return;
    const data = await hotelData(true); const b = bookingById(data, booking.id);
    if (!b) throw new Error('Booking berubah atau tidak lagi tersedia. Muat ulang sebelum melanjutkan.');
    const status = bookingStatus(b), room = roomByNumber(data, b.roomNumber) || {};
    const balance = Number(b.balanceDue != null ? b.balanceDue : Math.max(0, Number(b.totalAmount || 0) - Number(b.amountPaid || b.downPaymentAmount || 0)));
    const held = Number(b.securityDepositHeld || 0);
    const online = navigator.onLine !== false;
    let checks = [];
    let content = '';
    let blocked = false;
    if (kind === 'transfer') {
      const rooms = sellableRooms(data, b);
      checks = [
        ['Booking masih aktif', status === 'active', 'Pindah kamar hanya untuk tamu yang sudah check-in.'],
        ['Browser online', online, 'Pindah kamar aktif wajib online agar kamar asal/tujuan, housekeeping, kunci dan audit diproses atomik.'],
        ['Ada kamar tujuan yang benar-benar sellable', rooms.length > 0, 'Kamar dirty/maintenance/ber-blocker tidak ditawarkan.'],
        ['Alur transaksi asli tetap digunakan', true, 'Wizard ini tidak melakukan PUT/POST sendiri.']
      ];
      blocked = checks.some((x) => !x[1]);
      content = '<div class="ui-guest-center-wizard-info"><div><span>Sisa tagihan</span><b>'+money(balance)+'</b></div><div><span>Deposit ditahan</span><b>'+money(held)+'</b></div><div><span>Status kunci</span><b>'+esc(b.keyControlStatus || 'not_issued')+'</b></div></div>'+
        '<label class="ui-guest-center-wizard-field"><span>Kamar tujuan yang tersedia</span><select data-room-choice '+(rooms.length ? '' : 'disabled')+'><option value="">-- pilih untuk panduan --</option>'+rooms.map((r) => '<option value="'+esc(r.number)+'">Kamar '+esc(r.number)+' · '+esc(r.type || '-')+' · '+money(r.price)+'</option>').join('')+'</select><small>Pilihan ini hanya panduan. Form resmi TAMASYA tetap meminta Anda mengonfirmasi kamar tujuan.</small></label>';
    } else {
      const smartNeedsOnline = ['smart','hybrid'].includes(String(b.accessMode || 'physical').toLowerCase());
      checks = [
        ['Booking masih aktif', status === 'active', 'Hanya booking aktif yang dapat di-checkout.'],
        ['Akses smart-lock dapat diproses', !smartNeedsOnline || online, smartNeedsOnline ? 'Smart/hybrid checkout wajib online untuk mencabut akses.' : 'Kunci fisik tetap dikonfirmasi pada form resmi.'],
        ['Saldo ditampilkan untuk direkonsiliasi', Number.isFinite(balance) && balance >= 0, 'Server akan menghentikan checkout bila ledger tidak konsisten.'],
        ['Deposit ditampilkan untuk settlement', true, held > 0 ? 'Ada deposit yang harus diputuskan refund/hold/forfeit di form resmi.' : 'Tidak ada saldo deposit ditahan pada proyeksi saat ini.'],
        ['Alur checkout asli tetap digunakan', true, 'Finalisasi, jurnal, pajak, housekeeping, key control dan audit tetap dilakukan backend resmi.']
      ];
      blocked = checks.some((x) => !x[1]);
      content = '<div class="ui-guest-center-wizard-info"><div><span>Total tagihan</span><b>'+money(b.totalAmount)+'</b></div><div><span>Sudah dibayar</span><b>'+money(b.amountPaid || b.downPaymentAmount)+'</b></div><div class="'+(balance > 0 ? 'warn':'ok')+'"><span>Sisa pelunasan</span><b>'+money(balance)+'</b></div><div><span>Deposit ditahan</span><b>'+money(held)+'</b></div><div><span>Mode akses</span><b>'+esc(b.accessMode || 'physical')+'</b></div><div><span>Status kunci</span><b>'+esc(b.keyControlStatus || 'not_issued')+'</b></div></div>';
    }
    const wizard = shell.querySelector('.ui-guest-center-wizard');
    wizard.querySelector('.ui-guest-center-loading').outerHTML = '<div class="ui-guest-center-wizard-body">'+
      '<div class="ui-guest-center-check-list">'+checks.map((c) => '<div class="ui-guest-center-check '+(c[1]?'pass':'fail')+'"><span>'+(c[1]?'✓':'!')+'</span><div><strong>'+esc(c[0])+'</strong><small>'+esc(c[2])+'</small></div></div>').join('')+'</div>'+content+
      '<div class="ui-guest-center-wizard-note"><b>Tidak ada transaksi yang dieksekusi dari wizard ini.</b> Setelah preflight, Anda tetap masuk ke form resmi sehingga seluruh validasi lama tetap aktif.</div>'+(
        blocked ? '<button type="button" class="ui-guest-center-wizard-submit" disabled>Belum Aman untuk Dilanjutkan</button>' : '<button type="button" class="ui-guest-center-wizard-submit">'+(kind === 'transfer' ? 'Buka Form Resmi Pindah Kamar' : 'Buka Form Resmi Checkout')+'</button>')+'</div>';
    const submit = wizard.querySelector('.ui-guest-center-wizard-submit:not([disabled])');
    if (submit) submit.addEventListener('click', async () => {
      closeOverlay('ui-guest-center-safety-wizard'); closeOverlay('ui-guest-center-guest-drawer');
      const label = kind === 'transfer' ? ['Pindah Kamar'] : ['Check-Out Tamu (Selesai)'];
      const ok = await navigateToBookingAndAction(b, label);
      if (!ok) toast('Booking sudah dibuka. Gunakan tombol '+label[0]+' pada panel resmi.', 'info');
    });
  }

  async function navigateToTab(tab) {
    try {
      if (window.TAMASYA_NAVIGATION?.navigate && window.TAMASYA_NAVIGATION.navigate(tab)) { await wait(120); return true; }
    } catch {}
    const btn = document.getElementById('tab-' + tab.replace(/_/g,'-'));
    if (btn) { btn.click(); await wait(120); return true; }
    if (tab === 'pos') {
      const pos = document.querySelector('a[href*="pos.html"],button[data-tab="pos"]'); if (pos) { pos.click(); return true; }
    }
    return false;
  }
  async function navigateToRooms(roomNumber) {
    await navigateToTab('rooms');
    await waitFor(() => currentRoute() === 'rooms', 2500);
    if (roomNumber) {
      const candidate = findClickableByRoom(roomNumber); if (candidate) { candidate.click(); candidate.scrollIntoView({ behavior:'smooth', block:'center' }); return true; }
    }
    return true;
  }
  function findClickableByRoom(roomNumber) {
    const number = String(roomNumber || '').trim(); if (!number) return null;
    const candidates = Array.from(document.querySelectorAll('button,[role="button"]')).filter((el) => {
      const t = textOf(el); return t === number || t.includes('Kamar ' + number) || (t.includes(number) && /kamar/i.test(t));
    });
    return candidates.find((el) => !el.closest('[id^="ui-guest-center-"]')) || null;
  }
  async function navigateToBookingAndAction(booking, labels) {
    await navigateToRooms(booking.roomNumber);
    // Room selection often reveals the authoritative booking action panel.
    let action = await waitFor(() => findContextAction(booking, labels), 3200);
    if (action) { action.click(); action.scrollIntoView({ behavior:'smooth', block:'center' }); return true; }
    const room = findClickableByRoom(booking.roomNumber); if (room) { room.click(); await wait(120); }
    action = await waitFor(() => findContextAction(booking, labels), 2600);
    if (action) { action.click(); action.scrollIntoView({ behavior:'smooth', block:'center' }); return true; }
    return labels.length === 0;
  }
  function actionLabelVariants(label) { return [String(label || '')]; }
  function findContextAction(booking, labels) {
    if (!labels.length) return null;
    const buttons = Array.from(document.querySelectorAll('button')).filter((btn) => labels.some((label) => actionLabelVariants(label).some((candidate) => textOf(btn).includes(candidate))) && !btn.closest('[id^="ui-guest-center-"]'));
    if (!buttons.length) return null;
    const guest = String(booking.guestName || ''), room = String(booking.roomNumber || '');
    for (const btn of buttons) {
      let node = btn;
      for (let depth=0; node && depth<7; depth++, node=node.parentElement) {
        const txt = textOf(node);
        if ((!guest || txt.includes(guest)) && (!room || txt.includes(room))) return btn;
      }
    }
    return buttons.length === 1 ? buttons[0] : null;
  }
  function wait(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }
  async function waitFor(fn, timeout) {
    const start = Date.now();
    while (Date.now() - start < timeout) { try { const value = fn(); if (value) return value; } catch {} await wait(80); }
    return null;
  }
  function toast(message, tone) {
    const el = document.createElement('div'); el.className = 'ui-guest-center-toast '+(tone || ''); el.textContent = message; document.body.appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  function exposeGuestShortcuts() {
    // Existing room page remains authoritative. The guest center only adds a small shortcut next to an opened active booking when identifiable.
    if (currentRoute() !== 'rooms' || !loggedIn()) return;
    if (document.querySelector('[data-ui-guest-center-guest-shortcut]')) return;
    const checkout = document.getElementById('btn-checkout-guest');
    if (!checkout) return;
    let host = checkout.parentElement;
    for (let i=0; host && i<5; i++, host=host.parentElement) {
      const txt = textOf(host);
      if (/Check-Out Tamu|Perpanjang Sewa|Extra \/ Layanan/.test(txt)) break;
    }
    if (!host) return;
    const nameNode = Array.from(host.querySelectorAll('h1,h2,h3,h4,p,strong')).find((el) => {
      const txt=textOf(el); return txt && !/Check-Out|Perpanjang|Tagihan|Deposit|Kamar|Extra|Status/i.test(txt) && txt.length < 90;
    });
    const roomMatch = textOf(host).match(/Kamar\s*([A-Za-z0-9-]+)/i);
    const guestName = nameNode ? textOf(nameNode) : '';
    if (!guestName && !roomMatch) return;
    const shortcut = document.createElement('button'); shortcut.type='button'; shortcut.dataset.uiGuestCenterShortcut='1'; shortcut.className='ui-guest-center-inline-shortcut'; shortcut.textContent='◎ Pusat Tamu';
    shortcut.addEventListener('click', async () => {
      try { const data=await hotelData(false); const b=(data.bookings||[]).find((x) => bookingStatus(x)==='active' && ((guestName && String(x.guestName)===guestName) || (roomMatch && String(x.roomNumber)===String(roomMatch[1])))); if (b) openGuestCenter(b.id); else openSearch(); }
      catch { openSearch(); }
    });
    checkout.parentElement.insertAdjacentElement('beforebegin',shortcut);
  }

  function mutationNeedsApply(records) {
    for (const record of records || []) {
      if (record.type === 'attributes' && record.attributeName === 'data-active') {
        const target = record.target;
        if (target && target.nodeType === 1 && (target.classList?.contains('nav-tab') || String(target.id || '').startsWith('tab-'))) return true;
      }
      if (record.type === 'childList') {
        const nodes = [...record.addedNodes, ...record.removedNodes];
        for (const node of nodes) {
          if (!node || node.nodeType !== 1) continue;
          if (node.id && (String(node.id).startsWith('ui-core-role-') || String(node.id).startsWith('ui-guest-center-'))) continue;
          if (node.matches?.('.nav-scroll,.nav-tab,[id^="tab-"]') || node.querySelector?.('.nav-scroll,.nav-tab,[id^="tab-"]')) return true;
          if (node.matches?.('#btn-checkout-guest') || node.querySelector?.('#btn-checkout-guest')) return true;
        }
      }
    }
    return false;
  }
  function observeStableRoot() {
    if (!observer) return;
    observer.observe(document.getElementById('root') || document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['data-active']});
  }
  function schedule() { if (scheduled) return; scheduled = true; requestAnimationFrame(() => { scheduled=false; if (observer) observer.disconnect(); ensureSearchLauncher(); ensureGuestServiceQueueLauncher(); exposeGuestShortcuts(); observeStableRoot(); }); }
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 'k') { e.preventDefault(); if (loggedIn()) openSearch(); }
    if (e.key === 'Escape') closeAll();
  });
  document.addEventListener('click', (e) => { if (e.target.closest('.nav-tab')) { invalidate(); setTimeout(schedule,30); } }, true);
  window.addEventListener('storage', schedule);
  window.addEventListener('tamasya-module-dock-ready', schedule);
  window.addEventListener('tamasya-shell-utility-ready', schedule);
  window.addEventListener('online', () => { invalidate(); refreshGuestServiceIndicator(true, true).catch(() => {}); });
  window.addEventListener('offline', invalidate);
  // Lightweight visual alert refresh. The dedicated indicator endpoint returns
  // only active request metadata, so a 30s visible-tab poll does not download
  // booking/finance/inventory payloads merely to discover lobby work.
  window.setInterval(() => {
    if (document.visibilityState === 'visible' && loggedIn() && GUEST_SERVICE_ROLES.has(role()) && tabAllowed('operations')) {
      refreshGuestServiceIndicator(true, true).catch(() => {});
    }
  }, 30000);
  document.addEventListener('DOMContentLoaded', schedule);
  observer = new MutationObserver((records) => { if (mutationNeedsApply(records)) schedule(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', observeStableRoot);
  else observeStableRoot();
  schedule();
  window.TAMASYA_GUEST_CENTER = Object.freeze({ version: VERSION, mutationMode: 'delegate-to-existing-authoritative-forms', refresh: invalidate, openGuestCenter });
})();
