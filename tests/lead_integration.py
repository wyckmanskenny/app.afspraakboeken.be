"""Company lead journeys; run only within the disposable SMTP/API suite."""
import concurrent.futures
import datetime
import os
from pathlib import Path
import secrets
import subprocess

def run(Client,owner,anonymous,messages,envelopes,config,state):
    base=Path(os.environ['TEST_RUNTIME_USR'])
    def sql(statement):
        return subprocess.check_output([str(base/'bin/mariadb'),'--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=53306','--user=root','--batch','--skip-column-names','ab_backend_test','-e',statement],stderr=subprocess.DEVNULL).decode().strip()
    password='Only-for-integration-tests-123!'
    def create(email):
        c=owner.call('tenants/create',{'name':'Lead Company','admin_name':'Owner','email':email,'sector':'Test'},expected=201)
        anonymous.call('invite/accept',{'token':c['invite_url'].split('#')[1],'password':password})
        t=owner.call('workspace',tenant=c['tenant_id'])['tenant']
        owner.call('tenant/admin',{'tenant':t['id'],'revision':t['revision'],'active':True,'plan_type':'monthly','monthly_cents':0,'lifetime_cents':0})
        client=Client();client.login(email);return t['id'],client
    tenant,a=create('lead-owner@example.test');other,b=create('lead-other@example.test')
    def settings(values,client=a,tid=tenant):
        t=client.call('workspace',tenant=tid)['tenant']
        client.call('tenant/save',{'tenant':tid,'revision':t['revision'],'settings':values})
    def intake(mode='request',selection=False): settings({'intake':{'mode':mode,'serviceSelection':selection}})
    intake();settings({'notice':0,'hours':[{'open':True,'start':'09:00','end':'18:00'} for _ in range(7)]})
    # Lead collection is self-managed; it does not grant permission to enable agency modules.
    t=owner.call('workspace',tenant=tenant)['tenant']
    owner.call('tenant/save',{'tenant':tenant,'revision':t['revision'],'settings':{'features':{'crm':False,'tracking':True}}})
    catalog=anonymous.call('public/catalog',tenant=tenant)
    assert catalog['tenant']['intake']=={'mode':'request','serviceSelection':False} and catalog['services']==[]
    lead={'tenant':tenant,'name':'Lead <script>Person</script>','email':'lead-person@example.test','phone':'','notes':'Please call about a quote','request_key':secrets.token_hex(16),'marketing_consent':False,'source':'facebook','campaign':'private'}
    anonymous.call('public/lead',{**lead,'website':'spam'},expected=422)
    before=len(messages);first=anonymous.call('public/lead',lead,expected=201)
    assert first['status']=='received' and first['replayed'] is False
    assert len(messages)==before+1 and envelopes[-1]==['lead-owner@example.test']
    assert str(messages[-1]['Reply-To'])=='lead-person@example.test'
    assert '<script>' not in messages[-1].get_body(('html',)).get_content()
    assert anonymous.call('public/lead',lead,expected=201)['id']==first['id'] and len(messages)==before+1
    anonymous.call('public/lead',{**lead,'name':'Changed'},expected=409)
    w=a.call('workspace');l=w['leads'][0]
    assert l['service'] is None and l['source']=='website' and l['campaign']=='' and not w['bookings']
    assert b.call('workspace')['leads']==[]
    b.call('lead/update',{'tenant':other,'id':l['id'],'revision':l['revision'],'stage':'done'},expected=404)
    b.call('lead/update',{'tenant':tenant,'id':l['id'],'revision':l['revision'],'stage':'done'},expected=404)
    anonymous.call('lead/update',{'tenant':tenant,'id':l['id']},expected=401)
    a.call('lead/update',{'tenant':tenant,'id':l['id'],'revision':l['revision'],'stage':'contacted','internalNotes':'Called, quote sent'})
    a.call('lead/update',{'tenant':tenant,'id':l['id'],'revision':l['revision'],'stage':'done'},expected=409)
    assert a.call('workspace')['leads'][0]['internalNotes']=='Called, quote sent'
    assert not any(x['email']=='lead-person@example.test' for x in owner.call('contacts')['contacts'])
    print('PASS contact-only leads, tenant isolation, separate agency inbox, consent, SMTP and retry deduplication',flush=True)
    service={'name':'Advice','description':'Advice for your business','duration':30,'buffer':30,'price':50,'active':True}
    sid=a.call('service/save',{**service,'tenant':tenant})['id'];foreign=b.call('service/save',{**service,'tenant':other})['id']
    intake(selection=True)
    anonymous.call('public/lead',{**lead,'email':'required@example.test','request_key':secrets.token_hex(16)},expected=422)
    anonymous.call('public/lead',{**lead,'email':'foreign@example.test','service':foreign,'request_key':secrets.token_hex(16)},expected=422)
    selected=anonymous.call('public/lead',{**lead,'service':sid,'email':'selected@example.test','request_key':secrets.token_hex(16),'marketing_consent':True},expected=201)
    selected_lead=next(x for x in a.call('workspace')['leads'] if x['id']==selected['id'])
    assert selected_lead['service']==sid and selected_lead['source']=='facebook' and selected_lead['campaign']=='private'
    tomorrow=(datetime.date.today()+datetime.timedelta(days=3)).isoformat()
    booking={'tenant':tenant,'service':sid,'date':tomorrow,'time':'10:00','name':'Booker','email':'booker@example.test','phone':'0400000000','request_key':secrets.token_hex(16)}
    anonymous.call('public/book',booking,expected=409)
    anonymous.call('public/slots',tenant=tenant,service=sid,date=tomorrow,expected=409)
    assert '10:00' in a.call('lead/slots',tenant=tenant,id=l['id'],service=sid,date=tomorrow)['times']
    intake('booking')
    anonymous.call('public/lead',{**lead,'email':'stale@example.test','request_key':secrets.token_hex(16)},expected=409)
    assert len(a.call('workspace')['leads'])==2 and not a.call('workspace')['bookings']
    l=next(x for x in a.call('workspace')['leads'] if x['id']==l['id'])
    plan={'tenant':tenant,'id':l['id'],'revision':l['revision'],'service':sid,'date':tomorrow,'time':'10:00'}
    b.call('lead/slots',tenant=other,id=l['id'],service=foreign,date=tomorrow,expected=404)
    b.call('lead/schedule',{**plan,'tenant':other},expected=404)
    a.call('lead/schedule',{**plan,'service':foreign},expected=404)
    def schedule(_):
        c=Client();c.login('lead-owner@example.test');return c.call('lead/schedule',plan,expected=201)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: results=list(pool.map(schedule,range(2)))
    assert results[0]['id']==results[1]['id'] and sum(not r['replayed'] for r in results)==1
    w=a.call('workspace');linked=next(x for x in w['leads'] if x['id']==l['id']);bkg=w['bookings'][0]
    assert len(w['bookings'])==1 and linked['bookingId']==bkg['id'] and linked['stage']=='contacted'
    assert linked['internalNotes']=='Called, quote sent' and bkg['notes']==lead['notes']
    a.call('lead/schedule',{**plan,'time':'11:00'},expected=409)
    remaining=anonymous.call('public/slots',tenant=tenant,service=sid,date=tomorrow)['times']
    assert '10:00' not in remaining and '10:30' not in remaining and '11:00' in remaining
    print('PASS optional service selection, stale public forms, internal scheduling, buffer and duplicate scheduling lock',flush=True)
    # A public booking and an internal lead scheduling attempt compete for the same tenant/time lock.
    plan2={**plan,'id':selected_lead['id'],'revision':selected_lead['revision'],'time':'12:00'}
    def competing(kind):
        c=Client()
        if kind=='lead':c.login('lead-owner@example.test')
        try:return c.call('lead/schedule' if kind=='lead' else 'public/book',plan2 if kind=='lead' else {**booking,'time':'12:00','request_key':secrets.token_hex(16)},expected=201)
        except AssertionError as e:
            assert e.args[0][2]==409,e
            return None
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool: results=list(pool.map(competing,['lead','booking']))
    assert sum(x is not None for x in results)==1
    intake()
    state['reject']=True
    failed=anonymous.call('public/lead',{**lead,'email':'failed-company@example.test','request_key':secrets.token_hex(16)},expected=201)
    state['reject']=False
    assert sql(f"SELECT action FROM ab_audit WHERE target_id='{failed['id']}' AND action LIKE 'lead.mail_%'")=='lead.mail_failed'
    failed_lead=next(x for x in a.call('workspace')['leads'] if x['id']==failed['id'])
    a.call('lead/update',{'tenant':tenant,'id':failed['id'],'revision':failed_lead['revision'],'stage':'done'})
    a.call('lead/schedule',{**plan,'id':failed['id'],'revision':failed_lead['revision']+1,'time':'14:00'},expected=409)
    sql(f"INSERT INTO ab_trials(tenant_id,starts_at,ends_at) VALUES ('{tenant}',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY))")
    anonymous.call('public/lead',{**lead,'email':'expired-lead@example.test','request_key':secrets.token_hex(16)},expected=404)
    a.call('lead/update',{'tenant':tenant,'id':failed['id'],'revision':failed_lead['revision']+1,'stage':'new'},expected=402)
    a.call('lead/schedule',{**plan,'id':failed['id'],'revision':failed_lead['revision']+1,'time':'14:00'},expected=402)
    print('PASS public/internal reservation race, closing without booking, SMTP failure durability and expired-trial restrictions',flush=True)
