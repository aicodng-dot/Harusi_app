# Approved reference UI visual QA

Updated 2026-10-30 (Africa/Dar_es_Salaam). **Current evidence: reference inspection only.** All 39 original PangaEvents PNGs were individually opened and visually examined by the team. Their intrinsic metadata was read, and the measurement audit found all 39 before/after SHA-256 values unchanged. These results establish availability, reference hierarchy and asset integrity. They do not establish implemented appearance, functionality, accessibility or device behavior.

**Approved-design implementation: all 39 NOT STARTED; 0/39 COMPLETE. Implementation visual QA: 0/39 run. Functional QA for this integration: 0/39 run.** No browser implementation screenshots, UI actions, application tests or device checks were performed for this documentation integration. Historical test results elsewhere remain historical evidence for their named tasks. Phase 4 authorization and its existing gates remain active.

Read with the [screen/route matrix](REFERENCE_UI_SCREEN_MATRIX.md), [component matrix](REFERENCE_UI_COMPONENT_MATRIX.md), [measurement and implementation record](REFERENCE_UI_IMPLEMENTATION.md), [responsive plan](RESPONSIVE_LAYOUT.md), [test strategy](TEST_STRATEGY.md) and [project plan](PROJECT_PLAN.md). Approved PNG visuals supersede earlier TikTok-style directions at mapped faces; backend contracts still control actual behavior.

## Evidence definitions

| Label | What it establishes | What it does not establish |
|---|---|---|
| AVAILABLE / INSPECTED | Original file was readable and individually examined using `view_image`; intrinsic size and visible composition were recorded. | Working route, production asset, font identity, locale behavior or implemented match. |
| Metadata / raster measurement | Intrinsic PNG pixels; exact sampled colors and approximate artwork bounds where measured; unchanged originals. | CSS viewport/DPR, original font/radius/shadow tokens, responsive behavior or pixel-perfect app fidelity. |
| Implementation visual NOT RUN | No implemented screen was captured/compared for this integration. | Neither a pass nor a defect determination about the current legacy screen. |
| Functional NOT RUN / BLOCKED | No action was exercised here. BLOCKED identifies a missing/gated backend or customer exposure required before the depicted flow can be accepted. | A claim that the current legacy operation is broken, or permission to open a new route. |
| NOT STARTED adoption | The approved-reference design has not been implemented/accepted for this screen. | Absence of every legacy feature or source analog. |

## Reference inspection ledger: every original

01–19 are 863 × 1822 PNG px. 20–39 are 941 × 1672 PNG px. All were reported RGBA, 8 bits/channel, noninterlaced, with no authoritative DPI/DPR/CSS viewport metadata. Inspection was divided into individually viewed sets 01–13, 14–26 and 27–39; the count comes from those visual examinations, not merely a filename inventory. No file was unavailable. Every row's implementation adoption status is **NOT STARTED**.

| ID / original filename | Reference evidence | Implementation visual QA | Functional QA / dependency |
|---|---|---|---|
| 01 `01_Splash_Screen.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: routed onboarding/resume flow absent. |
| 02 `02_Register_Account.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: public signup, terms and onboarding contract. |
| 03 `03_Login.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; preserve current credential login; Google/register actions BLOCKED. |
| 04 `04_Choose_Event_Type.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: customer event wizard/type field and scope. |
| 05 `05_Event_Details.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; legacy event form analog only; customer time/type/card handoff BLOCKED. |
| 06 `06_Invitation_Preview.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: invitation renderer/studio/template/send flow. |
| 07 `07_Guest_List.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; legacy list analog only; customer scope/RSVP/delivery categories BLOCKED. |
| 08 `08_Guest_RSVP_Response.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: public capability and response contract. |
| 09 `09_Guest_QR_Ticket.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; current QR generation is an analog; public own-ticket capability/rendering BLOCKED. |
| 10 `10_Welcome_How_It_Works.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: routed onboarding; SMS claim unapproved. |
| 11 `11_Forgot_Password.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; existing email recovery contract must survive visual adaptation. |
| 12 `12_Event_Dashboard.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; admin admission dashboard analog; customer delivery/RSVP/device summary BLOCKED. |
| 13 `13_Choose_Invitation_Template.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: versioned visual-template catalogue/selection. |
| 14 `14_Edit_Invitation_Card.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: invitation editor/render/storage contract. |
| 15 `15_Add_Guest.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; preserve real guest/pass/QR fields; optional table field BLOCKED. |
| 16 `16_Import_Guests.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; preserve `.xlsx` review/confirm; CSV/arbitrary column mapping BLOCKED. |
| 17 `17_Invitation_Sending_Progress.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: durable authorized send batches/status. |
| 18 `18_Invitation_Delivery_Summary.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: real delivery/open/RSVP/reminder and PDF/Excel contracts. |
| 19 `19_Scanner_Home.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; existing scanner dashboard analog; ready-device/remaining summary needs defined data. |
| 20 `20_My_Events_List.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; admin event list analog; customer list/media/time/display lifecycle BLOCKED. |
| 21 `21_Upload_Custom_Invitation_Card.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: upload safety/storage/render/personalization. |
| 22 `22_Guest_Details.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; current guest counts/QR analog; resend/RSVP/composed-ticket timeline BLOCKED. |
| 23 `23_Manual_Guest_Search.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; preserve phone/four-digit search, duplicate matches, quantity/confirm and guards. |
| 24 `24_Admission_Success.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; existing committed-admit result state analog, no standalone success page. |
| 25 `25_Recent_Scans_History.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; current paginated history analog; proposed name/date/status filtering BLOCKED. |
| 26 `26_Reports_and_Analytics.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; current admission reports/CSV analog; RSVP/sent/PDF/Excel BLOCKED. |
| 27 `27_Messages_and_Reminders.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: authorized schedules/consent/delivery/retry; no SMS provider. |
| 28 `28_Account_and_Settings.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: own-account preference/integration/security settings contract. |
| 29 `29_Scanner_Team_Management.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; legacy staff analog; tenant teams/device telemetry BLOCKED. |
| 30 `30_Event_Setup_Progress.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: persisted setup prerequisites and next-step flow. |
| 31 `31_Select_Invitation_Recipients.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: authorized recipient/filter/selection/send contract. |
| 32 `32_Message_Delivery_Status.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: durable provider status/report/idempotent retry. |
| 33 `33_Public_Guest_Invitation.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: opaque personal capability, protected render/response/ticket. |
| 34 `34_RSVP_Summary.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: response aggregates/filters/export/reminders. |
| 35 `35_QR_Scanner_Camera.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; existing scanner camera analog; device/permission/torch switching remains untested. |
| 36 `36_Invalid_or_Used_Ticket.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; existing scanner failure-result analog; privacy and result distinctions must survive. |
| 37 `37_Confirm_Guest_Admission.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; existing quantity/admit analog; capacity/projection/concurrency remain authoritative. |
| 38 `38_Event_Settings.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; event edit is partial analog; RSVP/ticket policy/settings/delete contracts BLOCKED. |
| 39 `39_Subscription_Plans.png` | AVAILABLE / INSPECTED | NOT RUN | NOT RUN; BLOCKED: approved plans/pricing/billing/entitlements. |

## Actual inspection findings

The references consistently use strong task titles, muted explanatory copy, forest-green primary actions, warm pale foundations, white rounded cards, thin outline icons, text-bearing status pills and selective gold/botanical decoration. Invitation names/dates use serif contrast against sans-serif application copy. Those are visual findings; they do not identify fonts or establish accessibility contrast. Exact point samples and approximate representative bounds are in the measurement record.

The files contain several header/framing families. Earlier 863px-wide files include both screen-only art and drawn phone rims. 20–29 include phone framing. 30–39 use a restrained wordmark, but 30's inset panel/shadow differs from 37's measured full-bleed art. The cropped app hierarchy must be evaluated per original. Counts, QR shapes, named people, dates, phone numbers, table labels and prices are mock content; none was validated as real application data.

Current source analogs exist for login/recovery, legacy event/guest/report administration and web scanner operations. They are not approved-design matches or evidence of customer/public availability. Many depicted paths—public signup, invitation studio, protected public RSVP/tickets, outbound batches, own-account preferences and billing—remain planned. No visual resemblance can satisfy their missing backend or authorization dependencies.

## Reference discrepancy register

These are observed inconsistencies or required correctness exceptions to resolve during later implementation. Preserve the approved visual hierarchy while using truthful behavior/copy. Recording an exception is not a completed fix.

| ID | Screens / finding | Required resolution before acceptance | Current state |
|---|---|---|---|
| D01 | Many screens show EN active with Swahili content; 02–03 have ambiguous pale language state; 21 shows SW active consistently. 28 also shows SW in-page with EN header. | Visible selected locale, persisted preference and SW/EN strings must agree; do not copy the contradictory state. | OPEN; implementation NOT RUN. |
| D02 | 03 displays Google sign-in; 02/public registration is implied by auth links. | Do not render a functioning provider or signup promise until approved identity/routes exist; preserve credential/recovery behavior. | BLOCKED on owning identity/onboarding contracts. |
| D03 | 10/27 include SMS; WhatsApp/link reminders appear in several screens. | SMS is not an approved provider. Meta readiness/consent/template approval and actual channel state govern claims. A webhook acknowledgment/log is not outbound capability. | BLOCKED on P24 and channel approval where applicable. |
| D04 | 29's trust banner suggests shared-one-account scanner use. | Retain banner styling with security-correct individual-account/role wording. Do not normalize shared credentials or infer authorization from a device card. | OPEN; deliberate copy deviation required. |
| D05 | 26's RSVP counts 185+35+100 total 320, but 66.1%/12.5%/35.7% use about 280 and sum 114.3%. | Compute all response percentages from one explicitly named denominator. Keep invite records, people admissions, used tickets and remaining counts distinct. | OPEN; sample percentages must not ship. |
| D06 | 30 shows illustrative step 4/6 and 68% progress. | Derive completed/current/pending steps, percentage and next action from persisted prerequisites; no hardcoded success or fixed progress timer. | BLOCKED on setup-state contract. |
| D07 | 38 shows QR-after-RSVP toggle ON. | The illustration does not change backward-compatible default ticket/admission policy or invalidate existing passes. Persist future policy only through explicit approved settings/authorization. | OPEN dependency; no policy change made. |
| D08 | 39 shows TSh 29,000/79,000, free-forever wording, quotas/seats/template counts. | Treat all prices/features as illustrative until approved plan configuration, entitlement checks, billing terms and purchase flow exist. Preserve the required one-hundred-template engine roadmap. | BLOCKED on billing/entitlements. |
| D09 | 28 shows a dark-theme choice while its page is light; connected WhatsApp/device appearance is illustrative. | Selected theme and real rendered theme must agree. Connection and device state must come from authoritative data. | OPEN; settings backend/QA pending. |
| D10 | 16 advertises Excel/CSV and Column A/B/C mapping. | Current `.xlsx` required-header import/review/duplicate/confirm flow remains. Add CSV/mapping only through a separate validated contract, or use accurate supported copy. | OPEN; expansion BLOCKED. |
| D11 | 05/15/20–22 include event time/media/type/table information beyond current fields; lifecycle pills differ from active/archived/cancelled. | Do not invent persisted fields or overwrite stored statuses. Add approved fields or derive explicit display states later. | BLOCKED on relevant event/guest contracts. |
| D12 | 17/18/26/32 show sent/delivered/opened/response counts and exports. | Keep durable processing, provider acceptance, delivery/read/open, RSVP and admissions separate; current report exports are CSV, not PDF/native Excel. | BLOCKED on actual data/export contracts. |
| D13 | 23 omits the guest name and displays a direct admit action; 37 shows projected counters. | Preserve identity for duplicate-phone selection, explicit quantity and confirmation. Projection remains pending until server admission commits; enforce capacity/concurrency/offline rules. | OPEN; existing backend behavior authoritative. |
| D14 | 36 includes details within an invalid/used result card. | Unknown or foreign-event tickets must not reveal guest/event PII. Use generic unauthorized states and preserve distinct invalid/used/cancelled/revoked/wrong-event results. | OPEN; privacy acceptance required. |
| D15 | 34 shows 87% alongside 278/320 responses and 228 affirmative replies. | Label 87% as response rate, not attendance/yes rate; recompute from real response aggregates. | OPEN; response reporting BLOCKED. |
| D16 | 12/19/29 show ready/online scanner counts and battery/gate cards. | Staff-account counts do not prove device presence or readiness. Define telemetry or use truthful available metrics. | BLOCKED on device/team data. |
| D17 | 11 permits email-or-phone entry and shows “send instructions.” | Registered-email recovery and generic anti-enumeration status remain; never imply SMS/WhatsApp recovery or confirmed account existence. | OPEN; preserve existing recovery contract. |
| D18 | Reference brand, photos, illustrations and template designs are embedded in composite PNGs. | Obtain/create separately managed production assets and verify rendering. Never ship a whole screenshot as the interactive page, sample QR as a pass, or a thumbnail as a working template. | Asset implementation NOT STARTED. |

## Comparison plan — not executed

1. Open only the later task's approved routes/states using representative authorized fixtures. Record screen ID, commit/source manifest, route, role/workspace/event/guest context, locale, browser/device, CSS viewport, DPR, date and dataset. A current legacy analog does not automatically satisfy a customer/public screen.
2. Inspect each original again to choose its own app-content crop/mask. Remove drawn status graphics, phone bezel, exterior canvas/shadow and home indicator. Retain actual in-screen background/decor. Do not apply the same crop to all files in a dimension/header group.
3. Capture the implemented app at a documented viewport. Preserve aspect ratio when normalizing reference crop width; compare task/header/context, content order, typography hierarchy, card/field geometry, image placement, primary action, navigation and states. Compare the app, not the decorative device shell. Do not stretch a full mockup to a CSS device width.
4. Record intentional correctness deviations with discrepancy ID: locale, identity/provider claims, reliable counts, security copy, admission confirmation and policy/billing examples. Decoration cannot cover controls, QR quiet zones or focus indicators. Resolve unexpected differences before acceptance.
5. Attach original crop coordinates/masks and actual implementation captures/diffs as separate future artifacts. Record PASS/FAIL only for the route/state/locale/width actually observed. Do not call an unavailable feature, source read, old regression run or reference inspection a screenshot pass.

Representative measurement evidence provides **proposed comparison crops only**, not universal viewport or safe-area metadata. Boxes are `[left, top, right, bottom)` in original PNG pixels, with right/bottom excluded. Bounds have antialias/artwork uncertainty; status cuts are explicit editorial audit choices.

| Representative | Proposed crop | Why it differs / limitation |
|---|---|---|
| 03 Login | `[0,96,863,1822)` | Screen-only representative; top cut excludes drawn status graphics. Not a crop for rimmed 02/04–07. |
| 27 Messages | `[31,96,911,1657)` | Inside measured display/bezel transition; rounded corners still need masking; keep actual in-screen leaves/waves. Not a universal 20–29 crop. |
| 30 Setup | `[55,112,886,1629)` | Conservative inset-panel interior; exterior shadow excluded. Does not define a real device or the crop for 31–39. |
| 37 Admission | `[0,80,941,1672)` | Full-bleed representative, unlike 30's inset panel. |

Optional comparison normalization is `s = chosen CSS comparison width / crop PNG width`, applied uniformly after cropping; the measurement audit illustrated 390 CSS px. No original DPR or CSS size is known. Normalized sizes are proposed comparison units, not proof of a font/control dimension. Measured 27's primary action would normalize to about 39px high at that assumption, below the future ordinary 44px touch target: accessibility takes priority over literal reproduction, with any adjustment recorded.

## Responsive, keyboard and device plan — all NOT RUN

The following widths are the required future coverage grid for relevant implemented screens in **both Kiswahili and English**. Widths are CSS pixels; choose/document representative heights separately. These are planned checks, not captured screenshots.

| Widths | Planned layout checks | Current evidence |
|---|---|---|
| 320, 360, 375 | Dense headers/language controls, long SW labels, one-column reflow, pass/metric/plan cards, horizontal pill handling, QR and camera visibility, action reachability. | NOT RUN. |
| 390, 414, 430 | Primary phone comparison, card/field rhythm, no unexpected horizontal overflow, bottom CTA/nav spacing and virtual-keyboard interaction. | NOT RUN. |
| 768 | Tablet reflow, invitation/editor two-column choices where useful, readable list/chart density, menu and focus behavior. | NOT RUN. |
| 1024, 1280, 1440 | Desktop navigation/context, useful content widths, list/table/detail and chart layout, sensible invitation preview bounds; no artificial phone shell around the app. | NOT RUN. |

| Interaction / state family | Required future observations | Current evidence |
|---|---|---|
| Keyboard and assistive access | Tab/Shift+Tab, Enter/Space, visible focus, semantic action/field names, associated help/error, radio/checkbox behavior, menu/dialog Escape and focus restoration; logical reading order. | NOT RUN. |
| Touch / zoom / motion | Ordinary targets generally ≥44 × 44 CSS px; principal mobile/RSVP/scanner actions generally ≥48px high; 200% text zoom, sensible spacing, reduced motion and no hover-only essential action. | NOT RUN; source/raster dimensions are not a pass. |
| Forms | Empty/filled, validation, preserved safe old input, password eye/remember, submit loading/retry, keyboard open, autofill, no duplicate effect or lost selection; generic recovery status. | NOT RUN. |
| Lists / dialogs / progress | Empty, long/missing identity, multi-page data, filters/selection preserved, overflow menus, disabled actions, honest count units, server progress/retry and live announcements without repeated interruption. | NOT RUN. |
| Invitation / RSVP / ticket | Real selected template/version, protected own data/media/downloads, pending/yes/no/change/replay/expired/revoked states, QR scan readability and quiet zone, no admission consumed by RSVP. | NOT RUN; backend/assets BLOCKED where absent. |
| Scanner real device | HTTPS, permission denied/allowed, absent/busy camera, start/stop/release, torch/switch support detection, low light/rotation, repeated scans, manual fallback, verify/quantity/confirm, committed success, invalid/used/wrong-event/cancelled/revoked and retry. | NOT RUN on browser or device. |
| Authorization / provider / billing | Correct role/workspace/event/guest boundaries, revocation/denied/expired states, real Meta readiness/consent/status, individual scanner identities, accurate plan/quota/price and purchase consequences. | NOT RUN; missing/gated behavior BLOCKED. |

## Future acceptance record

For each implemented screen/state, record: screen ID; exact existing or newly approved route/view/component; source revision; prerequisites; locale; viewport/height/DPR/device/browser; authorized fixture lineage; reference crop; screenshot/diff paths; functional observations; discrepancy IDs; accessibility findings; failures/repairs and recheck scope. A pass belongs only to those observed conditions. Keep visual, functional, authorization and device results separate.

A screen becomes COMPLETE only after its owning implementation task and required dependency gates pass, actual renders and supported states have evidence at the agreed responsive/locale coverage, consequential actions retain backend/security correctness, and unresolved material defects have been addressed. This documentation does not mark any of the 39 screens complete, advance the active phase, or certify release readiness.
