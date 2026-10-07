(() => {
  'use strict';
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', () => {
    const pageUrl = new URL(window.location.href);
    const baseUrl = new URL('./', pageUrl);
    const baseDir = baseUrl.pathname;
    const swUrl = new URL('sw.js?v=20261007-growth-ui-r10', baseUrl).href;
    navigator.serviceWorker.register(swUrl, { scope: baseDir, updateViaCache: 'none' })
      .then((reg) => reg.update().catch((err) => console.warn('PWA Service Worker update check failed:', err)))
      .catch((err) => console.error('PWA Service Worker registration failed:', err));
  });
})();
