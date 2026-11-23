# PangaEvents approved reference UI implementation

Updated 2026-10-30 (Africa/Dar_es_Salaam). **Current scope: reference inspection, measurement, mapping and roadmap integration. No approved UI screen has been implemented by this update.** Phase 4 authorization preparation remains active; P4-038 is complete at 2,028 tests / 41,407 assertions. The reference adoption task does not restart migration, open customer routes or change that backend gate.

## Authority and coverage

The 39 original PNGs in [`reference UI/`](../reference%20UI) are the visual source of truth for their mapped PangaEvents screens. All 39 were individually visually examined; none is missing. Their artwork, layout hierarchy, brand, typography proportions, decorations and screen flow govern future implementation. The earlier eight TikTok references remain historical journey evidence. Their competitor artwork is not an asset source for these approved PangaEvents designs.

PangaEvents is the approved user-facing identity for this reference adoption. Kadify/Harusi names in historical migration evidence, backend identifiers, database structures and existing source are not silently renamed. Backend/security contracts remain authoritative for behavior. Correctness, access protection, accessibility, responsive adaptation and the explicitly recorded conflicts in [visual QA](REFERENCE_UI_VISUAL_QA.md) are the permitted reasons to depart from a literal pixel or sample label. Report each departure; do not redesign the interface.

The [screen matrix](REFERENCE_UI_SCREEN_MATRIX.md) contains all 39 routes/views or explicit missing dependencies, proposed targets, components, authentication requirements and independent implementation/QA states. **Reference inspection: 39/39; approved UI completion: 0/39; rendered visual comparisons: 0/39.** Existing legacy views and backend tests do not constitute approved-design acceptance.

Keep originals immutable: no resizing, renaming, recoloring, regeneration or replacement. The [measurement evidence](REFERENCE_UI_MEASUREMENTS.json) records all original hashes and intrinsic metadata. Any later approved logo/photo/leaf extraction, optimized derivative or comparison capture belongs in a separate directory with its source and crop recorded. Never make an entire reference PNG an interactive-page background. Buttons, fields, charts, lists and navigation must be real components driven by authorized state.

## Measured evidence and normalization

All files are 8-bit RGBA PNGs. IDs 01-19 are **863 x 1822 PNG pixels**; IDs 20-39 are **941 x 1672 PNG pixels**. No pHYs/DPI metadata is present. Image dimensions do not establish browser viewport, device pixel ratio, exact font family or CSS box metrics. Framing varies even within these size groups: inspect the individual original. Drawn phone rims, synthetic OS bars, exterior shadows and home indicators are presentation; botanical artwork inside the application remains part of its design.

The evidence JSON preserves exact sampled pixels, sampling coordinates/methods, representative scanlines and approximate element boxes. These are raster observations, not recovered CSS declarations. Texture, gradients, anti-aliasing and shadows make adjacent samples differ. No original font files, separately supplied brand SVG or extracted decoration assets were established by this inspection; exact font identity/weight, CSS shadow and radius parameters remain implementation verification work.

Representative exact samples (zero-based original coordinates):

| Reference and role | Coordinate | Observed RGB hex |
|---|---|---|
|03 background / primary / headline ink|20,1000 / 100,1100 / 249,286|`#FEFBF4` / `#1B493B` / `#04202D`|
|03 white input / border edge / secondary ink|400,580 / 432,556 / 628,382|`#FEFEFE` / `#EDECE6` / `#4C5261`|
|27 background / primary / surface|400,220 / 200,1580 / 500,700|`#FCFAF4` / `#144136` / `#FEFEFD`|
|27 sage tint / botanical leaf / logo gold|120,570 / 60,1610 / 329,139|`#EEF5EC` / `#87A482` / `#CB901B`|
|30 background / primary / gold progress|400,100 / 200,1500 / 200,596|`#F8F9F6` / `#164940` / `#C7A073`|
|37 background / primary / headline ink|400,100 / 500,1520 / 288,209|`#F8F9F7` / `#174C41` / `#1A312E`|

The initial comparison baseline is an **explicit engineering assumption of 390 CSS pixels**, not a discovered device viewport. Given a selected application crop `[L,T,R,B)`, use `s = 390 / (R-L)`, then `x_css=(x-L)*s`, `y_css=(y-T)*s` and `size_css=size_png*s`. Refine the per-screen crop/viewport after an actual browser comparison; retain the measurement provenance. Do not normalize all 39 against the full PNG width or one blanket crop.

| Representative reference | Proposed comparison crop, PNG px | Scale at assumed 390 CSS px | Measured approximate element box, PNG px | Normalized size under that assumption |
|---|---|---|---|---|
|03 Login|`[0,96,863,1822)`|0.45191194|Input `[50,556,815,698)`; CTA `[50,1048,815,1175)`|Input 345.71 x 64.17; CTA 345.71 x 57.39 CSS px|
|27 Messages|`[31,96,911,1657)`|0.44318182|First card `[64,521,879,711)`; CTA `[121,1546,820,1634)`|Card 361.19 x 84.20; CTA 309.78 x 39.00 CSS px|
|30 Setup|`[55,112,886,1629)`|0.46931408|Use JSON element bounds, not the exterior panel/shadow|Inset panel needs its own crop; no blanket 30-39 crop|
|37 Admission|`[0,80,941,1672)`|0.41445271|Use JSON element bounds and full-bleed application width|Different scale and framing from 30|

Element bounds have approximately 2-4 PNG-pixel edge uncertainty; text ink bounds are not font metrics. Status-bar cutoff rows are chosen comparison exclusions, not measured safe-area values. The 39 CSS-pixel CTA estimate in 27 is a documented accessibility conflict: preserve its appearance where possible while providing a nonoverlapping target of at least 44px, preferably 48px. Do not shrink all other controls to a screenshot-derived undersized hit area.

## Shared implementation architecture

Use the existing Laravel 12/Blade, Tailwind 4/CSS, Vite and JavaScript architecture; reuse actual equivalent services found in source. Do not add a generic admin theme or 39 isolated page implementations. The [component matrix](REFERENCE_UI_COMPONENT_MATRIX.md) defines shared units and screen-specific variants. Public/auth, customer portal, guest invitation, scanner and platform administration retain separate role-appropriate layouts with common branding and tokens.

Centralize semantic colors (`--color-primary`, hover, background, surface, muted surface, primary/secondary text, border, accent, success, warning, danger), typography/line-height, spacing, radius, shadow, field/button heights, headers and navigation dimensions. Scope approved ribbon-logo/warm-botanical and minimal-wordmark/sage variants deliberately; common tokens must not erase differences visible in the originals. Derive initial values from measured evidence and verify rendered outcomes. Do not invent hover colors, font identity or exact shadow values and label them measured. Use existing source font stack only as an explicitly recorded fallback until a suitable licensed font is identified and compared.

Real CSS/Blade/SVG elements provide headers, fields, icons, cards, charts and selection. Invitation artwork may be a properly separated approved decorative/background asset; guest names, editable text, QR and controls remain actual rendered content. Preserve original artwork proportions, QR quiet zones and long-name handling. Do not generate replacement imagery for a missing asset or replace the 100-original-template engine with the reference image gallery.

## Backend boundaries

Reuse current shared identity, Event/Guest records, phone normalization, existing import/QR/pass logic, notification provider abstractions and scanner admission behavior. Some requested service names are targets rather than installed classes: there is no inspected GuestService, invitation editor/renderer or unified AdmissionService implementation to assume available. Phase 5 supplies reviewed reusable contracts; later owning phases implement their features. Existing scanner controller remains authoritative until that compatible extraction is verified. No alternate guest table, QR system, scanner identity, admission algorithm or fake successful action is permitted.

Customer routes require active identity, workspace membership, event ownership, resource and operation permission. Existing legacy admin pages are not customer endpoints. Public invitation/RSVP requires secure opaque guest-specific access without mandatory account creation. Keep invitation delivery, RSVP intent and actual admissions separate. Existing pass validity remains the default; the ON toggle in38 does not approve RSVP-gated admission. Current queued Meta/provider acceptance is not delivery; verified webhook status is required. SMS, Google login, device telemetry, new preference settings and purchases require their own approved working contracts. Missing features are blocked honestly, not represented by mock success.

All example guests, counts, percentages, prices and quotas are illustrative. Use one authoritative unit/denominator for statistics; show actual state. Preserve Single/Double/Special capacities, used entries, manual codes, admission history and offline restrictions. Account/team users retain distinct authorized identities; replace the shared-credential implication in29 while preserving the notice styling. See [API](API_CONTRACT.md), [database](DATABASE_CONVENTIONS.md), [RSVP](RSVP_AND_GUEST_TICKETS.md), [WhatsApp](WHATSAPP_INTEGRATION.md) and [scanner](SCANNER_ARCHITECTURE.md).

## Batches within the existing roadmap

These are **NOT STARTED** implementation batches, not a new migration sequence. Preserve every prior task status and all 20 earlier UX tasks; link their outcomes to the 39-screen matrix. Open small tasks within the owning phases after their dependencies. Reuse foundation components before feature screens and carry IDs through later work so none is abandoned.

| Batch | References | Existing phase placement and prerequisites |
|---|---|---|
|A Entry/authentication|01,02,03,10,11|P6 patterns/localization and existing P2 auth/recovery; public entry P7, registration/onboarding P10 after tenant gates|
|B Events/dashboard|04,05,12,20,30|P8/P9 authorized portal/event data; preview/count handoffs only after P17/P19/P24|
|C Invitation design|06,13,14,21|P16 renderer/data, P17 preview/editor, P18 uploaded art; P21-23 retain 100-original-design scope|
|D Guests|07,15,16,22|P5 contracts, P6 shared patterns, P9 self-service; P19/P24 before RSVP/send-history claims|
|E Distribution|17,18,27,31,32|P19 secure links and P20 exports; P24 approved Meta templates/consent/queues/status/retry/usage|
|F Guest invitation/RSVP/ticket|08,09,33,34|P16/P18 render assets, P19 secure access/replies/tickets/counts and authoritative pass compatibility|
|G Scanner|19,23,24,25,35,36,37|P6 existing website presentation; P12/P13 teams/shared backend; P14/P15 native/offline only in their approved phases with Android source available|
|H Reports/team/settings/plans|26,28,29,38,39|P5/P9 reports; P6/P11/P12 preferences/team; P19 RSVP settings; P24 message settings; P25 billing and approved prices|

For each implemented screen: inspect original, establish crop/viewport and assets, reuse/build actual components, wire approved backend, capture at comparable viewport, compare side by side, fix targeted discrepancies, test behavior/SW/EN/responsiveness, update both QA states, and report the batch. Diagnose specificity, asset, viewport, font, layout or stale build issues before rewriting components. Do not undertake one giant 39-screen source change.

Mobile QA widths are 320,360,375,390,414,430; tablet/desktop 768,1024,1280,1440. Desktop uses bounded content, appropriate grids/tables and role-specific navigation with the same identity and journey. Document desktop adaptations where no reference exists. Check keyboard/focus/contrast, long localized text, safe areas, camera permission, loading/empty/error/retry, dialogs, fixed navigation, QR clipping and hidden Save/RSVP/admission controls. These are planned checks, not executed browser evidence.

## Acceptance and reporting

Use NOT STARTED, IN PROGRESS, VISUAL REVIEW, FUNCTIONAL REVIEW, COMPLETED or BLOCKED for each screen. Complete requires both reference-comparison and real functional acceptance, including declared mobile/desktop widths and SW/EN. Backend absence can block functionality while a separately approved future UI task builds a safe disabled structure; it cannot justify fake actions or39/39 completion.

Each batch report names IDs/files/components/routes/services, visual differences, functional/responsive/localization checks, blockers and next batch. Keep [visual QA](REFERENCE_UI_VISUAL_QA.md) factual. Final audit must account for all 39 entries and unresolved states. Current reference adoption changes documentation/evidence only; no production UI, locale switch, signup, invitation, RSVP, pricing, payment, messaging or Android implementation is claimed.
