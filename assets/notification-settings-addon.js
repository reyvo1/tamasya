(() => {
  'use strict';
  if (window.TamasyaNotificationSound) return;

  const STORAGE_KEY = 'tamasya_notification_settings_v2';
  const DB_NAME = 'tamasya_notification_audio';
  const STORE_NAME = 'files';
  const CUSTOM_KEY = 'custom-tone';
  const MAX_CUSTOM_BYTES = 2 * 1024 * 1024;
  const defaults = Object.freeze({
    enabled: true,
    supportTone: 'reception',
    reservationTone: 'standard',
    volume: 100,
    repeats: 2,
    gapMs: 1200,
    vibrate: true,
    visualToast: true,
    customName: ''
  });

  const tones = {
    soft: 'Lembut',
    standard: 'Standar',
    loud: 'Keras',
    reception: 'Bel Resepsionis',
    urgent: 'Alarm Penting',
    custom: 'Nada Unggahan Sendiri'
  };

  const clamp = (value, min, max) => Math.max(min, Math.min(max, Number(value) || 0));
  const sanitize = (raw) => ({
    enabled: raw?.enabled !== false,
    supportTone: tones[raw?.supportTone] ? raw.supportTone : defaults.supportTone,
    reservationTone: tones[raw?.reservationTone] ? raw.reservationTone : defaults.reservationTone,
    volume: Math.round(clamp(raw?.volume ?? defaults.volume, 0, 100)),
    repeats: Math.round(clamp(raw?.repeats ?? defaults.repeats, 1, 4)),
    gapMs: Math.round(clamp(raw?.gapMs ?? defaults.gapMs, 400, 5000)),
    vibrate: raw?.vibrate !== false,
    visualToast: raw?.visualToast !== false,
    customName: String(raw?.customName || '').slice(0, 120)
  });
  let memorySettings = { ...defaults };
  const load = () => {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored) memorySettings = sanitize(JSON.parse(stored));
    } catch (_) {}
    return { ...memorySettings };
  };
  let settings = load();
  const save = (next) => {
    settings = sanitize(next);
    memorySettings = { ...settings };
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(settings)); } catch (_) {}
    return settings;
  };

  let customBlobUrl = '';
  let customBlobPromise = null;
  const openDb = () => new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) { reject(new Error('IndexedDB tidak tersedia.')); return; }
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) db.createObjectStore(STORE_NAME);
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error('Gagal membuka penyimpanan nada.'));
  });
  const putCustomBlob = async (blob) => {
    const db = await openDb();
    await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      tx.objectStore(STORE_NAME).put(blob, CUSTOM_KEY);
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error || new Error('Gagal menyimpan nada.'));
    });
    db.close();
    if (customBlobUrl) URL.revokeObjectURL(customBlobUrl);
    customBlobUrl = '';
    customBlobPromise = null;
  };
  const getCustomBlobUrl = async () => {
    if (customBlobUrl) return customBlobUrl;
    if (customBlobPromise) return customBlobPromise;
    customBlobPromise = (async () => {
      const db = await openDb();
      const blob = await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readonly');
        const req = tx.objectStore(STORE_NAME).get(CUSTOM_KEY);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error || new Error('Gagal membaca nada.'));
      });
      db.close();
      if (!(blob instanceof Blob)) return '';
      customBlobUrl = URL.createObjectURL(blob);
      return customBlobUrl;
    })().finally(() => { customBlobPromise = null; });
    return customBlobPromise;
  };
  const removeCustomBlob = async () => {
    try {
      const db = await openDb();
      await new Promise((resolve, reject) => {
        const tx = db.transaction(STORE_NAME, 'readwrite');
        tx.objectStore(STORE_NAME).delete(CUSTOM_KEY);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error || new Error('Gagal menghapus nada.'));
      });
      db.close();
    } catch (_) {}
    if (customBlobUrl) URL.revokeObjectURL(customBlobUrl);
    customBlobUrl = '';
    customBlobPromise = null;
  };

  const oscillatorPattern = {
    soft: [{ f: 660, at: 0, d: 0.22, type: 'sine', gain: 0.42 }],
    standard: [
      { f: 740, at: 0, d: 0.18, type: 'sine', gain: 0.7 },
      { f: 988, at: 0.20, d: 0.20, type: 'sine', gain: 0.78 }
    ],
    loud: [
      { f: 880, at: 0, d: 0.18, type: 'triangle', gain: 0.88 },
      { f: 1174, at: 0.20, d: 0.20, type: 'triangle', gain: 0.92 },
      { f: 1397, at: 0.42, d: 0.22, type: 'sine', gain: 0.88 }
    ],
    reception: [
      { f: 784, at: 0, d: 0.20, type: 'sine', gain: 0.82 },
      { f: 1047, at: 0.18, d: 0.22, type: 'sine', gain: 0.9 },
      { f: 1319, at: 0.38, d: 0.28, type: 'sine', gain: 0.94 }
    ],
    urgent: [
      { f: 1047, at: 0, d: 0.16, type: 'square', gain: 0.72 },
      { f: 784, at: 0.19, d: 0.16, type: 'square', gain: 0.72 },
      { f: 1047, at: 0.38, d: 0.16, type: 'square', gain: 0.72 },
      { f: 784, at: 0.57, d: 0.18, type: 'square', gain: 0.72 }
    ]
  };

  const playPresetOnce = (ctx, tone, volume, delaySeconds = 0) => {
    const pattern = oscillatorPattern[tone] || oscillatorPattern.standard;
    const now = ctx.currentTime + Math.max(0, delaySeconds);
    const master = ctx.createGain();
    const compressor = typeof ctx.createDynamicsCompressor === 'function' ? ctx.createDynamicsCompressor() : null;
    const target = Math.max(0.0001, clamp(volume, 0, 1) * 0.62);
    master.gain.setValueAtTime(0.0001, now);
    master.gain.exponentialRampToValueAtTime(target, now + 0.025);
    const endAt = now + Math.max(...pattern.map((n) => n.at + n.d)) + 0.08;
    master.gain.exponentialRampToValueAtTime(0.0001, endAt);
    if (compressor) {
      compressor.threshold.value = -12;
      compressor.knee.value = 18;
      compressor.ratio.value = 8;
      compressor.attack.value = 0.004;
      compressor.release.value = 0.16;
      master.connect(compressor);
      compressor.connect(ctx.destination);
    } else master.connect(ctx.destination);
    pattern.forEach((note) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      const start = now + note.at;
      osc.type = note.type;
      osc.frequency.setValueAtTime(note.f, start);
      gain.gain.setValueAtTime(0.0001, start);
      gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, note.gain), start + 0.018);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + note.d);
      osc.connect(gain);
      gain.connect(master);
      osc.start(start);
      osc.stop(start + note.d + 0.03);
    });
  };

  const playCustom = async (volume, repeats, gapMs) => {
    const url = await getCustomBlobUrl();
    if (!url) throw new Error('Nada kustom belum diunggah.');
    for (let index = 0; index < repeats; index += 1) {
      window.setTimeout(() => {
        const audio = new Audio(url);
        audio.volume = clamp(volume, 0, 1);
        audio.play().catch(() => {});
      }, index * gapMs);
    }
  };

  const vibrate = (tone, repeats) => {
    if (!settings.vibrate || typeof navigator.vibrate !== 'function') return;
    const base = tone === 'urgent' ? [220, 100, 220, 100, 300] : [180, 100, 260];
    const pattern = [];
    for (let i = 0; i < repeats; i += 1) {
      if (i > 0) pattern.push(Math.max(200, settings.gapMs - 500));
      pattern.push(...base);
    }
    try { navigator.vibrate(pattern); } catch (_) {}
  };

  const play = (ctx, notificationType = 'public_support') => {
    settings = load();
    if (!settings.enabled || settings.volume <= 0) return;
    const tone = notificationType === 'public_reservation' ? settings.reservationTone : settings.supportTone;
    const volume = settings.volume / 100;
    const repeats = settings.repeats;
    if (tone === 'custom') {
      playCustom(volume, repeats, settings.gapMs).catch(() => {
        for (let i = 0; i < repeats; i += 1) playPresetOnce(ctx, 'reception', volume, i * settings.gapMs / 1000);
      });
    } else {
      for (let i = 0; i < repeats; i += 1) playPresetOnce(ctx, tone, volume, i * settings.gapMs / 1000);
    }
    vibrate(tone, repeats);
  };

  const toastSeen = new Set();
  const notify = (notification, count = 1) => {
    settings = load();
    if (!settings.visualToast || !notification) return;
    const id = String(notification.id || '');
    if (id && toastSeen.has(id)) return;
    if (id) toastSeen.add(id);
    let stack = document.getElementById('tamasya-notification-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'tamasya-notification-toast-stack';
      document.body.appendChild(stack);
    }
    const isSupport = String(notification.type) === 'public_support';
    const card = document.createElement('div');
    card.className = 'tamasya-notification-toast';
    card.innerHTML = `
      <div class="tamasya-notification-toast-icon">${isSupport ? '💬' : '📅'}</div>
      <div class="tamasya-notification-toast-body">
        <strong>${isSupport ? 'Chat Bantuan Baru' : 'Reservasi Website Baru'}${Number(count) > 1 ? ` (${Number(count)})` : ''}</strong>
        <span></span>
      </div>
      <button type="button" aria-label="Tutup notifikasi">×</button>`;
    card.querySelector('span').textContent = String(notification.message || 'Notifikasi baru diterima.');
    const close = () => card.remove();
    card.querySelector('button').addEventListener('click', close);
    card.addEventListener('click', (event) => {
      if (event.target.closest('button')) return;
      if (isSupport) {
        const buttons = [...document.querySelectorAll('button')];
        const support = buttons.find((button) => /^support$/i.test((button.textContent || '').trim()) || /chat bantuan|inbox bantuan/i.test(button.textContent || ''));
        support?.click();
      }
      close();
    });
    stack.appendChild(card);
    window.setTimeout(close, 9000);
  };

  const ensureStyles = () => {
    if (document.getElementById('tamasya-notification-settings-style')) return;
    const style = document.createElement('style');
    style.id = 'tamasya-notification-settings-style';
    style.textContent = `
      #tamasya-notification-toast-stack{position:fixed;right:18px;top:18px;z-index:2147483000;display:flex;flex-direction:column;gap:10px;width:min(390px,calc(100vw - 28px));pointer-events:none}
      .tamasya-notification-toast{pointer-events:auto;display:grid;grid-template-columns:auto 1fr auto;gap:11px;align-items:start;padding:14px;border:1px solid rgba(52,211,153,.38);border-radius:16px;background:rgba(15,23,42,.97);box-shadow:0 18px 55px rgba(0,0,0,.38);color:#e2e8f0;cursor:pointer;animation:tamasyaNotifIn .16s ease-out both}
      .tamasya-notification-toast-icon{display:grid;place-items:center;width:34px;height:34px;border-radius:11px;background:rgba(16,185,129,.16);font-size:18px}
      .tamasya-notification-toast-body{min-width:0;display:flex;flex-direction:column;gap:4px}.tamasya-notification-toast-body strong{font-size:13px;color:#f8fafc}.tamasya-notification-toast-body span{font-size:12px;line-height:1.45;color:#cbd5e1;overflow-wrap:anywhere}
      .tamasya-notification-toast button{border:0;background:transparent;color:#94a3b8;font-size:21px;line-height:1;cursor:pointer;padding:0 2px}.tamasya-notification-toast button:hover{color:#fff}
      @keyframes tamasyaNotifIn{from{opacity:0;transform:translateY(-8px) scale(.985)}to{opacity:1;transform:none}}
      .tamasya-notif-settings-button{border:1px solid rgba(52,211,153,.32);background:rgba(16,185,129,.12);color:#6ee7b7;border-radius:9px;padding:5px 8px;font-size:10px;font-weight:700;cursor:pointer}.tamasya-notif-settings-button:hover{background:rgba(16,185,129,.2)}
      #tamasya-notification-settings-backdrop{position:fixed;inset:0;z-index:2147483100;background:rgba(2,6,23,.72);display:grid;place-items:center;padding:18px}
      #tamasya-notification-settings-modal{width:min(620px,100%);max-height:min(780px,calc(100vh - 36px));overflow:auto;border:1px solid rgba(148,163,184,.28);border-radius:22px;background:#0f172a;color:#e2e8f0;box-shadow:0 30px 90px rgba(0,0,0,.52);font-family:Inter,system-ui,sans-serif}
      .tns-head{display:flex;justify-content:space-between;gap:12px;align-items:start;padding:20px;border-bottom:1px solid rgba(148,163,184,.16)}.tns-head h2{font-size:18px;font-weight:800;margin:0}.tns-head p{font-size:12px;color:#94a3b8;margin:5px 0 0}.tns-close{border:0;background:rgba(148,163,184,.1);color:#cbd5e1;border-radius:10px;width:34px;height:34px;font-size:22px;cursor:pointer}
      .tns-body{padding:20px;display:grid;gap:16px}.tns-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.tns-field{display:grid;gap:7px}.tns-field>label,.tns-label{font-size:11px;font-weight:800;color:#cbd5e1;text-transform:uppercase;letter-spacing:.06em}.tns-field select,.tns-field input[type=number],.tns-field input[type=file]{width:100%;border:1px solid rgba(148,163,184,.24);border-radius:11px;background:#111c30;color:#e2e8f0;padding:10px;font-size:13px}.tns-range{display:grid;grid-template-columns:1fr 54px;gap:10px;align-items:center}.tns-range input[type=range]{width:100%;accent-color:#10b981}.tns-value{text-align:center;border:1px solid rgba(148,163,184,.22);border-radius:9px;padding:6px;font-size:12px;background:#111c30}.tns-checks{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.tns-check{display:flex;gap:8px;align-items:center;border:1px solid rgba(148,163,184,.16);border-radius:11px;padding:10px;font-size:12px;background:rgba(30,41,59,.45)}.tns-check input{accent-color:#10b981}.tns-note{border:1px solid rgba(245,158,11,.22);background:rgba(245,158,11,.08);color:#fde68a;border-radius:12px;padding:10px;font-size:11px;line-height:1.5}.tns-status{min-height:20px;font-size:12px;color:#6ee7b7}.tns-actions{display:flex;flex-wrap:wrap;gap:9px;padding:16px 20px 20px;border-top:1px solid rgba(148,163,184,.16)}.tns-btn{border:1px solid rgba(148,163,184,.25);background:#1e293b;color:#e2e8f0;border-radius:11px;padding:9px 12px;font-size:12px;font-weight:800;cursor:pointer}.tns-btn.primary{background:#10b981;border-color:#10b981;color:#052e2b}.tns-btn.warn{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.35);color:#fde68a}.tns-btn:hover{filter:brightness(1.08)}
      @media(max-width:640px){.tns-grid,.tns-checks{grid-template-columns:1fr}.tns-actions{position:sticky;bottom:0;background:#0f172a}}
      @media print{#tamasya-notification-toast-stack,#tamasya-notification-settings-backdrop{display:none!important}}
    `;
    document.head.appendChild(style);
  };

  const toneOptions = (selected) => Object.entries(tones).map(([value, label]) => `<option value="${value}"${selected === value ? ' selected' : ''}>${label}</option>`).join('');
  const openSettings = () => {
    ensureStyles();
    document.getElementById('tamasya-notification-settings-backdrop')?.remove();
    settings = load();
    const backdrop = document.createElement('div');
    backdrop.id = 'tamasya-notification-settings-backdrop';
    backdrop.innerHTML = `
      <section id="tamasya-notification-settings-modal" role="dialog" aria-modal="true" aria-labelledby="tns-title">
        <header class="tns-head"><div><h2 id="tns-title">Pengaturan Nada Notifikasi</h2><p>Pengaturan disimpan per browser/perangkat. Volume akhir tetap mengikuti volume perangkat.</p></div><button class="tns-close" type="button" aria-label="Tutup">×</button></header>
        <div class="tns-body">
          <div class="tns-grid">
            <div class="tns-field"><label for="tns-support">Chat Bantuan</label><select id="tns-support">${toneOptions(settings.supportTone)}</select></div>
            <div class="tns-field"><label for="tns-reservation">Reservasi Website</label><select id="tns-reservation">${toneOptions(settings.reservationTone)}</select></div>
          </div>
          <div class="tns-field"><span class="tns-label">Volume aplikasi</span><div class="tns-range"><input id="tns-volume" type="range" min="0" max="100" step="1" value="${settings.volume}"><output id="tns-volume-value" class="tns-value">${settings.volume}%</output></div></div>
          <div class="tns-grid">
            <div class="tns-field"><label for="tns-repeats">Jumlah pengulangan</label><select id="tns-repeats">${[1,2,3,4].map((n) => `<option value="${n}"${settings.repeats === n ? ' selected' : ''}>${n} kali</option>`).join('')}</select></div>
            <div class="tns-field"><label for="tns-gap">Jeda pengulangan</label><select id="tns-gap">${[[600,'0,6 detik'],[1000,'1 detik'],[1200,'1,2 detik'],[2000,'2 detik'],[3000,'3 detik']].map(([n,l]) => `<option value="${n}"${settings.gapMs === n ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
          </div>
          <div class="tns-checks">
            <label class="tns-check"><input id="tns-enabled" type="checkbox"${settings.enabled ? ' checked' : ''}> Nada aktif</label>
            <label class="tns-check"><input id="tns-vibrate" type="checkbox"${settings.vibrate ? ' checked' : ''}> Getar HP</label>
            <label class="tns-check"><input id="tns-toast" type="checkbox"${settings.visualToast ? ' checked' : ''}> Banner visual</label>
          </div>
          <div class="tns-field"><label for="tns-custom">Unggah nada sendiri (MP3/WAV/OGG, maks. 2 MB)</label><input id="tns-custom" type="file" accept="audio/*"><small id="tns-custom-name" style="color:#94a3b8">${settings.customName ? `Tersimpan: ${settings.customName}` : 'Belum ada nada kustom.'}</small></div>
          <div class="tns-note">Agar suara dapat diputar, lakukan satu klik/tap setelah login. Browser tidak dapat melewati mode senyap, volume perangkat, atau tab yang dimute.</div>
          <div id="tns-status" class="tns-status"></div>
        </div>
        <footer class="tns-actions"><button id="tns-test-support" class="tns-btn" type="button">Tes Chat Bantuan</button><button id="tns-test-reservation" class="tns-btn" type="button">Tes Reservasi</button><button id="tns-permission" class="tns-btn" type="button">Izin Notifikasi Desktop</button><button id="tns-reset" class="tns-btn warn" type="button">Reset</button><button id="tns-save" class="tns-btn primary" type="button">Simpan Pengaturan</button></footer>
      </section>`;
    document.body.appendChild(backdrop);
    const modal = backdrop.querySelector('#tamasya-notification-settings-modal');
    const close = () => backdrop.remove();
    backdrop.addEventListener('click', (event) => { if (event.target === backdrop) close(); });
    modal.querySelector('.tns-close').addEventListener('click', close);
    const volume = modal.querySelector('#tns-volume');
    volume.addEventListener('input', () => { modal.querySelector('#tns-volume-value').textContent = `${volume.value}%`; });
    const status = (message, isError = false) => {
      const el = modal.querySelector('#tns-status'); el.textContent = message; el.style.color = isError ? '#fca5a5' : '#6ee7b7';
    };
    const formSettings = () => sanitize({
      enabled: modal.querySelector('#tns-enabled').checked,
      supportTone: modal.querySelector('#tns-support').value,
      reservationTone: modal.querySelector('#tns-reservation').value,
      volume: volume.value,
      repeats: modal.querySelector('#tns-repeats').value,
      gapMs: modal.querySelector('#tns-gap').value,
      vibrate: modal.querySelector('#tns-vibrate').checked,
      visualToast: modal.querySelector('#tns-toast').checked,
      customName: settings.customName
    });
    const test = async (type) => {
      try {
        settings = formSettings();
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) throw new Error('Web Audio tidak tersedia pada browser ini.');
        const ctx = window.__TAMASYA_NOTIFICATION_TEST_CONTEXT__ || new Ctx();
        window.__TAMASYA_NOTIFICATION_TEST_CONTEXT__ = ctx;
        await ctx.resume();
        play(ctx, type);
        status('Nada uji diputar. Periksa volume perangkat bila belum terdengar.');
      } catch (error) { status(error?.message || 'Nada uji gagal diputar.', true); }
    };
    modal.querySelector('#tns-test-support').addEventListener('click', () => test('public_support'));
    modal.querySelector('#tns-test-reservation').addEventListener('click', () => test('public_reservation'));
    modal.querySelector('#tns-permission').addEventListener('click', async () => {
      try {
        if (!('Notification' in window)) throw new Error('Notifikasi desktop tidak didukung browser ini.');
        const result = await Notification.requestPermission();
        status(result === 'granted' ? 'Izin notifikasi desktop aktif.' : `Izin notifikasi: ${result}.`, result === 'denied');
      } catch (error) { status(error?.message || 'Permintaan izin gagal.', true); }
    });
    modal.querySelector('#tns-custom').addEventListener('change', async (event) => {
      const file = event.target.files?.[0];
      if (!file) return;
      if (!String(file.type || '').startsWith('audio/')) { status('File harus berformat audio.', true); event.target.value = ''; return; }
      if (file.size > MAX_CUSTOM_BYTES) { status('Ukuran nada melebihi 2 MB.', true); event.target.value = ''; return; }
      try {
        await putCustomBlob(file);
        settings = save({ ...formSettings(), customName: file.name });
        modal.querySelector('#tns-custom-name').textContent = `Tersimpan: ${file.name}`;
        status('Nada kustom tersimpan pada perangkat ini.');
      } catch (error) { status(error?.message || 'Nada kustom gagal disimpan.', true); }
    });
    modal.querySelector('#tns-reset').addEventListener('click', async () => {
      await removeCustomBlob();
      save({ ...defaults });
      close();
      window.setTimeout(openSettings, 20);
    });
    modal.querySelector('#tns-save').addEventListener('click', () => {
      save({ ...formSettings(), customName: settings.customName });
      status('Pengaturan nada berhasil disimpan.');
      window.setTimeout(close, 450);
    });
    window.setTimeout(() => modal.querySelector('#tns-support')?.focus(), 30);
  };

  const NOTIFICATION_TITLE_SELECTOR = 'span,div,p,h1,h2,h3,h4';

  const injectSettingsButton = (scope = document) => {
    ensureStyles();
    const candidates = [];
    if (scope instanceof Element && scope.matches(NOTIFICATION_TITLE_SELECTOR)) candidates.push(scope);
    if (scope && typeof scope.querySelectorAll === 'function') {
      scope.querySelectorAll(NOTIFICATION_TITLE_SELECTOR).forEach((node) => candidates.push(node));
    }
    for (const node of candidates) {
      if ((node.textContent || '').trim() !== 'Notifikasi Sistem') continue;
      const header = node.parentElement;
      if (!header || header.querySelector('.tamasya-notif-settings-button')) continue;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'tamasya-notif-settings-button';
      button.textContent = 'Atur Nada';
      button.addEventListener('click', (event) => { event.stopPropagation(); openSettings(); });
      const markAll = [...header.querySelectorAll('button')].find((el) => /tandai semua/i.test(el.textContent || ''));
      if (markAll) header.insertBefore(button, markAll); else header.appendChild(button);
    }
  };

  window.TamasyaNotificationSound = {
    play,
    notify,
    openSettings,
    getSettings: () => ({ ...load() }),
    saveSettings: (next) => ({ ...save(next) }),
    version: 'V137'
  };

  const boot = () => {
    ensureStyles();
    injectSettingsButton(document);
    const pendingScopes = new Set();
    let scanFrame = 0;
    const flushScopes = () => {
      scanFrame = 0;
      const scopes = [...pendingScopes];
      pendingScopes.clear();
      for (const scope of scopes) {
        if (scope === document.documentElement || document.documentElement.contains(scope)) {
          injectSettingsButton(scope);
        }
      }
    };
    const observer = new MutationObserver((records) => {
      for (const record of records) {
        if (record.type !== 'childList' || record.addedNodes.length === 0) continue;
        for (const added of record.addedNodes) {
          if (added.nodeType === Node.ELEMENT_NODE) pendingScopes.add(added);
          else if (added.nodeType === Node.TEXT_NODE && added.parentElement) pendingScopes.add(added.parentElement);
        }
      }
      if (pendingScopes.size && !scanFrame) scanFrame = requestAnimationFrame(flushScopes);
    });
    observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
})();
