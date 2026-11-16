"""Local-only trial cases, invoked by mail-integration.py with its TLS mail sink."""
from pathlib import Path
import datetime as dt
import json
import os
import re
import subprocess

def run(Client,owner,anonymous,messages,envelopes,config,state):
    base=Path(os.environ['TEST_RUNTIME_USR']).resolve()
    assert config.is_relative_to(Path(__file__).resolve().parent/'.runtime')
    def sql(statement):
        return subprocess.check_output([str(base/'bin/mariadb'),'--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=53306','--user=root','--batch','--skip-column-names','ab_backend_test','-e',statement],stderr=subprocess.DEVNULL).decode().strip()
    def cron():
        args=[str(base/'bin/php8.4'),'-n','-d','extension_dir='+str(base/'lib/php/20240924'),'-d','extension=pdo','-d','extension=mysqlnd','-d','extension=pdo_mysql','-d','openssl.cafile='+str(config.parent/'smtp-cert.pem'),str(Path(__file__).resolve().parent.parent/'api/cron-trials.php')]
        result=subprocess.run(args,env={**os.environ,'AFSPRAAKBOEKEN_CONFIG':str(config)},capture_output=True,text=True,check=True)
        return json.loads(result.stdout)
    start_count=len(owner.call('tenants')['tenants'])
    sql("UPDATE ab_sessions s JOIN ab_users u ON u.id=s.user_id SET s.last_seen=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE u.email='owner@example.test'")
    last_seen=sql("SELECT MAX(s.last_seen) FROM ab_sessions s JOIN ab_users u ON u.id=s.user_id WHERE u.email='owner@example.test'")
    owner.call('notifications')
    assert sql("SELECT MAX(s.last_seen) FROM ab_sessions s JOIN ab_users u ON u.id=s.user_id WHERE u.email='owner@example.test'")==last_seen
    signup={'name':'Trial Owner','company':'Trial <b>Company</b>','email':'trial@example.test','sector':'Zakelijke dienstverlening','website':''}
    assert anonymous.call('health')['trial_available'] is True
    anonymous.call('trial/signup',signup,expected=201)
    assert envelopes[-1]==['trial@example.test'] and len(owner.call('tenants')['tenants'])==start_count
    token=re.search(r'#trial=([a-f0-9]{64})',messages[-1].get_body(('plain',)).get_content())[1]
    assert anonymous.call('trial/check',{'token':token})['trial'] is True
    created=anonymous.call('trial/accept',{'token':token,'password':'Only-for-integration-tests-123!'})
    anonymous.call('trial/accept',{'token':token,'password':'Only-for-integration-tests-123!'},expected=400)
    tenant_id=created['tenant_id'];tenant=owner.call('workspace',tenant=tenant_id)['tenant']
    trial=tenant['trial'];assert (dt.datetime.fromisoformat(trial['ends_at'])-dt.datetime.fromisoformat(trial['starts_at'])).days==14
    assert tenant['active'] is True and trial['converted_at'] is None
    before=len(messages);anonymous.call('trial/signup',signup,expected=201)
    assert len(messages)==before and len(owner.call('tenants')['tenants'])==start_count+1
    client=Client();client.login('trial@example.test');workspace=client.call('workspace')
    assert workspace['tenant']['trial']['days_left']==14 and 'subscription' not in workspace['tenant']
    client.call('notifications',expected=403);anonymous.call('notifications',expected=401)
    print('PASS mailbox verification creates exactly one account and starts 14 real days',flush=True)

    service=client.call('service/save',{'tenant':tenant_id,'name':'Advice','category':'','description':'Trial test','duration':30,'buffer':0,'price':0,'active':True,'image':'','video':''})
    anonymous.call('public/catalog',tenant=tenant_id)
    for days in (3,2,1,0):
        sql(f"UPDATE ab_trials SET ends_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {days} DAY) WHERE tenant_id='{tenant_id}'")
        if days==3:
            state['reject']=True;assert cron()['failed']==1
            assert cron()['failed']==0
            sql(f"UPDATE ab_trial_mail SET attempted_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR) WHERE tenant_id='{tenant_id}'")
            state['reject']=False
        before=len(messages);result=cron();assert result['sent']==1,(days,result)
        assert len(messages)==before+1 and envelopes[-1]==['trial@example.test']
        assert '?view=subscription' in messages[-1].get_body(('plain',)).get_content()
        assert cron()['sent']==0 and len(messages)==before+1
    sql(f"UPDATE ab_trials SET ends_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE tenant_id='{tenant_id}'")
    assert client.call('workspace')['tenant']['trial']['expired'] is True
    client.call('service/save',{'tenant':tenant_id},expected=402)
    anonymous.call('public/catalog',tenant=tenant_id,expected=404)
    anonymous.call('public/slots',tenant=tenant_id,service=service['id'],date='2026-12-01',expected=404)
    client2=Client();client2.login('trial@example.test')
    print('PASS reminder milestones 3/2/1/0 deliver once; expiry blocks booking and writes without cron',flush=True)

    before=owner.call('notifications')['new_requests']
    request={'confirmed':True,'legalName':'Trial Company','billingEmail':'billing-trial@example.test','address':'Teststraat 1, Brussel','vatNumber':''}
    client.call('trial/upgrade',{**request,'confirmed':False},expected=422)
    client.call('trial/upgrade',request)
    assert envelopes[-1]==['kenny@kdesign.be']
    assert owner.call('notifications')['new_requests']==before+1
    sent=len(messages);client.call('trial/upgrade',request);assert len(messages)==sent
    assert owner.call('notifications')['new_requests']==before+1
    trial=owner.call('workspace',tenant=tenant_id)['tenant'];assert trial['subscription']['billing']['email']=='billing-trial@example.test'
    client.call('trial/convert',{'tenant':tenant_id,'confirmed':True},expected=403)
    owner.call('trial/convert',{'tenant':tenant_id,'confirmed':False},expected=422)
    owner.call('trial/convert',{'tenant':tenant_id,'confirmed':True})
    assert owner.call('notifications')['new_requests']==before
    assert client.call('workspace')['tenant']['trial']['converted_at'] is not None
    anonymous.call('public/catalog',tenant=tenant_id)
    sent=len(messages);assert cron()['sent']==0 and len(messages)==sent
    owner.call('trial/convert',{'tenant':tenant_id,'confirmed':True})
    assert sql(f"SELECT COUNT(*) FROM ab_audit WHERE action='trial.converted' AND target_id='{tenant_id}'")=='1'
    print('PASS activation request, badge count, separate billing, role checks and manual conversion',flush=True)
