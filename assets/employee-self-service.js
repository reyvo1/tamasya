/** TAMASYA V137 Employee Self-Service UI. Presentation layer only. */
(() => {
  'use strict';
  if (window.__TAMASYA_EMPLOYEE_SELF_SERVICE_V137__) return;
  window.__TAMASYA_EMPLOYEE_SELF_SERVICE_V137__ = true;

  const API = '/api/employee-self-service';
  const id = (x) => document.getElementById(x);
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const T = { account:'Akun Saya', subtitle:'Cuti & Gaji Saya', leave:'Cuti Saya', salary:'Gaji Saya', approvals:'Persetujuan Cuti', request:'Ajukan Cuti', type:'Jenis Cuti', start:'Mulai', end:'Selesai', reason:'Alasan', submit:'Kirim Pengajuan', close:'Tutup', pending:'Menunggu', approved:'Disetujui', rejected:'Ditolak', cancelled:'Dibatalkan', annual:'Cuti Tahunan', sick:'Sakit', permission:'Izin', family:'Keluarga', unpaid:'Cuti Tanpa Gaji', other:'Lainnya', noLeave:'Belum ada pengajuan cuti.', noSalary:'Belum ada slip gaji yang diterbitkan.', period:'Periode', base:'Gaji Pokok', allowances:'Tunjangan', deductions:'Potongan', bonus:'Bonus', net:'Gaji Bersih', status:'Status', downloadCsv:'Unduh CSV', printPdf:'Cetak / Simpan PDF', approve:'Setujui', reject:'Tolak', cancel:'Batalkan', refresh:'Muat Ulang', sent:'Pengajuan cuti berhasil dikirim.', decide:'Keputusan cuti berhasil disimpan.', paid:'Dibayar', printed:'Diterbitkan', sentSlip:'Terkirim', day:'hari', days:'hari', loading:'Memuat data akun…', error:'Gagal memuat data akun.', leaveMenu:'Ajukan Cuti Saya' };
  const t = (k) => T[k] || k;
  const money = (n) => window.TamasyaCurrencyDisplay.formatRupiah(Number(n||0));
  const statusText = (s) => { const k=String(s||'').toLowerCase(); return k==='sent'?t('sentSlip'):t(k); };
  const typeText = (s) => t(String(s||'').toLowerCase());
  const dayText = () => t('days');
  let state = null;

  function shell(){
    let launcher=id('tamasya-ess-core-launcher');
    if(!launcher){
      launcher=document.createElement('button');launcher.id='tamasya-ess-core-launcher';launcher.type='button';launcher.hidden=true;launcher.innerHTML='<span class="ess-core-icon">👤</span><span class="ess-core-launch-text"></span>';
      launcher.addEventListener('click',()=>openDrawer());document.body.appendChild(launcher);
    }
    let overlay=id('tamasya-ess-core-overlay');
    if(!overlay){
      overlay=document.createElement('div');overlay.id='tamasya-ess-core-overlay';overlay.hidden=true;
      overlay.innerHTML='<div class="ess-core-backdrop"></div><aside class="ess-core-drawer" role="dialog" aria-modal="true"><header><div><h2 id="ess-core-title"></h2><p id="ess-core-subtitle"></p></div><button type="button" class="ess-core-close" aria-label="Tutup">×</button></header><div class="ess-core-tabs" id="ess-core-tabs"></div><div class="ess-core-body" id="ess-core-body"></div></aside>';
      overlay.querySelector('.ess-core-backdrop').addEventListener('click',closeDrawer);overlay.querySelector('.ess-core-close').addEventListener('click',closeDrawer);document.body.appendChild(overlay);
    }
    launcher.querySelector('.ess-core-launch-text').textContent=t('account');
    id('ess-core-title').textContent=t('account');id('ess-core-subtitle').textContent=t('subtitle');
    return {launcher,overlay};
  }
  function closeDrawer(){const o=id('tamasya-ess-core-overlay');if(o)o.hidden=true;document.body.classList.remove('ess-core-open');}
  async function api(method='GET', body=null, includeTeam=true){
    const url=method==='GET'?`${API}?includeTeam=${includeTeam?'1':'0'}`:API;
    const r=await fetch(url,{method,headers:body?{'Content-Type':'application/json'}:undefined,body:body?JSON.stringify(body):undefined,cache:'no-store'});
    const j=await r.json().catch(()=>({}));if(!r.ok||j.success===false)throw new Error(j.error||j.message||`HTTP ${r.status}`);return j;
  }
  async function refresh(){
    const ui=shell();
    if(sessionStorage.getItem('hotel_logged_in')!=='true'){state=null;ui.launcher.hidden=true;return false;}
    const body=id('ess-core-body');if(body)body.innerHTML=`<div class="ess-core-loading">${esc(t('loading'))}</div>`;
    try{state=await api('GET',null,true);ui.launcher.hidden=false;renderTabs('leave');return true;}catch(e){if(ui.overlay.hidden){ui.launcher.hidden=true;}else if(body)body.innerHTML=`<div class="ess-core-error">${esc(e.message||t('error'))}</div>`;return false;}
  }
  function tabButton(key,label,active){return `<button type="button" data-ess-tab="${key}" class="${active===key?'active':''}">${esc(label)}</button>`;}
  function renderTabs(active='leave'){
    if(!state)return;const tabs=id('ess-core-tabs');
    tabs.innerHTML=tabButton('leave',t('leave'),active)+tabButton('salary',t('salary'),active)+(state.canApproveLeave?tabButton('approvals',t('approvals'),active):'');
    tabs.querySelectorAll('[data-ess-tab]').forEach(b=>b.addEventListener('click',()=>renderTabs(b.dataset.essTab)));
    if(active==='salary')renderSalary();else if(active==='approvals')renderApprovals();else renderLeave();
  }
  const badge=(s)=>`<span class="ess-core-badge s-${esc(String(s||'pending').toLowerCase())}">${esc(statusText(s))}</span>`;
  function renderLeave(){
    const b=id('ess-core-body');const rows=state.leaveRequests||[];
    b.innerHTML=`<section class="ess-core-card"><h3>${esc(t('request'))}</h3><form id="ess-core-leave-form" class="ess-core-form"><label>${esc(t('type'))}<select name="leaveType"><option value="annual">${esc(t('annual'))}</option><option value="sick">${esc(t('sick'))}</option><option value="permission">${esc(t('permission'))}</option><option value="family">${esc(t('family'))}</option><option value="unpaid">${esc(t('unpaid'))}</option><option value="other">${esc(t('other'))}</option></select></label><div class="ess-core-grid2"><label>${esc(t('start'))}<input type="date" name="startDate" required></label><label>${esc(t('end'))}<input type="date" name="endDate" required></label></div><label>${esc(t('reason'))}<textarea name="reason" minlength="5" maxlength="1000" required></textarea></label><button class="ess-core-primary" type="submit">${esc(t('submit'))}</button></form></section><section class="ess-core-card"><h3>${esc(t('leave'))}</h3>${rows.length?rows.map(r=>`<article class="ess-core-row"><div><strong>${esc(r.startDate)} → ${esc(r.endDate)}</strong><small>${esc(typeText(r.leaveType))} · ${esc(r.daysRequested)} ${esc(dayText(r.daysRequested))}</small><p>${esc(r.reason)}</p>${r.decisionNotes?`<small>${esc(r.decisionNotes)}</small>`:''}</div><div>${badge(r.status)}${r.status==='pending'?`<button type="button" class="ess-core-link" data-cancel="${esc(r.id)}">${esc(t('cancel'))}</button>`:''}</div></article>`).join(''):`<p class="ess-core-muted">${esc(t('noLeave'))}</p>`}</section>`;
    const form=id('ess-core-leave-form');form.addEventListener('submit',async ev=>{ev.preventDefault();const fd=new FormData(form);const btn=form.querySelector('button[type=submit]');btn.disabled=true;try{await api('POST',{command:'request_leave',leaveType:fd.get('leaveType'),startDate:fd.get('startDate'),endDate:fd.get('endDate'),reason:fd.get('reason')});await refresh();alert(t('sent'));}catch(e){alert(e.message);}finally{btn.disabled=false;}});
    b.querySelectorAll('[data-cancel]').forEach(x=>x.addEventListener('click',async()=>{if(!confirm(`${t('cancel')}?`))return;try{await api('POST',{command:'cancel_leave',requestId:x.dataset.cancel});await refresh();}catch(e){alert(e.message);}}));
  }
  function salaryCsv(){
    const rows=state.salarySlips||[];const headers=[t('period'),t('status'),t('base'),t('allowances'),t('bonus'),t('deductions'),t('net'),'Dibayar Pada','Catatan'];
    const q=v=>{let text=String(v??'');if(typeof v==='string'&&/^[\s\uFEFF]*[=+\-@]|^[\t\r\n]/.test(text))text="'"+text;return '"'+text.replace(/"/g,'""')+'"';};const lines=[headers.map(q).join(',')];
    rows.forEach(r=>lines.push([r.period,r.status,r.basicSalary,r.allowances,r.bonus,r.deductions,r.netSalary,r.paidAt||'',r.notes||''].map(q).join(',')));
    const blob=new Blob(['\uFEFF'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'}),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=`TAMASYA_GAJI_${state.profile?.id||'staff'}.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(a.href),1000);
  }
  function salaryPrint(){
    const rows=state.salarySlips||[];const w=window.open('','_blank','width=900,height=700');if(!w)return;
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(t('salary'))}</title><style>body{font-family:Arial,sans-serif;padding:28px;color:#111}h1{font-size:22px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{border:1px solid #ccc;padding:7px;text-align:right}th:first-child,td:first-child{text-align:left}</style></head><body><h1>TAMASYA - ${esc(t('salary'))}</h1><p>${esc(state.profile?.name||'')} · ${esc(state.profile?.id||'')}</p><table><thead><tr><th>${esc(t('period'))}</th><th>${esc(t('base'))}</th><th>${esc(t('allowances'))}</th><th>${esc(t('bonus'))}</th><th>${esc(t('deductions'))}</th><th>${esc(t('net'))}</th><th>${esc(t('status'))}</th></tr></thead><tbody>${rows.map(r=>`<tr><td>${esc(r.period)}</td><td>${esc(money(r.basicSalary))}</td><td>${esc(money(r.allowances))}</td><td>${esc(money(r.bonus))}</td><td>${esc(money(r.deductions))}</td><td><strong>${esc(money(r.netSalary))}</strong></td><td>${esc(statusText(r.status))}</td></tr>`).join('')}</tbody></table><script>window.onload=()=>window.print()<\/script></body></html>`);w.document.close();
  }
  function renderSalary(){
    const b=id('ess-core-body'),rows=state.salarySlips||[];
    b.innerHTML=`<section class="ess-core-card"><div class="ess-core-cardhead"><div><h3>${esc(t('salary'))}</h3><p class="ess-core-muted">${esc(state.profile?.name||'')}</p></div><div class="ess-core-actions"><button type="button" id="ess-core-csv" ${rows.length?'':'disabled'}>${esc(t('downloadCsv'))}</button><button type="button" id="ess-core-print" ${rows.length?'':'disabled'}>${esc(t('printPdf'))}</button></div></div>${rows.length?rows.map(r=>`<article class="ess-core-pay"><div><strong>${esc(r.period)}</strong>${badge(r.status)}<small>${esc(r.paidAt||'')}</small></div><div class="ess-core-net">${esc(money(r.netSalary))}</div><div class="ess-core-paygrid"><span>${esc(t('base'))}<b>${esc(money(r.basicSalary))}</b></span><span>${esc(t('allowances'))}<b>${esc(money(r.allowances))}</b></span><span>${esc(t('bonus'))}<b>${esc(money(r.bonus))}</b></span><span>${esc(t('deductions'))}<b>${esc(money(r.deductions))}</b></span></div>${r.notes?`<p>${esc(r.notes)}</p>`:''}</article>`).join(''):`<p class="ess-core-muted">${esc(t('noSalary'))}</p>`}</section>`;
    id('ess-core-csv')?.addEventListener('click',salaryCsv);id('ess-core-print')?.addEventListener('click',salaryPrint);
  }
  function renderApprovals(){
    const b=id('ess-core-body'),rows=state.pendingTeamLeave||[];
    b.innerHTML=`<section class="ess-core-card"><h3>${esc(t('approvals'))}</h3>${rows.length?rows.map(r=>`<article class="ess-core-row"><div><strong>${esc(r.staffName)}</strong><small>${esc(r.startDate)} → ${esc(r.endDate)} · ${esc(typeText(r.leaveType))} · ${esc(r.daysRequested)} ${esc(dayText(r.daysRequested))}</small><p>${esc(r.reason)}</p></div><div class="ess-core-actions"><button type="button" class="ess-core-ok" data-approve="${esc(r.id)}">${esc(t('approve'))}</button><button type="button" class="ess-core-danger" data-reject="${esc(r.id)}">${esc(t('reject'))}</button></div></article>`).join(''):`<p class="ess-core-muted">${esc(t('noLeave'))}</p>`}</section>`;
    b.querySelectorAll('[data-approve]').forEach(x=>x.addEventListener('click',()=>decide(x.dataset.approve,true)));
    b.querySelectorAll('[data-reject]').forEach(x=>x.addEventListener('click',()=>decide(x.dataset.reject,false)));
  }
  async function decide(requestId,approve){const notes=prompt(approve?'Catatan persetujuan (opsional):':'Alasan penolakan (opsional):','')??'';try{await api('POST',{command:approve?'approve_leave':'reject_leave',requestId,notes});await refresh();renderTabs('approvals');alert(t('decide'));}catch(e){alert(e.message);}}
  async function openDrawer(tab='leave'){const {overlay}=shell();overlay.hidden=false;document.body.classList.add('ess-core-open');await refresh();if(state)renderTabs(tab);}
  function leavePageButton(){
    const path=location.pathname.toLowerCase();
    const activeLeaveTab=(window.TAMASYA_NAVIGATION?.current?.() || sessionStorage.getItem('hotel_active_tab')) === 'leaves';
    const heading=[...document.querySelectorAll('h1,h2,h3')].find(x=>/cuti|leave/i.test(x.textContent||''));
    const isLeaveContext=path.endsWith('/leaves')||path.includes('/leaves/')||!!activeLeaveTab||!!heading;
    if(!isLeaveContext)return;
    if(id('tamasya-ess-core-leave-inline'))return;
    if(!heading)return;
    const b=document.createElement('button');b.id='tamasya-ess-core-leave-inline';b.type='button';b.className='ess-core-inline';b.textContent=t('leaveMenu');b.addEventListener('click',()=>openDrawer('leave'));heading.parentElement?.appendChild(b);
  }
  function boot(){
    shell();
    let lastAuth=sessionStorage.getItem('hotel_logged_in')==='true';
    if(lastAuth)refresh().then(()=>leavePageButton());
    let frame=0;
    const schedule=()=>{if(frame)return;frame=requestAnimationFrame(()=>{frame=0;const auth=sessionStorage.getItem('hotel_logged_in')==='true';if(auth&&!lastAuth)refresh().then(()=>leavePageButton());else if(!auth&&lastAuth){state=null;shell().launcher.hidden=true;closeDrawer();}lastAuth=auth;leavePageButton();});};
    const root=document.getElementById('root')||document.body;
    const relevantAddedNode=(node)=>{
      if(!(node instanceof Element))return false;
      if(node.matches('h1,h2,h3,.nav-tab,[id^="tab-"],[data-tab],[data-route]'))return true;
      return !!node.querySelector('h1,h2,h3,.nav-tab,[id^="tab-"],[data-tab],[data-route]');
    };
    const mutationNeedsSchedule=(records)=>records.some(record=>{
      if(record.type!=='childList'||record.addedNodes.length===0)return false;
      if(record.target===root)return true;
      return [...record.addedNodes].some(relevantAddedNode);
    });
    const mo=new MutationObserver((records)=>{if(mutationNeedsSchedule(records))schedule();});
    mo.observe(root,{childList:true,subtree:true});
    window.addEventListener('tamasya-route-change',schedule);
    window.addEventListener('tamasya-auth-expired',schedule);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
