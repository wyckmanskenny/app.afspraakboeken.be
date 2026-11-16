"""Run inside the disposable database and loopback SMTP suite only."""
import secrets
import urllib.parse
import csv
import io
import datetime

def run(Client,owner,anonymous,messages):
    def create(address):
        r=owner.call('tenants/create',{'name':'CRM Test','admin_name':'Owner','email':address,'sector':'Test'},expected=201)
        anonymous.call('invite/accept',{'token':r['invite_url'].split('#')[1],'password':'Only-for-integration-tests-123!'})
        t=owner.call('workspace',tenant=r['tenant_id'])['tenant']
        owner.call('tenant/admin',{'tenant':t['id'],'revision':t['revision'],'active':True,'plan_type':'monthly','monthly_cents':0,'lifetime_cents':0})
        c=Client();c.login(address);return t['id'],c
    tid,a=create('crm-owner@example.test');other,b=create('crm-other@example.test')
    def enabled(value):
        t=owner.call('workspace',tenant=tid)['tenant'];owner.call('tenant/admin',{'tenant':tid,'revision':t['revision'],'active':True,'plan_type':'monthly','monthly_cents':0,'lifetime_cents':0,'crm_plus':value})
    a.call('crm/contacts',tenant=tid,expected=403)
    anonymous.call('crm/contacts',tenant=tid,expected=401)
    t=a.call('workspace')['tenant'];a.call('tenant/save',{'tenant':tid,'revision':t['revision'],'settings':{'features':{'crmPlus':True}}},expected=403)
    enabled(True)
    data={'tenant':tid,'name':'=2+2','company':'Roofers','email':'person@example.test','phone':'0470000000','address':'Example','tags':['Vaste klant'],'request_key':secrets.token_hex(16)}
    a.call('crm/save',{**data,'email':'','phone':''},expected=422)
    r=a.call('crm/save',data);cid=r['id'];assert a.call('crm/save',data)['id']==cid
    b.call('crm/contact',tenant=tid,id=cid,expected=404)
    b.call('crm/export',tenant=tid,expected=404)
    b.call('crm/save',{**data,'id':cid,'revision':1},expected=404)
    c=a.call('crm/contact',tenant=tid,id=cid)['contact'];assert c['tags']==['Vaste klant']
    a.call('crm/save',{**data,'id':cid,'revision':c['revision'],'name':'Jan Peeters'})
    a.call('crm/save',{**data,'id':cid,'revision':c['revision']},expected=409)
    note={'tenant':tid,'contact':cid,'content':'Called about roof repairs','request_key':secrets.token_hex(16)}
    n=a.call('crm/note',note);assert a.call('crm/note',note)['id']==n['id']
    a.call('crm/note',{**note,'content':'Different'},expected=409)
    # Cross-tenant identifiers are rejected even when the destination module is active.
    bt=owner.call('workspace',tenant=other)['tenant'];owner.call('tenant/save',{'tenant':other,'revision':bt['revision'],'settings':{'features':{'crmPlus':True}}})
    b.call('crm/note',{**note,'tenant':other},expected=404)
    b.call('crm/contact',tenant=other,id=cid,expected=404)
    task={'tenant':tid,'contact':cid,'title':'Call back','due_date':datetime.date.today().isoformat(),'request_key':secrets.token_hex(16)}
    a.call('crm/task',{**task,'due_date':'2026-02-31'},expected=422)
    k=a.call('crm/task',task);assert a.call('crm/task',task)['id']==k['id']
    w=a.call('crm/contacts',tenant=tid,search='Jan',tag='Vaste klant');assert w['total']==1 and w['tasks'][0]['id']==k['id']
    b.call('crm/task',{'tenant':other,'id':k['id'],'revision':1,'completed':True},expected=404)
    a.call('crm/task',{'tenant':tid,'id':k['id'],'revision':1,'completed':True})
    a.call('crm/task',{'tenant':tid,'id':k['id'],'revision':1,'completed':False},expected=409)
    assert a.call('crm/contacts',tenant=tid)['tasks']==[]
    t=a.call('workspace')['tenant'];a.call('tenant/save',{'tenant':tid,'revision':t['revision'],'settings':{'intake':{'mode':'request','serviceSelection':False}}})
    lead={'tenant':tid,'name':'Jan Peeters','email':'person@example.test','phone':'0470000000','request_key':secrets.token_hex(16)}
    lid=anonymous.call('public/lead',lead,expected=201)['id']
    detail=a.call('crm/contact',tenant=tid,id=cid);assert len(detail['notes'])==1 and detail['leads'][0]['id']==lid
    assert a.call('crm/contacts',tenant=tid)['total']==1
    anonymous.call('public/lead',{**lead,'name':'Another person','request_key':secrets.token_hex(16)},expected=201)
    contacts=a.call('crm/contacts',tenant=tid)['contacts'];assert len(contacts)==2 and any(c['review_needed'] for c in contacts)
    assert a.call('crm/import',{'tenant':tid})['processed']==0
    # Formula injection is neutralized in a full, authenticated export, including after deactivation.
    current=a.call('crm/contact',tenant=tid,id=cid)['contact']
    a.call('crm/save',{**data,'id':cid,'revision':current['revision'],'name':'=2+2'})
    enabled(False)
    a.call('crm/contacts',tenant=tid,expected=403)
    a.call('crm/note',{**note,'request_key':secrets.token_hex(16)},expected=403)
    a.call('crm/task',{'tenant':tid,'id':k['id'],'revision':2,'completed':False},expected=403)
    def export(kind):
        from urllib.parse import urlsplit
        # Client's cookie jar is used; URL stays on the disposable local server.
        import os
        url=os.environ['API_BASE']+'/api/index.php?'+urllib.parse.urlencode({'route':'crm/export','tenant':tid,'kind':kind})
        r=a.opener.open(url);assert r.headers['Content-Type'].startswith('text/csv')
        return list(csv.reader(io.StringIO(r.read().decode('utf-8-sig')),delimiter=';'))
    rows=export('contacts');assert len(rows)==3 and any(r[1]=="'=2+2" for r in rows[1:])
    assert len(export('notes'))==2 and len(export('tasks'))==2
    before=len(messages);a.call('crm/request',{'tenant':tid});a.call('crm/request',{'tenant':tid});assert len(messages)==before+1
    assert any('CRM-uitbreiding' in c['message'] for c in owner.call('contacts')['contacts'])
    # Leads received while CRM is disabled are safely backfilled once enabled.
    a.call('lead/create',{'tenant':tid,'name':'Offline lead','phone':'0412345678','request_key':secrets.token_hex(16)},expected=201)
    enabled(True);assert a.call('crm/import',{'tenant':tid})['processed']==1
    assert a.call('crm/import',{'tenant':tid})['processed']==0
    assert a.call('crm/contacts',tenant=tid)['total']==3
    # Both direct bookings and internally scheduled leads retain the correct customer file.
    st=a.call('workspace')['tenant'];a.call('tenant/save',{'tenant':tid,'revision':st['revision'],'settings':{'notice':0,'hours':[{'open':True,'start':'09:00','end':'18:00'} for _ in range(7)],'intake':{'mode':'booking','serviceSelection':True}}})
    sid=a.call('service/save',{'tenant':tid,'name':'Visit','description':'Visit','duration':30,'buffer':0,'price':0,'active':True})['id']
    day=(datetime.date.today()+datetime.timedelta(days=4)).isoformat()
    booking=anonymous.call('public/book',{'tenant':tid,'service':sid,'date':day,'time':'10:00','name':'Booking Person','email':'booking-crm@example.test','phone':'0470123456','request_key':secrets.token_hex(16)},expected=201)
    bc=next(c for c in a.call('crm/contacts',tenant=tid)['contacts'] if c['email']=='booking-crm@example.test')
    assert a.call('crm/contact',tenant=tid,id=bc['id'])['bookings'][0]['id']==booking['id']
    lead_record=next(x for x in a.call('workspace')['leads'] if x['id']==lid)
    scheduled=a.call('lead/schedule',{'tenant':tid,'id':lid,'revision':lead_record['revision'],'service':sid,'date':day,'time':'12:00'},expected=201)
    assert any(b['id']==scheduled['id'] for b in a.call('crm/contact',tenant=tid,id=cid)['bookings'])
    print('PASS CRM paywall, tenant isolation, notes/tasks, duplicate review, auto-linking, idempotent import, export after deactivation and safe CSV',flush=True)
