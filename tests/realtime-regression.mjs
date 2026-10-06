import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import http from 'node:http';
import {createRealtimeServer,validateTicket} from '../services/realtime/server.mjs';
const secret='test-only-'+ 'x'.repeat(40),now=Math.floor(Date.now()/1000),checks=[];
const sign=c=>{const p=Buffer.from(JSON.stringify(c)).toString('base64url');return p+'.'+crypto.createHmac('sha256',secret).update(p).digest('base64url');};
const claims={v:1,aud:'tamasya-realtime',companyId:'company-a',propertyIds:['hotel-a'],exp:now+60};
let revision='1',upstreamDown=false;
const upstream=http.createServer((req,res)=>{assert.equal(req.headers.authorization,'Bearer private-reader');if(upstreamDown){res.writeHead(503);res.end('{}');return;}res.setHeader('Content-Type','application/json');res.end(JSON.stringify({success:true,data:{contractVersion:'tamasya-realtime-v1',companyId:'company-a',revisions:{'hotel-a':revision,'hotel-b':'private-revision'}}}));});
await new Promise(r=>upstream.listen(0,'127.0.0.1',r));
const config={localTest:true,pollMs:30000,maxClients:1,allowedOrigins:['https://hq.example'],tenants:{'company-a':{enabled:true,secret,propertyIds:['hotel-a','hotel-b'],readToken:'private-reader',apiUrl:`http://127.0.0.1:${upstream.address().port}/api.php`}}};
const runtime=createRealtimeServer(config);await new Promise(r=>runtime.server.listen(0,'127.0.0.1',r));
const url=`http://127.0.0.1:${runtime.server.address().port}`;
function check(name,fn){fn();checks.push(name);}
try{
 check('Valid scoped ticket',()=>assert.equal(validateTicket(sign(claims),config,now).companyId,'company-a'));
 for(const [name,change] of [['Expired',{exp:now}],['Excess expiry',{exp:now+100}],['Foreign tenant',{companyId:'company-b'}],['Foreign property',{propertyIds:['hotel-c']}],['Wrong audience',{aud:'wrong'}]])check(name,()=>assert.throws(()=>validateTicket(sign({...claims,...change}),config,now)));
 check('Forged signature',()=>assert.throws(()=>validateTicket(sign(claims).slice(0,-5)+'aaaaa',config,now)));
 check('Invalid connection cap fails closed',()=>assert.throws(()=>createRealtimeServer({...config,maxClients:'NaN'})));
 check('Malformed polling config fails closed',()=>assert.throws(()=>createRealtimeServer({...config,pollMs:0})));
 let r=await fetch(url+'/events');check('Anonymous HTTP rejected',()=>assert.equal(r.status,401));
 r=await fetch(url+'/events',{headers:{Origin:'https://foreign.example',Authorization:'Bearer '+sign(claims)}});check('Foreign browser origin rejected',()=>assert.equal(r.status,403));
 const abort=new AbortController();r=await fetch(url+'/events',{headers:{Authorization:'Bearer '+sign(claims)},signal:abort.signal});const reader=r.body.getReader();
 check('SSE stream opened',()=>assert.equal(r.headers.get('Content-Type'),'text/event-stream'));
 await reader.read();await runtime.poll();let event=Buffer.from((await reader.read()).value).toString();
 check('Revision event scoped without foreign property',()=>assert.ok(event.includes('hotel-a')&&!event.includes('hotel-b')&&!event.includes('private-revision')));
 revision='2';await runtime.poll();event=Buffer.from((await reader.read()).value).toString();check('Changed revision delivered',()=>assert.ok(event.includes('"hotel-a":"2"')));
 r=await fetch(url+'/events',{headers:{Authorization:'Bearer '+sign(claims)}});check('Connection cap enforced',()=>assert.equal(r.status,503));
 upstreamDown=true;await runtime.poll();event=Buffer.from((await reader.read()).value).toString();check('Upstream failure signaled for fallback',()=>assert.ok(event.includes('event: unavailable')));
 abort.abort();
}finally{runtime.close();upstream.closeAllConnections();await new Promise(r=>upstream.close(r));}
console.log(JSON.stringify({suite:'realtime',passed:checks.length,checks},null,2));
