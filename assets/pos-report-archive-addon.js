(() => {
  'use strict';

  const RELEASE = 'V137-POS-ARCHIVE-READABLE';
  const ALLOWED_ROLES = new Set(['admin', 'manager', 'finance', 'receptionist']);
  const PAGE_SIZE = 10;
  const money = (value) => `Rp ${Number(value || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 })}`;
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[char]));
  const isoToday = () => window.TamasyaPosBusinessDatePolicy.dateAt(new Date(), window.TamasyaPropertyBranding?.timezone);
  const isoMonthStart = () => `${isoToday().slice(0, 8)}01`;
  const dateTimeLabel = () => new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'long', timeStyle: 'short', timeZone: (window.TamasyaPropertyBranding&&window.TamasyaPropertyBranding.timezone)||Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC'
  }).format(new Date());

  function reportRoot() {
    return document.getElementById('financial-report-view');
  }

  function reportDates(root) {
    const inputs = [...root.querySelectorAll('input[type="date"]')];
    return { from: inputs[0]?.value || isoMonthStart(), to: inputs[1]?.value || isoToday() };
  }

  function statusLabel(status) {
    const value = String(status || '').toLowerCase();
    if (value === 'posted') return 'POSTED';
    if (value === 'void' || value === 'voided') return 'VOID';
    return value.toUpperCase() || '-';
  }

  function paymentLabel(method) {
    return ({ cash: 'Tunai', transfer: 'Transfer', qris: 'QRIS', card: 'Kartu', room_charge: 'Charge Kamar' })[method] || method || '-';
  }

  function deliveryLabel(status) {
    return ({
      created: 'Baru', preparing: 'Disiapkan', out_for_delivery: 'Dibawa', delivered: 'Diterima',
      cancelled: 'Dibatalkan', not_required: 'Tidak diperlukan'
    })[String(status || '').toLowerCase()] || status || '-';
  }

  async function fetchReport(from, to) {
    const url = new URL('./api.php', window.location.href);
    url.searchParams.set('action', 'pos-report');
    url.searchParams.set('from', from);
    url.searchParams.set('to', to);
    url.searchParams.set('_ts', String(Date.now()));
    const response = await fetch(url.toString(), {
      method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.success !== true) throw new Error(payload.error || payload.message || `HTTP ${response.status}`);
    return payload;
  }

  function csvCell(value) {
    let text = String(value ?? '').replace(/\r?\n/g, ' ');
    // Spreadsheet formula-injection guard. Preserve the visible value while
    // forcing Excel/LibreOffice/Sheets to treat guest/product text as text.
    if (typeof value === 'string' && /^[\s\uFEFF]*[=+\-@]|^[\t\r\n]/.test(text)) text = `'${text}`;
    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  }

  function downloadCsv(data) {
    const canViewCost = data.canViewCost === true;
    const headers = ['No Nota', 'Tanggal', 'Status', 'Metode', 'Kamar', 'Tamu', 'Subtotal', 'Diskon', 'Pajak', 'Total'];
    if (canViewCost) headers.push('HPP', 'Laba Kotor');
    headers.push('Petugas', 'Role Petugas', 'Delivery', 'Penerima', 'Alasan Void');
    const rows = (data.sales || []).map((sale) => {
      const row = [sale.receiptNumber, sale.saleDate, statusLabel(sale.status), paymentLabel(sale.paymentMethod), sale.roomNumber || '', sale.guestName || '', sale.subtotalAmount, sale.discountAmount, sale.taxAmount, sale.grossAmount];
      if (canViewCost) row.push(sale.costAmount ?? 0, sale.grossProfit ?? 0);
      row.push(sale.createdByName || '', sale.createdByRole || '', deliveryLabel(sale.deliveryStatus), sale.receivedByName || '', sale.voidReason || '');
      return row;
    });
    const content = [headers, ...rows].map((row) => row.map(csvCell).join(',')).join('\n');
    const blob = new Blob([`\ufeff${content}`], { type: 'text/csv;charset=utf-8' });
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = `Arsip_POS_Minibar_${data.from}_sd_${data.to}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(href);
  }

  function accountingFlowHtml() {
    return `<details class="pos-accounting-flow">
      <summary>Arah Arus Kas dan Pengakuan Keuangan POS / Minibar</summary>
      <div class="flow-grid">
        <div><strong>Penjualan langsung</strong><span>Tunai masuk ke laci kas. Transfer, QRIS, dan kartu masuk ke rekening/EDC yang dipilih dan tampil pada Log Kas.</span></div>
        <div><strong>Charge ke kamar</strong><span>Belum menjadi kas saat POS dibuat. Nilai menjadi tagihan folio dan baru menjadi kas/bank ketika tamu membayar.</span></div>
        <div><strong>Diskon dan pajak</strong><span>Diskon mengurangi nilai jual. Pajak adalah kewajiban, bukan laba hotel.</span></div>
        <div><strong>HPP dan stok</strong><span>HPP mengurangi laba dan persediaan, tetapi tidak mengurangi kas shift saat penjualan.</span></div>
        <div><strong>Void</strong><span>Membalik transaksi asal, memulihkan stok/HPP, serta menyimpan alasan dan pelaku.</span></div>
        <div><strong>Rekonsiliasi</strong><span>Arsip ini adalah register sumber. Saldo kas/bank, jurnal, dan pajak resmi tetap diperiksa di Laporan Keuangan.</span></div>
      </div>
    </details>`;
  }

  function paymentRowsHtml(data) {
    return (data.paymentBreakdown || []).map((row) => `<tr><td>${escapeHtml(paymentLabel(row.paymentMethod))}</td><td class="num">${Number(row.saleCount || 0)}</td><td class="num">${money(row.grossAmount)}</td><td class="num">${money(row.discountAmount)}</td><td class="num">${money(row.taxAmount)}</td></tr>`).join('');
  }

  function staffRowsHtml(data) {
    return (data.staffBreakdown || []).map((row) => `<tr><td>${escapeHtml(row.staffName || row.staffId || '-')}</td><td>${escapeHtml(row.staffRole || '-')}</td><td class="num">${Number(row.saleCount || 0)}</td><td class="num">${money(row.grossAmount)}</td><td class="num">${money(row.discountAmount)}</td></tr>`).join('');
  }

  function productRowsHtml(data) {
    const canViewCost = data.canViewCost === true;
    return (data.productBreakdown || []).map((row) => `<tr><td>${escapeHtml(row.sku || '-')}</td><td class="wrap">${escapeHtml(row.productName || '-')}</td><td class="num">${Number(row.quantity || 0).toLocaleString('id-ID')}</td><td class="num">${money(row.originalGross)}</td><td class="num">${money(row.discountAmount)}</td><td class="num">${money(row.taxAmount)}</td><td class="num">${money(row.grossAmount)}</td>${canViewCost ? `<td class="num">${money(row.costAmount)}</td><td class="num">${money(Number(row.grossAmount || 0) - Number(row.taxAmount || 0) - Number(row.costAmount || 0))}</td>` : ''}</tr>`).join('');
  }

  function saleCard(sale, canViewCost) {
    const voided = ['void', 'voided'].includes(String(sale.status || '').toLowerCase());
    return `<article class="sale-card${voided ? ' voided' : ''}">
      <header><div><strong>${escapeHtml(sale.receiptNumber || '-')}</strong><span>${escapeHtml(sale.saleDate || '-')}</span></div><span class="status">${escapeHtml(statusLabel(sale.status))}</span></header>
      <div class="sale-context"><span><b>Metode</b>${escapeHtml(paymentLabel(sale.paymentMethod))}</span><span><b>Kamar</b>${escapeHtml(sale.roomNumber || '-')}</span><span><b>Tamu</b>${escapeHtml(sale.guestName || '-')}</span><span><b>Petugas</b>${escapeHtml(sale.createdByName || '-')}</span></div>
      <div class="sale-money"><span><b>Subtotal</b>${money(sale.subtotalAmount)}</span><span><b>Diskon</b>${money(sale.discountAmount)}</span><span><b>Pajak</b>${money(sale.taxAmount)}</span><span class="total"><b>Total</b>${money(sale.grossAmount)}</span>${canViewCost ? `<span><b>HPP</b>${money(sale.costAmount)}</span><span><b>Laba Kotor</b>${money(sale.grossProfit)}</span>` : ''}</div>
      <footer><span>Delivery: ${escapeHtml(deliveryLabel(sale.deliveryStatus))}</span><span>Penerima: ${escapeHtml(sale.receivedByName || '-')}</span>${voided ? `<span class="void-reason">Alasan void: ${escapeHtml(sale.voidReason || '-')}</span>` : ''}</footer>
    </article>`;
  }

  function filteredSales(host) {
    const data = host._posReportData || {};
    const q = String(host._posSearch || '').trim().toLowerCase();
    const status = String(host._posStatus || 'all');
    return (data.sales || []).filter((sale) => {
      if (status !== 'all' && String(sale.status || '').toLowerCase() !== status) return false;
      if (!q) return true;
      return [sale.receiptNumber, sale.saleDate, sale.paymentMethod, sale.roomNumber, sale.guestName, sale.createdByName, sale.receivedByName, sale.voidReason].some((value) => String(value || '').toLowerCase().includes(q));
    });
  }

  function renderSalesPage(host) {
    if (!host._posReportData) return;
    const sales = filteredSales(host);
    const pages = Math.max(1, Math.ceil(sales.length / PAGE_SIZE));
    host._posPage = Math.min(Math.max(1, Number(host._posPage || 1)), pages);
    const start = (host._posPage - 1) * PAGE_SIZE;
    const subset = sales.slice(start, start + PAGE_SIZE);
    const list = host.querySelector('[data-pos-sales-list]');
    if (list) list.innerHTML = subset.map((sale) => saleCard(sale, host._posReportData.canViewCost === true)).join('') || '<div class="empty">Tidak ada transaksi yang cocok dengan filter.</div>';
    const pageLabel = host.querySelector('[data-pos-page-label]');
    if (pageLabel) pageLabel.textContent = `Menampilkan ${sales.length ? start + 1 : 0}–${Math.min(start + PAGE_SIZE, sales.length)} dari ${sales.length} transaksi · Halaman ${host._posPage}/${pages}`;
    const prev = host.querySelector('[data-pos-prev]');
    const next = host.querySelector('[data-pos-next]');
    if (prev) prev.disabled = host._posPage <= 1;
    if (next) next.disabled = host._posPage >= pages;
  }

  function renderReport(host, data) {
    const s = data.summary || {};
    const canViewCost = data.canViewCost === true;
    host._posReportData = data;
    host._posPage = 1;
    host._posSearch = '';
    host._posStatus = 'all';
    host.querySelector('[data-pos-report-body]').innerHTML = `
      <div class="pos-report-meta">Periode <strong>${escapeHtml(data.from)}</strong> s/d <strong>${escapeHtml(data.to)}</strong> · Role: <strong>${escapeHtml(data.role)}</strong> · ${escapeHtml(RELEASE)}</div>
      ${data.meta?.complete === false ? `<div class="error"><strong>ARSIP POS BELUM LENGKAP.</strong> Dataset ${escapeHtml((data.meta.truncatedDatasets || []).join(', '))} melewati batas aman ekspor. Perkecil rentang tanggal; CSV dan cetak final dikunci agar tidak menghasilkan arsip terpotong.</div>` : ''}
      <div class="pos-report-kpis">
        <div><span>Posted</span><strong>${Number(s.postedCount || 0)}</strong></div><div><span>Void</span><strong>${Number(s.voidCount || 0)}</strong></div>
        <div><span>Subtotal</span><strong>${money(s.subtotal)}</strong></div><div><span>Diskon</span><strong>${money(s.discount)}</strong></div>
        <div><span>Pajak</span><strong>${money(s.tax)}</strong></div><div><span>Total</span><strong>${money(s.gross)}</strong></div>
        <div><span>Penerimaan Langsung</span><strong>${money(s.directSale)}</strong></div><div><span>Tagihan Folio</span><strong>${money(s.roomCharge)}</strong></div>
        <div><span>Delivery Terbuka</span><strong>${Number(s.openDelivery || 0)}</strong></div>
        ${canViewCost ? `<div><span>HPP Non-Kas</span><strong>${money(s.cost)}</strong></div><div><span>Laba Kotor</span><strong>${money(s.grossProfit)}</strong></div>` : ''}
      </div>
      <section class="sales-section"><div class="sales-head"><div><h4>Register Penjualan dan Arsip Minibar</h4><p>Sepuluh nota per halaman. Gunakan pencarian atau filter status agar data tetap mudah dibaca.</p></div><div class="sales-filter"><input type="search" data-pos-search placeholder="Cari nota, tamu, kamar, petugas…"><select data-pos-status><option value="all">Semua status</option><option value="posted">Posted</option><option value="void">Void</option><option value="voided">Voided</option></select></div></div><div data-pos-sales-list class="sales-list"></div><div class="pager"><button type="button" class="secondary" data-pos-prev>← Sebelumnya</button><span data-pos-page-label></span><button type="button" class="secondary" data-pos-next>Berikutnya →</button></div></section>
      ${accountingFlowHtml()}
      <details class="summary-details"><summary>Ringkasan metode pembayaran dan petugas</summary><div class="pos-report-grid">
        <section><h4>Ringkasan Metode</h4><div class="table-wrap"><table><thead><tr><th>Metode</th><th>Nota</th><th>Total</th><th>Diskon</th><th>Pajak</th></tr></thead><tbody>${paymentRowsHtml(data) || '<tr><td colspan="5">Tidak ada data.</td></tr>'}</tbody></table></div></section>
        <section><h4>Ringkasan Petugas</h4><div class="table-wrap"><table><thead><tr><th>Petugas</th><th>Role</th><th>Nota</th><th>Total</th><th>Diskon</th></tr></thead><tbody>${staffRowsHtml(data) || '<tr><td colspan="5">Tidak ada data.</td></tr>'}</tbody></table></div></section>
      </div></details>
      <details class="summary-details"><summary>Ringkasan produk terjual</summary><section><h4>Produk</h4><div class="table-wrap product-table"><table><thead><tr><th>SKU</th><th>Produk</th><th>Qty</th><th>Nilai Awal</th><th>Diskon</th><th>Pajak</th><th>Total</th>${canViewCost ? '<th>HPP</th><th>Laba</th>' : ''}</tr></thead><tbody>${productRowsHtml(data) || `<tr><td colspan="${canViewCost ? 9 : 7}">Tidak ada produk terjual.</td></tr>`}</tbody></table></div></section></details>`;
    host.querySelector('[data-pos-search]').addEventListener('input', (event) => { host._posSearch = event.target.value; host._posPage = 1; renderSalesPage(host); });
    host.querySelector('[data-pos-status]').addEventListener('change', (event) => { host._posStatus = event.target.value; host._posPage = 1; renderSalesPage(host); });
    host.querySelector('[data-pos-prev]').addEventListener('click', () => { host._posPage -= 1; renderSalesPage(host); });
    host.querySelector('[data-pos-next]').addEventListener('click', () => { host._posPage += 1; renderSalesPage(host); });
    renderSalesPage(host);
    host.dataset.loaded = '1';
  }

  function printSummaryCards(data) {
    const s = data.summary || {};
    const cards = [['Posted', Number(s.postedCount || 0)], ['Void', Number(s.voidCount || 0)], ['Subtotal', money(s.subtotal)], ['Diskon', money(s.discount)], ['Pajak', money(s.tax)], ['Total', money(s.gross)], ['Penerimaan Langsung', money(s.directSale)], ['Tagihan Folio', money(s.roomCharge)], ['Delivery Terbuka', Number(s.openDelivery || 0)]];
    if (data.canViewCost === true) cards.push(['HPP Non-Kas', money(s.cost)], ['Laba Kotor', money(s.grossProfit)]);
    return cards.map(([label, value]) => `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join('');
  }

  function printTable(title, headers, rows, emptyColspan, className = '') {
    return `<section class="print-section ${className}"><h2>${escapeHtml(title)}</h2><table><thead><tr>${headers.map((header) => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${rows || `<tr><td colspan="${emptyColspan}">Tidak ada data.</td></tr>`}</tbody></table></section>`;
  }

  function buildPrintDocument(data) {
    const canViewCost = data.canViewCost === true;
    const productRows = productRowsHtml(data);
    const salesRows = (data.sales || []).map((sale) => {
      const voided = ['void', 'voided'].includes(String(sale.status || '').toLowerCase());
      const first = `<tr class="${voided ? 'void-row' : ''}"><td><b>${escapeHtml(sale.receiptNumber || '-')}</b><br><span>${escapeHtml(sale.saleDate || '-')}</span></td><td><b>${escapeHtml(statusLabel(sale.status))}</b><br>${escapeHtml(paymentLabel(sale.paymentMethod))}</td><td><b>Kamar ${escapeHtml(sale.roomNumber || '-')}</b><br>${escapeHtml(sale.guestName || '-')}</td><td class="num">${money(sale.subtotalAmount)}</td><td class="num">${money(sale.discountAmount)}<br><span>Pajak ${money(sale.taxAmount)}</span></td><td class="num total">${money(sale.grossAmount)}</td>${canViewCost ? `<td class="num">${money(sale.costAmount)}<br><span>Laba ${money(sale.grossProfit)}</span></td>` : ''}<td>${escapeHtml(sale.createdByName || '-')}<br><span>${escapeHtml(deliveryLabel(sale.deliveryStatus))}${sale.receivedByName ? ` · ${escapeHtml(sale.receivedByName)}` : ''}</span></td></tr>`;
      const voidLine = voided ? `<tr class="void-note"><td colspan="${canViewCost ? 8 : 7}"><b>Alasan void:</b> ${escapeHtml(sale.voidReason || '-')}</td></tr>` : '';
      return first + voidLine;
    }).join('');
    const productHeaders = ['SKU', 'Produk', 'Qty', 'Nilai Awal', 'Diskon', 'Pajak', 'Total'];
    if (canViewCost) productHeaders.push('HPP', 'Laba Kotor');
    const salesHeaders = ['Nota / Tanggal', 'Status / Metode', 'Kamar / Tamu', 'Subtotal', 'Diskon / Pajak', 'Total'];
    if (canViewCost) salesHeaders.push('HPP / Laba');
    salesHeaders.push('Petugas / Delivery');
    return `<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Arsip_POS_Minibar_${escapeHtml(data.from)}_sd_${escapeHtml(data.to)}</title><style>
      @page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}html,body{margin:0;background:#fff;color:#172033;font-family:Arial,Helvetica,sans-serif;font-size:10pt;line-height:1.35;-webkit-print-color-adjust:exact;print-color-adjust:exact}body{padding:0}.report-header{border-bottom:2px solid #0f766e;padding-bottom:4mm;margin-bottom:4mm;display:flex;justify-content:space-between;gap:8mm;align-items:flex-end}.report-header h1{font-size:18pt;margin:0;color:#0f5132}.report-header p{margin:1mm 0 0;color:#475569}.meta{text-align:right;font-size:8.5pt;color:#475569}.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:2.5mm;margin-bottom:4mm}.kpis>div{border:1px solid #cbd5e1;border-radius:2mm;padding:2.5mm;background:#f8fafc}.kpis span{display:block;font-size:7.5pt;text-transform:uppercase;color:#64748b}.kpis strong{display:block;margin-top:1mm;font-size:10pt}.flow{border:1px solid #99f6e4;border-radius:2mm;padding:3mm;background:#f0fdfa;margin-bottom:4mm}.flow h2{font-size:11pt;margin:0 0 2mm;color:#115e59}.flow-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:2mm 5mm}.flow-grid b{font-size:8.5pt;color:#134e4a}.flow-grid span{font-size:8pt;color:#334155}.summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:4mm}.print-section{margin-bottom:5mm}.print-section h2{font-size:11pt;color:#0f5132;margin:0 0 2mm;border-bottom:1px solid #94a3b8;padding-bottom:1mm}table{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}th,td{border:1px solid #cbd5e1;padding:1.7mm 1.8mm;vertical-align:top;overflow-wrap:anywhere}th{background:#e2e8f0;font-size:8pt;text-transform:uppercase;text-align:left}td{font-size:8.5pt}.num{text-align:right;font-variant-numeric:tabular-nums}.total{font-weight:800;color:#0f5132}td span{color:#64748b;font-size:7.5pt}.product-table td,.product-table th{font-size:7.8pt}.register{break-before:page;page-break-before:always}.void-row td{background:#fff1f2}.void-note td{background:#fff1f2;color:#9f1239;padding-top:1mm;padding-bottom:1mm}.screen-toolbar{display:none}@media screen{body{padding:8mm;max-width:1250px;margin:auto;box-shadow:0 0 30px #0002}.screen-toolbar{display:flex;position:sticky;top:0;z-index:5;justify-content:flex-end;gap:2mm;background:#fff;padding:2mm;border:1px solid #cbd5e1;border-radius:2mm;margin-bottom:4mm}.screen-toolbar button{border:0;border-radius:1.5mm;padding:2mm 4mm;font-weight:700;background:#0f766e;color:#fff;cursor:pointer}.screen-toolbar .secondary{background:#475569}}@media print{.screen-toolbar{display:none!important}}
    </style></head><body><div class="screen-toolbar"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button><button type="button" class="secondary" onclick="window.close()">Tutup</button></div><header class="report-header"><div><h1>Arsip POS / Minibar</h1><p>Register penjualan, pembayaran, charge kamar, diskon, pajak, delivery, void, HPP, dan laba kotor.</p></div><div class="meta"><b>Periode:</b> ${escapeHtml(data.from)} s/d ${escapeHtml(data.to)}<br><b>Role:</b> ${escapeHtml(data.role || '-')}<br><b>Dibuat:</b> ${escapeHtml(dateTimeLabel())}</div></header><section class="kpis">${printSummaryCards(data)}</section><section class="flow"><h2>Arah Arus Kas dan Pengakuan Keuangan</h2><div class="flow-grid"><div><b>Penjualan langsung:</b> <span>langsung masuk kas/bank/QRIS/kartu.</span></div><div><b>Charge kamar:</b> <span>masuk folio; belum kas sampai dibayar.</span></div><div><b>Diskon dan pajak:</b> <span>diskon mengurangi penjualan; pajak adalah kewajiban.</span></div><div><b>HPP:</b> <span>mengurangi laba dan stok, bukan kas shift saat penjualan.</span></div><div><b>Void:</b> <span>membalik transaksi dan memulihkan stok/HPP.</span></div><div><b>Rekonsiliasi:</b> <span>cek saldo resmi di Laporan Keuangan/Log Kas.</span></div></div></section><div class="summary-grid">${printTable('Ringkasan Metode', ['Metode','Nota','Total','Diskon','Pajak'], paymentRowsHtml(data), 5)}${printTable('Ringkasan Petugas', ['Petugas','Role','Nota','Total','Diskon'], staffRowsHtml(data), 5)}</div>${printTable('Ringkasan Produk', productHeaders, productRows, productHeaders.length, 'product-table')}<section class="print-section register"><h2>Register Penjualan dan Arsip Minibar</h2><table><thead><tr>${salesHeaders.map((h) => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead><tbody>${salesRows || `<tr><td colspan="${salesHeaders.length}">Tidak ada transaksi POS/minibar pada periode ini.</td></tr>`}</tbody></table></section></body></html>`;
  }

  function openPrintPreview(data) {
    const html = buildPrintDocument(data);
    const preview = window.open('', '_blank');
    if (preview) {
      try { preview.opener = null; } catch (_) {}
      preview.document.open(); preview.document.write(html); preview.document.close(); preview.focus(); return;
    }
    const frame = document.createElement('iframe');
    frame.setAttribute('title', 'Pratinjau cetak Arsip POS / Minibar');
    frame.style.cssText = 'position:fixed;width:1px;height:1px;opacity:0;pointer-events:none;right:0;bottom:0';
    frame.onload = () => { try { frame.contentWindow.focus(); frame.contentWindow.print(); } finally { setTimeout(() => frame.remove(), 1500); } };
    document.body.appendChild(frame);
    const doc = frame.contentDocument; doc.open(); doc.write(html); doc.close();
  }

  function styles() {
    if (document.getElementById('tamasya-pos-report-addon-style')) return;
    const style = document.createElement('style');
    style.id = 'tamasya-pos-report-addon-style';
    style.textContent = `
      #tamasya-pos-report-archive-addon{box-sizing:border-box;width:100%;max-width:100%;overflow:hidden;margin-top:1.5rem;padding:1rem;border:1px solid rgba(16,185,129,.28);border-radius:1rem;background:rgba(15,23,42,.78);color:#e2e8f0}
      #tamasya-pos-report-archive-addon *{box-sizing:border-box}#tamasya-pos-report-archive-addon .head{display:flex;gap:.75rem;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:.75rem}#tamasya-pos-report-archive-addon h3{font-size:1rem;font-weight:800;color:#6ee7b7;margin:0}#tamasya-pos-report-archive-addon h4{font-size:.85rem;font-weight:800;color:#cbd5e1;margin:.9rem 0 .45rem}
      #tamasya-pos-report-archive-addon button{border:0;border-radius:.65rem;padding:.55rem .8rem;font-weight:800;cursor:pointer;background:#34d399;color:#052e2b}#tamasya-pos-report-archive-addon button.secondary{background:#334155;color:#e2e8f0}#tamasya-pos-report-archive-addon button:disabled{opacity:.45;cursor:not-allowed}#tamasya-pos-report-archive-addon .actions{display:flex;gap:.5rem;flex-wrap:wrap}
      #tamasya-pos-report-archive-addon .pos-report-meta{font-size:.73rem;color:#94a3b8;margin:.5rem 0}#tamasya-pos-report-archive-addon .pos-report-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.55rem}#tamasya-pos-report-archive-addon .pos-report-kpis>div{padding:.7rem;border-radius:.75rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.07)}#tamasya-pos-report-archive-addon .pos-report-kpis span{display:block;font-size:.64rem;color:#94a3b8;text-transform:uppercase}#tamasya-pos-report-archive-addon .pos-report-kpis strong{display:block;margin-top:.25rem;font-size:.88rem;color:#f8fafc}
      #tamasya-pos-report-archive-addon .pos-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:.8rem}#tamasya-pos-report-archive-addon .table-wrap{overflow:auto;border:1px solid rgba(255,255,255,.08);border-radius:.75rem}#tamasya-pos-report-archive-addon table{width:100%;border-collapse:collapse;font-size:.72rem}#tamasya-pos-report-archive-addon th,#tamasya-pos-report-archive-addon td{padding:.5rem .6rem;border-bottom:1px solid rgba(255,255,255,.06);text-align:left;vertical-align:top}#tamasya-pos-report-archive-addon th{position:sticky;top:0;background:#0f172a;color:#94a3b8;z-index:1;white-space:nowrap}#tamasya-pos-report-archive-addon td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}#tamasya-pos-report-archive-addon td.wrap{min-width:180px}
      #tamasya-pos-report-archive-addon .pos-accounting-flow{margin:.85rem 0;padding:.8rem;border:1px solid rgba(45,212,191,.25);border-radius:.8rem;background:rgba(13,148,136,.07)}#tamasya-pos-report-archive-addon .pos-accounting-flow summary{cursor:pointer;font-size:.82rem;font-weight:800;color:#99f6e4}#tamasya-pos-report-archive-addon .flow-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.55rem;margin-top:.7rem}#tamasya-pos-report-archive-addon .flow-grid>div{padding:.65rem;border-left:2px solid #2dd4bf;background:rgba(15,23,42,.42);border-radius:.4rem}#tamasya-pos-report-archive-addon .flow-grid strong{display:block;font-size:.72rem;color:#ccfbf1}#tamasya-pos-report-archive-addon .flow-grid span{display:block;margin-top:.25rem;font-size:.68rem;line-height:1.45;color:#a8b3c7}
      #tamasya-pos-report-archive-addon .sales-section{margin-top:1rem}#tamasya-pos-report-archive-addon .sales-head{display:flex;gap:.75rem;align-items:flex-end;justify-content:space-between;flex-wrap:wrap}#tamasya-pos-report-archive-addon .sales-head h4{margin-bottom:.2rem}#tamasya-pos-report-archive-addon .sales-head p{font-size:.7rem;color:#94a3b8;margin:0}#tamasya-pos-report-archive-addon .sales-filter{display:flex;gap:.45rem;flex-wrap:wrap}#tamasya-pos-report-archive-addon .sales-filter input,#tamasya-pos-report-archive-addon .sales-filter select{background:#0f172a;color:#e2e8f0;border:1px solid rgba(255,255,255,.13);border-radius:.65rem;padding:.55rem .65rem;font-size:.75rem;min-width:180px}
      #tamasya-pos-report-archive-addon .sales-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem;margin-top:.7rem}#tamasya-pos-report-archive-addon .sale-card{border:1px solid rgba(148,163,184,.22);border-radius:.85rem;background:rgba(15,23,42,.62);overflow:hidden}#tamasya-pos-report-archive-addon .sale-card.voided{border-color:rgba(251,113,133,.45);background:rgba(136,19,55,.16)}#tamasya-pos-report-archive-addon .sale-card header{display:flex;justify-content:space-between;gap:.5rem;padding:.65rem .7rem;border-bottom:1px solid rgba(255,255,255,.07)}#tamasya-pos-report-archive-addon .sale-card header strong{display:block;color:#f8fafc;font-size:.86rem}#tamasya-pos-report-archive-addon .sale-card header span{font-size:.65rem;color:#94a3b8}#tamasya-pos-report-archive-addon .sale-card .status{font-weight:900;color:#6ee7b7}#tamasya-pos-report-archive-addon .sale-card.voided .status{color:#fda4af}
      #tamasya-pos-report-archive-addon .sale-context,#tamasya-pos-report-archive-addon .sale-money{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.5rem;padding:.65rem .7rem}#tamasya-pos-report-archive-addon .sale-context{border-bottom:1px solid rgba(255,255,255,.06)}#tamasya-pos-report-archive-addon .sale-context span,#tamasya-pos-report-archive-addon .sale-money span{font-size:.78rem;color:#e2e8f0;overflow-wrap:anywhere}#tamasya-pos-report-archive-addon .sale-context b,#tamasya-pos-report-archive-addon .sale-money b{display:block;font-size:.63rem;text-transform:uppercase;color:#94a3b8;margin-bottom:.12rem}#tamasya-pos-report-archive-addon .sale-money .total{color:#6ee7b7;font-weight:900}#tamasya-pos-report-archive-addon .sale-card footer{display:flex;flex-wrap:wrap;gap:.35rem .8rem;padding:.55rem .7rem;background:rgba(255,255,255,.025);font-size:.72rem;color:#94a3b8}#tamasya-pos-report-archive-addon .void-reason{flex-basis:100%;color:#fda4af}
      #tamasya-pos-report-archive-addon .summary-details{margin-top:.85rem;border:1px solid rgba(148,163,184,.18);border-radius:.8rem;background:rgba(15,23,42,.38);overflow:hidden}#tamasya-pos-report-archive-addon .summary-details>summary{cursor:pointer;padding:.75rem .85rem;font-size:.8rem;font-weight:800;color:#cbd5e1;background:rgba(255,255,255,.035)}#tamasya-pos-report-archive-addon .summary-details[open]>summary{border-bottom:1px solid rgba(255,255,255,.07);color:#6ee7b7}#tamasya-pos-report-archive-addon .summary-details>div,#tamasya-pos-report-archive-addon .summary-details>section{padding:.75rem}#tamasya-pos-report-archive-addon .pager{display:flex;align-items:center;justify-content:center;gap:.7rem;margin-top:.8rem;flex-wrap:wrap}#tamasya-pos-report-archive-addon .pager span{font-size:.7rem;color:#94a3b8}#tamasya-pos-report-archive-addon .empty,#tamasya-pos-report-archive-addon .error{padding:.85rem;border-radius:.75rem;background:rgba(255,255,255,.04);color:#cbd5e1}#tamasya-pos-report-archive-addon .error{background:rgba(244,63,94,.12);color:#fecdd3;border:1px solid rgba(244,63,94,.3)}
      @media(max-width:900px){#tamasya-pos-report-archive-addon .sales-list{grid-template-columns:1fr}}@media(max-width:640px){#tamasya-pos-report-archive-addon{padding:.7rem}#tamasya-pos-report-archive-addon .actions{width:100%}#tamasya-pos-report-archive-addon .actions button{flex:1}#tamasya-pos-report-archive-addon .pos-report-grid,#tamasya-pos-report-archive-addon .sales-list{grid-template-columns:1fr}#tamasya-pos-report-archive-addon .sales-filter{width:100%}#tamasya-pos-report-archive-addon .sales-filter input,#tamasya-pos-report-archive-addon .sales-filter select{flex:1;min-width:0;width:100%}#tamasya-pos-report-archive-addon .sale-context,#tamasya-pos-report-archive-addon .sale-money{grid-template-columns:repeat(2,minmax(0,1fr));padding:.6rem}#tamasya-pos-report-archive-addon .pos-report-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}#tamasya-pos-report-archive-addon .table-wrap{max-width:100%}}
      @media print{#tamasya-pos-report-archive-addon{display:none!important}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    const role = String(sessionStorage.getItem('hotel_role') || '').toLowerCase();
    const root = reportRoot();
    if (!root || !ALLOWED_ROLES.has(role)) return;
    styles();
    let host = document.getElementById('tamasya-pos-report-archive-addon');
    if (host) return;
    host = document.createElement('section');
    host.id = 'tamasya-pos-report-archive-addon';
    host.innerHTML = `<div class="head"><div><h3>Arsip POS / Minibar</h3><div class="pos-report-meta">Tampilan responsif untuk nota, diskon, pajak, charge kamar, delivery, void, petugas, dan HPP sesuai hak akses.</div></div><div class="actions"><button type="button" data-pos-report-load>Muat / Segarkan</button><button type="button" class="secondary" data-pos-report-csv disabled>Unduh CSV</button><button type="button" class="secondary" data-pos-report-print disabled>Cetak / Simpan PDF</button></div></div><div data-pos-report-body><div class="pos-report-meta">Tekan Muat / Segarkan untuk mengambil arsip sesuai periode laporan di atas.</div></div>`;
    root.appendChild(host);
    host.querySelector('[data-pos-report-load]').addEventListener('click', async () => {
      const button = host.querySelector('[data-pos-report-load]');
      const body = host.querySelector('[data-pos-report-body]');
      const { from, to } = reportDates(root);
      button.disabled = true;
      body.innerHTML = '<div class="pos-report-meta">Memuat arsip POS/minibar…</div>';
      try {
        const data = await fetchReport(from, to);
        renderReport(host, data);
        const complete = data.meta?.complete !== false;
        host.querySelector('[data-pos-report-csv]').disabled = !complete;
        host.querySelector('[data-pos-report-print]').disabled = !complete;
      } catch (error) {
        body.innerHTML = `<div class="error">${escapeHtml(error?.message || 'Gagal memuat arsip POS/minibar.')}</div>`;
      } finally { button.disabled = false; }
    });
    host.querySelector('[data-pos-report-csv]').addEventListener('click', () => {
      if (!host._posReportData || host._posReportData.meta?.complete === false) return;
      downloadCsv(host._posReportData);
    });
    host.querySelector('[data-pos-report-print]').addEventListener('click', () => {
      if (!host._posReportData || host._posReportData.meta?.complete === false) return;
      openPrintPreview(host._posReportData);
    });
  }

  window.TamasyaPosArchivePrint = Object.freeze({ buildPrintDocument });

  let mountFrame = 0;
  const scheduleMount = () => {
    if (mountFrame) return;
    mountFrame = requestAnimationFrame(() => { mountFrame = 0; mount(); });
  };
  const observer = new MutationObserver((records) => {
    for (const record of records) {
      if (record.type !== 'childList') continue;
      for (const node of record.addedNodes || []) {
        if (!node || node.nodeType !== 1) continue;
        if (node.id === 'financial-report-view' || node.querySelector?.('#financial-report-view')) { scheduleMount(); return; }
      }
    }
  });
  observer.observe(document.getElementById('root') || document.body, { childList: true, subtree: true });
  window.addEventListener('tamasya-route-change', (event) => { if (event?.detail?.route === 'report') scheduleMount(); });
  window.addEventListener('DOMContentLoaded', scheduleMount);
  window.addEventListener('popstate', scheduleMount);
})();
