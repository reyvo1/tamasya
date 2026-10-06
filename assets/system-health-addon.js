/* TAMASYA FIX28R6 AUDIT4 read-only diagnostics overlay.
 * Additive observability only: no cluster/offline mutation logic is changed here.
 * DOM ownership rule: this addon never inserts/removes/moves nodes inside React #root.
 * The launcher is rendered declaratively by app-shell.js next to Refresh.
 */
(function(){
  'use strict';
  const ID='tamasya-system-health-overlay';
  const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const role=()=>String(sessionStorage.getItem('hotel_role')||sessionStorage.getItem('hotel_staff_role')||'').trim().toLowerCase();
  const loggedIn=()=>sessionStorage.getItem('hotel_logged_in')==='true';
  let timer=null;

  function api(path){ return fetch('/api/'+path,{method:'GET',cache:'no-store',headers:{'Accept':'application/json'}}).then(async r=>({ok:r.ok,status:r.status,data:await r.json().catch(()=>({}))})); }
  function swStatus(){
    return new Promise(resolve=>{
      if(!('serviceWorker' in navigator) || !navigator.serviceWorker.controller){ resolve({controlled:false}); return; }
      const channel=new MessageChannel();
      const timeout=setTimeout(()=>resolve({controlled:true,reply:false}),1800);
      channel.port1.onmessage=e=>{ clearTimeout(timeout); resolve({controlled:true,reply:true,...(e.data||{})}); };
      try{ navigator.serviceWorker.controller.postMessage({type:'TAMASYA_SW_STATUS'},[channel.port2]); }
      catch(e){ clearTimeout(timeout); resolve({controlled:true,reply:false,error:e?.message||String(e)}); }
    });
  }
  const fmt=(v)=>v?String(v).replace('T',' ').replace(/\.\d+Z$/,'Z'):'—';
  function badge(label,value,tone='neutral'){
    return `<div class="tsh-card tsh-${tone}"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`;
  }
  function installStyle(){
    if(document.getElementById('tamasya-system-health-style')) return;
    const s=document.createElement('style'); s.id='tamasya-system-health-style';
    s.textContent=`
      #${ID}{position:fixed;inset:0;z-index:2147482000;background:rgba(2,6,23,.82);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;padding:18px;color:#e2e8f0;font-family:inherit}
      #${ID}[hidden]{display:none!important}.tsh-panel{width:min(1050px,96vw);max-height:92vh;overflow:auto;background:#020617;border:1px solid rgba(148,163,184,.25);border-radius:22px;box-shadow:0 30px 90px rgba(0,0,0,.5)}
      .tsh-head{position:sticky;top:0;z-index:2;display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;background:rgba(2,6,23,.96);border-bottom:1px solid rgba(148,163,184,.18)}
      .tsh-head h2{margin:0;font-size:18px}.tsh-head p{margin:4px 0 0;color:#94a3b8;font-size:12px}.tsh-actions{display:flex;gap:8px}.tsh-btn{border:1px solid rgba(148,163,184,.25);background:#0f172a;color:#e2e8f0;border-radius:10px;padding:8px 11px;font-weight:700;font-size:12px;cursor:pointer}.tsh-btn:hover{background:#1e293b}
      .tsh-body{padding:16px 18px 22px}.tsh-section{margin:0 0 18px}.tsh-section h3{font-size:13px;margin:0 0 9px;color:#bae6fd}.tsh-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}.tsh-card{min-height:66px;border:1px solid rgba(148,163,184,.16);background:#0f172a;border-radius:12px;padding:10px}.tsh-card span{display:block;color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.04em}.tsh-card strong{display:block;margin-top:6px;font-size:13px;word-break:break-word}.tsh-good{border-color:rgba(52,211,153,.32);background:rgba(6,78,59,.22)}.tsh-warn{border-color:rgba(251,191,36,.32);background:rgba(120,53,15,.20)}.tsh-bad{border-color:rgba(251,113,133,.35);background:rgba(136,19,55,.20)}
      .tsh-error{padding:10px 12px;border-radius:10px;background:rgba(136,19,55,.22);border:1px solid rgba(251,113,133,.3);color:#fecdd3;font-size:12px;margin-bottom:12px}.tsh-note{font-size:11px;color:#94a3b8;line-height:1.55}.tsh-launch{display:inline-flex!important;align-items:center;gap:6px;text-decoration:none!important}.tsh-list{display:grid;gap:7px}.tsh-issue{padding:9px 11px;border:1px solid rgba(148,163,184,.16);background:#0f172a;border-radius:10px;font-size:11px;line-height:1.5}.tsh-issue strong{font-size:12px}.tsh-issue code{color:#bae6fd;word-break:break-all}.tsh-issue small{display:block;color:#94a3b8;margin-top:2px}
      @media(max-width:640px){#${ID}{padding:6px}.tsh-panel{width:100%;max-height:97vh;border-radius:14px}.tsh-head{align-items:flex-start}.tsh-actions{flex-direction:column}.tsh-body{padding:12px}.tsh-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    `;
    document.head.appendChild(s);
  }
  function ensureOverlay(){
    installStyle();
    let el=document.getElementById(ID); if(el) return el;
    el=document.createElement('div'); el.id=ID; el.hidden=true;
    el.innerHTML=`<div class="tsh-panel" role="dialog" aria-modal="true" aria-labelledby="tsh-title"><div class="tsh-head"><div><h2 id="tsh-title">Diagnostik Sistem & Sinkronisasi</h2><p>Read-only · tidak melakukan failover, retry, atau mutation.</p></div><div class="tsh-actions"><button class="tsh-btn" data-tsh-refresh>Perbarui</button><button class="tsh-btn" data-tsh-close>Tutup</button></div></div><div class="tsh-body"><div data-tsh-content>Memuat…</div></div></div>`;
    document.body.appendChild(el);
    el.querySelector('[data-tsh-close]').addEventListener('click',close);
    el.querySelector('[data-tsh-refresh]').addEventListener('click',refresh);
    el.addEventListener('click',e=>{ if(e.target===el) close(); });
    return el;
  }
  async function refresh(){
    const el=ensureOverlay(), content=el.querySelector('[data-tsh-content]');
    content.innerHTML='<p class="tsh-note">Memeriksa Consistency Guard, API, cluster, antrean offline/node sync, dan Service Worker…</p>';
    const [base,sync,cluster,guard,sw]=await Promise.allSettled([api('status'),api('node-sync-status'),api('node-cluster-status'),api('consistency-guard-status?sampleLimit=5'),swStatus()]);
    const b=base.status==='fulfilled'?base.value:null;
    const sy=sync.status==='fulfilled'?sync.value:null;
    const cl=cluster.status==='fulfilled'?cluster.value:null;
    const gd=guard.status==='fulfilled'?guard.value:null;
    const s=sw.status==='fulfilled'?sw.value:{};
    const g=gd?.data?.guard||{};
    const errors=[];
    if(!b?.ok) errors.push('Status API: '+esc(b?.data?.error||b?.status||'gagal'));
    if(!sy?.ok) errors.push('Node sync: '+esc(sy?.data?.error||sy?.status||'gagal'));
    if(!cl?.ok) errors.push('Cluster: '+esc(cl?.data?.error||cl?.status||'gagal'));
    if(!gd?.ok) errors.push('Consistency Guard: '+esc(gd?.data?.error||gd?.status||'gagal'));
    const ns=sy?.data?.nodeSync||{}; const cs=cl?.data?.cluster||{}; const q=ns.outbox||{};
    const pending=Number(q.pending||0), sending=Number(q.sending||0), uncertain=Number(q.uncertain||0), failed=Number(q.failed||0), conflict=Number(q.conflict||0), completed=Number(q.completed||0), openConflicts=Number(ns.openConflicts||cs.openConflicts||0);
    const queueBad=uncertain+failed+conflict+openConflicts; const queueActive=pending+sending;
    const peerOk=!!cs?.peerProbe?.ok; const leaseOk=cs.leaseValid!==false; const split=!!cs.splitBrainRisk||String(cs.transferState||'')==='split_brain';
    const expectedBuild=String(g.buildId||b?.data?.buildId||'');
    const swBuild=String(s.cacheName||'');
    const versionMismatch=!!(expectedBuild&&s.controlled&&swBuild&&!swBuild.includes(expectedBuild));
    const toneFor=st=>st==='PASS'?'good':(st==='FAIL'?'bad':'warn');
    const metrics=Array.isArray(g.metrics)?g.metrics:[];
    const problems=metrics.filter(m=>m&&['FAIL','WARNING'].includes(String(m.status))).slice(0,12);
    const problemHtml=problems.length?problems.map(m=>{
      const sample=(Array.isArray(m.samples)?m.samples:[])[0]||{};
      const id=sample.id||sample.transaction_id||sample.event_id||sample.device_id||sample.request_id||'';
      const expected=sample.expected_cash??sample.splitCashAmount??sample.bank_leg??'';
      const actual=sample.calc_income??sample.journalCash??sample.matched??'';
      return `<div class="tsh-issue"><strong>${esc(m.status)} · ${esc(m.label||m.code)}</strong>${Number(m.count||0)>0?` · ${esc(m.count)}`:''}<small>${esc(m.message||'')}</small>${id?`<small>Evidence: <code>${esc(id)}</code>${expected!==''?` · expected ${esc(expected)}`:''}${actual!==''?` · actual ${esc(actual)}`:''}</small>`:''}</div>`;
    }).join(''):'<p class="tsh-note">Tidak ada invariant FAIL/WARNING pada scan ini.</p>';
    content.innerHTML=`
      ${errors.length?`<div class="tsh-error">${errors.join('<br>')}</div>`:''}
      ${versionMismatch?`<div class="tsh-error"><strong>VERSION MISMATCH:</strong> API mengharapkan build ${esc(expectedBuild)}, tetapi Service Worker masih ${esc(swBuild)}. Reload/update aplikasi sebelum melakukan transaksi.</div>`:''}
      <section class="tsh-section"><h3>Consistency Guard · Read-only</h3><div class="tsh-grid">
        ${badge('Overall',g.overall||'UNAVAILABLE',toneFor(g.overall))}
        ${badge('FAIL',g.summary?.fail??'—',Number(g.summary?.fail||0)?'bad':'good')}
        ${badge('WARNING',g.summary?.warning??'—',Number(g.summary?.warning||0)?'warn':'good')}
        ${badge('PASS',g.summary?.pass??'—','good')}
        ${badge('Server revision',g.serverRevision??'—')}
        ${badge('Build ID',expectedBuild||'—',versionMismatch?'bad':'good')}
        ${badge('Auto-fix',g.autoFix===false?'NONAKTIF':'—','good')}
        ${badge('Last check',fmt(g.checkedAt))}
        ${badge('Auto Guard',g.scheduler?.enabled?'AKTIF':'NONAKTIF',g.scheduler?.enabled?'good':'warn')}
        ${badge('Cron',g.scheduler?.cronRequired?'WAJIB':'OPSIONAL',g.scheduler?.cronRequired?'warn':'good')}
        ${badge('Auto ringan',g.scheduler?.lastLightAt?fmt(g.scheduler.lastLightAt):'BELUM',g.scheduler?.lastLightAt?'good':'warn')}
        ${badge('Auto deep',g.scheduler?.lastDeepAt?fmt(g.scheduler.lastDeepAt):'BELUM',g.scheduler?.lastDeepAt?'good':'warn')}
      </div><div class="tsh-list" style="margin-top:9px">${problemHtml}</div><p class="tsh-note">FAIL/WARNING hanya memberi bukti dan lokasi masalah. Consistency Guard tidak mengubah transaksi, pajak, jurnal, shift, booking, atau queue secara otomatis. Auto Guard memakai aktivitas aplikasi sebagai pemicu; cron hanya tambahan opsional jika server mendukung.</p></section>
      <section class="tsh-section"><h3>Server & Cluster</h3><div class="tsh-grid">
        ${badge('Browser',navigator.onLine?'ONLINE':'OFFLINE',navigator.onLine?'good':'warn')}
        ${badge('API ready',b?.data?.ready?'READY':(b?.data?.liveness?'LIVE / NOT READY':'UNREACHABLE'),b?.data?.ready?'good':'warn')}
        ${badge('Node',cs.nodeId||ns.nodeId||b?.data?.nodeId||'—')}
        ${badge('Role',cs.isPrimaryWriter?'PRIMARY WRITER':(ns.nodeRole||b?.data?.nodeRole||'—'),cs.isPrimaryWriter?'good':'neutral')}
        ${badge('Status cluster',cs.nodeStatus||cs.nodeStatusCode||'—',split?'bad':(cs.nodeStatusCode==='primary'||cs.nodeStatusCode==='standby_ready'?'good':'warn'))}
        ${badge('Peer',cs.peerConfigured?(peerOk?'TERHUBUNG':'TIDAK TERHUBUNG'):'BELUM DISET',peerOk?'good':'warn')}
        ${badge('Leadership epoch',cs.leadershipEpoch??'—')}
        ${badge('Lease',leaseOk?'VALID':'TIDAK VALID',leaseOk?'good':'bad')}
        ${badge('Revision node',cs.localRevision??ns.localServerRevision??'—')}
        ${badge('Primary revision',ns.lastPrimaryRevision??'—')}
      </div></section>
      <section class="tsh-section"><h3>Offline Queue / Node Sync</h3><div class="tsh-grid">
        ${badge('Pending',pending,pending?'warn':'good')}${badge('Sending',sending,sending?'warn':'good')}${badge('Uncertain',uncertain,uncertain?'bad':'good')}${badge('Failed',failed,failed?'bad':'good')}${badge('Conflict queue',conflict,conflict?'bad':'good')}${badge('Open conflict',openConflicts,openConflicts?'bad':'good')}${badge('Completed',completed)}${badge('Mutation guard',ns.mutationGuardReady?'READY':'NOT READY',ns.mutationGuardReady?'good':'bad')}
        ${badge('Last push',fmt(ns.lastPushAt))}${badge('Last pull',fmt(ns.lastPullAt))}${badge('Last success',fmt(ns.lastSuccessAt),ns.lastSuccessAt?'good':'warn')}${badge('Mirror',ns.mirrorInProgress?'SEDANG BERJALAN':'IDLE',ns.mirrorInProgress?'warn':'good')}
      </div>${ns.lastError?`<div class="tsh-error" style="margin-top:9px"><strong>Error terakhir:</strong> ${esc(ns.lastError)}</div>`:''}<p class="tsh-note">Queue bermasalah: <strong>${queueBad}</strong> · Queue aktif: <strong>${queueActive}</strong>. Mutation uncertain/conflict tetap fail-closed; panel ini tidak menyediakan tombol pemaksaan.</p></section>
      <section class="tsh-section"><h3>Service Worker / Offline Shell</h3><div class="tsh-grid">
        ${badge('SW controller',s.controlled?'AKTIF':'BELUM MENGONTROL',s.controlled?'good':'warn')}${badge('SW build',s.cacheName||'—',versionMismatch?'bad':'good')}${badge('Precache count',s.precacheCount??'—')}${badge('SW reply',s.reply?'OK':'TIDAK ADA',s.reply?'good':'warn')}
      </div><p class="tsh-note">Build mismatch dianggap masalah keselamatan karena browser lama tidak boleh menjalankan logika transaksi berbeda dari API baru.</p></section>`;
  }
  function open(){ const el=ensureOverlay(); el.hidden=false; document.body.style.overflow='hidden'; refresh(); clearInterval(timer); timer=setInterval(refresh,60000); }
  function close(){ const el=document.getElementById(ID); if(el) el.hidden=true; document.body.style.overflow=''; clearInterval(timer); timer=null; }
  function requestOpen(){
    if(!loggedIn()||!['admin','owner'].includes(role())) return;
    open();
  }
  // React owns every node under #root. The launcher itself is rendered declaratively
  // by app-shell.js; this addon only owns the body-level overlay and its data calls.
  window.addEventListener('tamasya-open-system-health',requestOpen);
  window.TamasyaSystemHealth=Object.freeze({open:requestOpen,close,refresh});
  window.addEventListener('keydown',e=>{ if(e.key==='Escape'&&!document.getElementById(ID)?.hidden) close(); });
})();
