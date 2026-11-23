# Self-service onboarding

Current review: 2026-10-23. Existing active customers can create an empty workspace through P4-009 setup and automatically own it. Public registration and event onboarding remain closed. P3-022/P4-008 provide the shared creation transaction and selected engine evidence; P4-003 provides neutral customer identity and the own-workspace home.

## CURRENT

[AuthController](../app/Http/Controllers/AuthController.php) supports email-or-phone/password login and logout. P2-003 adds [registered-email recovery](ACCOUNT_RECOVERY.md) and reset endpoints. [Routes](../routes/web.php) still contain no public registration. Admins currently create events, guests and scanner users using separate screens. [Dashboard](../resources/views/admin/dashboard.blade.php) is focused on admissions and metrics, without a setup wizard or persisted progress.

## TARGET

Registration collects full name, email, phone, password, confirmation, and preferred language; default Kiswahili. Reuse one account for customer, scanner website and future Android access. Follow [authentication architecture](AUTHENTICATION_ARCHITECTURE.md); do not introduce paid SMS/WhatsApp OTP.

| Step | Minimum purpose | Completion evidence (proposed) |
| --- | --- | --- |
| 1. Choose Event Type | One-tap category, initially Harusi, Send-Off, Kitchen Party and Sherehe | Category retained for the next form; this is not template selection |
| 2. Event Details | Names/event title, date/time and venue only | Authorized Event saved through the planned EventService responsibility; validated timezone/content mapping |
| 3. Instant Invitation | Render an appropriate professional default; optional Change Design/Quick Edit | Saved event-linked design and real preview; no mandatory advanced editor |
| 4. Add/Import Guests | Send to Guests opens add/import when empty, recipient selection otherwise | Same saved card preserved; existing guest/import infrastructure reused with row errors |
| 5. Review and Send | Confirm recipients, consent, message and invitation before dispatch | Authorized batch with honest queue/provider progress or manual-sharing fallback |
| 6. Responses and Readiness | Monitor replies; complete scanner-team setup and event-day readiness | Server-derived RSVP counts, authorized existing passes and active scanner assignments; no invented completion percentage |

Every step has a clear title, one short explanation, minimal fields, a primary action and Back. Use task-specific actions: **Tengeneza kadi / Create Invitation** on details and **Tuma kwa wageni / Send to Guests** on preview. Save/continue applies where appropriate; avoid two competing primary submit actions. Skip appears only for optional steps. Retain saved progress across sessions and device changes. Readiness is derived from records, not solely a stored step index.

The 2026-10-25 [reference flow](REFERENCE_UX_FLOW.md) replaces the earlier guests-before-preview target ordering. Family names, ceremony/place of worship, reception, contacts and dress codes belong in later Quick Edit. Do not require twenty fields before a first card. Category-specific labels should accept an event title for non-couple events; do not infer bride/groom fields by splitting arbitrary names on `&`. The existing date-only `event_date` requires an explicit additive time/timezone design before this form ships; preserve legacy dates.

## PLANNED

Registration is Phase 10 after workspace migration and authorization tests. Invitation capabilities arrive in Phases 16–20: early onboarding must represent their unavailable/deferred state honestly, not require an unimplemented editor or claim the complete reference flow is delivered. The full default onboarding journey is released only after instant preview, public guest access and sending gates are met. Do not replace the planned engine with a temporary disconnected card generator to bridge phases.

Create clear recovery paths for failed import, duplicate guest, interrupted upload, export failure, missing scanner, incomplete event, and offline event lock. A completed step must be re-evaluated if the underlying event data changes. Cross-workspace requests cannot restore or overwrite another customer's draft.

## REQUIRES VERIFICATION

Confirm required fields, optional steps, readiness rules, persistence schema, support-assisted recovery, and invitation availability at onboarding launch. Test refresh/back navigation, repeated submission, session expiry, multi-device editing, validation errors, 320px forms with keyboards, both languages, and tenant isolation. No database/schema changes were made for this specification.

## IMPLEMENTED — automatic ownership foundation

P3-022 prepares [transactional creator ownership](WORKSPACE_PROVISIONING.md): new workspace creation automatically gives its authenticated creator the initial active owner membership with safe retry handling. The customer may be a person, organization or planner and needs no developer-prepared file. No existing event is adopted automatically. This service has no runtime caller; safe customer identity, tenant policies and portal/setup forms are subsequent tasks.

## IMPLEMENTED — P4-003 identity prerequisite

Neutral customer web identity now supports safe shared sign-in/remember/recovery and an own-workspace read-only home. It gains no staff/scanner/device capability; legacy staff CRUD cannot rewrite customer accounts. Signup, automatic account/workspace setup and event steps remain closed until the remaining isolation/engine/rollout gates pass. New workspace ownership remains automatic for its authenticated creator, with no developer manifest. [Contract](CUSTOMER_WEB_IDENTITY.md).

## IMPLEMENTED — P4-009 customer workspace setup (2026-10-23)

Existing authenticated customers now create an empty workspace through two protected HTML routes and automatically become its active owner. Exact customer role is rechecked under the creator lock before creation/replay; submitted ownership/global fields grant no authority. Matching retries add no rows and failed inserts roll back. Full isolated source gate: **1,275 tests / 22,874 assertions**. Event/guest writers, signup and complete tenant isolation remain closed; no configured data or deployment changed. [Setup contract and verification limits](CUSTOMER_WORKSPACE_SETUP.md).
