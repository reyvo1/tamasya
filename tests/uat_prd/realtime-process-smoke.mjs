import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const upstreamPort=38571,realtimePort=38572,secret='r'.repeat(48),origin='https://hq.invalid';
let revision='11',down=false,passed=0;
const check=(ok,name,detail='')=>{if(!ok)throw new Error(`FAIL ${name} ${detail}`);passed++;console.log(`PASS ${name}`)};
const upstream=http.createServer((req,res)=>{
  if(down){res.writeHead(503,{'Content-Type':'application/json'});res.end('{}');return;}
  check(req.headers.authorization==='Bearer read-token','realtime process forwards private read token');
  res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify({success:true,data:{contractVersion:'tamasya-realtime-v1',companyId:'group-a',revisions:{'hotel-a':revision,'hotel-private':'999'}}}));
});
await new Promise((resolve,reject)=>upstream.listen(upstreamPort,'127.0.0.1',e=>e?reject(e):resolve()));
const dir=fs.mkdtempSync(path.join(os.tmpdir(),'tamasya-realtime-r2-'));const cfg=path.join(dir,'config.json');
fs.writeFileSync(cfg,JSON.stringify({host:'127.0.0.1',port:realtimePort,pollMs:500,maxClients:4,allowedOrigins:[origin],localTest:true,tenants:{'group-a':{enabled:true,secret,propertyIds:['hotel-a','hotel-private'],readToken:'read-token',apiUrl:`http://127.0.0.1:${upstreamPort}/api.php`}}}));
const child=spawn(process.execPath,[path.join(root,'services/realtime/server.mjs')],{env:{...process.env,TAMASYA_REALTIME_CONFIG_FILE:cfg},stdio:['ignore','pipe','pipe']});
let err='';child.stderr.on('data',b=>err+=b);
const wait=ms=>new Promise(r=>setTimeout(r,ms));
try{
  let health;
  for(let i=0;i<40;i++){try{health=await fetch(`http://127.0.0.1:${realtimePort}/healthz`);if(health.ok)break;}catch{}await wait(100)}
  check(health?.ok===true,'realtime CLI entrypoint serves healthz',err);
  const claims={v:1,aud:'tamasya-realtime',companyId:'group-a',propertyIds:['hotel-a'],exp:Math.floor(Date.now()/1000)+45,jti:'r2'};
  const payload=Buffer.from(JSON.stringify(claims)).toString('base64url');const ticket=payload+'.'+crypto.createHmac('sha256',secret).update(payload).digest('base64url');
  const collect=()=>new Promise((resolve,reject)=>{
    const req=http.get({host:'127.0.0.1',port:realtimePort,path:'/events',headers:{Authorization:`Bearer ${ticket}`,Origin:origin}},res=>{
      let data='';res.on('data',chunk=>{data+=chunk;if(data.includes('event: revision')||data.includes('event: unavailable')){req.destroy();resolve({status:res.statusCode,data});}});res.on('end',()=>resolve({status:res.statusCode,data}));
    });req.on('error',e=>{if(e.code==='ECONNRESET')return;reject(e)});setTimeout(()=>{req.destroy();resolve({status:0,data:''})},3500).unref();
  });
  const first=await collect();
  check(first.status===200&&first.data.includes('event: revision'),'realtime process emits revision SSE',first.data);
  check(first.data.includes('"hotel-a":"11"')&&!first.data.includes('hotel-private'),'SSE payload is reduced to ticket property scope',first.data);
  down=true;const second=await collect();
  check(second.status===200&&second.data.includes('event: unavailable')&&second.data.includes('"retryable":true'),'upstream outage becomes retryable unavailable event',second.data);
  const denied=await fetch(`http://127.0.0.1:${realtimePort}/events`,{headers:{Authorization:`Bearer ${ticket}`,Origin:'https://evil.invalid'}});
  check(denied.status===403,'realtime origin policy fails closed');
  console.log(`REALTIME PROCESS PASSED ${passed}`);
} finally {
  child.kill('SIGTERM');upstream.close();fs.rmSync(dir,{recursive:true,force:true});
}
