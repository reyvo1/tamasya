import scenario_extended as e
from scenario_extended import *
import io,zipfile,xml.etree.ElementTree as ET,os

e.logname='export-results.json'
_,catalog=request('canonical-report-types')
export_base=os.getenv('TAMASYA_UAT_BASE_URL','http://127.0.0.1:38184').rstrip('/')
session=json.loads((base/'session.json').read_text())
headers={
 'Authorization':'Bearer '+session['token'],
 'X-Device-ID':DEVICE_ID,
 'X-Tamasya-Offline-Session-Scope':session.get('offlineSessionScopeId') or OFFLINE_SESSION_SCOPE,
 'Origin':os.getenv('TAMASYA_UAT_ORIGIN',export_base)
}
for typ in catalog['types']:
 _,snapshot=request('canonical-report&type='+typ+'&from=2026-01-01&to=2026-12-31')
 for fmt in ['csv','xlsx','pdf']:
  req=urllib.request.Request(export_base+'/api.php?action=canonical-report&type='+typ+'&from=2026-01-01&to=2026-12-31&format='+fmt,headers=headers)
  try:r=urllib.request.urlopen(req,timeout=90)
  except urllib.error.HTTPError as er:r=er
  raw=r.read();ok=r.status==200 and r.headers.get('X-Tamasya-Report-SHA256')==snapshot['meta']['checksumSha256']
  try:
   if fmt=='xlsx':
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
     ok=ok and z.testzip() is None and 'xl/workbook.xml' in z.namelist()
     for n in z.namelist():
      if n.endswith(('.xml','.rels')):ET.fromstring(z.read(n))
   elif fmt=='pdf':ok=ok and raw.startswith(b'%PDF-') and b'%%EOF' in raw[-50:] and b'xref' in raw
   else:ok=ok and 'Report ID' in raw.decode('utf-8-sig')
  except Exception as ex:ok=False
  check(typ+' '+fmt+' structure and snapshot identity',ok,{'httpStatus':r.status,'bytes':len(raw)})
print('RESULT',sum(x['pass'] for x in e.results),'/',len(e.results))
