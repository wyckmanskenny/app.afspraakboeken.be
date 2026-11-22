"""Reminder lifecycle and actual SMTP headers. Disposable DB and loopback SMTP only."""
from pathlib import Path
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo
import concurrent.futures
import json
import os
import secrets
import subprocess


def run(Client, owner, anonymous, messages, envelopes, config, state):
    base = Path(os.environ['TEST_RUNTIME_USR'])
    api = Path(__file__).resolve().parent.parent / 'api'
    php = [str(base/'bin/php8.4'), '-n', '-d', 'extension_dir='+str(base/'lib/php/20240924'),
           '-d', 'extension=pdo', '-d', 'extension=mysqlnd', '-d', 'extension=pdo_mysql',
           '-d', 'openssl.cafile='+str(config.parent/'smtp-cert.pem')]

    def sql(statement):
        return subprocess.check_output([str(base/'bin/mariadb'), '--no-defaults', '--protocol=TCP',
                '--host=127.0.0.1', '--port=53306', '--user=root', '--batch', '--skip-column-names',
                'ab_backend_test', '-e', statement], stderr=subprocess.DEVNULL).decode().strip()

    def execute(code):
        includes = ''.join('require '+json.dumps(str(api/name))+';' for name in ['bootstrap.php', 'domain.php', 'mailer.php'])
        return subprocess.check_output(php+['-r', includes+code], env={**os.environ, 'AFSPRAAKBOEKEN_CONFIG': str(config)}).decode().strip()

    def cron():
        return json.loads(execute('echo json_encode(run_appointment_reminders());'))

    sql('DELETE FROM ab_rate_limits')
    company = owner.call('tenants/create', {'name': 'Dakwerken <Test>', 'admin_name': 'Owner',
                         'email': 'reminder-owner@example.test', 'sector': 'Test'}, expected=201)
    tid = company['tenant_id']
    t = owner.call('workspace', tenant=tid)['tenant']
    owner.call('tenant/admin', {'tenant': tid, 'revision': t['revision'], 'active': True,
               'plan_type': 'monthly', 'monthly_cents': 0, 'lifetime_cents': 0, 'billing': {'email': 'invoice@example.test'}})
    anonymous.call('invite/accept', {'token': company['invite_url'].split('#')[1], 'password': 'Only-for-integration-tests-123!'})
    client = Client(); client.login('reminder-owner@example.test')
    t = client.call('workspace')['tenant']
    client.call('tenant/save', {'tenant': tid, 'revision': t['revision'], 'settings': {
        'notice': 0, 'location': 'Teststraat 1', 'hours': [{'open': True, 'start': '00:00', 'end': '23:59'}]*7}})
    service = client.call('service/save', {'tenant': tid, 'name': 'Dakinspectie', 'description': 'Test',
        'duration': 30, 'buffer': 0, 'price': 0, 'active': True})['id']
    t = client.call('workspace')['tenant']
    assert t['settings']['appointmentReminders'] == {'oneDay': True, 'threeDays': False, 'sameDay': False, 'sameDayTime': '08:00', 'message': ''}
    day = (datetime.now(ZoneInfo('Europe/Brussels'))+timedelta(days=5)).date().isoformat()
    payload = {'tenant': tid, 'service': service, 'date': day, 'time': '10:00', 'name': 'Alex <script>x</script>',
               'email': 'reminder-visitor@example.test', 'phone': '0400000000', 'marketing_consent': False, 'request_key': secrets.token_hex(16)}

    def book(hour):
        return anonymous.call('public/book', {**payload, 'time': hour, 'request_key': secrets.token_hex(16)}, expected=201)['id']

    b = book('10:00')
    assert sql(f"SELECT GROUP_CONCAT(kind) FROM ab_appointment_reminders WHERE booking_id='{b}'") == 'oneDay'
    client.call('reminders/save', {'tenant': tid, 'settings': {'threeDays': True, 'sameDay': True, 'message': '<b>Neem je plannen mee.</b>'}})
    assert sql(f"SELECT COUNT(*) FROM ab_appointment_reminders WHERE booking_id='{b}' AND status='pending'") == '3'
    early = book('07:00')
    assert sql(f"SELECT COUNT(*) FROM ab_appointment_reminders WHERE booking_id='{early}' AND kind='sameDay'") == '0'
    client.call('reminders/save', {'tenant': tid, 'settings': {'sameDayTime': '25:00'}}, expected=422)
    client.call('reminders/save', {'tenant': tid, 'settings': {'oneDay': 'yes'}}, expected=422)
    other = next(t['id'] for t in owner.call('tenants')['tenants'] if t['id'] != tid)
    client.call('reminders/status', tenant=other, expected=404)
    client.call('reminders/save', {'tenant': other, 'settings': {'oneDay': False}}, expected=404)
    client.call('reminders/test', {'tenant': other}, expected=404)
    client.call('reminders/status', tenant=tid, booking='f'*32, expected=404)
    anonymous.call('reminders/status', tenant=tid, expected=401)
    preview = client.call('reminders/preview', tenant=tid)
    assert '&lt;b&gt;' in preview['html'] and '<b>Neem' not in preview['html']
    before = len(messages)
    client.call('reminders/test', {'tenant': tid, 'recipient': 'attacker@example.test'})
    assert len(messages) == before+1 and envelopes[-1] == ['reminder-owner@example.test']
    assert str(messages[-1]['Reply-To']) == 'reminder-owner@example.test'

    # Local-only dates make one reminder due; all other fixtures remain scheduled in the future.
    def due(booking, kind='oneDay'):
        now = datetime.now(timezone.utc).replace(microsecond=0)-timedelta(minutes=1)
        start = (now.astimezone(ZoneInfo('Europe/Brussels'))+timedelta(days=1)).astimezone(timezone.utc)
        a = start.strftime('%Y-%m-%d %H:%M:%S'); d = now.strftime('%Y-%m-%d %H:%M:%S')
        sql(f"UPDATE ab_appointment_reminders SET status='skipped' WHERE booking_id='{booking}' AND kind<>'{kind}'; "
            f"UPDATE ab_bookings SET starts_at='{a}',booking_date='{start.astimezone(ZoneInfo('Europe/Brussels')).date().isoformat()}',booking_time='{start.astimezone(ZoneInfo('Europe/Brussels')).strftime('%H:%M')}' WHERE id='{booking}'; "
            f"UPDATE ab_appointment_reminders SET appointment_start='{a}',due_at='{d}',next_attempt_at='{d}' WHERE booking_id='{booking}' AND kind='{kind}'")
        return sql(f"SELECT id FROM ab_appointment_reminders WHERE booking_id='{booking}' AND kind='{kind}'")

    jid = due(b)
    # Current tenant contact, not login, billing, visitor or agency fallback.
    t = client.call('workspace')['tenant']
    client.call('tenant/save', {'tenant': tid, 'revision': t['revision'], 'email': 'contact-dakwerker@example.test', 'settings': {}})
    before = len(messages)
    with concurrent.futures.ThreadPoolExecutor(2) as pool:
        list(pool.map(lambda _: cron(), range(2)))
    assert len(messages) == before+1 and envelopes[-1] == ['reminder-visitor@example.test']
    msg = messages[-1]; plain = msg.get_body(('plain',)).get_content(); html = msg.get_body(('html',)).get_content()
    assert str(msg['Reply-To']) == 'contact-dakwerker@example.test'
    assert 'Dakwerken <Test>' in str(msg['From']) and '<mailer@example.test>' in str(msg['From'])
    assert str(msg['Message-ID']) == f'<appointment-{jid}@127.0.0.1>'
    for content in [plain, html]:
        assert 'Dakinspectie' in content and 'Teststraat 1' in content and 'Neem je plannen mee.' in content
        assert 'Wycked' not in content and 'antwoord' not in content.lower() and 'annule' not in content.lower()
    assert '<script>' not in html and '&lt;script&gt;' in html
    cron(); assert len(messages) == before+1
    assert client.call('reminders/status', tenant=tid, booking=b)['reminders'][0]['status'] in ('sent', 'skipped')

    # Cancellation prevents a due reminder; rescheduling removes the old pending send.
    cancelled = book('11:00'); due(cancelled)
    client.call('booking/update', {'tenant': tid, 'id': cancelled, 'status': 'cancelled'})
    before = len(messages); cron(); assert len(messages) == before
    moved = book('12:00')
    client.call('booking/reschedule', {'tenant': tid, 'id': moved, 'expected_date': day, 'expected_time': '12:00', 'date': day, 'time': '13:00'})
    assert sql(f"SELECT COUNT(*) FROM ab_appointment_reminders WHERE booking_id='{moved}' AND status='skipped'") == '3'
    assert sql(f"SELECT COUNT(*) FROM ab_appointment_reminders WHERE booking_id='{moved}' AND status='pending'") == '3'
    client.call('reminders/save', {'tenant': tid, 'settings': {'sameDay': False}})
    assert sql(f"SELECT COUNT(*) FROM ab_appointment_reminders WHERE booking_id='{moved}' AND kind='sameDay' AND status='pending'") == '0'

    # Changing copy must preserve a reminder due within the next hour.
    close = book('14:00'); close_id = due(close)
    client.call('reminders/save', {'tenant': tid, 'settings': {'message': 'Nieuwe boodschap'}})
    assert sql(f"SELECT status FROM ab_appointment_reminders WHERE id='{close_id}'") == 'pending'
    state['reject'] = True; cron(); state['reject'] = False
    assert sql(f"SELECT status FROM ab_appointment_reminders WHERE id='{close_id}'") == 'failed'
    before = len(messages); cron(); assert len(messages) == before
    sql(f"UPDATE ab_appointment_reminders SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id='{close_id}'")
    cron(); assert len(messages) == before+1
    sql(f"UPDATE ab_appointment_reminders SET status='sending',attempted_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE) WHERE id='{close_id}'")
    before = len(messages); cron(); assert len(messages) == before
    assert sql(f"SELECT status FROM ab_appointment_reminders WHERE id='{close_id}'") == 'uncertain'

    # An appointment that already started must never be reminded.
    past = book('15:00'); past_id = due(past)
    sql(f"UPDATE ab_bookings SET starts_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id='{past}'; UPDATE ab_appointment_reminders SET appointment_start=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id='{past_id}'")
    before = len(messages); cron(); assert len(messages) == before
    assert sql(f"SELECT reason FROM ab_appointment_reminders WHERE id='{past_id}'") == 'past'
    print('PASS reminders: defaults/autosave, isolation, preview/test recipients, real SMTP Reply-To, escaping, no reply copy, concurrent workers, cancellation, rescheduling, retry spacing and uncertain/past suppression', flush=True)
