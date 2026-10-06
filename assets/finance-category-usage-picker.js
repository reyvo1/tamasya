(()=>{'use strict';
const ROLE_OPTIONS={
  income:[
    {key:'room_rental',label:'Pendapatan kamar',detail:'Dipakai otomatis oleh Booking, Check-in, Checkout, dan pembayaran kamar.'},
    {key:'extra_service',label:'Layanan tambahan tamu',detail:'Dipakai untuk add-on/layanan tambahan yang berasal dari Booking.'},
    {key:'pos_revenue',label:'Penjualan POS / Minibar',detail:'Dipakai untuk pendapatan penjualan langsung dari POS atau Minibar.'},
    {key:'pos_cogs_reversal',label:'Pembalik biaya barang POS',detail:'Dipakai otomatis saat HPP POS dibalik karena retur atau void.'}
  ],
  expense:[
    {key:'payroll_expense',label:'Penggajian',detail:'Dipakai oleh menu Payroll supaya pembayaran gaji tidak diposting ganda.'},
    {key:'inventory_expense',label:'Beban operasional non-stok',detail:'Untuk pembelian/beban yang memang bukan persediaan stok.'},
    {key:'maintenance_expense',label:'Pemeliharaan aset',detail:'Dipakai oleh workflow maintenance/pemeliharaan.'},
    {key:'pbjt_settlement',label:'Pembayaran / penyelesaian PBJT',detail:'Dipakai saat membayar utang PBJT; bukan beban baru.'},
    {key:'pos_refund',label:'Retur POS',detail:'Dipakai saat penjualan POS dibatalkan atau dikembalikan.'},
    {key:'pos_cogs',label:'Biaya barang terjual POS (HPP)',detail:'Dipakai untuk biaya persediaan yang terjual melalui POS.'}
  ]
};
function esc(v){return String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]))}
function close(el){try{el.remove()}catch(_){}}
function choose({categoryName='',type='income',currentSystemKey=''}={}){
  const options=ROLE_OPTIONS[String(type).toLowerCase()]||[];
  return new Promise(resolve=>{
    const overlay=document.createElement('div');
    overlay.setAttribute('role','dialog');overlay.setAttribute('aria-modal','true');overlay.setAttribute('aria-label','Pilih pemakaian otomatis kategori');
    overlay.style.cssText='position:fixed;inset:0;z-index:100000;background:rgba(2,6,23,.82);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;padding:18px';
    const card=document.createElement('div');
    card.style.cssText='width:min(560px,100%);max-height:88vh;overflow:auto;background:#0f172a;border:1px solid rgba(148,163,184,.22);border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.45);padding:22px;color:#e2e8f0;font-family:inherit';
    card.innerHTML=`
      <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:14px">
        <div><div style="font-size:17px;font-weight:800">Kategori ini dipakai otomatis untuk apa?</div>
        <div style="font-size:12px;color:#94a3b8;margin-top:4px">Kategori: <b style="color:#e2e8f0">${esc(categoryName)}</b></div></div>
        <button type="button" data-cancel style="border:0;background:rgba(255,255,255,.06);color:#cbd5e1;border-radius:10px;padding:6px 10px;cursor:pointer">✕</button>
      </div>
      <div style="font-size:12px;line-height:1.55;color:#cbd5e1;background:rgba(14,165,233,.08);border:1px solid rgba(14,165,233,.18);padding:11px 12px;border-radius:12px;margin-bottom:14px">
        Nama kategori tetap bebas dan bisa diubah. Pilihan ini hanya menentukan <b>modul yang boleh memposting transaksi otomatis</b> ke kategori tersebut. Kode internal disembunyikan.
      </div>
      <div data-options style="display:grid;gap:9px"></div>
      <div style="display:flex;justify-content:flex-end;margin-top:16px"><button type="button" data-cancel style="border:1px solid rgba(148,163,184,.22);background:rgba(255,255,255,.04);color:#cbd5e1;border-radius:12px;padding:9px 14px;font-weight:700;cursor:pointer">Batal</button></div>`;
    const list=card.querySelector('[data-options]');
    options.forEach(opt=>{
      const b=document.createElement('button');b.type='button';
      const selected=String(currentSystemKey||'')===opt.key;
      b.style.cssText=`text-align:left;border:1px solid ${selected?'rgba(52,211,153,.55)':'rgba(148,163,184,.16)'};background:${selected?'rgba(16,185,129,.12)':'rgba(255,255,255,.035)'};color:#e2e8f0;border-radius:14px;padding:12px 13px;cursor:pointer;transition:.15s`;
      b.innerHTML=`<div style="font-size:13px;font-weight:800">${esc(opt.label)}${selected?' <span style="font-size:10px;color:#6ee7b7">• saat ini</span>':''}</div><div style="font-size:11px;color:#94a3b8;line-height:1.45;margin-top:3px">${esc(opt.detail)}</div>`;
      b.onmouseenter=()=>{b.style.background=selected?'rgba(16,185,129,.16)':'rgba(59,130,246,.10)'};
      b.onmouseleave=()=>{b.style.background=selected?'rgba(16,185,129,.12)':'rgba(255,255,255,.035)'};
      b.onclick=()=>{close(overlay);resolve(opt.key)};list.appendChild(b);
    });
    card.querySelectorAll('[data-cancel]').forEach(b=>b.addEventListener('click',()=>{close(overlay);resolve(null)}));
    overlay.addEventListener('click',e=>{if(e.target===overlay){close(overlay);resolve(null)}});
    const onKey=e=>{if(e.key==='Escape'){document.removeEventListener('keydown',onKey);close(overlay);resolve(null)}};document.addEventListener('keydown',onKey,{once:true});
    overlay.appendChild(card);document.body.appendChild(overlay);
  });
}
window.TamasyaFinanceCategoryUsagePicker={choose,roles:ROLE_OPTIONS};
})();
