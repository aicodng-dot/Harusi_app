# Responsive layout

Approved-reference target (2026-10-30): [39-screen fidelity and normalization](REFERENCE_UI_IMPLEMENTATION.md) governs future responsive UI. PNGs have no authoritative CSS viewport/DPR; use per-image presentation exclusions and documented comparison assumptions. Add414px to the existing320-430 mobile matrix; retain768/1024/1280/1440 desktop checks. Phone bezels/OS bars are excluded; application decorations remain. No rendered comparison passed in this documentation update.

Audit date: 2026-10-11. This specialist audit inspects CSS and templates. Unless explicitly recorded in the [current audit](CURRENT_SYSTEM_AUDIT.md), browser/device behavior remains unverified.

## CURRENT

Admin uses responsive padding, a 280px sticky sidebar from the `md` breakpoint, horizontally scrolling navigation pills below `md`, and a sticky header. A selected-event banner adds another header row on mobile. Scanner uses a centered `max-w-md` shell, safe-area padding, fixed bottom navigation, and a camera region with `min-h-72` and `max-h-[50vh]`. Sources: [admin layout](../resources/views/layouts/admin.blade.php), [scanner CSS](../resources/css/app.css), [active scan view](../resources/views/scanner/index.blade.php).

`/scanner/scan` renders `scanner.index` through `ScannerController::index`. The separate [scanner/scan placeholder](../resources/views/scanner/scan.blade.php) is not the active route view. Do not infer the scanner is only a placeholder from that file. Sources: [routes](../routes/web.php), [controller](../app/Http/Controllers/ScannerController.php).

| Source-level risk | Why to verify | Priority widths |
| --- | --- | --- |
| Sidebar activates at `md`, remains 280px | At 768px only 488px remains before content padding; header actions may compete | 768, 1024 |
| [Check-in filters](../resources/views/admin/checkins.blade.php) use `lg:grid-cols-[minmax(240px,1fr)_190px_190px_160px_auto]` | Fixed minima plus gaps exceed available main content around 1024px | 1024, 1280 |
| [Guest filters](../resources/views/admin/guests.blade.php) use fixed 180/200px columns and 220px search minimum | Content width, not viewport width, determines fit beside sidebar | 1024 |
| Tables have 760px guest, 1240px QR, 1320px check-in, 1180px event/notification minimum widths | Containers scroll; discoverability, keyboard access, action reachability need checks | All phone widths, 768 |
| Scanner result and manual-admission rows combine labels with a 44+64+44px counter | Nested card padding leaves little space for labels and translated strings | 320, 360 |
| [Bottom navigation](../resources/views/scanner/_bottom-nav.blade.php) has four items | Longer labels and safe areas may crowd controls; verify four-column computed style | 320, 360, 390 |
| Scanner camera minimum height is 288px | Short screens and landscape may require scrolling past camera before admission controls | 320, 390; short heights |
| [Confirmation dialog](../resources/views/layouts/admin.blade.php) has a viewport-based width but no explicit component max-height | Long copy, translated actions, and keyboard reduce available height | 320, 375; short heights |
| Manual result names and phone values lack explicit word-break handling | Long unbroken values can overflow; scanner body hides horizontal overflow | 320, 360 |

These are inspection findings and test hypotheses, not a claim that each fails visually. Horizontal table scrolling is an existing strategy; it does not by itself prove whole-page overflow.

## TARGET

All primary flows must be usable at the widths below in both languages. Page-level horizontal overflow, clipped navigation, hidden Save/Continue/Confirm buttons, inaccessible forms, overlapping text, modal overflow, and unreachable invitation controls are release blockers. Wide data tables may use a deliberate accessible scroll region or a suitable mobile representation. Fixed elements must reserve enough content space.

| Width (CSS px) | English | Kiswahili |
| --- | --- | --- |
| 320 | Pending | Pending |
| 360 | Pending | Pending |
| 375 | Pending | Pending |
| 390 | Pending | Pending |
| 430 | Pending | Pending |
| 768 | Pending | Pending |
| 1024 | Pending | Pending |
| 1280 | Pending | Pending |
| 1440 | Pending | Pending |

“Pending” means no complete page-and-state sweep is recorded here. A login screenshot at one size does not pass the application matrix. Customer-home/setup resources now support EN/SW; the broader workflow and its responsive acceptance remain planned. See [localization](LOCALIZATION.md).

### TARGET — app-wide task clarity (2026-10-26)

Apply the [design system's app-wide simplicity principles](DESIGN_SYSTEM.md) to public/authentication pages, customer and platform-admin tools, scanner web and future Android, reports, settings, billing and support. The same visual language and task sequence must remain understandable across screen sizes; each surface uses the layout appropriate to its work. The RSVP card/panel composition is one example, not a universal page template.

At every matrix width, a user must be able to identify the task, relevant account/workspace/event and next action; reach optional details without losing current work; and recover from validation, interruption or navigation. Keep initial fields and visible choices focused on the task. On phones, progressively reveal filters and infrequent settings while keeping their active values and a clear return path visible. On larger screens, extra columns or panels may shorten navigation without changing the meaning or order of actions.

Preserve professional tools: detailed reports may use accessible scrolling/table layouts, administration retains permissions/audits, and billing/support retain necessary review information. Do not hide consent, destructive-action scope, scanner allowance or consequential confirmations to make a screen look simpler. Future Android must additionally pass real device, camera, keyboard, orientation and safe-area checks; the CSS-width matrix establishes web coverage only. Inherit these criteria in every later UI task rather than postponing app-wide usability to the invitation phase.

## PLANNED

Phase 6: adopt the [design measurements](DESIGN_SYSTEM.md), make dense filters respond to available content width, replace mobile admin pills with a drawer/sheet, and validate scanner controls without changing admission semantics. Future invitation phases must test Quick Edit, advanced controls, preview, upload validation, generation progress, and downloads at the same widths.

### PLANNED — customer, guest and ticket layout acceptance

Apply the full matrix above to the [reference flow](REFERENCE_UX_FLOW.md) in Kiswahili and English. The 320–430px experience is the primary design target; tablet and desktop use the same sequence, with extra room for preview or supporting information. Do not reproduce a decorative phone frame inside the actual mobile UI.

- Category selection uses two columns where each card and translated label remain readable; fall back to one column when required by text size or available width. Preserve one-tap selection and accessible focus.
- Minimal details stay a compact single form with persistent labels. The soft keyboard must not conceal validation or Create Invitation; Back restores saved values.
- The preview scales within available width without clipping or turning text unreadably small. Provide event details as readable HTML beside/below rendered artwork and a reachable Send to Guests button. Desktop may place controls next to preview.
- Guest rows stack name, phone and status as needed; progress labels wrap instead of truncating. Recipient confirmation and message preview remain separate, reachable steps before sending.
- RSVP choices remain visible and usable beneath the card. A sheet-like visual treatment must not trap the guest in a clipped fixed-height panel; use normal scrolling or an accessible dialog with enough available height.
- Ticket layout may place QR and details side by side only when the actual QR remains scannable. Stack them at narrow widths, preserve quiet zones and provide a larger ticket view without hiding guest allowance or entrance guidance.
- Reserve space for sticky headers, bottom actions and device safe areas. No sidebar overlap, hidden Save, clipped dialog, truncated primary CTA, inaccessible bottom navigation or hidden RSVP button is acceptable.

Test every stage with long names, large text/200% zoom, missing optional values, keyboard navigation, reduced motion and short/landscape screens. Test loading, validation, no guests, empty selection, render/send failure, partial batch, unavailable provider, expired/revoked invitation, repeated RSVP and withheld/unavailable ticket. Screenshot evidence must record locale, width/height and state; no browser/device pass is claimed by this update.

## REQUIRES VERIFICATION

Use login, dashboard, events, guest form/import/list, QR list/dialog, check-ins/reports, offline-session pages, scanner dashboard/scan/manual/recent views. Exercise empty, populated, long-name, validation-error, offline-lock, loading, denied, and successful-result states. Record viewport width/height, browser, locale, test data type, exact result, and screenshot location. Also test soft keyboards, notches/safe areas, landscape, zoom, keyboard focus, and dialogs; a desktop emulation is not a physical camera/scanner test.
