# Approved reference UI component matrix

Updated 2026-10-30 (Africa/Dar_es_Salaam). This is **PLANNED documentation**, based on individual visual inspection of all 39 original PangaEvents PNGs. The approved references supersede the earlier TikTok-style direction for their mapped interfaces. Phase 4 authorization work remains active; this document implements no UI, component, route, asset, schema or provider integration.

**Approved-design adoption: 0/39 COMPLETE; all 39 NOT STARTED.** Reference inspection is evidence about the originals, not browser or functional acceptance. See the [screen matrix](REFERENCE_UI_SCREEN_MATRIX.md), [implementation evidence and measurements](REFERENCE_UI_IMPLEMENTATION.md), [visual QA ledger](REFERENCE_UI_VISUAL_QA.md), [existing component inventory](UI_COMPONENTS.md) and [project plan](PROJECT_PLAN.md).

## Current reusable source

| Existing source | Reusable responsibility today | Limit on future reuse |
|---|---|---|
| [Admin layout](../resources/views/layouts/admin.blade.php) | Desktop sidebar, mobile menu, selected-event context, account/logout, offline-lock messaging and confirmation dialog. | Legacy admin authority and navigation remain distinct from customer authority. The approved owner shell is not already implemented here. |
| [Scanner layout](../resources/views/layouts/scanner.blade.php) and [scanner navigation](../resources/views/scanner/_bottom-nav.blade.php) | Shared scanner document/Vite entry and four current scanner destinations. | Current body is dark; reference light surfaces are future presentation work. Assigned-event, HTTPS and scanner-role restrictions remain. |
| [CSS](../resources/css/app.css) | Card, metric, field, button, status, dialog, navigation, QR and scanner classes. | Existing classes do not establish reference colors, rounded geometry, typography or responsive acceptance. Current 36–40px action variants need review against future touch targets. |
| [JavaScript](../resources/js/app.js) | Submit disabling, confirmation, selection/copy feedback, pass quantities, camera, verify/result/admit state. | Preserve selectors, CSRF and endpoint contracts during later markup changes; localize visible strings without changing internal result identifiers. |
| [Guest form](../resources/views/admin/guests/_form.blade.php), [event form](../resources/views/admin/events/_form.blade.php), [staff form](../resources/views/admin/users/_form.blade.php) | Existing server field names, old-input restoration, validation and shared form markup. | Visual consolidation must retain required fields and existing validation/permission effects. Customer use requires separately authorized scoped actions. |
| [Login](../resources/views/auth/login.blade.php), [recovery](../resources/views/auth/forgot-password.blade.php), [reset](../resources/views/auth/reset-password.blade.php) | Working credential/recovery forms and status/error behavior. | These are standalone views, not a shared reference auth layout. No current Google OAuth or public registration backend is established by their appearance. |
| [Customer home](../resources/views/customer/home.blade.php) and [workspace setup](../resources/views/customer/workspaces/create.blade.php) | Existing account-scoped workspace landing/setup. | They do not provide customer event, invitation, guest, settings, report or billing routes. Do not route customer reference navigation into global admin views. |

There is no dedicated shared `resources/views/components` library in the inspected view inventory. All new component paths below are **PLANNED proposals**, not files created by this integration. Reuse existing Blade, CSS and JavaScript responsibilities before adding parallel implementations.

## Shell variants and framing

Source-image dimensions are intrinsic PNG pixels, not CSS viewport measurements. All originals contain drawn status graphics. Exclude fake time, cellular/Wi-Fi/4G/battery symbols, physical phone rims, exterior shadows/canvas and home indicators from web application chrome. Preserve actual in-screen design details, including useful botanical decoration, when they do not obscure content.

| Variant | References | Shared application treatment | Framing qualification |
|---|---|---|---|
| Intro / onboarding | 01, 10 | Centered brand/benefits; one clear start action where present; progress dots only when backed by a real journey. | 01 is a dark photographic intro; 10 is a light process-card composition. They are not ordinary forms or mandatory intermediate pages. |
| Earlier portrait brand / wordmark | 02–19, with intro exceptions above | Back or menu header, pictorial brand or spaced wordmark, language controls where shown, strong task heading, generous vertical rhythm. | All are 863 × 1822. Framing varies and must be inspected per original: measured 03 is full bleed, 08 has strongly rounded outer edges, and 09 has a drawn phone rim. No universal crop or no-bezel rule follows from this dimension/header group. Exclude each image's presentation frame/status artwork before comparison. |
| Ribbon-brand owner / scanner | 20–29 | Gold/green P mark plus wordmark, language pills, event/person context, white cards and selective cream/botanical decoration. | All are 941 × 1672 and include drawn phone framing. The interior envelope and amount of outside canvas vary per image. Screen 20 and 26 have different owner-nav item counts. |
| Minimal wordmark | 30–39 | Spaced PangaEvents text header, back/menu, language controls, restrained event strips and task cards. | All are 941 × 1672. 30 has an inset rounded panel with exterior shadow; measured 37 is full bleed. Group membership does not justify one crop, artificial panel, shadow or botanical footer for every screen. |

**PLANNED shared shell targets:** `resources/views/components/reference/page-header.blade.php`, `language-switch.blade.php`, `event-context.blade.php` and `mobile-navigation.blade.php`. Final names and extraction belong to the later scoped implementation task. Customer, legacy admin, public capability and scanner shells retain separate authority/navigation contracts while sharing visual primitives. Desktop must adapt the app hierarchy; it must not center an entire phone screenshot as the functioning interface.

## Token and typography evidence

These are semantic token responsibilities to define later in the existing CSS entry. Raster sampling supplies evidence for visual choices, not an authoritative original stylesheet. Texture, gradients and antialiasing cause variation. The exact samples below are original-image pixels, measured read-only with `System.Drawing.Bitmap.GetPixel`; coordinates are zero-based `(x,y)` PNG pixels. They are **not approved CSS constants** and no CSS was changed.

| PLANNED token responsibility | Measured raster examples | Intended use / verification |
|---|---|---|
| Page foundation | 03 at (20,1000): `#FEFBF4`; 27 at (400,220): `#FCFAF4`; 30 at (400,100): `#F8F9F6`. | Warm pale background variants; choose coherent tokens and validate contrast in real renders. |
| Primary action / brand | 03 at (100,1100): `#1B493B`; 27 at (200,1580): `#144136`; 37 at (500,1520): `#174C41`. | Deep green primary action, selection and brand; gradients may need separate treatment. |
| Surface | 03 at (400,580): `#FEFEFE`; 27 at (500,700): `#FEFEFD`. | White/light card and control surfaces; sampled pixels do not prove uniform fills. |
| Text and supporting copy | 03 headline ink at (249,286): `#04202D`; secondary ink at (628,382): `#4C5261`. | Strong near-black heading hierarchy and quieter body; actual foreground/background contrast remains untested. |
| Sage information / icon tint | 30 at (500,480): `#ECF3F0`; 37 at (100,1050): `#E2ECE5`. | Context cards and informational surfaces, with textual status meaning. |
| Gold accent | 03 logo ink at (276,180): `#D29734`; 30 progress at (200,596): `#C7A073`. | Brand/card ornament and progress accent; distinct source regions need not collapse into one flat token. |
| Border, radius, shadow and spacing | Border pixels and approximate element bounds were sampled; there is no recovered CSS box model. | Define an accessible consistent scale later. Do not turn antialiased edge samples, normalized PNG bounds or diffuse shadows into falsely exact CSS values. |
| Success / warning / failure / categorical tones | Visual inspection found mint/green, amber, pink/red, lavender and pale blue across lists, reports and scanner results. | Exact state colors were not established for every screen. Text/icons and stable internal status mapping carry meaning. |

Source CSS currently names `Instrument Sans` with system fallbacks. The active layouts do not establish a downloaded font asset. The rasters show a heavy sans-serif application hierarchy and contrasting serif invitation names/dates; no raster proves a family, numerical weight or CSS font size. Screen 14's visible “Playfair Display” selection is control text, not proof of the rendered font. Font availability, licensing/source choice, SW/EN coverage and render behavior remain later implementation prerequisites.

## Component families and complete screen coverage

This inventory partitions all 39 IDs once into the same A–H coverage batches as the [screen matrix](REFERENCE_UI_SCREEN_MATRIX.md). Cross-cutting components below may serve more than one batch. Every responsibility and target in this table is **PLANNED**.

| Batch / IDs | Component families | Existing source to preserve where applicable | New responsibility / dependency |
|---|---|---|---|
| A: 01, 02, 03, 10, 11 | Brand intro, auth header, labeled/icon field, phone identifier, password eye, remember/recovery row, language choice, terms, process cards. | Existing auth views, `identifier`/password/remember login POST, email recovery and reset. | Public registration/onboarding routes and locale behavior require their own contracts. Google is not an approved implemented provider. |
| B: 04, 05, 12, 20, 30 | Event-type photo choice, compact event details, event context, metric grid, event list, setup rail/progress and next action. | Legacy admin event form/index/dashboard; existing customer workspace landing/setup. | Customer event scope, event type/time/media and persisted setup prerequisites. Sent/replied/device metrics need real data, independent of admission totals. |
| C: 06, 13, 14, 21 | Invitation canvas/preview, visual template radio grid, category pills, swatches, font/image/text rows, upload drop zone, personalization field list. | Existing guest QR generation/download is compatible data infrastructure, not an implemented invitation renderer. | Versioned renderer/studio/custom assets, protected media and safe field substitution. Template catalogue and export behavior remain gated. |
| D: 07, 15, 16, 22 | Guest list/avatar/badge, guest form/pass choice/count stepper, import review, guest identity/counters, ticket preview and activity. | Current guest list/create/form/import/show, real QR, duplicate decisions and checkin history. | Customer-safe scoped exposure; optional table label, RSVP/delivery/resend/timeline contracts. Keep current `.xlsx` headers/review/confirm flow until expanded separately. |
| E: 17, 18, 27, 31, 32 | Recipient checkbox rows, filters/selected count, message/reminder cards, status tabs, progress donut/bar, delivery metrics, send history/retry. | Notification logs and Meta readiness/webhook surfaces are only partial analogs. | Meta consent/template approval, durable authorized batches/status/retry, reminders and report exports. SMS has no approved integration. |
| F: 08, 09, 33, 34 | Personalized invitation, RSVP choice sheet, own guest ticket/QR, capability errors, response summary/filters/chart. | Existing secure Guest→QrCode→Event lineage, generated QR/manual code and capacity. | Opaque public capabilities, protected own-ticket/media, response change/replay policy and authoritative aggregates. No guest account or public guest enumeration. |
| G: 19, 23, 24, 25, 35, 36, 37 | Scanner context/actions, real camera frame, manual lookup, result/receipt, admission quantity/projection, history/filter/list, invalid/used state. | Scanner layout/nav, `scanner.index`, manual-search/recent-scans, current camera/result/admit JavaScript and endpoint contracts. | Light reference presentation; actual-device capability QA and later scoped team/shared-admission work. Preserve explicit verify/confirm/admit, quantity bounds, unknown-ticket privacy and offline locks. |
| H: 26, 28, 29, 38, 39 | Operational report/metric/chart/export, profile/settings rows, role/team/device cards, event toggles, plan/price/upgrade cards. | Current admin reports/settings/users/event edit are limited legacy analogs. | Tenant-safe own-account/team/settings/report/billing contracts; separate RSVP/delivery metrics and device telemetry. Prices, quotas and toggle samples are not policy. |

## Shared component contracts

All paths here are **PLANNED**, under `resources/views/components/reference/` unless an existing owner is named. The future task may refine extraction without changing the screen IDs or backend behavior.

| Family / proposed partial | References | Required states and behavior |
|---|---|---|
| Header, context and navigation: `page-header`, `event-context`, `language-switch`, `mobile-navigation` | 02–39 where shown | Current task, explicit authorized context, active destination/locale, back/cancel behavior, keyboard menu and focus return. No fake device chrome; no customer links into global admin actions. |
| Action/field: `action`, `field`, `password-field`, `alert` | 02–05, 11, 14–16, 21, 23, 28, 37–39 | Semantic link versus button; persistent label/help/error; safe old input; visible focus; loading/disabled/error/success. Text controls must stay accessible when leading/trailing icons are decorative. |
| Selectable visual card / pill: `choice-card`, `filter-pills` | 04, 13, 15, 20, 25, 27, 31, 34, 39 | Native radio/checkbox semantics or equivalent tested keyboard behavior, visible text/check selection, no color-only meaning; preserve filters and selections. |
| Lists and identity: `guest-row`, `avatar`, `status-badge`, `activity-row` | 07, 17–19, 22, 25, 27, 29, 31, 32, 34, 36, 37 | Long names/phones, missing identity, empty/loading/error, pagination/overflow actions; separate delivery, response, admission usage and scan result. Do not expose unknown/foreign-ticket PII. |
| Metric/progress/chart: `metric-card`, `progress`, `chart-summary` | 12, 16–19, 26, 29, 30, 32, 34 | Named unit/denominator, zero/partial/complete/failure state; accessible value and tabular/text chart alternative; updates announced at sensible intervals. Progress comes from server prerequisites or durable work, not animation. |
| Upload/import: `upload-field`, `import-summary` | 16, 21 | Supported file type/size and error copy must match validation; keyboard file picker; review/confirm and duplicate choice; loading/retry; authoritative ready/invalid totals. A decorative drag zone alone is not implementation. |
| Invitation/ticket: `invitation-preview`, `guest-ticket` | 06, 08, 09, 13, 14, 21, 22, 33 | Real event/guest data; preview/loading/render failure; selected design version; sufficient QR quiet zone/readability; protected downloads. Templates, public capabilities and render assets remain dependencies. |
| Admission: adapt existing scanner result/quantity markup before extracting `admission-result`, `admission-quantity` | 19, 23–25, 35–37 | Camera denied/unavailable, verification, selected count, projection, submitting, committed success, used/cancelled/revoked/wrong event/invalid, retry and Scan Next. Server capacity and concurrency checks remain authoritative. |
| Settings/team/plan: `settings-row`, `team-card`, `plan-card` | 28, 29, 38, 39 | Real persisted setting/account/role/plan, unavailable prerequisite, saving/error/success and consequential-action review. Individual scanner accounts; real telemetry only; no invented purchase/provider activation. |

Ordinary touch actions target at least 44 × 44 CSS px; principal mobile/scanner/RSVP actions should generally be at least 48px high. These are future acceptance targets, not measured passes. Preserve visible keyboard focus, 200% text zoom, sensible tab order, screen-reader labels, reduced motion and safe bottom-action visibility when the keyboard opens.

## Asset availability and constraints

| Asset family | Actually available / inspected | PLANNED integration boundary |
|---|---|---|
| Approved reference images | All 39 original composite PNGs remain in `reference UI`; metadata and before/after hashes were inspected. | Keep originals immutable. They are design evidence, not full-page interactive assets or production QR codes. Any later crop/derivative must use a separate file and retain provenance. No derivative was made here. |
| Logo / icons / illustration | Visible P ribbon marks, plain wordmarks, outline icons and dimensional onboarding illustrations are embedded in the reference composites. | No matching standalone production brand/icon/illustration set was found in the `resources`/`public` raster/vector inventory. Reusable accessible assets need a later source/creation and validation task; initials/text abbreviations currently exist in live markup. |
| Event / couple / venue photos | Wedding couple, rings, beach arch, banquet, balloons, candlelit event and related thumbnails are visible in inspected originals. | No standalone production originals/catalogue was found in that inventory. Define approved sample assets and real event media/storage before shipping; do not present the pictured couple as every event's real identity. |
| Botanical / gold decoration | White flowers, green leaves, cream/gold curves, gold lines/hearts/ornaments and forest backdrop are reference artwork. | No reusable production layers were found. Use separate future assets where approved; keep decoration away from inputs, text, QR quiet zones, focus outlines and bottom actions. Do not include outside-phone artwork by accident. |
| Invitation template files | Four named designs and additional card examples are pictured. | A photographed template thumbnail is not a working versioned design. The existing planned default/five/twenty/one-hundred distinct-template engine gates remain; this integration supplies no verified template asset. |
| Generated QR | Current QR service and authenticated PNG/download endpoints exist. | Use actual secure QR lineage and four-digit manual code. Never extract/decode a sample raster QR into a production pass. Composed invitation PNG/PDF exports are separately gated. |
| Fonts | CSS names Instrument Sans plus system fallbacks; serif appearance and one font-picker label are visible in references. | No font identity or bundled download was established from the rasters. Choose/verify available fonts later; prevent missing glyphs and layout shift. |

## Contracts that visual reuse must preserve

Existing routes, CSRF, throttles, HTTPS, role/workspace/event/guest lineage and fresh action authorization remain authoritative. A legacy admin analog is not a customer route. Public access needs an opaque capability and exact own-guest scope; no raw guest enumeration or scanner login is implied. Account and support operations retain existing identity/recovery semantics, including generic registered-email recovery status.

Delivery attempts/provider acceptance/delivery/read or opened invitation, RSVP response, pass usage and scan result are separate systems. Current Meta readiness says delivery processing/template approval work is pending; no Google or SMS backend is approved by the mockups. Device online/battery/ready counts require telemetry, not staff-account counts.

Single=1, Double=2 and Special/Family=3–100 remain the admission capacities. QR and manual codes stay distinct. RSVP never consumes entries, and the QR-after-RSVP toggle shown ON in 38 does not alter the backward-compatible policy for existing passes. The shared-account banner in 29 needs individual-account security wording; plan prices/quotas in 39 remain examples. The [QA discrepancy register](REFERENCE_UI_VISUAL_QA.md#reference-discrepancy-register) records the deliberate correctness deviations.

Later component and screen tasks must record their exact source manifest and focused verification cases before implementation. No component design in this document opens a gated feature or marks the current authorization phase complete.
