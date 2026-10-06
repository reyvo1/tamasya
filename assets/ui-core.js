/* TAMASYA V137 admin UI compatibility + shell utility controller.
 * AUDIT12 ROOT FIX:
 * - React remains the sole owner of #root primary navigation.
 * - This file MUST NOT create, hide, reorder, insert, or remove navigation nodes.
 * - Domain groups are rendered natively by the React app shell.
 * - Session utilities (role badge only) are mounted into the independent
 *   Module Hotel dock utility host; search/service launchers remain owned by guest-center.
 */
(function () {
  'use strict';

  const VERSION = 'V137-CANONICAL-ADMIN-UI-ROOT16-REACT-MODULE-DOCK-AUDIT13';

  const ROLE_LABELS = Object.freeze({
    admin: 'Administrator',
    manager: 'Manajer',
    receptionist: 'Resepsionis',
    finance: 'Keuangan',
    owner: 'Owner (Read-only)',
    koki: 'Koki',
    tukang_kebun: 'Tukang Kebun',
    cleaning_service: 'Housekeeping',
    keamanan: 'Keamanan',
    lain_lain: 'Staf'
  });

  const TAB_LABELS = Object.freeze({
    dashboard: 'Dashboard',
    rooms: 'Kamar',
    reservations: 'Reservasi',
    finance: 'Keuangan',
    owner: 'Owner (Read-only)',
    savings: 'Simpanan Karyawan',
    report: 'Laporan',
    operations: 'Pusat Operasional & Kontrol',
    website: 'Digital / Website',
    support: 'Layanan Tamu / Bantuan',
    telegram: 'Tugas & Notifikasi',
    staff: 'Karyawan',
    config: 'Integrasi / API',
    db_config: 'Database',
    inventory: 'Aset & Stok',
    leaves: 'Cuti',
    attendance: 'Absensi',
    pos: 'POS / Minibar',
    local_connect: 'Koneksi Lokal'
  });

  const TELEGRAM_LABELS = Object.freeze({
    bookings: 'Reservasi & Kamar',
    finance: 'Keuangan & Gaji',
    inventory: 'Aset & Perbaikan',
    operations: 'Operasional & Housekeeping',
    hr: 'SDM, Absensi & Cuti',
    system: 'Sistem & Keamanan Akun'
  });

  const ROLE_DEFAULT_TELEGRAM = Object.freeze({
    admin: Object.freeze(['bookings','finance','inventory','operations','hr','system']),
    manager: Object.freeze(['bookings','finance','inventory','operations','hr','system']),
    receptionist: Object.freeze(['bookings','inventory','operations']),
    finance: Object.freeze(['finance']),
    owner: Object.freeze([]),
    koki: Object.freeze(['inventory']),
    tukang_kebun: Object.freeze([]),
    cleaning_service: Object.freeze(['inventory','operations']),
    keamanan: Object.freeze(['operations']),
    lain_lain: Object.freeze([])
  });

  let openPanel = null;

  const role = () => String(sessionStorage.getItem('hotel_role') || sessionStorage.getItem('hotel_staff_role') || '').trim().toLowerCase();
  const loggedIn = () => sessionStorage.getItem('hotel_logged_in') === 'true';
  function permissions() {
    try { return JSON.parse(sessionStorage.getItem('hotel_permissions') || '{}') || {}; }
    catch (_) { return {}; }
  }
  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  const chip = (value) => '<span class="ui-core-role-chip">' + escapeHtml(value) + '</span>';

  function currentRoute() {
    try {
      if (window.TAMASYA_NAVIGATION && typeof window.TAMASYA_NAVIGATION.current === 'function') {
        return String(window.TAMASYA_NAVIGATION.current() || '');
      }
    } catch (_) {}
    try { return String(sessionStorage.getItem('hotel_active_tab') || '').replace(/-/g, '_'); }
    catch (_) { return ''; }
  }

  function closeRolePanel() {
    if (openPanel && openPanel.isConnected) openPanel.remove();
    openPanel = null;
  }

  function toggleRolePanel(event) {
    event.preventDefault();
    event.stopPropagation();
    if (openPanel && openPanel.isConnected) {
      closeRolePanel();
      return;
    }

    const p = permissions();
    const tabs = Object.entries(p.desktopTabs || {})
      .filter(([, ok]) => ok === true)
      .map(([key]) => TAB_LABELS[key] || key);
    const storedTelegram = p.telegramNotifications || {};
    const telegramKeys = Object.keys(storedTelegram).length
      ? Object.entries(storedTelegram).filter(([, ok]) => ok === true).map(([key]) => key)
      : (ROLE_DEFAULT_TELEGRAM[role()] || []);
    const telegram = telegramKeys.map((key) => TELEGRAM_LABELS[key] || key);
    const r = role();

    const panel = document.createElement('div');
    panel.id = 'ui-core-role-role-panel';
    panel.className = 'ui-core-role-popover';
    panel.innerHTML =
      '<div class="ui-core-role-popover-head"><div><strong>' + escapeHtml(ROLE_LABELS[r] || r || 'Staf') +
      '</strong><small>Hak akses sesi aktif</small></div><button type="button" aria-label="Tutup">×</button></div>' +
      '<div class="ui-core-role-popover-section"><b>Menu yang diizinkan</b><div class="ui-core-role-chip-list">' +
      (tabs.length ? tabs.map(chip).join('') : '<span class="ui-core-role-muted">Mengikuti preset role / server.</span>') +
      '</div></div>' +
      '<div class="ui-core-role-popover-section"><b>Telegram yang diterima</b><div class="ui-core-role-chip-list">' +
      (telegram.length ? telegram.map(chip).join('') : '<span class="ui-core-role-muted">Tidak ada kategori Telegram kustom aktif.</span>') +
      '</div></div>' +
      '<p class="ui-core-role-popover-note">Hak akses server tetap menjadi pengaman utama. Tampilan ini hanya menjelaskan sesi aktif.</p>';

    document.body.appendChild(panel);
    openPanel = panel;
    panel.querySelector('button')?.addEventListener('click', closeRolePanel);

    const rect = event.currentTarget.getBoundingClientRect();
    const width = Math.min(390, Math.max(260, window.innerWidth - 24));
    panel.style.width = width + 'px';
    panel.style.left = Math.max(12, Math.min(window.innerWidth - width - 12, rect.left)) + 'px';
    panel.style.top = Math.max(12, Math.min(window.innerHeight - panel.offsetHeight - 12, rect.bottom + 8)) + 'px';
  }

  const announcedUtilityHosts = new WeakSet();

  function ensureShellUtility() {
    if (!loggedIn()) {
      document.getElementById('ui-core-role-role-wrap')?.remove();
      closeRolePanel();
      return null;
    }

    // AUDIT13: React owns the Module Hotel dock and its utility island.
    // ui-core may only mount the role control inside that explicit island;
    // it must never create/move the dock or utility host itself.
    const utility = document.querySelector('#tamasya-module-dock .tmd-head > #ui-core-role-utility-row');
    if (!utility) return null;

    let changed = false;
    let wrap = document.getElementById('ui-core-role-role-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'ui-core-role-role-wrap';
      wrap.className = 'ui-core-role-role-wrap';
      wrap.innerHTML = '<button id="ui-core-role-role-button" type="button" class="ui-core-role-role-button" aria-haspopup="dialog"><span class="ui-core-role-role-dot"></span><span class="ui-core-role-role-copy"></span><span aria-hidden="true">⌄</span></button>';
      wrap.querySelector('button')?.addEventListener('click', toggleRolePanel);
      utility.prepend(wrap);
      changed = true;
    } else if (wrap.parentElement !== utility) {
      // A stale utility from an older shell must be discarded, not moved into React.
      wrap.remove();
      return ensureShellUtility();
    }

    const copy = wrap.querySelector('.ui-core-role-role-copy');
    const label = 'Mode ' + (ROLE_LABELS[role()] || role() || 'Staf');
    if (copy && copy.textContent !== label) {
      copy.textContent = label;
      changed = true;
    }

    if (!announcedUtilityHosts.has(utility) || changed) {
      announcedUtilityHosts.add(utility);
      window.dispatchEvent(new CustomEvent('tamasya-shell-utility-ready', { detail: { version: VERSION, owner: 'react-app-shell' } }));
    }
    return utility;
  }

  function scheduleEnsure() {
    if (typeof requestAnimationFrame === 'function') requestAnimationFrame(ensureShellUtility);
    else setTimeout(ensureShellUtility, 0);
  }

  document.addEventListener('click', (event) => {
    if (openPanel && !openPanel.contains(event.target) && !event.target.closest?.('#ui-core-role-role-button')) closeRolePanel();
  });
  window.addEventListener('resize', closeRolePanel, { passive: true });
  window.addEventListener('scroll', closeRolePanel, { passive: true, capture: true });
  window.addEventListener('storage', scheduleEnsure);
  window.addEventListener('tamasya-module-dock-ready', scheduleEnsure);
  window.addEventListener('tamasya-auth-changed', scheduleEnsure);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scheduleEnsure, { once: true });
  else scheduleEnsure();

  window.TAMASYA_UI_CORE = Object.freeze({
    version: VERSION,
    navigationOwner: 'react-app-shell',
    utilityOwner: 'module-hotel-dock',
    currentRoute,
    ensureShellUtility,
    roleLabels: ROLE_LABELS,
    tabLabels: TAB_LABELS,
    telegramLabels: TELEGRAM_LABELS
  });

  window.TAMASYA_UI_OPERATIONS = Object.freeze({
    version: VERSION,
    navigationOwner: 'react-app-shell',
    operationsGrouping: 'react-domain-groups',
    rootMutationPolicy: 'no-external-navigation-dom-mutation'
  });
})();
