# Localization

Approved-reference requirement (2026-10-30): all 39 PangaEvents screens require working SW/EN through Laravel resources, with Kiswahili default as specified. Several PNGs select EN while displaying Swahili; reproduce toggle styling but correct selected state and persist actual locale. Long-label layout and both-language behavior remain NOT RUN. [Exceptions and QA](REFERENCE_UI_VISUAL_QA.md).

Initial audit: 2026-10-11. Source review/additive flow specification completed 2026-10-26. This update changes documentation only.

## CURRENT

[Application config](../config/app.php) defaults locale and fallback to `en` via environment settings; timezone is `UTC`. The 2026-10-11 baseline found English literals and no application language resources. P4-003/P4-009 subsequently added [Kiswahili customer resources](../lang/sw/customer.php) and [English customer resources](../lang/en/customer.php), with explicit translation calls in the independent customer-home/setup views. The customer controller supplies the account's supported preferred language, falling back to `sw`. This is a bounded translated customer foundation, not whole-application locale middleware or bilingual invitation/RSVP delivery. Broader admin/scanner strings and generated output still require migration; deployed runtime behavior remains unverified.

| Surface | Evidence and gap |
| --- | --- |
| Login and navigation | [login](../resources/views/auth/login.blade.php), [admin](../resources/views/layouts/admin.blade.php): English labels; no language selector |
| Scanner feedback | [JavaScript](../resources/js/app.js), [controller](../app/Http/Controllers/ScannerController.php): English camera, status, quantity and error messages |
| Event dates/statuses | [dashboard](../resources/views/admin/dashboard.blade.php): explicit `M j, Y` formatting and `ucfirst` status presentation |
| QR download | [image service](../app/Services/GuestQrImageService.php): hardcoded “Manual admission code”; restricted bitmap glyph set |
| WhatsApp | [message config](../config/notification_templates.php): English message text; message-template configuration is separate from UI localization |
| User preference | P2-002 adds stored preferred_language sw/en (defaultsw) to [User](../app/Models/User.php); customer home/setup now use it explicitly; shared locale selection/persistence and broader translation remain Phase6 |

## TARGET

Kiswahili is the default; English is fully supported. Language must cover public pages, authentication/recovery, customer portal, onboarding, scanner, invitation editor, validation, notifications, dates, empty states, and errors. Registration collects a preferred language. A visible selector must preserve the user's choice across navigation and, once authenticated, sessions as designed.

Use stable keys for UI and backend message codes; translate text at the appropriate presentation boundary. Persist database states and API machine codes independently of translated labels. Do not translate or reinterpret QR tokens, guest IDs, phone canonical values, or four-digit manual codes; preserve leading zeros.

Proposed locale precedence: an explicit current user selection, saved account preference, then Kiswahili default. Confirm session/cookie persistence and fallback policy during Phase 6. Event timezone and display formatting must be decided explicitly rather than silently treating UTC as local event time.

### TARGET — invitation and RSVP terminology

The 2026-10-25 update requires every new screen and state in [the reference journey](REFERENCE_UX_FLOW.md) in both languages. These are proposed Laravel resource keys/copy, not new implemented resource files. Use complete translated messages and pluralization for counts, never hard-coded labels or concatenated sentence fragments.

| Proposed key | Kiswahili (default) | English |
| --- | --- | --- |
| `invitation.choose_event_type` | Chagua aina ya tukio | Choose Event Type |
| `invitation.event_details` | Maelezo ya tukio | Event Details |
| `invitation.names` | Majina | Names |
| `invitation.event_title` | Jina la tukio | Event Name |
| `invitation.date` | Tarehe | Date |
| `invitation.time` | Muda | Time |
| `invitation.venue` | Ukumbi | Venue |
| `invitation.create` | Tengeneza kadi | Create Invitation |
| `invitation.send_to_guests` | Tuma kwa wageni | Send to Guests |
| `invitation.change_design` | Badilisha muundo | Change Design |
| `invitation.edit_details` | Hariri maelezo | Edit Details |
| `invitation.change_colors` | Badilisha rangi | Change Colors |
| `invitation.customize_text` | Hariri maandishi | Customize Text |
| `invitation.preview` | Hakiki kadi | Preview Invitation |
| `invitation.save` | Hifadhi | Save |
| `delivery.sending` | Inatuma | Sending |
| `delivery.queued` | Kwenye foleni | Queued |
| `delivery.processing` | Inachakatwa | Processing |
| `delivery.sent` | Imetumwa | Sent |
| `delivery.delivered` | Imefikishwa | Delivered |
| `delivery.failed` | Imeshindwa | Failed |
| `delivery.skipped` | Imerukwa | Skipped |
| `rsvp.question` | Utahudhuria? | Will you attend? |
| `rsvp.yes` | Ndiyo, nitakuja | Yes, I'll attend |
| `rsvp.no` | Siwezi | Unable to attend |
| `rsvp.confirmed` | Wamethibitisha | Confirmed |
| `rsvp.awaiting` | Wanasubiriwa kujibu | Awaiting Response |
| `ticket.title` | Tiketi Yako | Your Ticket |
| `ticket.entrance` | Onyesha mlangoni | Show at Entrance |
| `ticket.table` | Meza :number | Table :number |

Translate all event categories, validation, render/export errors, consent, retry, response-change, access-denied and expired-link guidance too. “Sent” is provider acceptance; “Delivered” requires confirmed delivery evidence. Do not translate machine states into wording that promises more. Ticket guest counts and RSVP guest-record counts need separate pluralized labels. Anonymous guests need a locale selector without account creation; their locale choice must not change invitation ownership or security tokens. Format the event's defined timezone and local date/time consistently in preview, public invitation, ticket and messages.

## PLANNED

1. Inventory strings in Blade, PHP, JavaScript, generated images, and Meta template mappings.
2. Establish `sw` and `en` resources, locale middleware/persistence, translated validation, date/number formatting, and a small reviewed terminology glossary.
3. Provide scanner JavaScript a safe translation dictionary or translated server messages plus stable codes; avoid concatenating English fragments.
4. Review Kiswahili with a fluent product reviewer; exercise text expansion before approving navigation, dialogs, and scanner controls.
5. Add per-language notification/template mapping only when the required Meta templates and delivery behavior are verified.

The supplied product promise is approved source copy: “Simamia kila kitu mwenyewe, kwa urahisi, sehemu moja.” / “Manage everything yourself, easily, from one place.” Other proposed translations need review.

## REQUIRES VERIFICATION

No bilingual application pass is claimed. Verify all [responsive widths](RESPONSIVE_LAYOUT.md) in both languages, persistence between sessions, fallback for missing keys, validation and access-denied responses, font coverage, screen-reader pronunciation, date/time interpretation, and generated-card/notification output. Configuring `APP_LOCALE=sw` alone cannot satisfy these checks.
