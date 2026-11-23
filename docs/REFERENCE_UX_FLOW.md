# Reference customer and RSVP flow

Requirements received 2026-10-25; documentation integration completed 2026-10-26 (Africa/Dar_es_Salaam). **TARGET / PLANNED**, not implementation evidence. Phase 4 authorization, Phase 3 rollout and the existing review gates remain open. See [project plan](PROJECT_PLAN.md) for the current source task.

## Examined reference material

All eight attached screenshots were visually examined in the conversation. They show frames from a TikTok demonstration; only the app inside the demonstrated smartphone is a UX reference. TikTok navigation, account details, likes/comments/shares, video progress, watermarks, screenshot overlays and the outer navy background are excluded. No third-party artwork or screenshot is copied into production assets by this update.

| Supplied screenshot | Visible app content | Original Kadify mapping |
| --- | --- | --- |
| `Screenshot_20261023_102026_TikTok.jpg` (#1) | Harusi / Maelezo ya tukio; names entered, date partially entered, venue empty | Minimal event-details form with clear validation and retained values |
| `Screenshot_20261023_102047_TikTok.jpg` (#2) | Chagua muundo; two-column Harusi, Send-off, Kitchen party and Sherehe cards | Chagua aina ya tukio: category chooser with original visuals, large touch areas and one-tap continuation |
| `Screenshot_20261023_102053_TikTok.jpg` (#3) | Names, date/time and venue completed; Tengeneza kadi | Save validated real event data and show its default invitation immediately |
| `Screenshot_20261023_102055_TikTok.jpg` (#4) | Card preview with names, date, time and venue; Tuma kwa wageni | Readable branded preview with Send to Guests primary and optional editing controls |
| `Screenshot_20261023_102057_TikTok.jpg` (#5) | SMS + WhatsApp label; Inatuma… 0 / 320; names/phones and status marks | Recipient progress view; Kadify uses Meta WhatsApp only, with explicit queue/provider states |
| `Screenshot_20261023_102059_TikTok.jpg` (#6) | Inatuma… 217 / 320; partly filled progress bar and changing status marks | Server-derived processed/total progress with separate sent/delivered/failed/skipped counts |
| `Screenshot_20261023_102106_TikTok.jpg` (#7) | Invitation above Utahudhuria?; Ndiyo, nitakuja / Siwezi | Secure account-free personal invitation with accessible Yes/No RSVP and confirmation |
| `Screenshot_20261023_102110_TikTok.jpg` (#8) | Tiketi Yako; Asha M., QR, Wageni 2, Meza 12, Onyesha mlangoni; Wamethibitisha 131 callout | Own authoritative guest ticket and optional table; separate owner confirmation summary |

The screenshots demonstrate hierarchy and sequence, not the other application's backend, security, delivery receipts or actual concurrency. Numbers such as 320, 217 and 131 are reference examples, never seeded Kadify progress. Screenshot #2 precedes #1/#3 logically even though it appears second in the attachment order. The final confirmation callout informs the owner dashboard; it does not justify revealing other guests or global counts on a personal invitation.

## Target journey and screen contract

**Register/sign in → choose event type → enter minimal details → see invitation → optionally change design/edit → add/import guests → confirm recipients and preview message → send → track progress → guest opens invitation → RSVP → authorized QR ticket → scanner admission → live reporting.**

| Screen | Main action and hierarchy | Persistence / recovery |
| --- | --- | --- |
| Event category | “Chagua aina ya tukio”; first Harusi, Send-Off, Kitchen Party, Sherehe; original two-column visual tiles where width permits | One activation selects and continues; Back retains selection |
| Event details | Category caption, “Maelezo ya tukio”, Majina/event title, Tarehe/time, Ukumbi; “Tengeneza kadi” | Validate and save through the EventService responsibility; retry must not duplicate events |
| Instant preview | Card is the focus; “Tuma kwa wageni”; secondary Change Design, Edit Details, Change Colors, Customize Text, Preview and Save | Use saved event and engine design/version; failure keeps details and offers retry |
| Guests and review | No guests: add/import; existing guests: select recipients; then confirm recipients and preview message/card | Reuse existing CSV/Excel infrastructure under tenant checks; retain saved card; report row errors |
| Sending progress | Processed/total and progress bar; readable name, phone and status per recipient | Queued, processing, sent, delivered when confirmed, failed and skipped are distinct; polling never fabricates success |
| Guest invitation / RSVP | Card, event details, optional directions/contact, “Utahudhuria?” and two large choices | Secure invitation-specific token, no guest account; save once, show immediate confirmation, allow changes only under event rules |
| Guest ticket | “Tiketi Yako”, own guest name, authoritative QR, allowed entries, optional table, “Onyesha mlangoni” | Respect release policy and existing pass status; no second admission token or guessable credential |
| Owner overview | Event, Continue Setup, useful next action; guests/sent/confirmed/scanner readiness | Authoritative event-scoped counts; show responses separately from event-day admissions |

Additional categories: Engagement, Church Wedding, Islamic/Nikah, Traditional Ceremony, Graduation, Birthday, Corporate Event and Other. Category selection is separate from the planned 100-template catalogue. A suitable validated default template makes the first preview immediate; changing templates and Quick Edit remain optional. Advanced canvas/layer controls are never a prerequisite. Existing original-template milestones and custom PNG/JPEG artwork support remain in force.

Use only essential initial fields. Family names, church/mosque/ceremony, reception, dress colors and RSVP contacts belong in later Quick Edit. The current Event stores date-only values and separate couple fields: define additive time/timezone/content mapping before implementation, without silently changing legacy dates or splitting arbitrary names on punctuation. Preview data cannot be a disconnected temporary event.

## Behavioral boundaries

- **Delivery:** use the existing Laravel Meta WhatsApp provider responsibility. No SMS gateway is authorized. Preview recipients, consent and approved message before queueing; progress reflects real persisted work. “Sent” requires provider acceptance and “Delivered” confirmed status. If unavailable, offer authorized card download, link copy or manual share without claiming provider delivery. See [WhatsApp](WHATSAPP_INTEGRATION.md).
- **RSVP:** intent is separate from delivery and admission. Store response/time/source with controlled changes and idempotent submissions; aggregate current records rather than incrementing contradictory counters. See [RSVP and tickets](RSVP_AND_GUEST_TICKETS.md).
- **QR:** display the existing guest/pass/event association. Single, Double and Special/Family retain permitted capacity. Existing-pass behavior remains the compatible default; a future affirmative-RSVP release policy must be explicit. A decline does not silently invalidate a pass or rewrite admission history.
- **Scanner:** preserve the Scanner Website, four-digit manual codes, capacity checks and Scan Next. Future shared AdmissionService/Android work uses the same authoritative logic; RSVP never consumes entry allowance.
- **Privacy:** every owner action checks account, membership, event and operation permission. A personal guest link reveals only that guest's allowed invitation/ticket data; it cannot enumerate guests or access unrelated QR/media.

## Original design and acceptance

The 2026-10-26 clarification makes the demonstrated simplicity a standard for **all Kadify UI**, including public/authentication pages, customer and administrator tools, web/future Android scanners, lists/reports, settings, billing and support. Reuse the original Kadify design language and clear interaction hierarchy while optimizing each actual task. The screen map above remains the invitation journey; it is not a requirement to put every task into an invitation-card or RSVP layout. The authoritative [design-system rules](DESIGN_SYSTEM.md) define this broader scope and usability acceptance.

Use Kadify typography, colors and original licensed visuals with rounded surfaces, whitespace and minimal text. Preserve the shared 4/8/12/16/20/24/32/40/48/64px spacing scale and 16/24/32px mobile/tablet/desktop padding. Ordinary inputs/touch controls are generally at least 44px; principal mobile actions preferably at least 48px. The product UI contains no TikTok chrome or decorative phone mockup.

Kiswahili is default with complete English parity through Laravel localization resources. Test every screen/state at **320, 360, 375, 390, 430, 768, 1024, 1280 and 1440px**. Desktop may use a side preview but must retain the same logical sequence. Required evidence includes readable previews, visible save/send/RSVP buttons, no header/sidebar/dialog overlap, usable keyboard/back navigation, accessible progress, loading/errors and safe-area behavior. See [design](DESIGN_SYSTEM.md), [responsive layout](RESPONSIVE_LAYOUT.md), [localization](LOCALIZATION.md) and [test strategy](TEST_STRATEGY.md).

Customer acceptance: sign in, tap Harusi, enter names/date/time/venue, create the card, optionally edit, add/select guests, review and send through configured WhatsApp, see honest progress and confirmations without platform-admin intervention. Guest acceptance: open personal link, understand date/location, answer Yes/No, see confirmation and own authorized pass/allowance/table without an account. Scanner acceptance: scan that same pass, validate the correct event/guest, admit within allowance, record actual attendance, prevent over-admission and continue scanning. These are future acceptance gates; this documentation update has not executed them.
