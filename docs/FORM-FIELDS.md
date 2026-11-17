# Widget form fields

Version 0.17.0 groups the widget screen into workflow, display and form fields.
Workflow changes save immediately with revision checks and rollback on failure.
Form fields save explicitly so unfinished questions are not published.

Name and email remain required. Phone, address and notes have hidden/optional/required modes, available to all accounts. Accounts without saved overrides keep the previous behavior (phone required for bookings, optional for leads; existing address/notes module settings).

CRM Plus enables up to five custom short-text or select questions, with up to ten choices each. Server validation enforces the subscription, field visibility, required answers and allowed choices. Disabling CRM Plus hides questions without deleting definitions or past answers. Old open forms containing removed questions must reload.

Definitions live in tenant settings_json.formFields. The idempotent schema initializer creates ab_form_answers automatically on the next API request. Answer snapshots include the original label and value, keyed by tenant, record type, record ID and question ID. They are inserted inside the booking/lead transaction. A readable snapshot is also included in customer notes for the existing notification emails, lead/booking details and CRM history. Renaming or removing a question does not rewrite old records.

CRM export kind=answers includes question/answer and linked contact ID. Export remains tenant-scoped and available after CRM Plus is disabled; existing CSV formula escaping applies.

Deploy via the app repository. No private configuration or cron changes required. Custom fields are available in the production widget; the fictitious demo keeps its fixed example form.
