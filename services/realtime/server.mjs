import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';
import {pathToFileURL} from 'node:url';

export function validateTicket(ticket, config, now = Math.floor(Date.now()/1000)) {
  if (typeof ticket !== 'string' || ticket.length > 10000) throw Error('INVALID_TICKET');
  const parts = ticket.split('.'); if (parts.length !== 2) throw Error('INVALID_TICKET');
  const claims = JSON.parse(Buffer.from(parts[0],'base64url').toString('utf8'));
  const tenant = Object.hasOwn(config.tenants,claims.companyId) ? config.tenants[claims.companyId] : null;
  if (!tenant || tenant.enabled !== true || typeof tenant.secret !== 'string' || tenant.secret.length < 32) throw Error('TENANT_DENIED');
  const signature = crypto.createHmac('sha256',tenant.secret).update(parts[0]).digest();
  const provided = Buffer.from(parts[1],'base64url');
  if (provided.length !== signature.length || !crypto.timingSafeEqual(provided,signature)) throw Error('SIGNATURE_INVALID');
  if (claims.v !== 1 || claims.aud !== 'tamasya-realtime' || !Number.isSafeInteger(claims.exp) || claims.exp <= now || claims.exp > now+90 || !Array.isArray(claims.propertyIds) || !claims.propertyIds.length || claims.propertyIds.length>50 || claims.propertyIds.some(id=>!tenant.propertyIds.includes(id))) throw Error('CLAIMS_DENIED');
  return claims;
}
export function createRealtimeServer(config) {
  if(!config.tenants || typeof config.tenants!=='object' || Array.isArray(config.tenants))throw Error('TENANTS_REQUIRED');
  if(config.maxClients!==undefined&&(!Number.isInteger(config.maxClients)||config.maxClients<1||config.maxClients>2000))throw Error('INVALID_MAX_CLIENTS');
  if(config.pollMs!==undefined&&(!Number.isInteger(config.pollMs)||config.pollMs<500||config.pollMs>30000))throw Error('INVALID_POLL_INTERVAL');
  for(const tenant of Object.values(config.tenants))if(!Array.isArray(tenant.propertyIds)||tenant.propertyIds.length>50)throw Error('INVALID_TENANT_GRANT');
  const clients = new Set(); let closed = false; let polling = false;
  const metrics = {connections:0,upstreamFailures:0,polls:0};
  const server = http.createServer((req,res)=>{
    if (req.url === '/healthz' && req.method === 'GET') { res.writeHead(200,{'Content-Type':'application/json'}); res.end(JSON.stringify({success:true,contractVersion:'tamasya-realtime-v1',clients:clients.size,...metrics})); return; }
    const origin = req.headers.origin;
    if (origin && !(config.allowedOrigins??[]).includes(origin)) { res.writeHead(403);res.end();return; }
    if (origin) { res.setHeader('Access-Control-Allow-Origin',origin);res.setHeader('Vary','Origin'); }
    if (req.method === 'OPTIONS') { res.writeHead(204,{'Access-Control-Allow-Methods':'GET','Access-Control-Allow-Headers':'Authorization'});res.end();return; }
    if (req.url !== '/events' || req.method !== 'GET') { res.writeHead(404);res.end();return; }
    if (clients.size >= Math.min(config.maxClients??256,2000)) { res.writeHead(503,{'Retry-After':'5'});res.end();return; }
    let claims;
    try { claims=validateTicket((req.headers.authorization??'').replace(/^Bearer /,''),config); } catch { res.writeHead(401);res.end();return; }
    res.writeHead(200,{'Content-Type':'text/event-stream','Cache-Control':'no-store','X-Accel-Buffering':'no','Connection':'keep-alive'});
    res.write('event: ready\ndata: {"contractVersion":"tamasya-realtime-v1"}\n\n');
    const client={res,claims,last:'',expiresAt:claims.exp*1000};clients.add(client);metrics.connections++;
    const cleanup=()=>clients.delete(client);req.on('close',cleanup);res.on('error',cleanup);
  });
  async function poll() {
    if (closed || polling) return; polling=true;
    try {
      for (const client of clients) if (Date.now() >= client.expiresAt || client.res.writableLength > 65536) {client.res.end();clients.delete(client);}
      const companies=[...new Set([...clients].map(c=>c.claims.companyId))];
      await Promise.all(companies.map(async company=>{
        const tenant=config.tenants[company];
        try {
          const url=new URL(tenant.apiUrl); if(url.protocol!=='https:' && !(config.localTest===true && ['127.0.0.1','localhost'].includes(url.hostname))) throw Error('HTTPS_REQUIRED');
          url.searchParams.set('action','event-revision');
          const response=await fetch(url,{headers:{Authorization:`Bearer ${tenant.readToken}`},signal:AbortSignal.timeout(4000),redirect:'error'});
          const body=await response.json();metrics.polls++;
          if (!response.ok || body.success!==true || body.data.contractVersion!=='tamasya-realtime-v1' || body.data.companyId!==company) throw Error('UPSTREAM_CONTRACT');
          for (const client of clients) if (client.claims.companyId===company) {
            const revisions={}; for (const id of client.claims.propertyIds) { if(!Object.hasOwn(body.data.revisions,id)) throw Error('UPSTREAM_SCOPE');revisions[id]=body.data.revisions[id]; }
            const content=JSON.stringify({contractVersion:'tamasya-realtime-v1',companyId:company,revisions});
            if (content!==client.last) {client.res.write(`event: revision\ndata: ${content}\n\n`);client.last=content;} else client.res.write(': heartbeat\n\n');
          }
        } catch {metrics.upstreamFailures++;for(const client of clients)if(client.claims.companyId===company)client.res.write('event: unavailable\ndata: {"retryable":true}\n\n');}
      }));
    } finally {polling=false;}
  }
  const timer=setInterval(poll,Math.max(500,Math.min(config.pollMs??3000,30000)));timer.unref();
  server.on('close',()=>{closed=true;clearInterval(timer);for(const c of clients)c.res.end();});
  return {server,poll,metrics,close:()=>{closed=true;clearInterval(timer);for(const c of clients)c.res.end();server.close();}};
}
if (process.argv[1] && import.meta.url===pathToFileURL(process.argv[1]).href) {
  const config=JSON.parse(fs.readFileSync(process.env.TAMASYA_REALTIME_CONFIG_FILE,'utf8'));
  const runtime=createRealtimeServer(config);runtime.server.listen(config.port??38200,config.host??'127.0.0.1');
  for(const signal of ['SIGINT','SIGTERM'])process.on(signal,()=>runtime.close());
}
