/* TAMASYA Flexible Maintenance R2
 * Portable opportunistic trigger for shared hosting, VPS, desktop/local, and
 * Primary/Standby deployments. Cron is optional; the server decides if a scan
 * is due. This addon never changes business data and never blocks the UI.
 */
(()=>{
  'use strict';
  const CLIENT_TICK_MS=5*60*1000;
  const INITIAL_DELAY_MS=45*1000;
  let lastAttempt=0;
  let timer=null;

  function loggedIn(){
    try{return sessionStorage.getItem('hotel_logged_in')==='true';}catch(_){return false;}
  }
  function apiBase(){
    let path=window.location.pathname||'/';
    const routes=['dashboard','rooms','finance','report','telegram','staff','config','db_config','inventory','leaves','attendance','operations','activity','website','support','savings','pos','login'];
    if(path.endsWith('/'))path=path.slice(0,-1);
    for(const route of routes){if(path.endsWith('/'+route)){path=path.slice(0,-route.length-1);break;}}
    if(path.endsWith('/index.html'))path=path.slice(0,-11);
    return path+(path.endsWith('/')?'':'/');
  }
  async function tick(reason='browser-opportunistic'){
    const now=Date.now();
    if(now-lastAttempt<60*1000 || !navigator.onLine || !loggedIn())return;
    if(reason!=='browser-manual-trigger' && document.visibilityState==='hidden')return;
    lastAttempt=now;
    try{
      const ctrl=('AbortController' in window)?new AbortController():null;
      const timeout=ctrl?setTimeout(()=>ctrl.abort(),90*1000):null;
      const response=await fetch(apiBase()+'api/consistency-guard-maintenance',{
        method:'POST',credentials:'same-origin',cache:'no-store',keepalive:true,
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({trigger:String(reason||'browser-opportunistic').slice(0,80)}),
        signal:ctrl?ctrl.signal:undefined
      });
      if(timeout)clearTimeout(timeout);
      if(response.ok){
        const data=await response.json().catch(()=>null);
        window.dispatchEvent(new CustomEvent('tamasya-flex-maintenance-result',{detail:data?.maintenance||null}));
      }
    }catch(_){/* offline/timeout is expected; next online/tick retries safely */}
  }
  function start(){
    if(timer)return;
    setTimeout(()=>tick('browser-startup'),INITIAL_DELAY_MS);
    timer=setInterval(()=>tick('browser-interval'),CLIENT_TICK_MS);
  }
  window.addEventListener('online',()=>setTimeout(()=>tick('browser-online'),5000));
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')setTimeout(()=>tick('browser-visible'),3000);});
  window.addEventListener('load',start,{once:true});
  if(document.readyState==='complete')start();
  window.TamasyaFlexibleMaintenance=Object.freeze({tick:()=>tick('browser-manual-trigger'),clientTickMs:CLIENT_TICK_MS,cronRequired:false});
})();
