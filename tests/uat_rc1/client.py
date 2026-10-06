from pathlib import Path
import json, os, re, urllib.request, urllib.error, uuid

base = Path(__file__).resolve().parent
(base/'logs').mkdir(parents=True, exist_ok=True)
BASE_URL = os.getenv('TAMASYA_UAT_BASE_URL','http://127.0.0.1:38184').rstrip('/')
DEVICE_ID = os.getenv('TAMASYA_UAT_DEVICE_ID','github-uat-rc1')
ORIGIN = os.getenv('TAMASYA_UAT_ORIGIN',BASE_URL)
OFFLINE_SESSION_SCOPE = os.getenv('TAMASYA_UAT_OFFLINE_SESSION_SCOPE','offline_github_uat_rc1')
if not re.fullmatch(r'[A-Za-z0-9._:-]{16,100}', OFFLINE_SESSION_SCOPE):
    raise RuntimeError('TAMASYA_UAT_OFFLINE_SESSION_SCOPE must satisfy RC1 offline-session scope contract')

def _session():
    path=base/'session.json'
    if not path.exists(): return {}
    return json.loads(path.read_text(encoding='utf8'))

def request(action, method='GET', data=None, operation=None, port=None):
    base_url = BASE_URL
    if port is not None:
        from urllib.parse import urlsplit, urlunsplit
        u=urlsplit(base_url); base_url=urlunsplit((u.scheme,f'{u.hostname}:{port}',u.path,u.query,u.fragment)).rstrip('/')
    query = action if action.startswith('action=') else 'action='+action
    url = base_url+'/api.php?'+query
    body=None
    headers={'Accept':'application/json','X-Device-ID':DEVICE_ID,'Origin':ORIGIN}
    sess=_session(); token=sess.get('token')
    if token: headers['Authorization']='Bearer '+token
    scope=sess.get('offlineSessionScopeId') or OFFLINE_SESSION_SCOPE
    if scope: headers['X-Tamasya-Offline-Session-Scope']=scope
    if operation: headers['X-Tamasya-Operation-ID']=operation
    if data is not None:
        body=json.dumps(data,separators=(',',':')).encode('utf8')
        headers['Content-Type']='application/json'
    req=urllib.request.Request(url,data=body,headers=headers,method=method.upper())
    try:
        resp=urllib.request.urlopen(req,timeout=90)
    except urllib.error.HTTPError as e:
        resp=e
    raw=resp.read().decode('utf8','replace')
    try: parsed=json.loads(raw) if raw else {}
    except Exception: parsed={'success':False,'error':'Non-JSON response','raw':raw[:2000]}
    return int(resp.status), parsed
