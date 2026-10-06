import {connectRealtime} from './realtime_client.js';
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let token = '', companyScope = '', reportData = null, cached = null, generation = 0, stopRealtime = null, loading = false, deliveryOperation = null;
  const money = (minor, currency) => {const value=BigInt(minor),abs=value<0n?-value:value;return `${currency} ${value<0n?'-':''}${new Intl.NumberFormat('id-ID').format(abs/100n)},${String(abs%100n).padStart(2,'0')}`;};
  const card = (label, value) => `<div class="card"><small>${esc(label)}</small><strong>${esc(value)}</strong></div>`;
  async function api(action, query = {}, input = null) {
    const url = new URL('./api.php', location.href); url.searchParams.set('action', action);
    if(companyScope)url.searchParams.set('company',companyScope);
    Object.entries(query).forEach(([k,v]) => url.searchParams.set(k, v));
    const headers = {Accept:'application/json',Authorization:`Bearer ${token}`};
    if (cached?.url === url.href) headers['If-None-Match'] = cached.etag;
    if(input) headers['Content-Type']='application/json';
    const response = await fetch(url, {headers, method:input?'POST':'GET',body:input?JSON.stringify(input):undefined,cache:'no-store', credentials:'omit'});
    if (response.status === 304 && cached?.url === url.href) return cached.body;
    const body = await response.json();
    if (!response.ok || body.success !== true) throw new Error(body.message || `HTTP ${response.status}`);
    const etag = response.headers.get('ETag');
    if (etag) cached = {url:url.href,etag,body};
    return body;
  }
  function render(data) {
    const report = data.report;
    const level = report.integrityStatus === 'PASS' ? '' : report.integrityStatus === 'FAIL' ? 'fail' : 'warning';
    let html = `<div class="status ${level}"><b>Integritas ${esc(report.integrityStatus)}</b> · ${report.sources.length} dari ${report.propertySet.length} properti tersedia.`;
    if (report.missingProperties.length) html += `<br>Snapshot belum tersedia: ${esc(report.missingProperties.join(', '))}. Total di bawah hanya mencakup properti yang tersedia.`;
    html += '</div>';
    for (const [currency, group] of Object.entries(report.currencies)) {
      const m = group.metricsMinor;
      html += `<section class="panel"><div class="panel-heading"><h2>Ringkasan ${esc(currency)}</h2><span class="tag">${esc(report.period.from)} — ${esc(report.period.to)}</span></div><div class="cards">`;
      for (const [label,key] of [['Pendapatan operasional','revenue'],['Beban operasional','expense'],['Laba / rugi operasional','profit'],['PBJT terbentuk','taxAccrued'],['PBJT dibayar','taxPaid'],['PPh dibayar','incomeTaxPaid'],['Mutasi kas bersih','cashMovement'],['Mutasi bank bersih','bankMovement'],['Mutasi likuid bersih','liquidMovement'],['Total debit jurnal','journalDebit'],['Total kredit jurnal','journalCredit']]) html += card(label,money(m[key],currency));
      html += card('Occupancy tertimbang',group.occupancyPct === null ? 'Belum ada inventory' : `${group.occupancyPct}%`);
      html += card('ADR estimasi',group.adrEstimateMinor === null ? '—' : money(group.adrEstimateMinor,currency));
      html += card('RevPAR estimasi',group.revparEstimateMinor === null ? '—' : money(group.revparEstimateMinor,currency));
      html += '</div></section>';
    }
    html += '<section class="panel"><h2>Jejak sumber & rekonsiliasi</h2><div class="table-wrap"><table><thead><tr><th>Properti</th><th>Revisi</th><th>Integritas / review</th><th>Penerimaan pertama (UTC)</th></tr></thead><tbody>';
    for (const source of report.sources) html += `<tr><td><b>${esc(source.propertyName || source.propertyId)}</b><br>${esc(source.propertyId)}<br><code>${esc(source.checksumSha256)}</code></td><td>${source.sourceRevision}</td><td>${esc(source.integrity.status)}<br>Pajak: ${source.integrity.unresolvedTaxCount}; backfill: ${source.integrity.pendingHistoricalCount}; konflik: ${source.integrity.openSyncConflicts}</td><td>${esc(data.receivedAt[source.propertyId])}</td></tr>`;
    html += `</tbody></table></div><p class="hash">Checksum laporan konsolidasi<code>${esc(report.checksumSha256)}</code></p></section>`;
    $('report').innerHTML = html;
  }
  $('login-form').onsubmit = async event => {
    event.preventDefault(); token = $('token').value.trim();companyScope=$('login-company').value.trim(); cached = null; $('message').textContent = '';
    const current = ++generation;
    try {
      const user = await api('overview'); if (current !== generation) return;
      $('token').value = ''; $('company').textContent = user.companyId;
      const destinations=await api('delivery-destinations');if(current!==generation)return;
      $('delivery-destination').innerHTML=destinations.data.map(d=>`<option value="${esc(d.id)}">${esc(d.label)} (${esc(d.channel)})</option>`).join('');
      $('delivery-panel').hidden=destinations.data.length===0;
      $('properties').innerHTML = user.propertyIds.map(id => `<label><input type="checkbox" value="${esc(id)}" checked>${esc(id)}</label>`).join('');
      stopRealtime?.();stopRealtime=connectRealtime({ticket:async()=>(await api('realtime-ticket')).data,changed:()=>{if(reportData&&!loading)$('report-form').requestSubmit();},status:value=>{$('realtime-status').textContent=value==='connected'?'Pembaruan langsung aktif':'Pembaruan berkala';}});
      $('login-panel').hidden = true; $('workspace').hidden = false; $('logout').hidden = false;
    } catch (error) { if(current===generation){token = ''; $('message').textContent = error.message;} }
  };
  $('report-form').onsubmit = async event => {
    event.preventDefault(); if(loading)return;loading=true; const current = ++generation; $('message').textContent = 'Memuat snapshot pusat…'; $('download').disabled = true; reportData = null; $('report').replaceChildren();
    try {
      const properties = [...$('properties').querySelectorAll('input:checked')].map(e=>e.value);
      if (!properties.length) throw new Error('Pilih setidaknya satu properti.');
      const frozen = await api('report-snapshot',{}, {from:$('from').value,to:$('to').value,properties});
      const response = await fetch(`./api.php?action=export&format=json&id=${encodeURIComponent(frozen.data.reportId)}&company=${encodeURIComponent(companyScope)}`,{headers:{Authorization:`Bearer ${token}`},cache:'no-store'});
      if(!response.ok)throw new Error('Snapshot laporan tidak tersedia.');
      const result={data:await response.json()};
      if (current !== generation) return;
      reportData = result.data; render(reportData); $('download').disabled = false; $('message').textContent = '';
    } catch (error) { if (current === generation) $('message').textContent = error.message; } finally {loading=false;}
  };
  $('download').onclick = async () => {
    if (!reportData) return;
    const id=reportData.report.checksumSha256,format=$('export-format').value;
    try {
      const response=await fetch(`./api.php?action=export&id=${id}&format=${encodeURIComponent(format)}&company=${encodeURIComponent(companyScope)}`,{headers:{Authorization:`Bearer ${token}`},cache:'no-store'});
      if(!response.ok)throw Error('Ekspor tidak tersedia untuk akses ini.');
      const url=URL.createObjectURL(await response.blob()),a=document.createElement('a');
      a.href=url;a.download=`hq-${id.slice(0,12)}.${{email:'html',telegram:'txt'}[format]||format}`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    }catch(error){$('message').textContent=error.message;}
  };
  $('send-report').onclick=async()=>{
    if(!reportData){$('message').textContent='Tampilkan laporan terlebih dahulu.';return;}
    const reportId=reportData.report.checksumSha256,destinationId=$('delivery-destination').value,key=reportId+'|'+destinationId;
    if(deliveryOperation?.key!==key)deliveryOperation={key,operationId:crypto.randomUUID()};
    const current=generation;$('send-report').disabled=true;
    try{const result=await api('queue-delivery',{}, {reportId,destinationId,operationId:deliveryOperation.operationId});if(current!==generation)return;$('message').textContent=`Pengiriman ${result.data.status}. Laporan ${reportId.slice(0,12)}.`;await showDeliveries();}
    catch(error){if(current===generation)$('message').textContent=error.message;}finally{$('send-report').disabled=false;}
  };
  async function showDeliveries(){const current=generation,result=await api('delivery-jobs');if(current!==generation)return;$('delivery-jobs').innerHTML=result.data.jobs.map(j=>`<p><code>${esc(j.report_id.slice(0,12))}</code> · ${esc(j.destination_id)} · <b>${esc(j.status)}</b>${j.last_error?' — '+esc(j.last_error):''}</p>`).join('')||'Belum ada pengiriman.';}
  $('refresh-deliveries').onclick=()=>showDeliveries().catch(error=>{$('message').textContent=error.message;});
  $('logout').onclick = () => { stopRealtime?.();stopRealtime=null;generation++; token = ''; cached = null; reportData = null; deliveryOperation=null;$('delivery-jobs').replaceChildren();$('delivery-destination').replaceChildren();$('delivery-panel').hidden=true;$('report').replaceChildren(); $('properties').replaceChildren(); $('company').textContent = ''; $('workspace').hidden = true; $('login-panel').hidden = false; $('logout').hidden = true; $('download').disabled = true; $('message').textContent = ''; };
  const now = new Date(); const local = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  $('to').value = local(now); now.setDate(1); $('from').value = local(now);
})();
