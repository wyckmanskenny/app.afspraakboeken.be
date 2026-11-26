# Backend verification

Run `python3 tests/configuration.py` for the database-free configuration path and guard checks. Set `PHP_BIN` if PHP is not on PATH. The hosting fallback also requires a separate live HTTP check: `/api/private/config.example.php` must return 403 without source or data. After duplicating it as `config.php`, verify that file before filling it in. The current Hostinger deployment does not enforce `.htaccess`, so the fallback relies on PHP execution and its internal guard, not a directory-wide HTTP deny.

Use a fresh **local disposable** MySQL/MariaDB database and a local PHP 8.2+ test server. Never use a production database: the installer test expects an empty schema. The integration test rejects non-loopback URLs.

Provide a private test config with `app_url` matching the PHP test server, `secure_cookies=false` (permitted only for localhost/127.0.0.1), `contact_origins=['https://afspraakboeken.be']`, and unique test-only app/setup keys. Run PHP with `AFSPRAAKBOEKEN_CONFIG` pointing to that file and `PHP_CLI_SERVER_WORKERS=4` so booking concurrency is real.

```sh
API_BASE=http://127.0.0.1:58089 TEST_SETUP_KEY='<local-test-setup-key>' python3 tests/api-integration.py
```

The test creates fictitious `example.test` users and companies, accepts invitation tokens, exercises both administrator roles and public forms, and attempts simultaneous bookings. Start with email disabled. It does not access external calendars.

Run `mail-integration.py` afterwards against the same disposable database/server, with `TEST_CONFIG_PATH` pointing to a config inside the ignored `tests/.runtime/` directory. Run PHP with `openssl.cafile` set to the absolute path of `tests/.runtime/smtp-cert.pem`. The test generates a temporary certificate and starts its own loopback TLS SMTP sink on port 5465; it never sends external email. It tests welcome messages, recipient separation, billing data, lead notifications, SMTP rejection, certificate hostname verification and missing mail configuration. It restores the test config when finished. Python 3.9+ and the `openssl` command are needed.

`tests/registration-smoke.mjs` checks readiness, optional fields, duplicate-click protection, successful lead submission, escaped confirmation text and failure recovery. `tests/ui-smoke.mjs` also checks account/billing address separation and inbox deep links. Run these using `node --experimental-vm-modules --test`, with Happy DOM available or `HAPPY_DOM_MODULE` pointing to its module entry file.

Syntax: run `php -l` on every `api/*.php` file and `node --check` on each JavaScript module. The optional frontend DOM tests use Happy DOM in an isolated VM; no dependency is required on the hosting server.


The SMTP suite also invokes `trial_integration.py`. Supply `TEST_RUNTIME_USR` pointing to the local test runtime containing `bin/php8.4`, `bin/mariadb`, and `lib/php/20240924`; `LD_LIBRARY_PATH` must match that runtime if it uses bundled libraries. The suite changes dates only in `ab_backend_test`, tests all four reminder milestones, SMTP retry spacing, expired-trial restrictions, conversion and notification counts. It verifies that passive badge polling does not refresh the idle session timestamp. No production database or mail server is used.

The SMTP suite invokes `lead_integration.py` for company leads, including contact-only mode without services, optional service selection, per-tenant access, consent, notification recipients, idempotent submissions, stale mode switches, internal scheduling, concurrent requests, a public/internal booking race, CRM notes, closing without booking and trial expiry. `ui-smoke.mjs` covers these public journeys and the corresponding account controls plus iframe tracking boundaries.

## Partner commissions (0.22.0)

`mail-integration.py` also runs `partner_integration.py`: one-step invitation and concurrent retry protection, partner-only access, verified trial attribution, receipt-based commission arithmetic and rate snapshots, stale-preview rejection, payout state transitions, duplicate payment protection, and safe migration reruns. All money and mail are fictitious and local.

Run the focused UI cases with `node --experimental-vm-modules --test tests/partner-admin-smoke.mjs tests/partner-portal-smoke.mjs tests/trial-pricing-smoke.mjs` and the same `HAPPY_DOM_MODULE` used by the existing UI suite. They cover onboarding retries, exact decimal conversion, stale rates, month selection, CSV formula protection, Belgian payout dates and incomplete-export handling.
