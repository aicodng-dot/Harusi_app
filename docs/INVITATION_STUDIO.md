# Invitation Studio

Approved visual target (2026-10-30): references06/13/14/21 and public guest screens08/09/33/34 now define PangaEvents presentation. They remain PLANNED; no editor/renderer/RSVP feature is created by inspection. Preserve the 100-original-template engine, saved Event/design data, secure personalization and authoritative QR. See [implementation](REFERENCE_UI_IMPLEMENTATION.md), [matrix](REFERENCE_UI_SCREEN_MATRIX.md) and [visual QA](REFERENCE_UI_VISUAL_QA.md).

Audit date: 2026-10-11. Studio is a target feature; this audit created documentation only.

## CURRENT

| Capability | Inspected status |
| --- | --- |
| Guest QR pass | [GuestQrImageService](../app/Services/GuestQrImageService.php) renders a fixed 1000×1200 PNG containing QR plus the same guest's visible four-digit manual code |
| QR encoding | [QrCodeService](../app/Services/QrCodeService.php) offers PNG/SVG QR encoding; [admin routes](../routes/web.php) provide guest QR generation/download and ZIP exports |
| Digital-card notification hook | [DigitalCardReady](../app/Events/DigitalCardReady.php) and [listener](../app/Listeners/SendDigitalCardNotification.php) pass guest/card context to [NotificationService](../app/Services/Notifications/NotificationService.php) |
| Invitation editor/renderer/catalogue | No corresponding controllers, models, migrations, routes, views or structured visual templates were found in the inspected application tree |
| Card artwork upload/personalized design export | No implementation located; QR pass download is not an invitation design engine |

`DigitalCardReady` is a messaging integration hook. It does not prove a card builder, card storage model, payment module, or finished invitation templates exist. The current event model stores event/couple/venue/date fields; the broader invitation-content schema remains unimplemented. See [event model](../app/Models/Event.php).

## TARGET

Default flow from the 2026-10-25 update: **Choose Event Category → Enter Minimal Details → Create Invitation → Instant Default Preview → Send to Guests**. This replaces template-first entry into the target studio. The category determines a suitable professional default; it is not one of the 100 detailed template choices. Advanced **Change Design → Quick Edit → optional Customize Layout** remains available without obstructing ordinary users. Advanced mode may move/resize permitted elements, adjust fonts/colors, and position the QR within validated limits.

Persist validated details in the real authorized Event before producing its preview; save an event-linked design/version through the planned invitation services. Show event type, names/title, date, time, venue and available optional content. The preview must render promptly from the same engine used for output and preserve edits across Back, reload and Add/Import Guests. A failed render keeps the saved event and offers retry; never replace a real preview with a success claim.

Keep **Tuma kwa wageni / Send to Guests** primary. Offer Change Design, Edit Details, Change Colors, Customize Text, Preview and Save as secondary controls. No guests opens Add/Import Guests; existing guests opens recipient selection. Both return to the same saved design, followed by recipient confirmation and message/invitation review. Category and template changes must not silently discard edited text or event details. See the [screen map](REFERENCE_UX_FLOW.md).

Support appropriate customizable fields: host family, second family, couple names, invited guest/family, date/time, ceremony venue, place of worship/church/mosque, reception, location, RSVP/contact, dress/color code, QR, manual code, table/seat, and custom messages. Show relevant fields for the chosen event/template rather than one overwhelming form.

Custom artwork initially supports PNG and JPG/JPEG as a locked background. Dynamic overlays include guest name, QR, manual code, and other text. One saved design produces guest-specific cards. Guest identity and QR/manual-code values come from authorized server records; editors may change their presentation, not swap security payloads manually.

Provide preview as guest, single generation, selected guests, bulk generation, generation status, and protected downloads. John’s card must contain John’s current QR and manual code; family admission quantities must remain the backend guest's values.

## PLANNED

Phases 16–20: engine → instant preview/Quick Edit → uploaded background → personalization → exports. The first preview needs a validated original default template; it uses the same engine and later catalogue acceptance rules. Formal catalogue expansion remains five original templates, then twenty, then one hundred. See [engine](INVITATION_TEMPLATE_ENGINE.md) and [catalogue](INVITATION_TEMPLATE_CATALOGUE.md). Do not build one hundred unrelated Blade pages or count recolors as original layouts. Public invitation/RSVP/ticket access follows the [guest contract](RSVP_AND_GUEST_TICKETS.md), not scanner-only ticket routes.

## REQUIRES VERIFICATION

Choose a rendering approach compatible with verified hosting; confirm image/font licensing, supported dimensions, upload/decompression limits, output formats, job/cron strategy, export retention, and QR-regeneration invalidation. Test real QR decoding at final resolution, quiet zones/contrast, long Swahili/English text, missing optional data, mobile editor controls, wrong-tenant/guest references, and interrupted bulk generation. No invitation template or rendering acceptance test passed merely because these documents exist.
