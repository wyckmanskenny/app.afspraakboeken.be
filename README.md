# Afspraakboeken.be · bedrijfsomgeving 0.9

PHP 8.2+ / MySQL 8 or MariaDB 10.6+, with PDO MySQL, JSON, OpenSSL and InnoDB. No Composer, Node server or build step is required on Hostinger. `main` deploys automatically to app.afspraakboeken.be.

## Account model

- One login screen. The role is read from the database, never selected or accepted from the browser.
- The first superadmin is installed using a private server-side setup key. No public self-registration exists.
- Superadmins create inactive companies with one initial company administrator, choose monthly/lifetime terms and manually activate them. Billing happens outside this platform.
- Company administrators can access only their own settings, services, appointments and enabled CRM. Billing data and the website inbox are not returned to them.
- Activation/password-reset links expire after 24 hours and work once. The superadmin copies and personally shares the link; no email is automatically sent.
- Session cookies are host-only, HttpOnly, Secure and SameSite=Lax. Tokens are hashed in the database. Sessions expire after 30 minutes of inactivity or 8 hours absolute. Deactivation and password changes revoke sessions.
- Authenticated writes require a session CSRF token and the configured Origin. Public contact writes allow only configured homepage origins. Public booking exposes availability, never existing customers' details.

## Install

Follow [docs/HOSTINGER.md](docs/HOSTINGER.md). Prefer storing database credentials and keys **outside every public web directory**. For restricted Hostinger accounts, the documented fallback is `api/private/config.php`, protected by a directory-wide HTTP deny rule and a PHP guard; verify HTTP 403 before adding secrets. Both locations are outside Git. `api/config.example.php` contains placeholders only. Nothing creates a Hostinger database automatically.

The API is `api/index.php?route=…` and does not depend on URL rewriting. `health` returns only readiness and version, with no-store caching. Until configured, login is unavailable and the homepage contact form remains disabled. The installation endpoint permanently refuses a second bootstrap after a superadmin exists.

## Booking and data

Each company currently has one bookable resource. Availability is calculated in Europe/Brussels; time intervals are stored in UTC. Booking insertion and availability edits lock the company row in an InnoDB transaction. Duration and buffer are both reserved. Request keys prevent duplicate appointments after network retries. CRM stages are separate from booking status. Blocked days stop new bookings without cancelling existing ones.

The management view loads the most recent 2,000 bookings; the agency inbox loads 1,000 requests. Search/pagination, multiple staff/resources, attachments and automatic retention policies are future work. Existing browser-local demo records are **not** imported as real customer data.

The old demonstration is isolated under `/demo/`. The public homepage uses that example. Real booking links use `/?mode=book&tenant=<company-id>`. The widget works as a drawer, popup or inline iframe. Consent-gated conversion events omit contact details; no external ad SDK is installed.

## Current limits

Google/Outlook calendar synchronization, automatic booking email, reminders and payments are not connected. Stored email templates are marked as previews. Confirmation is shown on screen and can be downloaded as an ICS calendar event. The contact inbox is stored in the database; it does not send an email notification yet.

The first server configuration and one successful real Hostinger database smoke test are required before operational use. This release does not claim an independent security audit.

## Validation

`tests/api-integration.py` uses a **fresh disposable local database**, rejects non-local server URLs, and exercises installation lockout, auth/CSRF, cross-company access, invites, activation, service edits, stale writes, idempotency, real concurrent reservations, CRM, cancellation, contact access, and session revocation. See [tests/README.md](tests/README.md).
