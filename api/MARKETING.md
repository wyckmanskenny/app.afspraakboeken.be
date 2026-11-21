# Marketing v0.19

The API migrates marketing.sql once using the existing audit/GET_LOCK mechanism. No manual SQL import is necessary.

- ab_marketing_forms: stable form identity owned by a tenant; a tenant starts with one default form. Multi-form creation and independent form content are not exposed yet.
- ab_marketing_visits: one consented browser-tab visit, deduplicated by tenant/form/UUID, with first observed source/medium/campaign, mode and cumulative step flags. Consent choice and ID last up to 30 minutes in sessionStorage. No identity stitching, IP addresses, field answers or contact details are stored here. Existing anti-abuse infrastructure uses its normal rate-limit mechanism.
- ab_marketing_submissions: durable, idempotent server-side records of successful public submissions. Inserted in the booking/lead transaction. Includes unmeasured submissions; cancelled bookings remain historical conversions. Old submissions are not backfilled. A later internal booking of an existing lead does not count as a second public conversion.

Reports use Brussels calendar boundaries. Funnel figures are visit cohorts: visits started in the selected period and their conversions known now. Receipt totals are separate and follow submission time. Filters for source, campaign, device and flow apply to measured cohorts; receipt totals only follow dates and form, as labelled. Previous period has the same number of calendar days. No ad-cost or revenue estimates. Report window at most 366 days, maximum 100,000 visits; a shorter window is required above the cap.

Tracking is disabled initially. The business can opt in from Marketing > Tracking instellen, after which a visitor sees an optional consent choice. Refusal never prevents submitting. The first measured view requires IntersectionObserver visibility. Missing/refused measurement is not assigned invented visit data. Refresh within the consent window reuses the visit ID. Session count is not a unique-person count.

The wrapper forwards UTM values and referrer origin. Update cached widget snippets to widget.js?v=0.19.0 for medium/referrer forwarding and Meta conversion handling. No arbitrary custom tracking scripts are accepted. An explicit visitor consent and a persisted booking/lead are required for Meta Schedule/Lead. Only the configured numeric pixel receives the event (trackSingle), autoConfig is disabled, and record ID is used for event deduplication. Existing independently configured GTM tags must be checked by the account owner to avoid their own duplicate conversions. Pixel configuration is not presented as verified delivery: validate in Meta Events Manager. Meta test-delivery diagnostics, ad-cost imports, lead-outcome reporting and multi-form editing are follow-up work.

CSV exports the selected measured cohort rows; it has a UTF-8 BOM, semicolon separators, quoted cells and spreadsheet-formula escaping. It contains no individual contact details. Existing CRM exports remain separate.

Tests: marketing-integration.py uses a disposable localhost fixture from api-integration.py; marketing-smoke.mjs covers filtering, CSV, consent and reload deduplication. Existing booking/portal smoke tests cover regressions.
