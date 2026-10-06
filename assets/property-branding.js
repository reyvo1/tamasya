(function(){
  'use strict';
  const fallback={hotelName:'Hotel',address:'',phone:'',whatsapp:'',email:'',timezone:window.TAMASYA_RUNTIME_CONFIG?.propertyTimezone||Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC'};
  let profile={...fallback};
  try{const cached=JSON.parse(localStorage.getItem('tamasya.property.profile.v137')||'null');if(cached&&typeof cached==='object')profile={...profile,...cached};}catch{}
  window.TamasyaPropertyBranding=profile;
  function apply(){
    const p=window.TamasyaPropertyBranding||profile;
    document.querySelectorAll('[data-property-name]').forEach(el=>{el.textContent=p.hotelName||'Hotel';});
    document.querySelectorAll('[data-property-mark]').forEach(el=>{const words=String(p.hotelName||'Hotel').trim().split(/\s+/).filter(Boolean);el.textContent=(words.slice(0,2).map(x=>x[0]).join('')||'H').toUpperCase();});
    if(document.body && document.body.dataset.propertyTitle==='1') document.title=`${p.hotelName||'Hotel'} — TAMASYA`;
  }
  apply();
  const api=new URL('api.php?action=public-site-config',location.href).toString();
  fetch(api,{cache:'no-store',credentials:'same-origin'}).then(r=>r.ok?r.json():null).then(data=>{
    const c=data&&data.success&&data.config?data.config:null;if(!c)return;
    profile={hotelName:String(c.hotelName||'Hotel'),address:String(c.address||''),phone:String(c.phone||''),whatsapp:String(c.whatsapp||''),email:String(c.email||''),timezone:String(c.propertyTimezone||'UTC')};
    window.TamasyaPropertyBranding=profile;
    try{localStorage.setItem('tamasya.property.profile.v137',JSON.stringify(profile));}catch{}
    apply();window.dispatchEvent(new CustomEvent('tamasya:property-profile',{detail:profile}));
  }).catch(()=>{});
})();
