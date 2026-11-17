"""Destructive only to an EMPTY disposable test database. Never point at production.
Run against a local PHP test server configured with a fresh local MariaDB database.
API_BASE and TEST_SETUP_KEY must be explicitly supplied.
"""
import concurrent.futures
import datetime
import http.cookiejar
import json
import os
import secrets
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ['API_BASE'].rstrip('/')
assert urllib.parse.urlparse(BASE).hostname in ('localhost', '127.0.0.1'), 'Local test server only'
KEY = os.environ['TEST_SETUP_KEY']
PASSWORD = 'Only-for-integration-tests-123!'
passed = []

class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ''

    def call(self, route, data=None, expected=200, origin=None, headers=None, **params):
        url = BASE + '/api/index.php?' + urllib.parse.urlencode({'route': route, **params})
        h = {'Origin': origin or BASE}
        if data is not None:
            h.update({'Content-Type': 'application/json', 'X-CSRF-Token': self.csrf})
        h.update(headers or {})
        req = urllib.request.Request(url, data=None if data is None else json.dumps(data).encode(), headers=h)
        try:
            response = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        result = json.loads(response.read())
        assert response.status == expected, (route, expected, response.status, result)
        return result

    def login(self, email):
        result = self.call('login', {'email': email, 'password': PASSWORD})
        self.csrf = result['csrf']
        return result

def ok(label):
    passed.append(label)
    print('PASS', label, flush=True)

anonymous, owner, a, b = Client(), Client(), Client(), Client()
assert anonymous.call('health', expected=503)['ready'] is False
anonymous.call('setup', {'name':'Agency','email':'owner@example.test','password':PASSWORD}, expected=403)
owner.call('setup', {'name':'Agency','email':'owner@example.test','password':PASSWORD}, headers={'X-Setup-Key':KEY}, expected=201)
owner.call('setup', {'name':'Intruder','email':'second@example.test','password':PASSWORD}, headers={'X-Setup-Key':KEY}, expected=409)
ok('Setup requires a private key and closes after the first superadmin')
assert anonymous.call('health')['ready'] is True
anonymous.call('me', expected=401)
anonymous.call('login', {'email':'owner@example.test','password':PASSWORD}, origin='https://evil.example', expected=403)
anonymous.call('login', {'email':'owner@example.test','password':'incorrect'}, expected=401)
assert owner.login('owner@example.test')['user']['role'] == 'superadmin'
assert any('HttpOnly' in cookie._rest and cookie._rest.get('SameSite') == 'Lax' for cookie in owner.jar)
owner.call('tenants/create', {}, headers={'X-CSRF-Token':'forged'}, expected=403)
ok('Login, HttpOnly cookie, origin checks and CSRF protection')

def create(name, email):
    return owner.call('tenants/create', {'name':name,'admin_name':name+' owner','email':email,'sector':'Test','plan_type':'monthly','monthly_cents':7900,'lifetime_cents':0,'role':'superadmin'}, expected=201)

ta = create('Company A','a@example.test')
tb = create('Company B','b@example.test')
token_a, token_b = ta['invite_url'].split('#')[1], tb['invite_url'].split('#')[1]
assert anonymous.call('invite/check', {'token':token_a})['company'] == 'Company A'
assert anonymous.call('invite/accept', {'token':token_a,'password':PASSWORD})['active'] is False
anonymous.call('invite/accept', {'token':token_a,'password':PASSWORD}, expected=400)
anonymous.call('login', {'email':'a@example.test','password':PASSWORD}, expected=403)
anonymous.call('invite/accept', {'token':token_b,'password':PASSWORD})
ok('Invites are single-use and inactive companies cannot log in')

def activate(tenant, active=True):
    record = owner.call('workspace', tenant=tenant)['tenant']
    owner.call('tenant/admin', {'tenant':tenant,'revision':record['revision'],'active':active,'plan_type':'monthly','monthly_cents':7900,'lifetime_cents':0,'billing':{'notes':'Private agency billing note'}})

activate(ta['tenant_id']); activate(tb['tenant_id'])
assert a.login('a@example.test')['user']['role'] == 'admin'
b.login('b@example.test')
wa = a.call('workspace')
assert 'subscription' not in wa['tenant'] and 'billing' not in json.dumps(wa)
a.call('tenants', expected=403);a.call('contacts', expected=403)
a.call('workspace', tenant=tb['tenant_id'], expected=404)
a.call('tenant/admin', {'tenant':ta['tenant_id']}, expected=403)
a.call('tenant/save', {'tenant':ta['tenant_id'],'revision':wa['tenant']['revision'],'settings':{'features':{'crm':True}}}, expected=403)
ok('Tenant admins cannot access agency routes, other tenants, billing or module permissions')

settings = {'notice':0,'hours':[{'open':True,'start':'09:00','end':'18:00'} for _ in range(7)]}
a.call('tenant/save', {'tenant':ta['tenant_id'],'revision':wa['tenant']['revision'],'settings':settings})
a.call('tenant/save', {'tenant':ta['tenant_id'],'revision':wa['tenant']['revision'],'settings':settings}, expected=409)
ok('Optimistic revision checks reject stale settings writes')
revision=a.call('workspace')['tenant']['revision']
a.call('tenant/save',{'tenant':ta['tenant_id'],'revision':revision,'settings':{'widgetMode':'inline'}})
assert a.call('workspace')['tenant']['settings']['widgetMode']=='inline'
revision=a.call('workspace')['tenant']['revision']
a.call('tenant/save',{'tenant':ta['tenant_id'],'revision':revision,'settings':{'widgetMode':'invalid'}},expected=422)
a.call('tenant/save',{'tenant':ta['tenant_id'],'revision':revision,'settings':{'widgetMode':'drawer'}})
ok('Widget display mode persists per account and rejects invalid modes')

service = {'name':'Advice','category':'Test','description':'A real test appointment','duration':45,'buffer':15,'price':85,'active':True,'image':'','video':''}
sa=a.call('service/save', {**service,'tenant':ta['tenant_id']})['id']
sb=b.call('service/save', {**service,'tenant':tb['tenant_id']})['id']
a.call('service/save', {**service,'tenant':ta['tenant_id'],'id':sb,'revision':1}, expected=404)
a.call('service/save', {**service,'tenant':ta['tenant_id'],'image':'javascript:alert(1)'}, expected=422)
ok('Service writes enforce tenant ownership and validate media URLs')

# Real multipart transport; fake MIME and executable uploads never become media.
import base64
image_bytes=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aH4kAAAAASUVORK5CYII=')
def upload(client,tenant,content,expected=201,csrf=None):
    boundary='test'+secrets.token_hex(12)
    payload=(f'--{boundary}\r\nContent-Disposition: form-data; name="tenant"\r\n\r\n{tenant}\r\n--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="upload.png"\r\nContent-Type: image/png\r\n\r\n').encode()+content+f'\r\n--{boundary}--\r\n'.encode()
    req=urllib.request.Request(BASE+'/api/index.php?route=service/image',data=payload,headers={'Origin':BASE,'Content-Type':'multipart/form-data; boundary='+boundary,'X-CSRF-Token':client.csrf if csrf is None else csrf})
    try:r=client.opener.open(req,timeout=20)
    except urllib.error.HTTPError as e:r=e
    body=json.loads(r.read());assert r.status==expected,(expected,r.status,body)
    return body
upload(anonymous,ta['tenant_id'],image_bytes,401)
upload(a,ta['tenant_id'],image_bytes,403,csrf='invalid')
upload(a,tb['tenant_id'],image_bytes,404)
upload(a,ta['tenant_id'],b'<?php echo "unsafe"; ?>',422)
upload(a,ta['tenant_id'],b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',422)
upload(a,ta['tenant_id'],b'x'*(5*1024*1024+1),413)
image_url=upload(a,ta['tenant_id'],image_bytes)['url']
assert upload(a,ta['tenant_id'],image_bytes)['url']==image_url
with anonymous.opener.open(image_url) as r:
    assert r.headers['Content-Type']=='image/png' and r.headers['X-Content-Type-Options']=='nosniff'
    assert r.read()==image_bytes
b.call('service/save',{**service,'tenant':tb['tenant_id'],'id':sb,'revision':1,'image':image_url},expected=422)
without_category={k:v for k,v in service.items() if k!='category'}
a.call('service/save',{**without_category,'tenant':ta['tenant_id'],'id':sa,'revision':1,'image':image_url})
saved=next(s for s in a.call('workspace')['services'] if s['id']==sa)
assert saved['category']=='Test' and saved['image']==image_url
# Removing the image falls back without losing the old category.
a.call('service/save',{**without_category,'tenant':ta['tenant_id'],'id':sa,'revision':saved['revision'],'image':''})
assert next(s for s in a.call('workspace')['services'] if s['id']==sa)['image']==''
ok('Image uploads enforce authentication, CSRF, tenant ownership, file type and 5 MB; bytes persist, deduplicate and categories survive')


tomorrow=(datetime.date.today()+datetime.timedelta(days=3)).isoformat()
catalog=anonymous.call('public/catalog', tenant=ta['tenant_id'])
assert 'email' not in catalog['tenant'] and 'subscription' not in catalog['tenant']
assert len(catalog['services']) == 1
slots=anonymous.call('public/slots', tenant=ta['tenant_id'], service=sa, date=tomorrow)['times']
assert slots[0]=='09:00' and slots[-1]=='17:00'
month=anonymous.call('public/month',tenant=ta['tenant_id'],service=sa,month=tomorrow[:7])
assert month['days'][tomorrow] is True
assert all(isinstance(v,bool) for v in month['days'].values())
anonymous.call('public/month',tenant=ta['tenant_id'],service=sa,month='2026-99',expected=422)
anonymous.call('public/month',tenant=ta['tenant_id'],service='f'*32,month=tomorrow[:7],expected=404)
revision=a.call('workspace')['tenant']['revision']
a.call('tenant/save',{'tenant':ta['tenant_id'],'revision':revision,'settings':{'blocks':[tomorrow]}})
assert anonymous.call('public/month',tenant=ta['tenant_id'],service=sa,month=tomorrow[:7])['days'][tomorrow] is False
assert anonymous.call('public/slots',tenant=ta['tenant_id'],service=sa,date=tomorrow)['times']==[]
revision=a.call('workspace')['tenant']['revision']
a.call('tenant/save',{'tenant':ta['tenant_id'],'revision':revision,'settings':{'blocks':[]}})
assert anonymous.call('public/month',tenant=ta['tenant_id'],service=sa,month=tomorrow[:7])['days'][tomorrow] is True
ok('Monthly availability reflects blocking and reopening; service scope and month validation hold')
booking={'tenant':ta['tenant_id'],'service':sa,'date':tomorrow,'time':'09:00','name':'Test Customer','email':'customer@example.test','phone':'0400000000','marketing_consent':False,'source':'facebook','campaign':'must-not-be-stored'}
key=secrets.token_hex(16)
first=anonymous.call('public/book', {**booking,'request_key':key}, expected=201)
repeat=anonymous.call('public/book', {**booking,'request_key':key}, expected=201)
assert first['id']==repeat['id'] and repeat['replayed'] is True
anonymous.call('public/book', {**booking,'name':'Different','request_key':key}, expected=409)
remaining=anonymous.call('public/slots', tenant=ta['tenant_id'], service=sa, date=tomorrow)['times']
assert '09:00' not in remaining and '09:30' not in remaining and '10:00' in remaining
records=a.call('workspace')['bookings'];assert len(records)==1 and records[0]['source']=='website' and records[0]['campaign']==''
assert anonymous.call('public/catalog', tenant=ta['tenant_id']) == catalog
ok('Booking duration/buffer, idempotency, consent and private-customer-data boundaries')

def concurrent_book(_):
    client=Client()
    try:
        return client.call('public/book', {**booking,'time':'11:00','request_key':secrets.token_hex(16)}, expected=201)
    except AssertionError as error:
        assert error.args[0][2] == 409, error
        return None
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    results=list(pool.map(concurrent_book, range(2)))
assert sum(r is not None for r in results) == 1
ok('Concurrent requests for the same time result in exactly one reservation')

b.call('booking/update', {'tenant':tb['tenant_id'],'id':first['id'],'status':'cancelled'}, expected=404)
a.call('booking/update', {'tenant':ta['tenant_id'],'id':first['id'],'stage':'contacted'})
a.call('booking/update', {'tenant':ta['tenant_id'],'id':first['id'],'status':'cancelled'})
assert '09:00' in anonymous.call('public/slots', tenant=ta['tenant_id'], service=sa, date=tomorrow)['times']
ok('CRM changes and cancellation remain tenant-scoped; cancellation frees the slot')

contact={'name':'Prospect','company':'Prospect Company','email':'prospect@example.test','phone':'','sector':'Beauty','message':'Please contact us','website':''}
anonymous.call('contact', contact, origin='https://afspraakboeken.be', expected=201)
anonymous.call('contact', contact, origin='https://evil.example', expected=403)
anonymous.call('contact', {**contact,'website':'spam'}, origin='https://afspraakboeken.be', expected=202)
assert len(owner.call('contacts')['contacts']) == 1
a.call('contact/update', {'id':owner.call('contacts')['contacts'][0]['id'],'status':'contacted'}, expected=403)
ok('Contact form origin, spam trap, persistence and superadmin-only inbox')

activate(ta['tenant_id'],False)
a.call('me', expected=401)
anonymous.call('public/catalog', tenant=ta['tenant_id'], expected=404)
anonymous.call('public/book', {**booking,'time':'14:00','request_key':secrets.token_hex(16)}, expected=404)
assert len(owner.call('workspace',tenant=ta['tenant_id'])['bookings']) == 2
ok('Deactivation revokes live sessions and public booking without deleting records')

reset1=owner.call('tenant/invite', {'tenant':tb['tenant_id']})['invite_url'].split('#')[1]
reset2=owner.call('tenant/invite', {'tenant':tb['tenant_id']})['invite_url'].split('#')[1]
anonymous.call('invite/check', {'token':reset1}, expected=400)
anonymous.call('invite/accept', {'token':reset2,'password':PASSWORD})
b.call('me', expected=401)
b.login('b@example.test')
b.call('password', {'current_password':'wrong','password':PASSWORD}, expected=422)
new_password='A-new-test-password-456!'
b.call('password', {'current_password':PASSWORD,'password':new_password})
b.call('me', expected=401)
anonymous.call('login', {'email':'b@example.test','password':PASSWORD}, expected=401)
anonymous.call('login', {'email':'b@example.test','password':new_password})
ok('Renewed invites revoke older links; reset and password change revoke sessions')

for _ in range(12):
    anonymous.call('login', {'email':'brute-force@example.test','password':'invalid'}, expected=401)
anonymous.call('login', {'email':'brute-force@example.test','password':'invalid'}, expected=429)
ok('Repeated password guesses are rate-limited')

owner.call('logout',{})
owner.call('me',expected=401)
ok('Logout revokes the server-side session')
print(f'\n{len(passed)} integration groups passed.')
