# User journeys

The approved PangaEvents [39-screen matrix](REFERENCE_UI_SCREEN_MATRIX.md) adds exact visual references to the existing journeys without replacing their backend sequence. All originals were inspected; no new flow was implemented. Keep simple task completion, real data, role-specific navigation, account-free secure guest access and the same authoritative admission rules. [Batches and dependencies](REFERENCE_UI_IMPLEMENTATION.md).

Audit date: 2026-10-11. `CURRENT` journeys are supported by inspected route/controller/view code, not a claim that all were executed in a browser.

## CURRENT

| Actor | Inspected journey | Evidence and limits |
| --- | --- | --- |
| Administrator | Shared `/login` → admin dashboard/event selection → create/select event → add/import guests → generate/download QR → manage scanner users → inspect check-ins/reports | [routes](../routes/web.php), [auth](../app/Http/Controllers/AuthController.php), [admin layout](../resources/views/layouts/admin.blade.php). No customer workspace boundary |
| Scanner | Shared `/login` → scanner dashboard → Scan QR → server validation → choose count → confirm → Scan Next | [scanner controller](../app/Http/Controllers/ScannerController.php), [active scanner view](../resources/views/scanner/index.blade.php), [JavaScript](../resources/js/app.js) |
| Scanner without readable QR | Manual Search → normalized phone or exact four-digit code within assigned event → select matching guest → choose count → confirm | [manual search view](../resources/views/scanner/manual-search.blade.php), [controller](../app/Http/Controllers/ScannerController.php). Guest-name search is not the current input path |
| Administrator using offline handoff | Create authorized offline session → generate/download package → later preview/confirm result import or resolve conflicts | [offline routes](../routes/web.php), [offline views](../resources/views/admin/offline-scanner-sessions). Android execution/inter-device behavior requires separate evidence |
| Existing account holder | Login → Forgot password → enter email or phone → receive link at registered email → reset → fresh login | [Recovery](ACCOUNT_RECOVERY.md); automated fake-delivery/session tests pass; actual mail/browser flow remains unverified |
| Anonymous visitor | `/` redirects to login | No public marketing or registration journey in inspected routes |

The root README describes manual lookup by “name or phone”; current controller and UI use phone/four-digit code. Treat README prose as outdated for this path. Do not accidentally restore name search while redesigning.

## TARGET

| Actor | Intended journey | Authorization boundary |
| --- | --- | --- |
| New customer | Register/sign in → own workspace → choose event category → enter minimal details → see invitation immediately → add/import guests → preview recipients/message → send → monitor replies | Tenant ownership and policies before public registration; release invitation steps only after their engine and access gates |
| Returning customer | Manage My Event → authorized workspace/event → useful next action | Membership, permission, event and resource ownership checked server-side |
| Workspace Manager | Sign in → permitted events → operational tasks | Explicit allowed capabilities; no implicit platform administration |
| Gate scanner | Scan Guests → assigned event → QR or phone/code lookup → count → confirm → next | Same identity; assigned-event authorization and authoritative admission service |
| Platform staff | Platform Administration → customer/workspace/support/platform tasks | Separate platform privilege, auditable access |
| Invitation creator | Choose category → enter names/date/time/venue → default card preview → optionally Change Design or Quick Edit → Send to Guests | Save real event/design records; correct guest/event relationship at preview and every export |
| Invited guest | Open personal invitation → view card → answer Yes/No → see confirmation → view authorized existing QR ticket → present at entrance | No Kadify account; opaque invitation access, exact guest/event binding and configured ticket-release rules |

P2-001 adds labelled `/admin/login` and `/scanner/login`, both sharing the existing POST `/login` identity; `/login` remains supported. These browser entry routes are implemented and tested, while customer registration/context switching remains planned. A context switcher is only for multiple legitimate capabilities. Website and Android must share identity/backend rules; native parity is not established by website source.

## PLANNED

Implement the [project plan](PROJECT_PLAN.md) in approved phases. Refer to [onboarding](SELF_SERVICE_ONBOARDING.md), [portal](CUSTOMER_PORTAL.md), and [invitation studio](INVITATION_STUDIO.md) for detailed target flows. No journey should require customers to contact platform staff for ordinary authorized event tasks once self-service is delivered.

### Additive reference journey — 2026-10-25

**Chagua Tukio → Jaza Maelezo → Tengeneza Kadi → Tuma kwa Wageni → Wageni Wanajibu → Tiketi ya QR → Ingia kwenye Tukio → Fuatilia Mahudhurio.** This is the target customer/guest sequence, not a new implementation claim. Phase 4 authorization remains active; existing review and rollout gates remain intact. The project plan records the current source task.

The [eight-screen reference map](REFERENCE_UX_FLOW.md) defines the screen hierarchy. Category selection and the 100-template library are different choices. Save validated event details before preview; no-guest Send leads to the existing add/import responsibility and returns to the saved card. Existing guests lead to recipient selection. A retry or Back must not duplicate the event, guests or invitation batch.

Delivery, RSVP and admission are separate journeys with separate state: a delivered message can await a reply; an attending guest can have zero admissions; a declined reply does not revoke a legacy pass. The [RSVP/ticket contract](RSVP_AND_GUEST_TICKETS.md) governs public access and responses. Keep the Scanner Website and future Android scanner on the same authoritative admission rules; preserve capacity, manual-code lookup and Scan Next.

## REQUIRES VERIFICATION

Walk each journey with a nontechnical user in Kiswahili and English. Include wrong login destination, unavailable event, expired session, camera denied, invalid/revoked/fully used QR, wrong event, connection failure, duplicate admission, import error, and insufficient permissions. Measure whether the next action is understandable and reachable; do not infer “zero training” from screen count alone.
