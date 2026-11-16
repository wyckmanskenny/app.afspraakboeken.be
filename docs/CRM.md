# CRM extension — 0.13.0

The existing kanban is included; `features.crmPlus` is a separate, default-off paid entitlement. Only superadmins can change it, in company Administration or Modules. Billing remains external. No module is enabled automatically for existing companies.

## Database

An additive, advisory-locked migration creates `ab_crm_contacts`, `ab_crm_notes`, `ab_crm_tasks`, `ab_crm_tags`, `ab_crm_contact_tags`, and `ab_crm_links`. The migration runs on API startup and records `schema.crm_v1` only after all tables exist. No existing table or record is removed. Contact/child composite foreign keys enforce tenant ownership. The link table connects existing leads/bookings without duplicating their history.

All writes authenticate, validate CSRF, lock the tenant and enforce entitlement. Contacts and tasks have optimistic revision checks. Creates use per-tenant idempotency keys. Exports authenticate and enforce tenant ownership, but remain readable after module/trial expiry. Disabling the whole company still revokes login as before.

## Intake and existing records

New public bookings, public leads, manual leads and lead scheduling automatically link while the extension is active. A single matching email/phone plus matching name can reuse a contact; shared/ambiguous identities remain separate and are flagged for human review. No fuzzy matching or destructive automatic merge occurs. Editing a contact never overwrites the original lead/booking.

“Bestaande aanvragen ophalen” links up to 250 unlinked leads and 250 unlinked bookings per invocation. Repeating is safe and skips already linked records; the UI prompts another batch when appropriate. Import is explicit rather than writing on page views. Missed records from a period with CRM disabled can be imported after reactivation.

## Customer interface

The CRM tab is visible for every customer. Without entitlement it displays clearly fictitious examples, an interest button and export links. Interest is stored in the agency inbox and uses the existing notification mailer, deduplicated for seven days. It is not a purchase or automatic activation.

Paid features: paginated/searchable/tag-filtered contacts, contact editing, internal append-only notes, follow-up tasks with completion/reopening, and original lead/booking history. Today's and overdue tasks are in-app reminders; no task reminder email is sent. Existing customer details can be marked reviewed and kept separate when flagged as a possible duplicate. Contact merge/deletion and external CRM integrations are not implemented.

Exports stream all tenant records (not only the visible page) as UTF-8 BOM semicolon CSV, with spreadsheet formula neutralization. Separate downloads cover contacts/labels, notes and tasks. Account passwords and mail configuration are never included.

## Verification

Disposable MySQL + loopback TLS SMTP integration tests cover entitlement, tenant isolation, stale writes, idempotency, automatic links for both intake routes, ambiguous matches, import, disabled-module export, formula escaping and interest notifications. Happy DOM tests cover preview gating, contact/note/task flows and existing booking/kanban journeys.
