#!/usr/bin/env python3
import argparse, base64, hashlib, hmac, json, ssl, threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

p=argparse.ArgumentParser()
p.add_argument('--host',default='0.0.0.0')
p.add_argument('--port',type=int,default=38443)
p.add_argument('--cert',required=True)
p.add_argument('--key',required=True)
p.add_argument('--state',required=True)
p.add_argument('--delivery-secret',required=True)
a=p.parse_args()

objects={}
lock=threading.Lock()
state={'objectPut':0,'objectGet':0,'objectDuplicate':0,'deliverySuccess':0,'deliveryAccepted':0,'deliveryWrong':0,'deliveryBadSignature':0}

def save_state():
    Path(a.state).write_text(json.dumps(state,sort_keys=True),encoding='utf-8')

def body_hash(data:bytes)->str:
    return hashlib.sha256(data).hexdigest()

class H(BaseHTTPRequestHandler):
    server_version='TamasyaPrdMock/1'
    def log_message(self, fmt, *args):
        return
    def send_json(self,status,obj):
        b=json.dumps(obj,separators=(',',':')).encode()
        self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(b)));self.end_headers();self.wfile.write(b)
    def do_GET(self):
        if self.path=='/healthz': return self.send_json(200,{'success':True})
        if not self.path.startswith('/tamasya-prd-ci/'):
            return self.send_json(404,{'success':False})
        with lock:
            data=objects.get(self.path)
            if data is None:return self.send_json(404,{'success':False})
            state['objectGet']+=1;save_state()
        self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
    def do_PUT(self):
        if not self.path.startswith('/tamasya-prd-ci/'):
            return self.send_json(403,{'success':False})
        n=int(self.headers.get('Content-Length','0'));data=self.rfile.read(n)
        auth=self.headers.get('Authorization','')
        content_sha=self.headers.get('x-amz-content-sha256','')
        checksum=self.headers.get('x-amz-checksum-sha256','')
        encrypted=self.headers.get('x-amz-server-side-encryption','')
        if not auth.startswith('AWS4-HMAC-SHA256 ') or content_sha!=body_hash(data) or checksum!=base64.b64encode(hashlib.sha256(data).digest()).decode() or encrypted!='AES256':
            return self.send_json(403,{'success':False,'code':'STORAGE_CONTRACT'})
        with lock:
            if self.path in objects and self.headers.get('If-None-Match')=='*':
                state['objectDuplicate']+=1;save_state();self.send_response(412);self.end_headers();return
            objects[self.path]=data;state['objectPut']+=1;save_state()
        self.send_response(201);self.end_headers()
    def do_POST(self):
        if not self.path.startswith('/delivery/'):
            return self.send_json(404,{'success':False})
        n=int(self.headers.get('Content-Length','0'));data=self.rfile.read(n)
        job=self.headers.get('Idempotency-Key','');ts=self.headers.get('X-Tamasya-Timestamp','');sig=self.headers.get('X-Tamasya-Signature','')
        payload_hash=body_hash(data)
        expected=hmac.new(a.delivery_secret.encode(),f'{ts}\n{job}\n{payload_hash}'.encode(),hashlib.sha256).hexdigest()
        if not job or not ts.isdigit() or not hmac.compare_digest(expected,sig):
            with lock:state['deliveryBadSignature']+=1;save_state()
            return self.send_json(401,{'success':False})
        try: payload=json.loads(data)
        except Exception:return self.send_json(400,{'success':False})
        mode=self.path.rsplit('/',1)[-1]
        if mode=='success':
            with lock:state['deliverySuccess']+=1;save_state()
            return self.send_json(200,{'success':True,'jobId':job,'reportId':payload.get('reportId'),'status':'delivered'})
        if mode=='accepted':
            with lock:state['deliveryAccepted']+=1;save_state()
            return self.send_json(202,{'success':True,'jobId':job,'reportId':payload.get('reportId'),'status':'accepted'})
        if mode=='wrong':
            with lock:state['deliveryWrong']+=1;save_state()
            return self.send_json(200,{'success':True,'jobId':'wrong-'+job,'reportId':payload.get('reportId'),'status':'delivered'})
        return self.send_json(404,{'success':False})

save_state()
httpd=ThreadingHTTPServer((a.host,a.port),H)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(a.cert,a.key);httpd.socket=ctx.wrap_socket(httpd.socket,server_side=True)
httpd.serve_forever()
