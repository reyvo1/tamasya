(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.TamasyaPosBusinessDatePolicy = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const isDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
  function dateAt(now = new Date(), timezone) {
    const zone = timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(now);
    const value = type => parts.find(part => part.type === type).value;
    return `${value('year')}-${value('month')}-${value('day')}`;
  }
  function monthRange(now = new Date(), timezone) {
    const to = dateAt(now, timezone);
    return { from: `${to.slice(0, 8)}01`, to };
  }
  function reconcile(input) {
    const businessDate = String(input && input.businessDate || '');
    const businessMonthStart = String(input && input.businessMonthStart || '');
    const currentFrom = String(input && input.currentFrom || '');
    const currentTo = String(input && input.currentTo || '');
    const mode = String(input && input.mode || 'auto') === 'custom' ? 'custom' : 'auto';
    const force = !!(input && input.force);
    if (!isDate(businessDate) || !isDate(businessMonthStart)) throw new Error('Business date POS dari server tidak valid.');
    if (mode === 'custom' && !force) return { from: currentFrom, to: currentTo, mode: 'custom', businessDate, changed: false };
    const changed = currentFrom !== businessMonthStart || currentTo !== businessDate;
    return { from: businessMonthStart, to: businessDate, mode: 'auto', businessDate, changed };
  }
  return Object.freeze({ reconcile, isDate, dateAt, monthRange });
});
