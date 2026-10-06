export function connectRealtime({ticket,changed,status}) {
  let stopped=false,controller=null,timer=null,lastRevision=null,generation=0;
  async function connect() {
    if(stopped||document.hidden)return;
    clearTimeout(timer);const current=++generation;controller?.abort();
    const abort=new AbortController();controller=abort;let delay=3000;
    try {
      const grant=await ticket();if(current!==generation||stopped)return;
      if(!grant.enabled){status('polling');delay=30000;return;}
      const response=await fetch(grant.url,{headers:{Authorization:`Bearer ${grant.ticket}`},signal:abort.signal,cache:'no-store'});
      if(!response.ok||!response.body)throw Error('Realtime unavailable');status('connected');
      const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='';
      while(!stopped&&current===generation){const part=await reader.read();if(part.done)break;buffer+=decoder.decode(part.value,{stream:true});if(buffer.length>65536)throw Error('Event too large');let boundary;
        while((boundary=buffer.indexOf('\n\n'))>=0){const event=buffer.slice(0,boundary);buffer=buffer.slice(boundary+2);if(event.startsWith('event: revision\n')){const payload=JSON.parse(event.slice(event.indexOf('data: ')+6));const hash=JSON.stringify(payload.revisions);if(lastRevision!==null&&hash!==lastRevision)changed();lastRevision=hash;}else if(event.startsWith('event: unavailable'))status('polling');}
      }
    }catch(error){if(error.name!=='AbortError'&&current===generation){status('polling');changed();}}
    finally{abort.abort();if(current===generation&&!stopped&&!document.hidden)timer=setTimeout(()=>{changed();connect();},delay);}
  }
  function visibility(){generation++;clearTimeout(timer);controller?.abort();if(!document.hidden){changed();connect();}}
  document.addEventListener('visibilitychange',visibility);connect();
  return ()=>{stopped=true;generation++;clearTimeout(timer);controller?.abort();document.removeEventListener('visibilitychange',visibility);};
}
