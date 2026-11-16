"""Partner authorization and referral tests; disposable database + loopback mail only."""
import secrets,re,os,subprocess
from pathlib import Path

def run(Client,owner,anonymous,messages):
    password='Only-for-integration-tests-123!'
    def sql(s):
        base=Path(os.environ['TEST_RUNTIME_USR'])
        return subprocess.check_output([str(base/'bin/mariadb'),'--no-defaults','--protocol=TCP','--host=127.0.0.1','--port=53306','--user=root','--batch','--skip-column-names','ab_backend_test','-e',s],stderr=subprocess.DEVNULL).decode().strip()
    sql('DELETE FROM ab_rate_limits')
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
    # Rights updates apply to live sessions, and pausing a partner leaves its customers intact.
    detail=owner.call('partners/detail',id=pid);partner=detail['partner']
    owner.call('partners/save',{**partner,'can_create':False,'can_activate':True})
    p.call('partner/customer-create',{**payload,'email':'denied@example.test','request_key':secrets.token_hex(16)},expected=403)
    p.call('partner/activation',{'tenant':refid,'activate':True,'confirmed':True})
    assert sql(f"SELECT COUNT(*) FROM ab_trials WHERE tenant_id='{refid}' AND converted_at IS NOT NULL")=='1'
    assert sql('SELECT COUNT(*) FROM ab_partner_commissions')=='0'
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
    print('PASS partner invitations, independent login/CSRF, rights, cross-partner isolation, customer approval, verified referral attribution, revocation and zero automatic commissions',flush=True)
