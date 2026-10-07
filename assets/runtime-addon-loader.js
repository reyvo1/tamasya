/* TAMASYA PRD performance loader.
 * Optional UI addons are loaded only when their route/role needs them.
 * Core transaction, auth, offline and navigation code remains in the canonical shell.
 */
(() => {
  'use strict';
  if (window.__TAMASYA_RUNTIME_ADDON_LOADER__) return;
  window.__TAMASYA_RUNTIME_ADDON_LOADER__ = true;

  const BUILD = '20261007-telegram-shift-r11';
  const resources = new Map();
  let firstRouteSeen = false;

  const base = () => new URL('./', document.baseURI || location.href);
  const abs = (relative) => new URL(relative.replace(/^\.\//, ''), base()).href;

  function emit(type, detail) {
    try { window.dispatchEvent(new CustomEvent(type, { detail })); } catch (_) {}
  }

  function loadStyle(relative) {
    const key = `style:${relative}`;
    if (resources.has(key)) return resources.get(key);
    const promise = new Promise((resolve, reject) => {
      const existing = [...document.querySelectorAll('link[rel="stylesheet"]')]
        .find((el) => (el.getAttribute('href') || '').split('?')[0].replace(/^\.\//, '') === relative.replace(/^\.\//, ''));
      if (existing) { resolve(existing); return; }
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = abs(relative);
      link.dataset.tamasyaLazyAddon = relative;
      link.addEventListener('load', () => resolve(link), { once: true });
      link.addEventListener('error', () => reject(new Error(`Gagal memuat stylesheet ${relative}`)), { once: true });
      document.head.appendChild(link);
    }).catch((error) => { emit('tamasya-addon-load-error', { resource: relative, message: error.message }); throw error; });
    resources.set(key, promise);
    return promise;
  }

  function loadScript(relative, version = BUILD) {
    const key = `script:${relative}`;
    if (resources.has(key)) return resources.get(key);
    const promise = new Promise((resolve, reject) => {
      const clean = relative.replace(/^\.\//, '');
      const existing = [...document.scripts]
        .find((el) => (el.getAttribute('src') || '').split('?')[0].replace(/^\.\//, '') === clean);
      if (existing) { resolve(existing); return; }
      const script = document.createElement('script');
      script.src = `${abs(clean)}?v=${encodeURIComponent(version)}`;
      script.async = true;
      script.dataset.tamasyaLazyAddon = clean;
      script.addEventListener('load', () => { emit('tamasya-addon-loaded', { resource: clean }); resolve(script); }, { once: true });
      script.addEventListener('error', () => reject(new Error(`Gagal memuat addon ${clean}`)), { once: true });
      document.head.appendChild(script);
    }).catch((error) => { emit('tamasya-addon-load-error', { resource: relative, message: error.message }); throw error; });
    resources.set(key, promise);
    return promise;
  }

  function idle(task, timeout = 1600) {
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(() => task(), { timeout });
    } else {
      window.setTimeout(task, Math.min(timeout, 500));
    }
  }

  function role() {
    try { return String(sessionStorage.getItem('hotel_staff_role') || sessionStorage.getItem('hotel_role') || '').trim().toLowerCase(); }
    catch (_) { return ''; }
  }

  function loggedIn() {
    try { return sessionStorage.getItem('hotel_logged_in') === 'true'; } catch (_) { return false; }
  }

  function runtimeFeatures() {
    const features = window.TAMASYA_RUNTIME_CONFIG && window.TAMASYA_RUNTIME_CONFIG.features;
    return features && typeof features === 'object' ? features : {};
  }

  const loadGuestCenter = () => Promise.all([
    loadStyle('assets/guest-center.css?v=20260815-r7-reservation-root-sync-r3-workflow-root-r4-room-blocker-owner-r5&fix=20260816-ops-guest3'),
    loadScript('assets/guest-center.js', '20261007-telegram-shift-r11')
  ]);
  const loadEmployeeSelfService = () => Promise.all([
    loadStyle('assets/employee-self-service.css?v=20260826-fix34-drawerscroll'),
    loadScript('assets/employee-self-service.js', BUILD)
  ]);
  const loadWebsiteTools = () => Promise.all([
    loadStyle('assets/website-cms-guard.css?v=20260810-v137'),
    loadScript('assets/website-cms-guard.js', '20260810-v137'),
    loadScript('assets/website-gps-addon.js', '20260821-audit16-prelock-observer-scope')
  ]);
  const loadReportTools = () => Promise.all([
    loadScript('assets/canonical-report-center.js', BUILD),
    loadScript('assets/pos-report-archive-addon.js', BUILD)
  ]);
  const loadSystemHealth = () => loadScript('assets/system-health-addon.js', BUILD);
  const loadGrowthLink = () => loadScript('assets/growth-pms-link-addon.js', BUILD);

  function ensureForRoute(route, initial = false) {
    const current = String(route || '').trim().replace(/-/g, '_');
    const currentRole = role();
    const run = (fn, deferInitial = false) => {
      if (initial && deferInitial) idle(() => fn().catch(() => {}));
      else fn().catch(() => {});
    };

    if (['rooms', 'reservations', 'support', 'operations'].includes(current)) run(loadGuestCenter, true);
    if (current === 'report' || current === 'finance') run(loadReportTools, false);
    if (current === 'website') run(loadWebsiteTools, false);

    if (['employee', 'staff', 'leaves', 'attendance', 'savings'].includes(current) || currentRole === 'employee') {
      run(loadEmployeeSelfService, initial);
    }

    const features = runtimeFeatures();
    if (features.growthSuiteEnabled === true && ['dashboard', 'rooms', 'reservations', 'finance', 'report'].includes(current)) {
      run(loadGrowthLink, true);
    }
  }

  window.addEventListener('tamasya-route-change', (event) => {
    const route = event && event.detail ? event.detail.route : '';
    const initial = !firstRouteSeen;
    firstRouteSeen = true;
    ensureForRoute(route, initial);
  });

  // System Health is admin-only and should cost nothing until the operator opens it.
  window.addEventListener('tamasya-open-system-health', () => {
    const alreadyLoaded = resources.has('script:assets/system-health-addon.js') || Boolean(window.TamasyaSystemHealth);
    if (alreadyLoaded) return;
    loadSystemHealth().then(() => {
      if (window.TamasyaSystemHealth && typeof window.TamasyaSystemHealth.open === 'function') window.TamasyaSystemHealth.open();
    }).catch(() => {});
  });

  function bootstrap() {
    let route = '';
    try { route = sessionStorage.getItem('hotel_active_tab') || document.documentElement.dataset.tamasyaRoute || ''; } catch (_) {}
    if (loggedIn() && route) {
      firstRouteSeen = true;
      ensureForRoute(route, true);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
  else bootstrap();

  window.TamasyaRuntimeAddonLoader = Object.freeze({
    build: BUILD,
    ensureForRoute: (route) => ensureForRoute(route, false),
    loadSystemHealth,
    loaded: (relative) => resources.has(`script:${relative}`) || resources.has(`style:${relative}`)
  });
})();
