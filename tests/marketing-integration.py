import os,json,urllib.request,urllib.parse,urllib.error,http.cookiejar,uuid,datetime
base=os.environ['API_BASE'];assert urllib.parse.urlparse(base).hostname=='127.0.0.1'
class Client:
 def __init__(self):self.csrf='';self.o=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 def call(self,route,d=None,status=200,**q):
  req=urllib.request.Request(base+'/api/index.php?'+urllib.parse.urlencode({'route':route,**q}),data=json.dumps(d).encode() if d is not None else None,headers={'Content-Type':'application/json','Origin':base,'X-CSRF-Token':self.csrf})
  try:r=self.o.open(req)
  except urllib.error.HTTPError as e:r=e
  result=json.loads(r.read());assert r.status==status,(route,r.status,result);return result
owner=Client();anon=Client();other=Client();owner.csrf=owner.call('login',{'email':'owner@example.test','password':'Only-for-integration-tests-123!'})['csrf']
other_result=owner.call('tenants/create',{'name':'Other marketing','admin_name':'Other','email':'other-marketing@example.test','sector':'Test','plan_type':'monthly','monthly_cents':7900},status=201)
other_id=other_result['tenant_id'];ot=owner.call('workspace',tenant=other_id)['tenant']
owner.call('tenant/admin',{'tenant':other_id,'revision':ot['revision'],'active':True,'plan_type':'monthly','monthly_cents':7900,'lifetime_cents':0,'billing':{}})
other.call('invite/accept',{'token':other_result['invite_url'].split('#')[1],'password':'Only-for-integration-tests-123!'})
other.csrf=other.call('login',{'email':'other-marketing@example.test','password':'Only-for-integration-tests-123!'})['csrf']
tid=owner.call('tenants/create',{'name':'Marketing Test','admin_name':'Marketing','email':'marketing@example.test','sector':'Test','plan_type':'monthly','monthly_cents':7900},status=201)['tenant_id']
t=owner.call('workspace',tenant=tid)['tenant'];owner.call('tenant/admin',{'tenant':tid,'revision':t['revision'],'active':True,'plan_type':'monthly','monthly_cents':7900,'lifetime_cents':0,'billing':{}})
t=owner.call('workspace',tenant=tid)['tenant'];owner.call('tenant/save',{'tenant':tid,'revision':t['revision'],'settings':{'intake':{'mode':'request','serviceSelection':False}}})
day=datetime.datetime.now().strftime('%Y-%m-%d');params={'tenant':tid,'from':day,'to':day};anon.call('marketing/report',status=401,**params);other.call('marketing/report',status=404,**params)
r=owner.call('marketing/report',**params);f=r['forms'][0];v=str(uuid.uuid4());event={'tenant':tid,'form':f['id'],'visit':v,'event':'view','consent':True,'source':'facebook','medium':'paid_social','campaign':'spring','device':'mobiel'}
assert anon.call('public/marketing-event',event)['ok']==False
owner.call('marketing/settings',{'tenant':tid,'form':f['id'],'revision':1,'enabled':True,'pixel':'notvalid'},status=422)
owner.call('marketing/settings',{'tenant':tid,'form':f['id'],'revision':1,'enabled':True,'pixel':''})
owner.call('marketing/settings',{'tenant':tid,'form':f['id'],'revision':1,'enabled':True,'pixel':''},status=409)
assert anon.call('public/marketing-event',{**event,'consent':False})['ok']==False
for e in ['view','view','start','start','details']:anon.call('public/marketing-event',{**event,'event':e})
lead={'tenant':tid,'name':'Test Lead','email':'lead-marketing@example.test','phone':'','address':'','notes':'','request_key':uuid.uuid4().hex,'marketing_consent':False,'measurement':{'consent':True,'visit':v}}
a=anon.call('public/lead',lead,status=201);b=anon.call('public/lead',lead,status=201);assert a['id']==b['id']
r=owner.call('marketing/report',**params);assert len(r['groups'])==1 and r['groups'][0]['visits']==1 and r['groups'][0]['converted']==1 and r['groups'][0]['started']==1
assert r['received'][0]['lead']==1 and r['received'][0]['unmeasured']==0
anon.call('public/lead',{**lead,'request_key':uuid.uuid4().hex,'email':'unmeasured@example.test','measurement':None},status=201)
r=owner.call('marketing/report',**params);assert r['received'][0]['lead']==2 and r['received'][0]['unmeasured']==1
anon.call('public/marketing-event',{**event,'form':'f'*32},status=404)
owner.call('marketing/report',status=422,tenant=tid,**{'from':'2020-01-01','to':day})
assert r['groups'][0]['source']=='facebook' and r['groups'][0]['medium']=='paid_social'
print('PASS marketing: migration, tenant isolation, consent, settings revision, visit deduplication, server-confirmed conversions, replay protection, unmeasured totals and date validation')
