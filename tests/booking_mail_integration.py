"""Company booking notifications, using only the local SMTP test sink."""
from pathlib import Path
import concurrent.futures
import datetime
import json
import os
import secrets
import subprocess

def run(Client,owner,anonymous,messages,envelopes,config,state):
    base=Path(os.environ['TEST_RUNTIME_USR']);api=Path(__file__).resolve().parent.parent/'api'
    def sql(statement):
        return subprocess.check_output([str(base/'bin/mariadb'),'--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=53306','--user=root','--batch','--skip-column-names','ab_backend_test','-e',statement],stderr=subprocess.DEVNULL).decode().strip()
    def cron():
        code=''.join('require '+json.dumps(str(api/name))+';' for name in ['bootstrap.php','mailer.php','lifecycle.php','company-notifications.php'])+'echo json_encode(run_company_notifications());'
        args=[str(base/'bin/php8.4'),'-n','-d','extension_dir='+str(base/'lib/php/20240924'),'-d','extension=pdo','-d','extension=mysqlnd','-d','extension=pdo_mysql','-d','openssl.cafile='+str(config.parent/'smtp-cert.pem'),'-r',code]
        return json.loads(subprocess.check_output(args,env={**os.environ,'AFSPRAAKBOEKEN_CONFIG':str(config)}))
    sql('DELETE FROM ab_rate_limits')
    created=owner.call('tenants/create',{'name':'Technics Test','admin_name':'Owner','email':'technics-owner@example.test','sector':'Test'},expected=201)
    tid=created['tenant_id'];t=owner.call('workspace',tenant=tid)['tenant']
    owner.call('tenant/admin',{'tenant':tid,'revision':t['revision'],'active':True,'plan_type':'monthly','monthly_cents':0,'lifetime_cents':0,'billing':{'email':'billing-not-recipient@example.test'}})
    t=owner.call('workspace',tenant=tid)['tenant']
    owner.call('tenant/save',{'tenant':tid,'revision':t['revision'],'settings':{'notice':0,'hours':[{'open':True,'start':'08:00','end':'18:00'} for _ in range(7)],'features':{'address':True,'notes':True}}})
    sid=owner.call('service/save',{'tenant':tid,'name':'Onderhoud airco','description':'Test','duration':30,'buffer':15,'price':90,'active':True,'image':'','video':''})['id']
    day=(datetime.date.today()+datetime.timedelta(days=4)).isoformat()
    payload={'tenant':tid,'service':sid,'date':day,'time':'10:00','name':'Klant <script>x</script>','email':'booking-person@example.test','phone':'0400000000','address':'Teststraat 12','notes':'Graag bellen voor aankomst.','marketing_consent':False,'request_key':secrets.token_hex(16),'to':'wrong@example.test'}
    before=len(messages);first=anonymous.call('public/book',payload,expected=201)
    assert len(messages)==before+1 and envelopes[-1]==['technics-owner@example.test']
    msg=messages[-1];plain=msg.get_body(('plain',)).get_content();html=msg.get_body(('html',)).get_content()
    assert 'Nieuwe lead: afspraak geboekt' in str(msg['Subject'])
    for value in ['Onderhoud airco','0400000000','booking-person@example.test','Teststraat 12','Graag bellen voor aankomst.','10:00',datetime.date.fromisoformat(day).strftime('%d/%m/%Y')]:assert value in plain and value in html
    assert '<script>' not in html and '&lt;script&gt;' in html
    assert str(msg['Reply-To'])=='booking-person@example.test' and 'Wycked' not in plain+html
    assert 'view=bookings' in plain and 'Afspraakboeken.be' in str(msg['From'])
    assert anonymous.call('public/book',payload,expected=201)['id']==first['id'] and len(messages)==before+1
    with concurrent.futures.ThreadPoolExecutor(3) as pool:
        list(pool.map(lambda _:Client().call('public/book',payload,expected=201),range(3)))
    assert len(messages)==before+1
    assert sql(f"SELECT status FROM ab_company_notifications WHERE object_id='{first['id']}'")=='sent'
    state['reject']=True
    second=anonymous.call('public/book',{**payload,'time':'12:00','request_key':secrets.token_hex(16)},expected=201)
    state['reject']=False
    assert sql(f"SELECT status FROM ab_company_notifications WHERE object_id='{second['id']}'")=='failed'
    assert any(b['id']==second['id'] for b in owner.call('workspace',tenant=tid)['bookings'])
    sql("UPDATE ab_company_notifications SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE status IN ('pending','failed','not_configured')")
    sql(f"UPDATE ab_company_notifications SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE object_id='{second['id']}'")
    before=len(messages);cron();assert len(messages)==before+1 and envelopes[-1]==['technics-owner@example.test']
    cron();assert len(messages)==before+1
    assert sql(f"SELECT status FROM ab_company_notifications WHERE object_id='{second['id']}'")=='sent'
    assert str(messages[-1]['Message-ID'])==f"<company-booking-{second['id']}@127.0.0.1>"
    # A worker crash after possible SMTP acceptance must not cause automatic duplicates.
    sql(f"UPDATE ab_company_notifications SET status='sending',attempted_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE) WHERE object_id='{second['id']}'")
    cron();assert len(messages)==before+1
    assert sql(f"SELECT status FROM ab_company_notifications WHERE object_id='{second['id']}'")=='uncertain'
    assert owner.call('workspace',tenant=tid)['notification_mail']['attention']==1
    print('PASS booking mail recipient/details, reply-to, HTML escaping, replay/concurrency deduplication, durable SMTP failure, cron retry and ambiguous-send protection',flush=True)
