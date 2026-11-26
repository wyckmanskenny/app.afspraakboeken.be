"""Same-email app/partner identities, using only the disposable database and SMTP sink."""
import os
import re
import secrets
import subprocess
from pathlib import Path


def run(Client, owner, anonymous, messages, envelopes):
    app_password = 'Dual-app-password-only-123!'
    partner_password = 'Dual-partner-password-only-123!'
    command = [str(Path(os.environ['TEST_RUNTIME_USR']) / 'bin/mariadb'),
               '--no-defaults', '--protocol=TCP', '--host=127.0.0.1', '--port=53306',
               '--user=root', '--batch', '--skip-column-names', 'ab_backend_test', '-e']

    def sql(statement):
        return subprocess.check_output(command + [statement], stderr=subprocess.DEVNULL).decode().strip()

    def credentials(client, address, password, partner=False):
        result = client.call('partner/login' if partner else 'login', {'email': address, 'password': password})
        client.csrf = result['csrf']
        return result

    def tenant_active(tenant_id, active=True):
        tenant = owner.call('workspace', tenant=tenant_id)['tenant']
        owner.call('tenant/admin', {'tenant': tenant_id, 'revision': tenant['revision'],
                                   'active': active, 'plan_type': 'monthly',
                                   'monthly_cents': 0, 'lifetime_cents': 0, 'billing': {}})

    def create_customer(address):
        return owner.call('tenants/create', {'name': 'Dual portal company', 'admin_name': 'Dual Customer',
                          'email': address, 'sector': 'Test', 'plan_type': 'monthly',
                          'monthly_cents': 0, 'lifetime_cents': 0}, expected=201)

    def create_partner(address):
        result = owner.call('partners/save', {'name': 'Dual portal partner', 'contact_name': 'Dual Partner',
                            'email': address, 'commission_percent': '20', 'send_invite': True,
                            'request_key': secrets.token_hex(16), 'active': True,
                            'can_create': True, 'can_activate': False})
        assert result['mail'] == 'sent' and envelopes[-1] == [address.lower()]
        assert str(messages[-1]['From']).endswith('<hallo@afspraakboeken.be>')
        return result

    def accept_customer(result):
        anonymous.call('invite/accept', {'token': result['invite_url'].split('#')[1], 'password': app_password})
        tenant_active(result['tenant_id'])

    def accept_partner(result):
        anonymous.call('partner/invite-accept', {'token': result['invite_url'].split('#invite=')[1],
                                                'password': partner_password})

    def identity_rows(address):
        return sql(f"SELECT id,COALESCE(tenant_id,''),email,name,role,COALESCE(password_hash,''),active "
                   f"FROM ab_users WHERE email='{address}' ORDER BY id")

    sql('DELETE FROM ab_rate_limits')
    commissions_before = sql('SELECT COUNT(*) FROM ab_partner_commissions')
    accounts_before = sql('SELECT COUNT(*) FROM ab_partner_accounts')

    # Exercise the real production upgrade: v3 had a global email key and existing sessions.
    # At this point preceding fixtures all have distinct emails, so the legacy constraint is valid.
    users_before = sql("SELECT id,COALESCE(tenant_id,''),email,name,role,COALESCE(password_hash,''),active FROM ab_users ORDER BY id")
    sessions_before = sql('SELECT token_hash,user_id,csrf,created_at,expires_at FROM ab_sessions ORDER BY token_hash')
    sql('ALTER TABLE ab_users ADD UNIQUE KEY legacy_email (email)')
    sql('ALTER TABLE ab_users DROP INDEX ab_user_email_scope, DROP COLUMN login_scope')
    sql("DELETE FROM ab_audit WHERE action='schema.partners_v4'")
    assert anonymous.call('health')['ready'] is True
    assert sql("SELECT id,COALESCE(tenant_id,''),email,name,role,COALESCE(password_hash,''),active FROM ab_users ORDER BY id") == users_before
    assert sql('SELECT token_hash,user_id,csrf,created_at,expires_at FROM ab_sessions ORDER BY token_hash') == sessions_before
    assert sql("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ab_users' AND INDEX_NAME='legacy_email'") == '0'
    assert sql("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ab_users' AND INDEX_NAME='ab_user_email_scope' AND NON_UNIQUE=0") == 'email,login_scope'
    assert sql("SELECT COUNT(*) FROM ab_audit WHERE action='schema.partners_v4'") == '1'
    owner.call('tenants')

    # Both invitations can be pending for one address. Neither can activate the other portal.
    address = 'dual-customer-first@example.test'
    customer = create_customer(address)
    original_customer = identity_rows(address)
    partner = create_partner(address)
    partner_id = partner['id']
    member_id = owner.call('partners/detail', id=partner_id)['members'][0]['id']
    customer_token = customer['invite_url'].split('#')[1]
    partner_token = partner['invite_url'].split('#invite=')[1]
    anonymous.call('partner/invite-check', {'token': customer_token}, expected=400)
    anonymous.call('invite/check', {'token': partner_token}, expected=400)
    anonymous.call('partner/invite-accept', {'token': customer_token, 'password': partner_password}, expected=400)
    anonymous.call('invite/accept', {'token': partner_token, 'password': app_password}, expected=400)
    accept_partner(partner)
    assert original_customer in identity_rows(address), 'Adding/activating a partner must preserve the customer row'
    anonymous.call('invite/check', {'token': customer_token})
    accept_customer(customer)
    assert sql(f"SELECT GROUP_CONCAT(CONCAT(role,':',login_scope) ORDER BY role) FROM ab_users WHERE email='{address}'") == 'admin:app,partner:partner'
    app_id = sql(f"SELECT id FROM ab_users WHERE email='{address}' AND role='admin'")
    assert app_id != member_id

    # One browser keeps two independent cookies and CSRF tokens, even for the same email.
    browser = Client()
    app_csrf = credentials(browser, address.upper(), app_password)['csrf']
    partner_csrf = credentials(browser, address.upper(), partner_password, partner=True)['csrf']
    assert app_csrf != partner_csrf
    assert browser.call('me')['user']['id'] == app_id
    browser.call('partner/me')
    anonymous.call('login', {'email': address, 'password': partner_password}, expected=401)
    anonymous.call('partner/login', {'email': address, 'password': app_password}, expected=401)
    browser.call('partners/list', expected=403)
    browser.csrf = app_csrf
    browser.call('partner/trial-link', {}, expected=403)
    browser.csrf = partner_csrf
    browser.call('password', {'current_password': app_password, 'password': app_password}, expected=403)

    # Duplicates are still blocked inside each portal, including capitalization and app roles.
    owner.call('tenants/create', {'name': 'Duplicate', 'admin_name': 'Duplicate', 'email': address.upper()}, expected=409)
    count = sql('SELECT COUNT(*) FROM ab_partners')
    owner.call('partners/save', {'name': 'Duplicate', 'contact_name': 'Duplicate', 'email': address.upper(),
                               'send_invite': True, 'request_key': secrets.token_hex(16)}, expected=409)
    assert sql('SELECT COUNT(*) FROM ab_partners') == count
    owner.call('partners/member', {'partner': partner_id, 'name': 'Duplicate', 'email': address.upper()}, expected=409)
    for role in ['admin', 'superadmin', 'partner']:
        duplicate = subprocess.run(command + [f"INSERT INTO ab_users(id,email,name,role) VALUES ('{secrets.token_hex(16)}','{address.upper()}','Duplicate','{role}')"], capture_output=True)
        assert duplicate.returncode != 0 and b'1062' in duplicate.stderr, f'Database must reject a duplicate {role} login'

    # Changing either password revokes only that portal's sessions.
    app_password2 = 'Dual-new-app-password-123!'
    partner_password2 = 'Dual-new-partner-password-123!'
    browser.csrf = app_csrf
    browser.call('password', {'current_password': app_password, 'password': app_password2})
    browser.call('me', expected=401)
    browser.call('partner/me')
    app_csrf = credentials(browser, address, app_password2)['csrf']
    browser.csrf = partner_csrf
    browser.call('partner/password', {'current_password': partner_password, 'password': partner_password2})
    browser.call('partner/me', expected=401)
    assert browser.call('me')['user']['id'] == app_id
    partner_csrf = credentials(browser, address, partner_password2, partner=True)['csrf']
    anonymous.call('login', {'email': address, 'password': app_password}, expected=401)
    anonymous.call('partner/login', {'email': address, 'password': partner_password}, expected=401)
    sql('DELETE FROM ab_rate_limits')

    # Resets for both accounts coexist; accepting one does not consume the other invitation.
    app_reset = owner.call('tenant/invite', {'tenant': customer['tenant_id']})['invite_url'].split('#')[1]
    partner_reset = owner.call('partners/member', {'partner': partner_id, 'id': member_id})['invite_url'].split('#invite=')[1]
    anonymous.call('invite/check', {'token': app_reset})
    anonymous.call('partner/invite-check', {'token': partner_reset})
    anonymous.call('invite/accept', {'token': app_reset, 'password': app_password})
    browser.call('me', expected=401)
    browser.call('partner/me')
    app_csrf = credentials(browser, address, app_password)['csrf']
    anonymous.call('partner/invite-check', {'token': partner_reset})
    anonymous.call('partner/invite-accept', {'token': partner_reset, 'password': partner_password})
    browser.call('partner/me', expected=401)
    assert browser.call('me')['user']['id'] == app_id
    partner_csrf = credentials(browser, address, partner_password, partner=True)['csrf']

    tenant_active(customer['tenant_id'], False)
    browser.call('me', expected=401)
    browser.call('partner/me')
    anonymous.call('login', {'email': address, 'password': app_password}, expected=403)
    tenant_active(customer['tenant_id'])
    credentials(browser, address, app_password)
    app_reset = owner.call('tenant/invite', {'tenant': customer['tenant_id']})['invite_url'].split('#')[1]
    partner_reset = owner.call('partners/member', {'partner': partner_id, 'id': member_id})['invite_url'].split('#invite=')[1]
    app_before_delete = sql(f"SELECT password_hash,active,role,tenant_id FROM ab_users WHERE id='{app_id}'")
    detail = owner.call('partners/detail', id=partner_id)['partner']
    owner.call('partners/delete', {'id': partner_id, 'revision': detail['revision'], 'confirmed': True})
    browser.call('partner/me', expected=401)
    assert browser.call('me')['user']['id'] == app_id
    assert sql(f"SELECT password_hash,active,role,tenant_id FROM ab_users WHERE id='{app_id}'") == app_before_delete
    anonymous.call('partner/invite-check', {'token': partner_reset}, expected=400)
    anonymous.call('invite/accept', {'token': app_reset, 'password': app_password})
    credentials(browser, address, app_password)

    # Reverse order works for manual customer creation and public 14-day signup.
    for mode in ['manual', 'trial']:
        sql('DELETE FROM ab_rate_limits')
        address2 = f'dual-partner-first-{mode}@example.test'
        second_partner = create_partner(address2)
        accept_partner(second_partner)
        partner_before = identity_rows(address2)
        if mode == 'manual':
            second_customer = create_customer(address2)
            accept_customer(second_customer)
        else:
            before = len(messages)
            anonymous.call('trial/signup', {'name': 'Dual Trial', 'company': 'Dual Trial',
                                           'email': address2.upper(), 'sector': 'Test'}, expected=201)
            assert len(messages) == before + 1 and envelopes[-1] == [address2]
            token = re.search(r'#trial=([a-f0-9]{64})', messages[-1].get_body(('plain',)).get_content())[1]
            second_customer = anonymous.call('trial/accept', {'token': token, 'password': app_password})
            assert second_customer['trial'] is True and second_customer['active'] is True
            before = len(messages)
            anonymous.call('trial/signup', {'name': 'Duplicate', 'company': 'Duplicate',
                                           'email': address2.upper(), 'sector': 'Test'}, expected=201)
            assert len(messages) == before, 'Existing app login must not be recreated by trial signup'
        assert partner_before in identity_rows(address2)
        dual = Client()
        credentials(dual, address2, app_password)
        credentials(dual, address2, partner_password, partner=True)
        dual.call('workspace')
        dual.call('partner/me')

    # Extra partner members also get their own identity alongside an existing customer login.
    extra_address = 'dual-extra-member@example.test'
    extra_customer = create_customer(extra_address)
    extra_member = owner.call('partners/member', {'partner': second_partner['id'], 'name': 'Extra Dual', 'email': extra_address})
    accept_partner(extra_member)
    accept_customer(extra_customer)
    extra = Client()
    credentials(extra, extra_address, app_password)
    credentials(extra, extra_address, partner_password, partner=True)
    extra.call('workspace')
    extra.call('partner/me')
    assert sql('SELECT COUNT(*) FROM ab_partner_accounts') == accounts_before, 'Sharing an email must not create referral attribution'

    # A superadmin can be a partner without losing or sharing the superadmin role/password.
    super_email = 'dual-superadmin@example.test'
    super_id = secrets.token_hex(16)
    sql(f"INSERT INTO ab_users(id,email,name,role,password_hash) SELECT '{super_id}','{super_email}','Dual Superadmin','superadmin',password_hash FROM ab_users WHERE email='owner@example.test' AND role='superadmin'")
    super_before = identity_rows(super_email)
    super_partner = create_partner(super_email)
    accept_partner(super_partner)
    assert super_before in identity_rows(super_email)
    super_browser = Client()
    credentials(super_browser, super_email, 'Only-for-integration-tests-123!')
    credentials(super_browser, super_email, partner_password, partner=True)
    assert super_browser.call('me')['user']['role'] == 'superadmin'
    super_browser.call('tenants')
    super_browser.call('partner/me')
    partner_only = Client()
    credentials(partner_only, super_email, partner_password, partner=True)
    partner_only.call('tenants', expected=401)
    anonymous.call('login', {'email': super_email, 'password': partner_password}, expected=401)

    # A partner can also create their own customer account through the partner portal.
    self_email = 'dual-partner-customer-create@example.test'
    self_partner = create_partner(self_email)
    accept_partner(self_partner)
    self_client = Client()
    credentials(self_client, self_email, partner_password, partner=True)
    self_payload = {'name': 'Own customer company', 'admin_name': 'Dual Owner', 'email': self_email,
                    'sector': 'Test', 'confirmed': True, 'request_key': secrets.token_hex(16)}
    self_customer = self_client.call('partner/customer-create', self_payload, expected=201)
    self_token = re.search(r'/activate.html#([a-f0-9]{64})', messages[-1].get_body(('plain',)).get_content())[1]
    anonymous.call('invite/accept', {'token': self_token, 'password': app_password})
    owner.call('partners/approve', {'partner': self_partner['id'], 'tenant': self_customer['tenant_id'], 'confirmed': True})
    credentials(self_client, self_email, app_password)
    self_client.call('workspace')
    self_client.call('partner/me')
    # A fresh creation key is a duplicate app identity, not another customer or commission.
    self_client.csrf = self_client.call('partner/me')['csrf']
    self_client.call('partner/customer-create', {**self_payload, 'email': self_email.upper(), 'request_key': secrets.token_hex(16)}, expected=409)
    assert sql('SELECT COUNT(*) FROM ab_partner_commissions') == commissions_before

    # Replaying v4 with actual duplicate emails must preserve every identity and session.
    users_before = sql("SELECT id,COALESCE(tenant_id,''),email,name,role,COALESCE(password_hash,''),active FROM ab_users ORDER BY id")
    sessions_before = sql('SELECT token_hash,user_id,csrf,created_at,expires_at FROM ab_sessions ORDER BY token_hash')
    sql("DELETE FROM ab_audit WHERE action='schema.partners_v4'")
    assert anonymous.call('health')['ready'] is True
    assert sql("SELECT id,COALESCE(tenant_id,''),email,name,role,COALESCE(password_hash,''),active FROM ab_users ORDER BY id") == users_before
    assert sql('SELECT token_hash,user_id,csrf,created_at,expires_at FROM ab_sessions ORDER BY token_hash') == sessions_before
    assert sql("SELECT COUNT(*) FROM ab_audit WHERE action='schema.partners_v4'") == '1'
    sql('DELETE FROM ab_rate_limits')
    print('PASS same-email app/partner creation, legacy migration, independent credentials/invites/cookies/sessions and deletion', flush=True)
