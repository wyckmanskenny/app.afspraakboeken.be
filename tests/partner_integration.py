"""Partner authorization and referral tests; disposable database + loopback mail only."""
import secrets,re,os,subprocess
from urllib.parse import urlparse,parse_qs
from datetime import datetime,timedelta
from zoneinfo import ZoneInfo
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
from threading import Barrier

def run(Client,owner,anonymous,messages):
    password='Only-for-integration-tests-123!'
    def sql(s):
        base=Path(os.environ['TEST_RUNTIME_USR'])
        return subprocess.check_output([str(base/'bin/mariadb'),'--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=53306','--user=root','--batch','--skip-column-names','ab_backend_test','-e',s],stderr=subprocess.DEVNULL).decode().strip()
    sql('DELETE FROM ab_rate_limits')
    # One-step onboarding is atomic and retry-safe; contact data is not a login rename.
    onboard={'name':'Onboarding <script>partner</script>','contact_name':'Contact <img src=x>',
             'email':'partner-onboarding@example.test','phone':'+32 470 12 34 56',
             'commission_percent':'20.25','send_invite':True,'request_key':secrets.token_hex(16),
             'active':True,'can_create':True,'can_activate':False}
    before=len(messages)
    bad=owner.csrf;owner.csrf='wrong'
    owner.call('partners/save',onboard,expected=403);owner.csrf=bad
    anonymous.call('partners/save',onboard,expected=401)
    saved=owner.call('partners/save',onboard);onboard_id=saved['id']
    assert saved['mail']=='sent' and len(messages)==before+1
    assert 'Welkom partner bij Afspraakboeken' in str(messages[-1]['Subject'])
    html=messages[-1].get_body(('html',)).get_content()
    assert '<script>' not in html and '<img src=x>' not in html
    assert owner.call('partners/save',onboard)['id']==onboard_id
    assert len(messages)==before+1, 'A retried partner creation must not send a second welcome mail'
    owner.call('partners/save',{**onboard,'name':'Changed request'},expected=409)
    detail=owner.call('partners/detail',id=onboard_id)
    assert len(detail['members'])==1 and detail['members'][0]['email']==onboard['email']
    assert detail['partner']['contact_name']==onboard['contact_name']
    assert detail['partner']['phone']==onboard['phone']
    assert detail['partner']['commission_bps']==2025
    for invalid in ['-1','100.01','1.001','not-a-rate']:
        owner.call('partners/save',{**detail['partner'],'commission_percent':invalid},expected=422)
    token=saved['invite_url'].split('#invite=')[1]
    anonymous.call('partner/invite-check',{'token':token})
    anonymous.call('partner/invite-accept',{'token':token,'password':password})
    anonymous.call('partner/invite-accept',{'token':token,'password':password},expected=400)
    joined=Client();joined.csrf=joined.call('partner/login',{'email':onboard['email'],'password':password})['csrf']
    changed=owner.call('partners/detail',id=onboard_id)['partner']
    owner.call('partners/save',{**changed,'email':'partner-new-contact@example.test','commission_percent':'25'})
    detail=owner.call('partners/detail',id=onboard_id)
    assert detail['partner']['email']=='partner-new-contact@example.test'
    assert detail['members'][0]['email']==onboard['email'], 'Changing contact email must not take over a login'
    joined.call('partner/me')
    joined.call('partner/login',{'email':'partner-new-contact@example.test','password':password},expected=401)
    link=joined.call('partner/trial-link',{'campaign':'open-deur-november'})['url']
    query=parse_qs(urlparse(link).query)
    assert query['partner']==[detail['partner']['referral_code']] and query['mode']==['trial']
    assert onboard['email'] not in link and onboard['contact_name'] not in link
    assert query.get('utm_campaign'), 'Campaign links should identify the campaign without customer data'
    joined.call('partner/trial-link',{'campaign':'a'*61},expected=422)
    bad=joined.csrf;joined.csrf='wrong';joined.call('partner/trial-link',{},expected=403);joined.csrf=bad
    anonymous.call('partner/trial-link',{},expected=401)
    # Duplicate login addresses cannot leave behind an uninvitable partner company.
    count=sql('SELECT COUNT(*) FROM ab_partners')
    owner.call('partners/save',{**onboard,'request_key':secrets.token_hex(16)},expected=409)
    assert sql('SELECT COUNT(*) FROM ab_partners')==count
    # Separate sessions exercise real concurrent HTTP requests against the multiworker test server.
    simultaneous_owners=[Client(),Client()]
    for client in simultaneous_owners:client.login('owner@example.test')
    def parallel(route,data=None):
        barrier=Barrier(2)
        def invoke(client):
            barrier.wait(timeout=10)
            return client.call(route,data)
        with ThreadPoolExecutor(max_workers=2) as executor:
            return list(executor.map(invoke,simultaneous_owners))
    concurrent_partner={**onboard,'name':'Concurrent partner','contact_name':'Concurrent contact',
                        'email':'partner-concurrent@example.test','request_key':secrets.token_hex(16)}
    before=len(messages)
    results=parallel('partners/save',concurrent_partner)
    assert len({result['id'] for result in results})==1
    assert sorted(result['replayed'] for result in results)==[False,True]
    concurrent_id=results[0]['id']
    assert sql(f"SELECT COUNT(*) FROM ab_partners WHERE id='{concurrent_id}'")=='1'
    assert sql(f"SELECT COUNT(*) FROM ab_partner_members WHERE partner_id='{concurrent_id}'")=='1'
    assert len(messages)==before+1, 'Concurrent onboarding may send only one welcome mail'
    pid=owner.call('partners/save',{'name':'Partner <script>A</script>','email':'partner-office@example.test','active':True,'can_create':True,'can_activate':False})['id']
    other=owner.call('partners/save',{'name':'Partner B','email':'partner-b@example.test','active':True,'can_create':True,'can_activate':True})['id']
    def member(pid,email):
        r=owner.call('partners/member',{'partner':pid,'name':'Sales Person','email':email})
        assert r['mail']=='sent' and '/partners/#invite=' in r['invite_url']
        token=r['invite_url'].split('#invite=')[1]
        anonymous.call('invite/check',{'token':token},expected=400)
        anonymous.call('partner/invite-check',{'token':token})
        anonymous.call('partner/invite-accept',{'token':token,'password':password})
        anonymous.call('partner/invite-accept',{'token':token,'password':password},expected=400)
        c=Client();c.csrf=c.call('partner/login',{'email':email,'password':password})['csrf'];return c
    p=member(pid,'partner-a@example.test');q=member(other,'partner-b-user@example.test')
    p.call('workspace',expected=401);p.call('partners/list',expected=401)
    p.call('login',{'email':'partner-a@example.test','password':password},expected=403)
    before=len(messages);payload={'name':'Partner Customer','admin_name':'Customer','email':'partner-customer@example.test','sector':'Test','confirmed':True,'request_key':secrets.token_hex(16)}
    bad=p.csrf;p.csrf='wrong';p.call('partner/customer-create',payload,expected=403);p.csrf=bad
    created=p.call('partner/customer-create',payload,expected=201);tid=created['tenant_id'];assert 'invite_url' not in created and len(messages)==before+1
    token=re.search(r'/activate.html#([a-f0-9]{64})',messages[-1].get_body(('plain',)).get_content()).group(1)
    anonymous.call('invite/accept',{'token':token,'password':password})
    assert p.call('partner/customer-create',payload,expected=201)['tenant_id']==tid and len(messages)==before+1
    p.call('partner/customer-create',{**payload,'name':'Changed'},expected=409)
    dashboard=p.call('partner/me');assert len(dashboard['accounts'])==1
    assert not any(k in dashboard['accounts'][0] for k in ['settings','bookings','billing','services','password_hash'])
    assert q.call('partner/me')['accounts']==[]
    q.call('partner/activation',{'tenant':tid,'activate':True,'confirmed':True},expected=404)
    p.call('partner/activation',{'tenant':tid,'activate':True,'confirmed':True},expected=403)
    p.call('partner/activation',{'tenant':tid,'activate':False,'confirmed':True})
    customer=Client();customer.call('login',{'email':payload['email'],'password':password},expected=403)
    owner.call('partners/approve',{'partner':pid,'tenant':tid,'confirmed':True})
    customer.login(payload['email']);customer.call('workspace')
    customer.call('partners/save',{'name':'Bad','email':'bad@example.test'},expected=403)
    customer.call('partner/login',{'email':payload['email'],'password':password},expected=401)
    assert owner.call('partners/detail',id=pid)['accounts'][0]['activation_requested_at'] is None
    # Referral survives email verification; a second partner cannot claim an existing account.
    code=p.call('partner/me')['partner']['referral_code'];code2=q.call('partner/me')['partner']['referral_code']
    assert anonymous.call('public/partner',code=code)['name']=='Partner <script>A</script>'
    trial={'name':'Referral Customer','company':'Referral company','email':'partner-referral@example.test','sector':'Test','partner':code}
    anonymous.call('trial/signup',trial,expected=201)
    trialtoken=re.search(r'/activate.html#trial=([a-f0-9]{64})',messages[-1].get_body(('plain',)).get_content()).group(1)
    result=anonymous.call('trial/accept',{'token':trialtoken,'password':password});refid=result['tenant_id']
    assert any(a['tenant_id']==refid for a in p.call('partner/me')['accounts'])
    anonymous.call('trial/signup',{**trial,'partner':code2},expected=201)
    assert not any(a['tenant_id']==refid for a in q.call('partner/me')['accounts'])
    anonymous.call('contact',{'name':'Information','company':'Info company','email':'partner-info@example.test','phone':'','sector':'Test','message':'Info please','partner':code},expected=201)
    assert len(p.call('partner/me')['enquiries'])==1 and q.call('partner/me')['enquiries']==[]
    # Only confirmed subscription receipts generate earnings; active accounts and trials do not.
    assert sql('SELECT COUNT(*) FROM ab_partner_commissions')=='0'
    today=datetime.now(ZoneInfo('Europe/Brussels')).date()
    previous=today.replace(day=1)-timedelta(days=1)
    def set_rate(value):
        settings=owner.call('partners/detail',id=pid)['partner']
        owner.call('partners/save',{**settings,'commission_percent':value})
    def receipt(reference,base=10001,day=previous):
        return {'partner':pid,'tenant':tid,'invoice_reference':reference,'month':day.strftime('%Y-%m'),
                'received_on':day.isoformat(),'base_cents':base,'expected_rate_bps':owner.call('partners/detail',id=pid)['partner']['commission_bps'],'confirmed':True,'request_key':secrets.token_hex(16)}
    first=receipt('PARTNER-INV-001')
    anonymous.call('partners/commission-record',first,expected=401)
    p.call('partners/commission-record',first,expected=401)
    customer.call('partners/commission-record',first,expected=403)
    bad=owner.csrf;owner.csrf='wrong';owner.call('partners/commission-record',first,expected=403);owner.csrf=bad
    owner.call('partners/commission-record',{**first,'partner':other},expected=404)
    owner.call('partners/commission-record',{**first,'tenant':refid},expected=422)
    owner.call('partners/commission-record',{**first,'confirmed':False},expected=422)
    owner.call('partners/commission-record',{**first,'base_cents':0},expected=422)
    owner.call('partners/commission-record',{**first,'base_cents':100000001},expected=422)
    owner.call('partners/commission-record',{**first,'base_cents':1.5},expected=422)
    tomorrow=today+timedelta(days=1)
    owner.call('partners/commission-record',{**first,'month':tomorrow.strftime('%Y-%m'),'received_on':tomorrow.isoformat()},expected=422)
    owner.call('partners/commission-record',{**first,'month':today.strftime('%Y-%m')},expected=422)
    set_rate('20.25')
    owner.call('partners/commission-record',first,expected=409)
    first['expected_rate_bps']=2025
    owner.call('partners/commission-record',{key:value for key,value in first.items() if key!='expected_rate_bps'},expected=422)
    concurrent_receipts=parallel('partners/commission-record',first)
    assert len({result['id'] for result in concurrent_receipts})==1
    assert sorted(result['replayed'] for result in concurrent_receipts)==[False,True]
    earned=next(result for result in concurrent_receipts if not result['replayed'])
    assert earned['amount_cents']==2025 and earned['rate_bps']==2025 and earned['status']=='approved'
    replay=owner.call('partners/commission-record',first)
    assert replay['id']==earned['id'] and replay['replayed']
    alternate_key=owner.call('partners/commission-record',{**first,'request_key':secrets.token_hex(16)})
    assert alternate_key['id']==earned['id'] and alternate_key['replayed']
    owner.call('partners/commission-record',{**first,'base_cents':20000},expected=409)
    owner.call('partners/commission-record',{**first,'request_key':secrets.token_hex(16),'base_cents':20000},expected=409)
    assert sql('SELECT COUNT(*) FROM ab_partner_commissions')=='1'
    # Full annual receipts count once in their actual receipt month, without invented monthly income.
    sql(f"UPDATE ab_tenants SET plan_type='annual' WHERE id='{tid}'")
    annual=owner.call('partners/commission-record',receipt('PARTNER-ANNUAL-001',18000,today))
    assert annual['amount_cents']==3645 and annual['rate_bps']==2025
    sql(f"UPDATE ab_tenants SET plan_type='monthly' WHERE id='{tid}'")
    # Round half up in integer cents; later rate changes never recalculate old rows.
    set_rate('12.50')
    half=owner.call('partners/commission-record',receipt('PARTNER-HALF-CENT',4,today))
    assert half['amount_cents']==1 and half['rate_bps']==1250
    set_rate('100')
    full=owner.call('partners/commission-record',receipt('PARTNER-FULL-RATE',99,today))
    assert full['amount_cents']==99 and full['rate_bps']==10000
    set_rate('0')
    zero=owner.call('partners/commission-record',receipt('PARTNER-ZERO-RATE',10000,today))
    assert zero['amount_cents']==0 and zero['rate_bps']==0
    again=owner.call('partners/commission-record',first)
    assert again['id']==earned['id'] and again['rate_bps']==2025 and again['amount_cents']==2025
    report=p.call('partner/me')
    rows={entry['id']:entry for entry in report['commissions']['entries']}
    assert rows[earned['id']]['rate_bps']==2025 and rows[annual['id']]['rate_bps']==2025
    assert not any(key in rows[earned['id']] for key in ['password_hash','terms_json','billing','settings','recorded_by'])
    periods={entry['month']:entry for entry in report['commissions']['months']}
    assert periods[previous.strftime('%Y-%m')]['amount_cents']==2025
    assert periods[today.strftime('%Y-%m')]['amount_cents']==3745
    assert report['summary']['month']==today.strftime('%Y-%m') and report['summary']['month_commission_cents']==3745
    assert report['summary']['running_trials']==1 and report['summary']['active_customers']==1
    assert q.call('partner/me')['commissions']['entries']==[]
    assert q.call('partner/me')['summary']['unpaid_commission_cents']==0
    # Payout recording is separately authorized and immutable; a void never removes history.
    paid={'partner':pid,'id':earned['id'],'status':'paid','paid_on':today.isoformat(),'confirmed':True}
    p.call('partners/commission-status',paid,expected=401)
    customer.call('partners/commission-status',paid,expected=403)
    owner.call('partners/commission-status',{**paid,'partner':other},expected=404)
    owner.call('partners/commission-status',{**paid,'confirmed':False},expected=422)
    owner.call('partners/commission-status',{**paid,'paid_on':(previous-timedelta(days=1)).isoformat()},expected=422)
    owner.call('partners/commission-status',paid)
    assert owner.call('partners/commission-status',paid)['replayed']
    owner.call('partners/commission-status',{**paid,'paid_on':previous.isoformat()},expected=409)
    owner.call('partners/commission-status',{**paid,'status':'void'},expected=409)
    void={'partner':pid,'id':annual['id'],'status':'void','confirmed':True}
    owner.call('partners/commission-status',void)
    assert owner.call('partners/commission-status',void)['replayed']
    owner.call('partners/commission-status',{**void,'status':'paid','paid_on':today.isoformat()},expected=409)
    report=p.call('partner/me')
    assert report['summary']['paid_commission_cents']==2025
    assert report['summary']['unpaid_commission_cents']==100
    assert report['summary']['month_commission_cents']==100
    assert next(entry for entry in report['commissions']['entries'] if entry['id']==annual['id'])['status']=='void'
    commission_count=sql('SELECT COUNT(*) FROM ab_partner_commissions')
    # Re-run a completed additive migration concurrently; established rows and totals must survive unchanged.
    before_migration=report['commissions'];partner_count=sql('SELECT COUNT(*) FROM ab_partners')
    sql("DELETE FROM ab_audit WHERE action='schema.partners_v2'")
    assert all(result['ready'] for result in parallel('health'))
    assert sql("SELECT COUNT(*) FROM ab_audit WHERE action='schema.partners_v2'")=='1'
    assert sql('SELECT COUNT(*) FROM ab_partners')==partner_count
    assert sql('SELECT COUNT(*) FROM ab_partner_commissions')==commission_count
    assert p.call('partner/me')['commissions']==before_migration
    # Rights updates apply to live sessions, and pausing a partner leaves its customers intact.
    detail=owner.call('partners/detail',id=pid);partner=detail['partner']
    owner.call('partners/save',{**partner,'can_create':False,'can_activate':True})
    p.call('partner/customer-create',{**payload,'email':'denied@example.test','request_key':secrets.token_hex(16)},expected=403)
    p.call('partner/activation',{'tenant':refid,'activate':True,'confirmed':True})
    assert sql(f"SELECT COUNT(*) FROM ab_trials WHERE tenant_id='{refid}' AND converted_at IS NOT NULL")=='1'
    assert sql('SELECT COUNT(*) FROM ab_partner_commissions')==commission_count
    partner=owner.call('partners/detail',id=pid)['partner'];owner.call('partners/save',{**partner,'active':False})
    p.call('partner/me',expected=401);customer.call('workspace')
    anonymous.call('public/partner',code=code,expected=422)
    partner=owner.call('partners/detail',id=pid)['partner'];owner.call('partners/save',{**partner,'active':True})
    p.csrf=p.call('partner/login',{'email':'partner-a@example.test','password':password})['csrf']
    member_id=owner.call('partners/detail',id=pid)['members'][0]['id']
    owner.call('partners/member',{'partner':pid,'id':member_id,'active':False});p.call('partner/me',expected=401)
    owner.call('partners/member',{'partner':pid,'id':member_id,'active':True})
    p.csrf=p.call('partner/login',{'email':'partner-a@example.test','password':password})['csrf']
    p.call('partner/password',{'current_password':password,'password':'New-long-partner-password-123!'})
    p.call('partner/me',expected=401)
    print('PASS one-step partner onboarding, mail idempotency, contact/login isolation, trial links, partner invitations, independent login/CSRF, rights, cross-partner isolation, customer approval, verified referral attribution, revocation, receipt-only commission accounting, annual receipts, exact rounding, immutable rates, month totals, payout transitions duplicate protection, concurrent onboarding/receipts and migration replay',flush=True)
