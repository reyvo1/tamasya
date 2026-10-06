(function(){
  'use strict';
  const VERSION='V137-WEBSITE-CMS';

  const textOf=(el)=>String(el?.textContent||'').replace(/\s+/g,' ').trim();
  const isCmsHeading=(el)=>['Website Content Manager','Pengelola Konten Website'].includes(textOf(el));

  function findCmsRoot(){
    const heading=[...document.querySelectorAll('h1,h2,h3')].find(isCmsHeading);
    if(!heading)return null;
    let node=heading;
    for(let depth=0;node&&depth<8;depth+=1,node=node.parentElement){
      const hasSave=node.querySelector?.('button') && [...node.querySelectorAll('button')].some(btn=>/Simpan\s*&\s*Terbitkan/i.test(textOf(btn)));
      const hasHotelField=node.querySelector?.('label input, label textarea');
      if(hasSave&&hasHotelField)return node;
    }
    return heading.parentElement;
  }

  function findHotelNameField(root){
    if(!root)return null;
    const labels=[...root.querySelectorAll('label')];
    const label=labels.find(el=>/^(Nama hotel|Hotel Name)/i.test(textOf(el)));
    const input=label?.querySelector('input,textarea')||null;
    return input?{label,input}:null;
  }

  function clearFieldError(field){
    if(!field)return;
    field.input.classList.remove('cms-core-invalid');
    field.input.removeAttribute('aria-invalid');
    field.label.querySelector('.cms-core-field-error')?.remove();
  }

  function markRequired(field){
    if(!field)return;
    field.input.required=true;
    field.input.setAttribute('aria-required','true');
    let badge=field.label.querySelector('.cms-core-required');
    if(!badge){
      const caption=field.label.querySelector('span.font-semibold')||field.label.querySelector('span');
      if(caption){
        badge=document.createElement('small');
        badge.className='cms-core-required';
        badge.textContent='Wajib';
        caption.insertAdjacentElement('afterend',badge);
      }
    }
    if(!field.input.dataset.cmsGuardBound){
      field.input.dataset.cmsGuardBound='true';
      field.input.addEventListener('input',()=>{if(String(field.input.value||'').trim())clearFieldError(field);});
    }
  }

  function showRequiredError(field){
    if(!field)return;
    field.input.classList.add('cms-core-invalid');
    field.input.setAttribute('aria-invalid','true');
    let error=field.label.querySelector('.cms-core-field-error');
    if(!error){
      error=document.createElement('span');
      error.className='cms-core-field-error';
      field.label.appendChild(error);
    }
    error.textContent='Nama hotel wajib diisi sebelum website diterbitkan.';
    field.input.focus({preventScroll:true});
    field.input.scrollIntoView({behavior:'smooth',block:'center'});
  }

  function enhance(){
    const root=findCmsRoot();
    if(!root)return;
    root.classList.add('cms-core-professional');
    const field=findHotelNameField(root);
    markRequired(field);
  }

  document.addEventListener('click',(event)=>{
    const button=event.target.closest?.('button');
    if(!button)return;
    const label=textOf(button);
    if(/Simpan\s*&\s*Terbitkan/i.test(label)){
      const root=findCmsRoot();
      if(!root||!root.contains(button))return;
      const field=findHotelNameField(root);
      if(field&&!String(field.input.value||'').trim()){
        event.preventDefault();
        event.stopPropagation();
        if(typeof event.stopImmediatePropagation==='function')event.stopImmediatePropagation();
        showRequiredError(field);
        return;
      }
    }
    if(button.closest?.('.nav-scroll')||button.id==='tab-website'){
      setTimeout(enhance,40);setTimeout(enhance,180);
    }
  },true);

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{setTimeout(enhance,0);setTimeout(enhance,350);},{once:true});
  else {setTimeout(enhance,0);setTimeout(enhance,350);}

  window.TAMASYA_WEBSITE_CMS_GUARD=Object.freeze({version:VERSION,enhance});
})();
