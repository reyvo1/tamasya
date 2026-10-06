from client import *
username=os.getenv('APP_BOOTSTRAP_ADMIN_USERNAME','admin')
password=os.getenv('APP_BOOTSTRAP_ADMIN_PASSWORD','Tamasya-UAT-RC1-Only!')
status,body=request('login','POST',{'username':username,'password':password,'offlineSessionScopeId':OFFLINE_SESSION_SCOPE})
if status!=200 or body.get('success') is not True or not body.get('token'):
    raise SystemExit('Login bootstrap gagal: HTTP %s %s' % (status,json.dumps(body,ensure_ascii=False)[:1000]))
if body.get('offlineSessionScopeId') != OFFLINE_SESSION_SCOPE:
    raise SystemExit('Login bootstrap gagal: server tidak mempertahankan offline session scope RC1 yang diminta')
(base/'session.json').write_text(json.dumps(body,ensure_ascii=False,indent=2),encoding='utf8')
print('PASS bootstrap login',body.get('staffId'))
