"""Run after api-integration.py with the same disposable local server/database.
All messages go to a loopback TLS SMTP sink; no external mail is sent.
"""
from pathlib import Path
import base64
import email
from email import policy
import http.cookiejar
import json
import os
import socketserver
import ssl
import sys
import subprocess
import threading
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ['API_BASE'].rstrip('/')
assert urllib.parse.urlparse(BASE).hostname in ('localhost', '127.0.0.1')
root = Path(__file__).resolve().parent.parent
runtime = (root / 'tests/.runtime').resolve()
config = Path(os.environ['TEST_CONFIG_PATH']).resolve()
assert config.is_relative_to(runtime), 'Only an ignored test configuration may be changed'
original = config.read_bytes()
cert, key = runtime / 'smtp-cert.pem', runtime / 'smtp-key.pem'
subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
messages, envelopes = [], []
state = {'reject': False}

class SMTPHandler(socketserver.BaseRequestHandler):
    def handle(self):
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cert, key)
        try: connection = context.wrap_socket(self.request, server_side=True)
        except ssl.SSLError: return
        connection.settimeout(15)
        with connection, connection.makefile('rwb') as stream:
            def write(value): stream.write((value+'\r\n').encode()); stream.flush()
            write('220 localhost test SMTP')
            recipients = []
            while True:
                line = stream.readline()
                if not line: return
                command = line.decode().rstrip('\r\n')
                if command.startswith('EHLO'): write('250-localhost\r\n250-AUTH LOGIN\r\n250 SIZE 1000000')
                elif command == 'AUTH LOGIN':
                    write('334 '+base64.b64encode(b'Username:').decode())
                    username = base64.b64decode(stream.readline().strip()).decode()
                    write('334 '+base64.b64encode(b'Password:').decode())
                    password = base64.b64decode(stream.readline().strip()).decode()
                    write('235 Authenticated' if username=='mailer@example.test' and password=='test-smtp-password' and not state.get('reject_auth') else '535 Authentication rejected')
                elif command.startswith('MAIL FROM:'): recipients=[]; write('550 Sender rejected' if state.get('reject_sender') else '250 OK')
                elif command.startswith('RCPT TO:'): recipients.append(command[8:].strip('<>')); write('250 OK')
                elif command == 'DATA':
                    write('354 End with a dot'); data=[]
                    while True:
                        part=stream.readline()
                        if part in (b'.\r\n',b''): break
                        data.append(part[1:] if part.startswith(b'..') else part)
                    messages.append(email.message_from_bytes(b''.join(data),policy=policy.default)); envelopes.append(recipients[:])
                    write('554 Test rejection' if state['reject'] else '250 Accepted')
                elif command == 'QUIT': write('221 Bye'); return
                elif command == 'RSET': recipients=[]; write('250 OK')
                else: write('250 OK')

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address=True
    daemon_threads=True
    def handle_error(self, request, client_address):
        if not isinstance(sys.exception(), (BrokenPipeError, ConnectionResetError, ssl.SSLError)):
            super().handle_error(request, client_address)

class Client:
    def __init__(self):
        self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf=''
    def call(self,route,data=None,expected=200,**params):
        url=BASE+'/api/index.php?'+urllib.parse.urlencode({'route':route,**params})
        headers={'Origin':BASE,'Content-Type':'application/json','X-CSRF-Token':self.csrf}
        request=urllib.request.Request(url,data=None if data is None else json.dumps(data).encode(),headers=headers)
        try: response=self.opener.open(request,timeout=25)
        except urllib.error.HTTPError as error: response=error
        raw=response.read()
        try: body=json.loads(raw)
        except ValueError: raise AssertionError((route, response.status, raw.decode()[:1500]))
        assert response.status==expected,(route,expected,response.status,body)
        return body
    def login(self,address):
        self.csrf=self.call('login',{'email':address,'password':'Only-for-integration-tests-123!'})['csrf']

settings={'db':{'host':'127.0.0.1','port':53306,'name':'ab_backend_test','user':'root','password':''},'app_url':BASE,'contact_origins':['https://afspraakboeken.be'],'app_key':'test-only-app-key-not-a-real-production-secret','setup_key':'test-only-setup-key-not-a-real-production-secret','secure_cookies':False,'mail':{'enabled':True,'host':'127.0.0.1','port':5465,'encryption':'ssl','username':'mailer@example.test','password':'test-smtp-password','from_email':'mailer@example.test','from_name':'Afspraakboeken.be','reply_to':'agency@example.test'}}
def configure():
    encoded=base64.b64encode(json.dumps(settings).encode()).decode()
    config.write_text("<?php return json_decode(base64_decode('"+encoded+"'),true);")
def create(owner,address,name='Mail Company'):
    return owner.call('tenants/create',{'name':name,'admin_name':'Mail Owner','email':address,'sector':'Test','plan_type':'monthly','monthly_cents':1000,'lifetime_cents':0},expected=201)

server=Server(('127.0.0.1',5465),SMTPHandler)
thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
try:
    configure();owner=Client();owner.login('owner@example.test');anonymous=Client()
    created=create(owner,'welcome@example.test','Mail <script>alert(1)</script> Company')
    assert created['mail']=={'status':'sent','recipient':'welcome@example.test'}
    assert envelopes[-1]==['welcome@example.test']
    message=messages[-1]; html=message.get_body(('html',)).get_content(); plain=message.get_body(('plain',)).get_content()
    assert created['invite_url'] in html and created['invite_url'] in plain
    assert '<script>' not in html and '&lt;script&gt;' in html
    assert 'Afspraakboeken.be activeert' in plain and str(message['From']).endswith('<mailer@example.test>')
    print('PASS real TLS SMTP, correct account recipient, HTML escaping and text alternative',flush=True)

    tenant=owner.call('workspace',tenant=created['tenant_id'])['tenant']
    assert tenant['admin']['email']=='welcome@example.test' and tenant['welcome']['status']=='sent'
    update={'tenant':tenant['id'],'revision':tenant['revision'],'active':True,'plan_type':'monthly','monthly_cents':1000,'lifetime_cents':0,'billing':{'email':'ACCOUNTS@example.test','legalName':'Legal Company'}}
    owner.call('tenant/admin',{**update,'billing':{'email':'not-an-email'}},expected=422)
    owner.call('tenant/admin',update)
    tenant=owner.call('workspace',tenant=tenant['id'])['tenant']
    assert tenant['subscription']['billing']['email']=='accounts@example.test'
    owner.call('tenant/admin',{**update,'revision':tenant['revision'],'billing':{'notes':'Preserve email'}})
    tenant=owner.call('workspace',tenant=tenant['id'])['tenant'];assert tenant['subscription']['billing']['email']=='accounts@example.test'
    again=owner.call('tenant/welcome',{'tenant':tenant['id'],'email':'wrong-recipient@example.test'})
    assert again['mail']['status']=='sent' and envelopes[-1]==['welcome@example.test']
    assert 'meteen aanmelden' in messages[-1].get_body(('plain',)).get_content()
    anonymous.call('invite/check',{'token':created['invite_url'].split('#')[1]},expected=400)
    anonymous.call('invite/accept',{'token':again['invite_url'].split('#')[1],'password':'Only-for-integration-tests-123!'})
    client=Client();client.login('welcome@example.test');client.call('tenant/welcome',{'tenant':tenant['id']},expected=403)
    own=client.call('workspace')['tenant'];assert 'subscription' not in own and 'welcome' not in own and 'admin' not in own
    print('PASS billing address separation, validation, persistence and role restrictions',flush=True)

    lead={'name':'Lead Owner','company':'Registration mail test','email':'lead@example.test','phone':'','sector':'Bouw, renovatie & installatie','message':'Accountaanvraag via de registratiepagina.','to':'wrong-recipient@example.test'}
    response=anonymous.call('contact',lead,expected=201)
    assert 'mail' not in response and envelopes[-1]==['kenny@kdesign.be']
    assert str(messages[-1]['Reply-To'])=='lead@example.test'
    inbox=owner.call('contacts')['contacts'];saved=next(x for x in inbox if x['company']==lead['company'])
    assert saved['notification']=='contact.mail_sent'
    print('PASS registration lead saved in inbox and SMTP notification sent only to Kenny',flush=True)

    from trial_integration import run as run_trial_tests
    run_trial_tests(Client,owner,anonymous,messages,envelopes,config,state)

    from lead_integration import run as run_lead_tests
    run_lead_tests(Client,owner,anonymous,messages,envelopes,config,state)
    from crm_integration import run as run_crm_tests
    run_crm_tests(Client,owner,anonymous,messages)
    from partner_integration import run as run_partner_tests
    run_partner_tests(Client,owner,anonymous,messages,envelopes,config,state)

    from booking_mail_integration import run as run_booking_mail_tests
    run_booking_mail_tests(Client,owner,anonymous,messages,envelopes,config,state)

    from appointment_reminders_integration import run as run_appointment_tests
    run_appointment_tests(Client,owner,anonymous,messages,envelopes,config,state)

    state['reject']=True
    failed=create(owner,'smtp-failure@example.test');assert failed['mail']['status']=='failed'
    assert anonymous.call('invite/check',{'token':failed['invite_url'].split('#')[1]})['company']=='Mail Company'
    anonymous.call('contact',{**lead,'company':'SMTP rejected lead','email':'rejected@example.test'},expected=201)
    assert next(x for x in owner.call('contacts')['contacts'] if x['company']=='SMTP rejected lead')['notification']=='contact.mail_failed'
    print('PASS SMTP rejection keeps account, activation link and lead data intact',flush=True)

    state['reject']=False;settings['mail']['host']='localhost';configure()
    mismatch=create(owner,'tls-mismatch@example.test');assert mismatch['mail']['status']=='failed'
    print('PASS TLS certificate hostname verification is enforced',flush=True)

    settings['mail']['host']='127.0.0.1';settings['mail']['enabled']=False;configure();count=len(messages)
    inactive=create(owner,'unconfigured@example.test');assert inactive['mail']['status']=='not_configured'
    owner.call('tenant/welcome',{'tenant':inactive['tenant_id']},expected=422)
    anonymous.call('invite/check',{'token':inactive['invite_url'].split('#')[1]})
    assert len(messages)==count and owner.call('tenants')['mail']['configured'] is False
    anonymous.call('trial/signup',{'name':'No Mail','company':'No Mail','email':'no-mail@example.test','sector':'Test'},expected=503)
    assert anonymous.call('health')['trial_available'] is False
    print('PASS unconfigured email is explicit and does not revoke existing invitation on resend',flush=True)
finally:
    config.write_bytes(original)
    server.shutdown();server.server_close();thread.join(timeout=2)
