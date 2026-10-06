/* Owner observes the Admin workspace. Business writes remain forbidden at the API. */
(function(root){
  'use strict';
  const mutationCallbacks=/^on.*(?:Add|Edit|Delete|Save|Update|Restore|Approve|Reject|Submit|Mark|Reset|Set|Clear|Assign|Process|Import|Create|Void|Pay|Cancel|Lock|CheckIn|CheckOut|Checkout|Checkin|RoomStatus|Booking|Finalize|Move|Transfer)/;
  const writeLabel=/\b(?:simpan|ubah|edit|hapus|buat|tambah|catat|laporkan|jalankan|proses|tandai|mulai|ambil|daftar(?:kan)?|bayar|setor|tarik|setujui|tolak|kirim|unggah|upload|impor|import|restore|pulihkan|arsip(?:kan)?|aktifkan|nonaktifkan|cabut|batalkan|selesaikan|konfirmasi|promosi|promote|redeem|void|checkout|check.?in|check.?out|clock.?in|clock.?out|absen masuk|absen pulang|buka shift|tutup (?:shift|kas)|ambil tugas|mulai tugas|reset|bersihkan)\b/i;
  const writeAttributes=['data-mutation','data-po-status','data-link-expense','data-pr-status','data-sinv-status','data-campaign-status','data-voucher-redeem','data-edit','data-delete','data-archive','data-restore','data-stock','data-void','data-product','data-plus','data-minus','data-post-inv','data-pay-inv','data-camp-approve','data-camp-snapshot','data-camp-send','data-charge-booking','data-pay-tx'];
  const role=()=>{try{if(root.sessionStorage?.getItem('hotel_logged_in')!=='true')return '';return String(root.sessionStorage?.getItem('hotel_staff_role')||root.sessionStorage?.getItem('hotel_role')||'').trim().toLowerCase();}catch(_){return '';}};
  const isOwner=r=>String(r||'').trim().toLowerCase()==='owner';
  function requestAllowed(r,method,url){
    if(!isOwner(r)||['GET','HEAD','OPTIONS'].includes(String(method||'GET').toUpperCase()))return true;
    let action='';try{const u=new URL(String(url),root.location?.href||'https://tamasya.invalid');action=u.searchParams.get('action')||u.pathname.match(/\/api\/([^/]+)\/?$/)?.[1]||'';}catch(_){}
    return ['login','verify-2fa','refresh-session','logout'].includes(action);
  }
  function denied(){const e=new Error('Owner hanya dapat melihat. Perubahan data tidak diizinkan.');e.code='OWNER_READ_ONLY';return e;}
  function componentProps(r,props){
    if(!isOwner(r)||!props)return props;
    let result=props;
    for(const [key,value] of Object.entries(props))if(typeof value==='function'&&mutationCallbacks.test(key)){
      if(result===props)result={...props};result[key]=()=>{throw denied();};
    }
    return result;
  }
  function controlIsMutation(info){
    if(info.navigation||info.readAction)return false;
    return Boolean(info.writeForm||info.explicitMutation||writeLabel.test(info.label||''));
  }
  function describe(el){
    const tag=el.tagName?.toLowerCase();
    return {navigation:Boolean(el.matches?.('[data-tab],[data-operation-key],[data-domain-trigger],[data-domain-member],[data-tamasya-tab],nav button,[id^="tab-"]')||el.closest?.('nav')),
      readAction:el.matches?.('.tamasya-report-actions [data-format],[data-owner-read-action]')||/^(?:Reset|Bersihkan)\s+(?:Filter|Pencarian)\b/i.test((el.textContent||'').trim()),
      writeForm:Boolean(el.closest?.('form:not([data-owner-read-form])')||el.matches?.('[data-owner-readonly-fields] input,[data-owner-readonly-fields] select,[data-owner-readonly-fields] textarea')),
      explicitMutation:el.matches?.('#queue-snapshot,#push-hq,#route-apply,#folio-invoice,#folio-sync-charges,#pr-to-po,#create-grn-now,#post-grn-now')||el.hasAttribute?.('data-owner-mutation')||writeAttributes.some(a=>el.hasAttribute?.(a)),
      label:[el.textContent||'',el.getAttribute?.('aria-label')||'',el.getAttribute?.('title')||'',tag==='input'?el.value||'':''].join(' ')};
  }
  function apply(){
    if(!root.document)return;
    const owner=isOwner(role());if(root.document.documentElement.dataset.tamasyaOwnerReadonly!==(owner?'1':'0'))root.document.documentElement.dataset.tamasyaOwnerReadonly=owner?'1':'0';
    if(!owner){
      root.document.querySelectorAll('[data-owner-disabled="1"]').forEach(el=>{el.disabled=el.dataset.ownerWasDisabled==='1';el.removeAttribute('data-owner-disabled');el.removeAttribute('data-owner-was-disabled');if(el.dataset.ownerAriaDisabled==='1')el.removeAttribute('aria-disabled');el.removeAttribute('data-owner-aria-disabled');});return;
    }
    root.document.querySelectorAll('form:not([data-owner-read-form]) input,form:not([data-owner-read-form]) select,form:not([data-owner-read-form]) textarea,[data-owner-readonly-fields] input,[data-owner-readonly-fields] select,[data-owner-readonly-fields] textarea,button,input[type="submit"],a[data-owner-mutation]').forEach(el=>{
      if(!controlIsMutation(describe(el)))return;
      if(el.dataset.ownerDisabled!=='1'){el.dataset.ownerWasDisabled=el.disabled?'1':'0';el.dataset.ownerDisabled='1';if(!el.hasAttribute('aria-disabled')){el.dataset.ownerAriaDisabled='1';el.setAttribute('aria-disabled','true');}}
      if(!el.disabled)el.disabled=true;
    });
  }
  const policy=Object.freeze({isOwner,requestAllowed,componentProps,controlIsMutation,apply,denied});
  if(typeof module==='object'&&module.exports)module.exports=policy;
  root.TamasyaOwnerReadOnlyPolicy=policy;
  if(!root.document)return;
  // Install before application clients: a denied write never reaches transport or an offline queue.
  if(typeof root.fetch==='function'){
    const fetch=root.fetch.bind(root);
    root.fetch=(input,init)=>{
      const url=typeof input==='string'||input instanceof URL?String(input):input?.url||String(input);
      const method=init?.method||input?.method||'GET';
      if(!requestAllowed(role(),method,url))return Promise.resolve(new Response(JSON.stringify({success:false,code:'OWNER_READ_ONLY',error:denied().message}),{status:403,headers:{'Content-Type':'application/json'}}));
      return fetch(input,init);
    };
  }
  root.document.addEventListener('submit',e=>{if(isOwner(role())&&!e.target.matches('[data-owner-read-form]')){e.preventDefault();e.stopImmediatePropagation();}},true);
  root.document.addEventListener('click',e=>{const el=e.target.closest?.('button,input[type="submit"],a[data-owner-mutation]');if(isOwner(role())&&el&&controlIsMutation(describe(el))){e.preventDefault();e.stopImmediatePropagation();apply();}},true);
  let frame=0;const schedule=()=>{if(frame)return;frame=root.requestAnimationFrame(()=>{frame=0;apply();});};
  new MutationObserver(schedule).observe(root.document.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:['disabled']});
  root.addEventListener('tamasya-auth-changed',schedule);root.addEventListener('storage',schedule);root.document.addEventListener('DOMContentLoaded',apply);apply();
})(typeof window==='object'?window:globalThis);
