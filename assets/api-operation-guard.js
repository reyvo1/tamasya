/**
 * TAMASYA V137 canonical mutation idempotency guard.
 *
 * Adds X-Tamasya-Operation-ID to TAMASYA API mutations that do not already
 * provide one. Pending IDs are retained for ambiguous/retryable responses so a
 * retry reuses the same identity instead of creating a duplicate transaction.
 */
(() => {
  'use strict';
  if (window.__TAMASYA_OPERATION_GUARD_INSTALLED__) return;
  window.__TAMASYA_OPERATION_GUARD_INSTALLED__ = true;
  window.__TAMASYA_OPERATION_GUARD_VERSION__ = 'V137';

  const nativeFetch = window.fetch.bind(window);
  const mutationMethods = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

  const requestUrl = (input) => {
    const raw = input instanceof Request ? input.url : String(input || '');
    try {
      const runtime = window.TAMASYA_RUNTIME_CONFIG || {};
      const runtimeBase = runtime.VITE_TAMASYA_ONLINE_API_URL || runtime.VITE_TAMASYA_LOCAL_API_URL || '';
      const base = /^https?:/i.test(String(location.href || ''))
        ? location.href
        : (runtimeBase || 'https://tamasya.invalid/');
      return new URL(raw, base);
    } catch (_) {
      return null;
    }
  };

  const allowedApiOrigins = () => {
    const origins = new Set();
    try { if (/^https?:$/i.test(location.protocol)) origins.add(location.origin); } catch (_) {}
    const runtime = window.TAMASYA_RUNTIME_CONFIG || {};
    for (const raw of [runtime.VITE_TAMASYA_ONLINE_API_URL, runtime.VITE_TAMASYA_LOCAL_API_URL]) {
      try { if (raw) origins.add(new URL(String(raw), location.href).origin); } catch (_) {}
    }
    return origins;
  };

  const isTamasyaApi = (url) => {
    if (!url) return false;
    const path = String(url.pathname || '');
    if (!/(?:^|\/)api(?:\.php|\/|$)/i.test(path)) return false;
    const origins = allowedApiOrigins();
    return origins.size === 0 || origins.has(url.origin);
  };

  const fnv1a = (value) => {
    let hash = 2166136261;
    for (let i = 0; i < value.length; i += 1) {
      hash ^= value.charCodeAt(i);
      hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0).toString(16).padStart(8, '0');
  };

  const bytesFingerprint = (bytes) => {
    let hash = 2166136261;
    for (let i = 0; i < bytes.length; i += 1) {
      hash ^= bytes[i];
      hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0).toString(16).padStart(8, '0');
  };

  const bodyFingerprint = async (body, request) => {
    if (body === undefined && request) {
      try { return `request:${await request.clone().text()}`; } catch (_) { return 'request:unreadable'; }
    }
    if (body === undefined || body === null) return 'body:none';
    if (typeof body === 'string') return `string:${body}`;
    if (body instanceof URLSearchParams) return `urlsearch:${body.toString()}`;
    if (body instanceof FormData) {
      const entries = [];
      for (const [key, value] of body.entries()) {
        if (value instanceof File) entries.push([key, 'file', value.name, value.size, value.type, value.lastModified]);
        else if (value instanceof Blob) entries.push([key, 'blob', value.size, value.type]);
        else entries.push([key, 'text', String(value)]);
      }
      return `form:${JSON.stringify(entries)}`;
    }
    if (body instanceof File) return `file:${body.name}:${body.size}:${body.type}:${body.lastModified}`;
    if (body instanceof Blob) return `blob:${body.size}:${body.type}`;
    if (body instanceof ArrayBuffer) return `arraybuffer:${body.byteLength}:${bytesFingerprint(new Uint8Array(body))}`;
    if (ArrayBuffer.isView(body)) return `arrayview:${body.byteLength}:${bytesFingerprint(new Uint8Array(body.buffer, body.byteOffset, body.byteLength))}`;
    return `body:${Object.prototype.toString.call(body)}`;
  };

  const newOperationId = () => {
    const random = globalThis.crypto?.randomUUID?.()
      || `${Date.now()}_${Math.random().toString(36).slice(2, 14)}`;
    return `op_web_${random}`;
  };

  const getStored = (key) => {
    try { return sessionStorage.getItem(key) || ''; } catch (_) { return ''; }
  };
  const setStored = (key, value) => {
    try { sessionStorage.setItem(key, value); } catch (_) {}
  };
  const clearStored = (key) => {
    try { sessionStorage.removeItem(key); } catch (_) {}
  };

  const responseIsRetryableOrAmbiguous = (status) => (
    status === 202 || status === 408 || status === 425 || status === 429 || status >= 500
  );

  // FIX38 duplicate-submit shield: forms that mint a fresh operationId inside the
  // request body defeat the pending-operation reuse above (every click produces a
  // different body fingerprint), so a double click can post the same transaction
  // twice. We therefore keep a short-lived "recently succeeded" map keyed by a
  // fingerprint that strips volatile id/timestamp fields from a JSON body. An
  // identical mutation landing within the window is rejected locally with 409
  // instead of reaching the server. Legitimate resubmissions after the window are
  // unaffected, and failed requests never arm the shield.
  const RECENT_SUCCESS_BLOCK_MS = 3000;
  const recentSuccessAt = new Map();

  const pruneRecentSuccess = (now) => {
    if (recentSuccessAt.size < 500) return;
    for (const [key, at] of recentSuccessAt) {
      if (now - at >= RECENT_SUCCESS_BLOCK_MS) recentSuccessAt.delete(key);
    }
  };

  const volatileIdKey = /^(operationid|clientrequestid|requestid|idempotencykey|nonce|clientmutationid)$/i;

  const stripVolatileIdKeys = (text) => {
    try {
      const parsed = JSON.parse(text);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        const clone = { ...parsed };
        for (const key of Object.keys(clone)) {
          if (volatileIdKey.test(key)) delete clone[key];
        }
        return JSON.stringify(clone);
      }
    } catch (_) { /* not JSON: fall back to raw text */ }
    return text;
  };

  const shieldBodyFingerprint = async (init, request, url) => {
    let bodyText = '';
    if (typeof init.body === 'string') bodyText = init.body;
    else if (init.body === undefined && request) {
      try { bodyText = await request.clone().text(); } catch (_) { bodyText = ''; }
    }
    if (bodyText) {
      return `${url?.pathname || ''}|${url?.search || ''}|${stripVolatileIdKeys(bodyText)}`;
    }
    return `${url?.pathname || ''}|${url?.search || ''}|fp:${await bodyFingerprint(init.body, request)}`;
  };

  window.fetch = async function tamasyaFetchWithOperationId(input, init = {}) {
    const request = input instanceof Request ? input : null;
    const method = String(init.method || request?.method || 'GET').toUpperCase();
    const url = requestUrl(input);
    let pendingKey = '';
    let shieldKey = '';
    const nextInit = { ...init };

    if (mutationMethods.has(method) && isTamasyaApi(url)) {
      const headers = new Headers(request?.headers || {});
      new Headers(init.headers || {}).forEach((value, key) => headers.set(key, value));

      if (!headers.get('X-Tamasya-Operation-ID')) {
        const fingerprint = `${method}|${url?.origin || ''}|${url?.pathname || ''}|${url?.search || ''}|${await bodyFingerprint(init.body, request)}`;
        pendingKey = `tamasya_pending_operation_${fnv1a(fingerprint)}`;
        shieldKey = `tamasya_shield_${fnv1a(`${method}|${await shieldBodyFingerprint(init, request, url)}`)}`;
        const lastSuccessAt = recentSuccessAt.get(shieldKey) || 0;
        if (Date.now() - lastSuccessAt < RECENT_SUCCESS_BLOCK_MS) {
          return new Response(JSON.stringify({
            ok: false,
            success: false,
            error: 'duplicate_request_blocked',
            message: 'Permintaan identik baru saja berhasil diproses. Segarkan data untuk memastikan; ulangi hanya bila memang ingin mencatat dua kali.'
          }), { status: 409, headers: { 'Content-Type': 'application/json' } });
        }
        let operationId = getStored(pendingKey);
        if (!/^[A-Za-z0-9._:-]{8,100}$/.test(operationId)) operationId = newOperationId();
        headers.set('X-Tamasya-Operation-ID', operationId);
        setStored(pendingKey, operationId);
      }
      nextInit.headers = headers;
    }

    try {
      const response = await nativeFetch(input, nextInit);
      // Accepted/in-progress responses must retain their operation ID and remain retryable.
      let completedSuccessfully = response.ok && !responseIsRetryableOrAmbiguous(response.status);
      if (completedSuccessfully && (response.headers.get('content-type') || '').includes('application/json')) {
        try {
          const body = await response.clone().json();
          if (body?.success === false || body?.ok === false) completedSuccessfully = false;
        } catch (_) { completedSuccessfully = false; }
      }
      if (shieldKey && completedSuccessfully) {
        const now = Date.now();
        pruneRecentSuccess(now);
        recentSuccessAt.set(shieldKey, now);
      }
      if (pendingKey && !responseIsRetryableOrAmbiguous(response.status)) clearStored(pendingKey);
      return response;
    } catch (error) {
      // No terminal server response: preserve the pending operation identity.
      throw error;
    }
  };
})();
