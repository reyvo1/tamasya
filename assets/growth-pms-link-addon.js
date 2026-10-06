/* TAMASYA Growth Integration Addon (G2-PMS-LINK).
 * Additive only: never inserts/removes/moves nodes inside React #root.
 *
 * Tiga kemampuan (semua fail-closed, hanya aktif saat flag Growth ON):
 *   1. Deep-link: tombol "Growth: Folio" / "Growth: Group" pada kartu booking
 *      (observer menempelkan tombol pada elemen yang memuat ID booking).
 *   2. KPI mini read-only di dashboard (Occupancy/ADR/RevPAR dari KPI G1).
 *   3. Saran harga Revenue Manager read-only saat membuat reservasi
 *      (rate-suggestion; core tetap sumber kebenaran, tidak memaksa harga).
 */
(function () {
  'use strict';
  if (window.__TAMASYA_GROWTH_LINK_ADDON__) return;
  window.__TAMASYA_GROWTH_LINK_ADDON__ = true;

  var VERSION = '20260910-production-audit-r4-growth-link';
  var STYLE_ID = 'tamasya-growth-link-style';
  var KPI_PANEL_ID = 'tamasya-growth-kpi-mini';
  var SUGGEST_BOX_ID = 'tamasya-growth-suggest-box';

  var esc = function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  var money = function (n) {
    try {
      return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(n || 0));
    } catch (e) { return String(n); }
  };
  var role = function () {
    return String(sessionStorage.getItem('hotel_staff_role') || sessionStorage.getItem('hotel_role') || '').trim().toLowerCase();
  };
  var loggedIn = function () { return sessionStorage.getItem('hotel_logged_in') === 'true'; };

  /* ---------- runtime features (dari runtime_config.php, sama seperti growth-suite.js) ---------- */
  function features() {
    var rc = window.TAMASYA_RUNTIME_CONFIG || {};
    return rc.features || {};
  }
  function growthOn() { return features().growthSuiteEnabled === true; }

  function baseUrl() {
    // base direktori admin_app, aman untuk subfolder shared hosting
    var path = window.location.pathname.replace(/\/+$/, '');
    var routes = ['dashboard', 'rooms', 'finance', 'report', 'telegram', 'staff', 'config', 'db_config',
      'inventory', 'leaves', 'attendance', 'operations', 'activity', 'website', 'support', 'savings', 'pos', 'login'];
    for (var i = 0; i < routes.length; i++) {
      if (path.endsWith('/' + routes[i])) { path = path.slice(0, -(routes[i].length + 1)); break; }
    }
    if (path.endsWith('/index.html')) path = path.slice(0, -11);
    return path + (path.endsWith('/') ? '' : '/');
  }
  function growthUrl(params) {
    var url = new URL('./growth-suite.html', window.location.origin + baseUrl());
    Object.keys(params || {}).forEach(function (k) { url.searchParams.set(k, params[k]); });
    return url.toString();
  }

  /* ---------- API kecil khusus GET (idempotent, tanpa mutasi) ---------- */
  function apiGet(command, query) {
    var url = new URL('./api.php', window.location.origin + baseUrl());
    url.searchParams.set('action', 'growth-suite');
    url.searchParams.set('command', command);
    Object.keys(query || {}).forEach(function (k) {
      if (query[k] !== undefined && query[k] !== null) url.searchParams.set(k, String(query[k]));
    });
    url.searchParams.set('_ts', Date.now());
    var headers = { 'Accept': 'application/json' };
    var token = sessionStorage.getItem('hotel_session_token') || '';
    if (token) headers.Authorization = 'Bearer ' + token;
    var scope = String(sessionStorage.getItem('hotel_offline_hotel_scope') || '').trim();
    if (scope) headers['X-Tamasya-Hotel-Scope'] = scope;
    return fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', headers: headers })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (d) {
        if (!d || d.success === false) throw new Error((d && (d.error || d.message)) || 'Respons tidak valid');
        return d.data;
      });
  }

  /* ---------- style ---------- */
  function installStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var s = document.createElement('style');
    s.id = STYLE_ID;
    s.textContent =
      '.tgl-actions{display:inline-flex;gap:4px;margin-left:6px;vertical-align:middle}' +
      '.tgl-btn{border:1px solid rgba(148,163,184,.35);background:rgba(15,23,42,.06);border-radius:8px;padding:3px 8px;font-size:11px;font-weight:700;cursor:pointer;color:#17324d;text-decoration:none;display:inline-flex;align-items:center;gap:3px;line-height:1.4}' +
      '.tgl-btn:hover{background:rgba(23,50,77,.12)}' +
      '#' + KPI_PANEL_ID + '{margin:12px 0;border:1px solid rgba(148,163,184,.25);border-radius:14px;background:linear-gradient(135deg,rgba(23,50,77,.04),rgba(17,97,73,.05));padding:10px 14px;font-size:12px;color:#334155}' +
      '#' + KPI_PANEL_ID + ' .tgl-kpi-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px}' +
      '#' + KPI_PANEL_ID + ' .tgl-kpi-head strong{font-size:12px;letter-spacing:.02em;text-transform:uppercase;color:#17324d}' +
      '#' + KPI_PANEL_ID + ' .tgl-kpi-grid{display:flex;flex-wrap:wrap;gap:8px}' +
      '#' + KPI_PANEL_ID + ' .tgl-kpi-card{min-width:110px;border:1px solid rgba(148,163,184,.2);border-radius:10px;padding:7px 10px;background:rgba(255,255,255,.65)}' +
      '#' + KPI_PANEL_ID + ' .tgl-kpi-card span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}' +
      '#' + KPI_PANEL_ID + ' .tgl-kpi-card strong{display:block;font-size:14px;color:#0f172a}' +
      '#' + KPI_PANEL_ID + ' .tgl-link{font-size:11px;font-weight:700;color:#116149;text-decoration:none;border-bottom:1px dotted currentColor}' +
      '#' + SUGGEST_BOX_ID + '{margin-top:6px;border:1px dashed rgba(17,97,73,.45);border-radius:10px;padding:7px 10px;font-size:12px;color:#334155;background:rgba(17,97,73,.05)}' +
      '#' + SUGGEST_BOX_ID + ' strong{color:#116149}' +
      '#' + SUGGEST_BOX_ID + ' .tgl-muted{color:#64748b;font-size:11px}';
    document.head.appendChild(s);
  }

  /* ================= FITUR 1: deep-link folio/group pada kartu booking ================= */
  var BOOKING_ID_RE = /(BK|BKG|BOOK)[-_]?(\d{3,12})/i;

  function decorateBookingCard(el) {
    if (el.dataset.tglDone === '1') return;
    var text = (el.getAttribute('data-booking-id') || el.getAttribute('data-id') ||
      (el.textContent || '').slice(0, 400));
    var m = String(text).match(BOOKING_ID_RE);
    if (!m) return;
    var bookingId = m[0].toUpperCase().replace(/[^A-Z0-9_-]/g, '');
    if (!bookingId) return;
    var r = role();
    if (!['admin', 'manager', 'finance'].includes(r)) return;
    if (!growthOn()) return;
    el.dataset.tglDone = '1';
    var bar = document.createElement('span');
    bar.className = 'tgl-actions';
    bar.innerHTML =
      '<a class="tgl-btn" title="Buka Guest Folio booking ini di Growth Suite" href="' +
      esc(growthUrl({ focus: 'folio', bookingId: bookingId })) + '">📈 Folio</a>' +
      '<a class="tgl-btn" title="Cari & tautkan booking ini ke Group di Growth Suite" href="' +
      esc(growthUrl({ focus: 'group', q: bookingId })) + '">👥 Group</a>';
    bar.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function (e) { e.stopPropagation(); }); });
    el.appendChild(bar);
  }

  function scanBookingCards(root) {
    if (!loggedIn() || !growthOn()) return;
    var scope = root && root.querySelectorAll ? root : document;
    // heuristik aman: hanya kartu/row yang jelas memuat ID booking
    var candidates = scope.querySelectorAll('[data-booking-id],[data-id],[class*="booking" i],tr,[class*="card" i]');
    var n = Math.min(candidates.length, 400); // batas aman observer
    for (var i = 0; i < n; i++) decorateBookingCard(candidates[i]);
  }

  /* ================= FITUR 2: KPI mini read-only di dashboard ================= */
  function ensureKpiPanel() {
    if (!loggedIn() || !growthOn()) { removeKpiPanel(); return null; }
    var existing = document.getElementById(KPI_PANEL_ID);
    if (existing) return existing.querySelector('.tgl-kpi-grid') ? existing : existing;
    // tempel DI LUAR #root: fixed strip tepat setelah panel root secara visual
    var rootEl = document.getElementById('root');
    if (!rootEl || !document.body.contains(rootEl)) return null;
    // hanya tampil saat user ada di dashboard/route utama
    var p = decodeURIComponent(window.location.pathname).toLowerCase();
    var onDashboard = /\/(dashboard)?\/?$/.test(new URL(window.location.href).pathname.toLowerCase()) ||
      window.location.hash === '' || window.location.hash === '#dashboard';
    if (!onDashboard) { removeKpiPanel(); return null; }
    if (existing) return existing;
    var panel = document.createElement('div');
    panel.id = KPI_PANEL_ID;
    panel.setAttribute('data-addon', 'growth-link');
    panel.innerHTML =
      '<div class="tgl-kpi-head"><strong>Growth · KPI Hotel (read-only)</strong>' +
      '<div><a class="tgl-link" href="' + esc(growthUrl({ focus: 'kpi' })) + '">Detail KPI →</a></div></div>' +
      '<div class="tgl-kpi-grid"><div class="tgl-kpi-card"><span>Memuat…</span><strong>—</strong></div></div>';
    document.body.appendChild(panel);
    positionKpiPanel(panel);
    loadKpiMini(panel);
    return panel;
  }
  function positionKpiPanel(panel) {
    // posisi: bawah-tengah, tidak menghalangi klik (pointer-events none di container)
    panel.style.position = 'fixed';
    panel.style.left = '50%';
    panel.style.transform = 'translateX(-50%)';
    panel.style.bottom = '10px';
    panel.style.zIndex = '2147481000';
    panel.style.maxWidth = '96vw';
    panel.style.boxShadow = '0 8px 30px rgba(2,6,23,.18)';
    panel.style.pointerEvents = 'auto';
  }
  function removeKpiPanel() {
    var el = document.getElementById(KPI_PANEL_ID);
    if (el) el.remove();
  }
  var kpiCache = null, kpiCacheAt = 0, kpiCacheDate = '';
  function loadKpiMini(panel) {
    var today = new Date();
    var from = window.TamasyaPosBusinessDatePolicy.dateAt(today, window.TamasyaPropertyBranding && window.TamasyaPropertyBranding.timezone);
    var grid = panel.querySelector('.tgl-kpi-grid');
    if (!grid) return;
    if (kpiCache && kpiCacheDate === from && Date.now() - kpiCacheAt < 5 * 60 * 1000) { renderKpiMini(grid, kpiCache); return; }
    apiGet('kpis', { from: from, to: from }).then(function (k) {
      kpiCache = k; kpiCacheAt = Date.now(); kpiCacheDate = from;
      renderKpiMini(grid, k);
    }).catch(function (e) {
      grid.innerHTML = '<div class="tgl-kpi-card"><span>KPI</span><strong>—</strong></div>';
      panel.title = 'KPI tidak tersedia: ' + e.message;
    });
  }
  function renderKpiMini(grid, k) {
    var num = function (n, d) { d = d == null ? 1 : d; try { return new Intl.NumberFormat('id-ID', { maximumFractionDigits: d }).format(Number(n || 0)); } catch (e) { return String(n); } };
    var cards = [
      ['Occupancy', num(k.occupancyPct) + '%'],
      ['ADR', money(k.adr)],
      ['RevPAR', money(k.revpar)],
      ['Room terjual', num(k.soldRoomNights, 0)]
    ];
    grid.innerHTML = cards.map(function (c) {
      return '<div class="tgl-kpi-card"><span>' + esc(c[0]) + '</span><strong>' + esc(c[1]) + '</strong></div>';
    }).join('');
  }

  /* ================= FITUR 3: saran harga Revenue Manager (read-only) ================= */
  var suggestTimer = null, suggestCtl = null;

  function findReservationInputs() {
    // cari form reservasi generik: select tipe kamar/kamar + input tanggal checkin
    var selects = Array.prototype.slice.call(document.querySelectorAll('#root select'));
    var roomTypeSel = selects.find(function (s) {
      var lbl = ((s.previousElementSibling && s.previousElementSibling.textContent) || s.name || s.id || '').toLowerCase();
      var opts = Array.prototype.slice.call(s.options || []).map(function (o) { return o.text.toLowerCase(); }).join('|');
      return /tipe|type/.test(lbl) || /standard|deluxe|suite|superior|family/.test(opts);
    }) || null;
    var dateIns = Array.prototype.slice.call(document.querySelectorAll('#root input[type="date"]'));
    var checkin = dateIns[0] || null;
    var checkout = dateIns[1] || null;
    return { roomTypeSel: roomTypeSel, checkin: checkin, checkout: checkout };
  }

  function ensureSuggestBox(anchor) {
    var box = document.getElementById(SUGGEST_BOX_ID);
    if (box) return box;
    box = document.createElement('div');
    box.id = SUGGEST_BOX_ID;
    box.setAttribute('data-addon', 'growth-link');
    box.innerHTML = '<strong>📈 Saran harga (Revenue Manager)</strong> <span class="tgl-muted">read-only — harga final tetap kamu tentukan</span>';
    anchor.parentNode.insertBefore(box, anchor.nextSibling);
    return box;
  }
  function hideSuggestBox() {
    var box = document.getElementById(SUGGEST_BOX_ID);
    if (box) box.remove();
  }

  var planCache = null, planCacheAt = 0;
  function pickPlanId(plans, roomType) {
    var active = (plans || []).filter(function (p) { return p && (p.active === true || Number(p.active) === 1); });
    if (!active.length) return '';
    var wanted = String(roomType || '').toLowerCase();
    if (wanted) {
      var byType = active.filter(function (p) { return String(p.room_type || '').toLowerCase() === wanted; });
      if (byType.length) return String(byType[0].id || '');
    }
    var generic = active.filter(function (p) { return String(p.room_type || '') === ''; });
    return String((generic[0] || active[0]).id || '');
  }
  function resolveActivePlanId(roomType) {
    var now = Date.now();
    if (planCache && (now - planCacheAt) < 300000) return Promise.resolve(pickPlanId(planCache, roomType));
    return apiGet('bootstrap', {}).then(function (d) {
      planCache = (d && d.ratePlans) || [];
      planCacheAt = Date.now();
      return pickPlanId(planCache, roomType);
    });
  }

  function requestSuggestion() {
    if (!loggedIn() || !growthOn()) return;
    var r = role();
    if (!['admin', 'manager'].includes(r)) return;
    var inputs = findReservationInputs();
    if (!inputs.checkin) { hideSuggestBox(); return; }
    var roomType = inputs.roomTypeSel ? inputs.roomTypeSel.value : '';
    var stayDate = inputs.checkin.value;
    var los = 1;
    if (inputs.checkin && inputs.checkout && inputs.checkin.value && inputs.checkout.value) {
      var d1 = new Date(inputs.checkin.value), d2 = new Date(inputs.checkout.value);
      var diff = Math.round((d2 - d1) / 86400000);
      if (diff > 0) los = diff;
    }
    if (!stayDate) { hideSuggestBox(); return; }
    clearTimeout(suggestTimer);
    suggestTimer = setTimeout(function () {
      var box = ensureSuggestBox(inputs.checkin);
      box.innerHTML += ''; // keep header
      resolveActivePlanId(roomType)
        .then(function (planId) {
          if (!planId) {
            box.innerHTML = '<strong>📈 Saran harga</strong> <span class="tgl-muted">tidak tersedia (butuh rate plan aktif di Growth Suite)</span>';
            return null;
          }
          return apiGet('rate-suggestion', { planId: planId, stayDate: stayDate, lengthOfStay: los, roomType: roomType })
            .then(function (x) {
              var breakdown = (x.breakdown || []).map(function (b) {
                return esc(b.name) + ': ' + money(b.before) + ' → ' + money(b.after);
              }).join('<br>');
              box.innerHTML =
                '<strong>📈 Saran harga ' + money(x.rate) + '</strong>' +
                ' <span class="tgl-muted">· occupancy ' + Number(x.context && x.context.occupancyPct || 0).toFixed(1) +
                '% · LOS ' + los + ' malam' + (x.stopSell ? ' · <b>STOP SELL</b>' : '') +
                ' — read-only, harga final tetap di input kamu</span>' +
                (breakdown ? '<br><span class="tgl-muted">' + breakdown + '</span>' : '');
            });
        })
        .catch(function () { box.innerHTML = '<strong>📈 Saran harga</strong> <span class="tgl-muted">tidak tersedia (butuh rate plan aktif di Growth Suite)</span>'; });
    }, 600);
  }

  /* ================= wiring ================= */
  function tick() {
    installStyle();
    try { scanBookingCards(document); } catch (e) { /* noop */ }
    try { ensureKpiPanel(); } catch (e) { /* noop */ }
    try { requestSuggestion(); } catch (e) { /* noop */ }
  }

  function start() {
    installStyle();
    var scheduled = null;
    var scheduleTick = function (delay) {
      if (document.visibilityState !== 'visible') return;
      if (scheduled) clearTimeout(scheduled);
      scheduled = setTimeout(function () { scheduled = null; tick(); }, delay == null ? 180 : delay);
    };
    scheduleTick(0);
    var rootEl = document.getElementById('root');
    if (rootEl && typeof MutationObserver !== 'undefined') {
      var observer = new MutationObserver(function () { scheduleTick(220); });
      observer.observe(rootEl, { childList: true, subtree: true });
    }
    // Fallback ringan jika ada perubahan DOM yang tidak tertangkap observer.
    setInterval(function () { if (document.visibilityState === 'visible') scheduleTick(0); }, 15000);
    window.addEventListener('hashchange', function () { scheduleTick(0); });
    window.addEventListener('focus', function () { scheduleTick(0); });
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') scheduleTick(0); });
    ['change'].forEach(function (evt) {
      document.addEventListener(evt, function (e) {
        if (e.target && e.target.closest && e.target.closest('#root')) scheduleTick(120);
      }, true);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else { start(); }
})();
