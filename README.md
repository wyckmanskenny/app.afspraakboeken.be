# Afspraakboeken.be · bedrijfsomgeving 0.9

PHP 8.2+ / MySQL 8 or MariaDB 10.6+, with PDO MySQL, JSON, OpenSSL and InnoDB. No Composer, Node server or build step is required on Hostinger. `main` deploys automatically to app.afspraakboeken.be.

## Account model

- One login screen. The role is read from the database, never selected or accepted from the browser.
- The first superadmin is installed using a private server-side setup key. Public trials require mailbox verification before a company or login is created.
- Superadmins create inactive companies with one initial company administrator, choose monthly/lifetime terms and manually activate them. Billing happens outside this platform.
- Company administrators can access only their own settings, services, appointments and enabled CRM. Billing data and the website inbox are not returned to them.
- Activation/password-reset links expire after 24 hours and work once. With SMTP configured, new company administrators receive a branded welcome email. The superadmin can resend it or personally share the link. Manually created accounts still require manual company activation; verified public trials start immediately.
- Session cookies are host-only, HttpOnly, Secure and SameSite=Lax. Tokens are hashed in the database. Sessions expire after 30 minutes of inactivity or 8 hours absolute. Deactivation and password changes revoke sessions.
- Authenticated writes require a session CSRF token and the configured Origin. Public contact writes allow only configured homepage origins. Public booking exposes availability, never existing customers' details.

## Install

Follow [docs/HOSTINGER.md](docs/HOSTINGER.md). Prefer storing database credentials and keys **outside every public web directory**. For restricted Hostinger accounts, the documented fallback is `api/private/config.php`, protected by an internal PHP guard; verify HTTP 403 before adding secrets. The fallback requires working PHP execution and must never be copied to a static/text/backup file under the web directory. The supplied `.htaccess` offers additional protection only where the server honors it; the current Hostinger deployment does not enforce it. Completed configurations are outside Git. The example files contain placeholders only. Nothing creates a Hostinger database automatically.

The API is `api/index.php?route=…` and does not depend on URL rewriting. `health` returns only readiness and version, with no-store caching. Until configured, login is unavailable and the homepage contact form remains disabled. The installation endpoint permanently refuses a second bootstrap after a superadmin exists.

## Booking and data

Each company currently has one bookable resource. Availability is calculated in Europe/Brussels; time intervals are stored in UTC. Booking insertion and availability edits lock the company row in an InnoDB transaction. Duration and buffer are both reserved. Request keys prevent duplicate appointments after network retries. CRM stages are separate from booking status. Blocked days stop new bookings without cancelling existing ones.

The management view loads the most recent 2,000 bookings; the agency inbox loads 1,000 requests. Search/pagination, multiple staff/resources, attachments and automatic retention policies are future work. Existing browser-local demo records are **not** imported as real customer data.

The old demonstration is isolated under `/demo/`. The public homepage uses that example. Real booking links use `/?mode=book&tenant=<company-id>`. The widget works as a drawer, popup or inline iframe. Consent-gated conversion events omit contact details; no external ad SDK is installed.

## Current limits

Google/Outlook calendar synchronization, automatic booking email, booking reminders and payments are not connected. Stored booking email templates are marked as previews. Booking confirmation is shown on screen and can be downloaded as an ICS calendar event.

The public `/registreren/` information route stays on the same page in two steps. `/registreren/?mode=trial` sends a single-use 24-hour verification link; the 14-day trial starts only after mailbox confirmation and password setup. A public trial cannot start without SMTP. Trial expiry is enforced by the API even when cron is absent. Existing accounts are not converted to trials.

Pricing is agreed per company; the public trial flow promises no fixed subscription rate or automatic paid conversion. One administrator per company is currently supported. Trial upgrades are explicit requests, with billing details stored separately from the login address. A superadmin confirms the agreed formula; conversion preserves the configured price and billing period. Invoicing and collection stay manual. See [docs/PARTNERS.md](docs/PARTNERS.md) for partner onboarding, attribution and commissions on registered received subscription revenue excluding VAT.

Information and activation requests are stored in the superadmin inbox and notified to `kenny@kdesign.be`. The red badge counts status `new`; opening the inbox does not clear it. Visible agency tabs refresh the count every minute without extending the session idle timeout.

[docs/TRIALS.md](docs/TRIALS.md) covers the additive database migration and required Hostinger PHP cron job for trial reminders at 3, 2, 1 and 0 calendar days before expiry (Europe/Brussels). [docs/EMAIL.md](docs/EMAIL.md) covers SMTP. Failures never remove saved leads or existing accounts.

The first server configuration and one successful real Hostinger database smoke test are required before operational use. This release does not claim an independent security audit.

## Validation

`tests/api-integration.py` uses a **fresh disposable local database**, rejects non-local server URLs, and exercises installation lockout, auth/CSRF, cross-company access, invites, activation, service edits, stale writes, idempotency, real concurrent reservations, CRM, cancellation, contact access, and session revocation. See [tests/README.md](tests/README.md).

## Websitewidget and company leads (0.11.0)

Company admins can choose direct booking or collect requests, with optional service selection. Requests are stored separately from reservations and the agency sales inbox, can be followed up in the CRM, and can later be linked to an appointment. See [docs/LEADS.md](docs/LEADS.md) for behavior, additive migration, SMTP notifications, tracking and current limits.
