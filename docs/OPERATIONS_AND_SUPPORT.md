# Operations and support

## CURRENT

Global administrators manage events/users, selected-event guests/QR/history/reports, notification logs and offline session/package/import/conflict workflows. There is no customer self-service support desk, workspace billing or support-access audit mechanism. Existing offline session audits/import provenance can be reused.

## TARGET / PLANNED

Staff manage platform customers/workspaces, health, templates, plans and support. Customers manage their events. Support access must be explicit, time-bounded and auditable; a support screen must not bypass tenant policies. Account recovery without working email requires a documented identity-verification process with no automatic disclosure/phone-only takeover.

Event-day checks: verify event status/assignments, scanner HTTPS/camera, server connectivity, QR/manual lookup, remaining capacity, offline lock state, package version and master authority. Capture and reconcile pending device admissions before closing an offline session. Avoid recording secret codes/tokens or guest contact data in support tickets/screenshots.

Monitor auth failures/throttles, failed admissions, over-capacity/invariant alarms, offline conflicts, missing files, DB/queue/mail failures, signed-webhook failures and storage quotas. Define staff response, escalation and restoration procedures after deployment capability is established. Report failures without exposing secrets.

## REQUIRES VERIFICATION

Support ownership/SLA, incident contacts, actual logging/monitoring/cron/mail setup, data retention, approved recovery checks and maintenance windows. No external communication, live message sending or production operational changes occurred in Phase 0/1.
