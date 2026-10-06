/* TAMASYA FIX28R2 — Indonesian date display adapter.
 * Presentation only: canonical API/database values remain ISO (YYYY-MM-DD).
 */
(function () {
  'use strict';

  const VERSION = 'FIX39-DATE-DISPLAY-DDMMYYYY-20260829';
  const ISO_DATE_TIME_RE = /\b(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?)?\b/g;
  const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'CODE', 'PRE']);

  function validDate(y, m, d) {
    const year = Number(y), month = Number(m), day = Number(d);
    if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) return false;
    const dt = new Date(Date.UTC(year, month - 1, day));
    return dt.getUTCFullYear() === year && dt.getUTCMonth() === month - 1 && dt.getUTCDate() === day;
  }

  function formatText(value) {
    const text = String(value == null ? '' : value);
    return text.replace(ISO_DATE_TIME_RE, (whole, y, m, d, hh, mm, ss, offset, source) => {
      // Reference tokens like INV-2026-08-27-001 embed a date bounded by
      // hyphens; only rewrite when the match is not part of a longer token.
      const prev = offset > 0 ? source.charAt(offset - 1) : '';
      const next = source.charAt(offset + whole.length);
      if (/[\w-]/.test(prev) || /[\w-]/.test(next)) return whole;
      if (!validDate(y, m, d)) return whole;
      let result = `${d}/${m}/${y}`;
      if (hh != null && mm != null) result += ` ${hh}:${mm}${ss != null ? `:${ss}` : ''}`;
      return result;
    });
  }

  function eligibleTextNode(node) {
    if (!node || node.nodeType !== Node.TEXT_NODE || !node.parentElement) return false;
    const parent = node.parentElement;
    if (SKIP_TAGS.has(parent.tagName)) return false;
    if (parent.closest('[contenteditable="true"]')) return false;
    return /\b\d{4}-\d{2}-\d{2}\b/.test(node.nodeValue || '');
  }

  function formatTextNode(node) {
    if (!eligibleTextNode(node)) return;
    const before = node.nodeValue || '';
    const after = formatText(before);
    if (after !== before) node.nodeValue = after;
  }

  function formatElementAttributes(el) {
    if (!(el instanceof Element)) return;
    for (const attr of ['title', 'aria-label']) {
      const before = el.getAttribute(attr);
      if (!before || !/\b\d{4}-\d{2}-\d{2}\b/.test(before)) continue;
      const after = formatText(before);
      if (after !== before) el.setAttribute(attr, after);
    }
  }

  function walk(root) {
    if (!root) return;
    if (root.nodeType === Node.TEXT_NODE) {
      formatTextNode(root);
      return;
    }
    if (!(root instanceof Element || root instanceof Document || root instanceof DocumentFragment)) return;
    if (root instanceof Element) formatElementAttributes(root);
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) formatTextNode(node);
    if (root.querySelectorAll) root.querySelectorAll('[title],[aria-label]').forEach(formatElementAttributes);
  }

  function start() {
    walk(document.body);
    let frame=0;
    const textNodes=new Set();
    const elements=new Set();
    const roots=new Set();
    const flush=()=>{
      frame=0;
      const pendingText=[...textNodes]; textNodes.clear();
      const pendingElements=[...elements]; elements.clear();
      const pendingRoots=[...roots]; roots.clear();
      pendingText.forEach(formatTextNode);
      pendingElements.forEach(formatElementAttributes);
      pendingRoots.forEach(walk);
    };
    const schedule=()=>{ if(!frame) frame=requestAnimationFrame(flush); };
    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        if (mutation.type === 'characterData') textNodes.add(mutation.target);
        if (mutation.type === 'attributes') elements.add(mutation.target);
        for (const node of mutation.addedNodes || []) roots.add(node);
      }
      if(textNodes.size||elements.size||roots.size) schedule();
    });
    observer.observe(document.body, {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
      attributeFilter: ['title', 'aria-label']
    });
  }

  window.TamasyaDateDisplay = Object.freeze({ version: VERSION, formatText });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
