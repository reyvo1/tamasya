(() => {
  'use strict';

  const ADDON_ID = 'tamasya-website-gps-addon';
  const MAP_PLACEHOLDER_TOKEN = 'www.google.com/maps/embed';

  function setControlledValue(element, value) {
    const proto = element instanceof HTMLTextAreaElement
      ? HTMLTextAreaElement.prototype
      : HTMLInputElement.prototype;
    const descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
    if (descriptor && descriptor.set) descriptor.set.call(element, value);
    else element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function errorMessage(error) {
    if (error && error.code === 1) return 'Izin lokasi ditolak. Aktifkan Location untuk situs TAMASYA di pengaturan browser.';
    if (error && error.code === 2) return 'Perangkat belum dapat menentukan posisi. Aktifkan GPS dan coba di area dengan sinyal lebih baik.';
    if (error && error.code === 3) return 'Pengambilan lokasi timeout. Coba lagi beberapa saat.';
    return 'Lokasi hotel tidak dapat diambil dari perangkat.';
  }

  function createAddon(textarea) {
    if (!textarea || textarea.dataset.tamasyaWebsiteGpsEnhanced === '1') return;
    textarea.dataset.tamasyaWebsiteGpsEnhanced = '1';

    const wrapper = document.createElement('div');
    wrapper.id = ADDON_ID;
    wrapper.style.display = 'flex';
    wrapper.style.flexWrap = 'wrap';
    wrapper.style.gap = '8px';
    wrapper.style.alignItems = 'center';
    wrapper.style.marginTop = '8px';

    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = 'Ambil Titik GPS Hotel';
    button.style.border = '1px solid rgba(255,255,255,.12)';
    button.style.borderRadius = '10px';
    button.style.padding = '8px 10px';
    button.style.fontSize = '12px';
    button.style.fontWeight = '700';
    button.style.color = '#d1fae5';
    button.style.background = 'rgba(16,185,129,.12)';
    button.style.cursor = 'pointer';

    const status = document.createElement('span');
    status.style.fontSize = '11px';
    status.style.color = '#94a3b8';
    status.textContent = 'Opsional — mengambil titik hotel dari GPS perangkat ini.';

    button.addEventListener('click', () => {
      if (!window.isSecureContext) {
        status.textContent = 'GPS browser wajib HTTPS (atau localhost).';
        status.style.color = '#fca5a5';
        return;
      }
      if (!navigator.geolocation) {
        status.textContent = 'Browser/perangkat ini tidak menyediakan Geolocation API.';
        status.style.color = '#fca5a5';
        return;
      }

      button.disabled = true;
      button.textContent = 'Mengambil GPS…';
      status.textContent = 'Menunggu koordinat presisi dari perangkat…';
      status.style.color = '#fde68a';

      navigator.geolocation.getCurrentPosition(
        (position) => {
          const lat = Number(position.coords.latitude);
          const lng = Number(position.coords.longitude);
          const accuracy = Number(position.coords.accuracy || 0);
          if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            status.textContent = 'Perangkat mengembalikan koordinat tidak valid.';
            status.style.color = '#fca5a5';
            button.disabled = false;
            button.textContent = 'Ambil Titik GPS Hotel';
            return;
          }

          // URL ini tidak memerlukan API key dan dapat dipakai sebagai iframe Google Maps.
          const mapUrl = `https://www.google.com/maps?q=${lat.toFixed(7)},${lng.toFixed(7)}&z=18&output=embed`;
          setControlledValue(textarea, mapUrl);
          status.textContent = `Titik GPS terisi: ${lat.toFixed(6)}, ${lng.toFixed(6)}${Number.isFinite(accuracy) && accuracy > 0 ? ` · akurasi ±${Math.round(accuracy)} m` : ''}. Klik Simpan & Terbitkan.`;
          status.style.color = accuracy > 100 ? '#fde68a' : '#a7f3d0';
          button.disabled = false;
          button.textContent = 'Ambil Ulang Titik GPS';
        },
        (error) => {
          status.textContent = errorMessage(error);
          status.style.color = '#fca5a5';
          button.disabled = false;
          button.textContent = 'Ambil Titik GPS Hotel';
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
      );
    });

    wrapper.append(button, status);
    textarea.insertAdjacentElement('afterend', wrapper);
  }

  function scan(scope=document) {
    const candidates=[];
    if(scope && scope.nodeType===1 && scope.matches?.('textarea')) candidates.push(scope);
    if(scope && scope.querySelectorAll) candidates.push(...scope.querySelectorAll('textarea'));
    for (const textarea of candidates) {
      if ((textarea.getAttribute('placeholder') || '').includes(MAP_PLACEHOLDER_TOKEN)) createAddon(textarea);
    }
  }

  let scanFrame=0;
  const scanScopes=new Set();
  function scheduleScan(scope){
    if(scope) scanScopes.add(scope);
    if(scanFrame) return;
    scanFrame=requestAnimationFrame(()=>{
      scanFrame=0;
      const scopes=[...scanScopes]; scanScopes.clear();
      scopes.forEach(scan);
    });
  }
  const observer = new MutationObserver((records)=>{
    for(const record of records){
      if(record.type!=='childList') continue;
      for(const node of record.addedNodes||[]){ if(node && node.nodeType===1) scheduleScan(node); }
    }
  });
  observer.observe(document.getElementById('root') || document.body, { childList: true, subtree: true });
  window.addEventListener('tamasya-route-change',(event)=>{ if(event?.detail?.route==='website') scheduleScan(document.getElementById('root')||document.body); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ()=>scan(document), { once: true });
  else scan(document);
})();
