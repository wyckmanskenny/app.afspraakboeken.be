# Backend verification

Run `python3 tests/configuration.py` for the database-free configuration path and guard checks. Set `PHP_BIN` if PHP is not on PATH. The hosting fallback also requires a separate live HTTP check: `/api/private/access-check.txt` must return 403. PHP's local development server does not enforce `.htaccess`.

Use a fresh **local disposable** MySQL/MariaDB database and a local PHP 8.2+ test server. Never use a production database: the installer test expects an empty schema. The integration test rejects non-loopback URLs.

Provide a private test config with `app_url` matching the PHP test server, `secure_cookies=false` (permitted only for localhost/127.0.0.1), `contact_origins=['https://afspraakboeken.be']`, and unique test-only app/setup keys. Run PHP with `AFSPRAAKBOEKEN_CONFIG` pointing to that file and `PHP_CLI_SERVER_WORKERS=4` so booking concurrency is real.

```sh
API_BASE=http://127.0.0.1:58089 TEST_SETUP_KEY='<local-test-setup-key>' python3 tests/api-integration.py
```

The test creates fictitious `example.test` users and companies, accepts invitation tokens, exercises both administrator roles and public forms, and attempts simultaneous bookings. It does not send email or access external calendars.

Syntax: run `php -l` on every `api/*.php` file and `node --check` on each JavaScript module. The optional frontend DOM tests use Happy DOM in an isolated VM; no dependency is required on the hosting server.
