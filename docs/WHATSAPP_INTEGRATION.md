# Meta WhatsApp integration

## CURRENT — reusable foundation

`App/Services/Notifications/NotificationService` normalizes phones, renders configured placeholders and writes `notification_logs`. `MetaWhatsappProvider` sends templates/text/media with a server-side bearer credential and 15-second HTTP timeout. Missing provider credentials select `NullWhatsappProvider` and record skipped logs. Message IDs and send failures are recorded. The provider strips `+` for recipient transport and defaults template language to `en`.

Business-event listeners call the service and catch/report failures. Pledge/payment/meeting/digital-card modules themselves do not exist; hooks are preparation, not proof of those workflows. Existing dispatch guidance remains in [whatsapp-notification-hooks.md](whatsapp-notification-hooks.md). QR tokens are removed from structured notification context; manual codes may be delivered as admission information. Arbitrary text/media must still be reviewed for sensitive content.

`GET /webhooks/whatsapp/meta` validates configured verification token and subscribe mode, returns challenge as text/plain (200), or Forbidden (403). `POST` is exempt from CSRF, parses JSON and returns `{"ok":true,"provider":"meta"}` (200). It does not verify `X-Hub-Signature-256` or update delivery/read/failure status; unsigned POST presently performs no business update.

Sources: [service](../app/Services/Notifications/NotificationService.php), [provider](../app/Services/Notifications/MetaWhatsappProvider.php), [webhook](../app/Http/Controllers/Webhooks/MetaWhatsappWebhookController.php), [template config](../config/notification_templates.php). Unit/feature tests use HTTP fakes; no message was sent by this audit.

## TARGET / PLANNED

Reuse the current service/provider. Add authorized bilingual invitation/code/pledge/meeting/thanks/congratulations/update workflows only when source modules exist. Validate approved Meta templates/language codes with the actual account; configured template names do not prove approval. Verify webhook HMAC over raw body with app secret before processing. Match provider message IDs to authorized logged attempts; apply explicit idempotent status transitions and replay controls.

Queue/retry/backoff and usage accounting are absent today. Choose cron-compatible bounded queue processing after hosting verification; do not mandate persistent workers. Log delivery attempts with idempotency/provenance and tenant/event ownership. Keep credentials out of browser/Android/admin HTML and backups delivered publicly. Use protected expiring media access acceptable to Meta; define consent and retention.

## REQUIRES VERIFICATION

Actual Meta account/template approvals, supported API version, webhook deployment/HTTPS, consent, mail/hosting capacity and costs. Local configured API version is a setting, not a statement that Meta currently supports it. Production API documentation must be checked when implementing integration changes.

## PLANNED — customer invitation delivery (2026-10-25)

This additive contract supports the [reference journey](REFERENCE_UX_FLOW.md) and [RSVP/ticket model](RSVP_AND_GUEST_TICKETS.md). It does not implement sending, queues or webhook processing. Phase 4 authorization remains active; P24-UX-01 through P24-UX-03 follow the service, tenancy, invitation and protected-file dependencies in the [project plan](PROJECT_PLAN.md).

The approved provider remains **Meta WhatsApp Cloud API through Laravel**. No SMS gateway is introduced. Reuse `NotificationService`, `MetaWhatsappProvider`, `NullWhatsappProvider`, phone normalization and `notification_logs`. The only current application job is password recovery; it is not an invitation queue. The current log model exposes `pending`, `sent`, `failed`, `skipped`, `simulated`; `delivered` and `read` are not implemented delivery states. The current provider treats any successful HTTP response as success even if `messages.0.id` is absent. Before the customer flow ships, require validated provider acceptance and a nonempty provider message ID for the new flow's Sent result; classify an ambiguous response for reconciliation rather than certifying success. Do not relabel historical logs as delivered.

### Customer sequence and fallback

1. From the saved card, **Tuma kwa wageni / Send to Guests** opens Add/Import Guests when the event has none; otherwise it opens recipient selection. Preserve the same event, invitation design and preview when navigating back.
2. Reuse the existing CSV/Excel parsing through a tenant-authorized import boundary. Show normalized phone, selected count, validation errors and duplicates for review. A phone match alone must not merge guests or disclose another guest's personal link.
3. Confirm recipients, approved template/language and the actual message/card preview. Persist applicable WhatsApp opt-in evidence, source and time; importing a number, issuing a link or receiving an RSVP is not consent. Explain any skipped recipient before confirming the send.
4. An authenticated, CSRF-protected POST records a durable batch and bounded jobs; it returns promptly to the progress screen. The browser does not call Meta or loop through 320 blocking HTTP sends.
5. Show a paginated/mobile guest list with name, authorized phone display and a text status with an icon, plus server-derived progress. Poll through the authorized batch endpoint with bounded frequency and backoff; survive refresh, navigation and a temporarily unavailable worker. Never animate fake completion.

When credentials, an approved language template or verified queue capability are unavailable, show an honest unavailable/skipped state and offer authorized card download, personal invitation-link copy or manual sharing. The share action opens the device's sharing interface; it does not prove a message was sent or delivered. Bulk export/link access must remain event-scoped and private. No automatic real message is sent as part of testing this workflow.

### Durable work, authorization and retries

Use the proposed `invitation_batches` and `invitation_batch_recipients` for an immutable, deduplicated selection and per-recipient work state; reuse `notification_logs` for attempt/provider evidence. See [persistence rules](RSVP_AND_GUEST_TICKETS.md). A separate `invitation_deliveries` history is unnecessary unless the implementing schema audit demonstrates a missing responsibility.

- Record the initiating actor, exact workspace/event, design revision, approved message template/language, consent version and recipient set. Use an operation key plus request fingerprint; an exact authorized retry returns the existing batch, while changed payload with the same key conflicts. A deliberate resend is a new authorized operation.
- On enqueue, job execution, retry, progress read and file delivery, revalidate active authority, exact guest/event/workspace relationships, consent, current event availability and usage entitlement. A queued identifier or former membership is not authority. Revocation before execution must prevent the affected unsent work.
- Commit batch/recipient records before provider dispatch. Use a recoverable outbox/dispatch design, bounded claims and a documented claim lease so a worker interruption cannot strand work silently or produce concurrent sends for one attempt. Do not hold a long database transaction over the network request.
- Reserve/reconcile usage atomically; retries and duplicate queue delivery cannot charge or count the same logical attempt twice. Record retries as attempts beneath one recipient, with bounded backoff and maximum attempts. Review retryable/permanent provider errors against current official documentation at implementation.
- A timeout or worker crash after provider acceptance may leave an unknown outcome. Persist that uncertainty, reconcile by provider evidence where available, and do not blindly resend. Local idempotency alone cannot guarantee exactly-once remote delivery. A manual resend after unresolved uncertainty requires an explicit duplicate-risk notice in the send review.
- Choose a real persistent queue driver and bounded cPanel-compatible cron workers after host verification, including overlap prevention and failed-job recovery. The test suite's synchronous queue is not an acceptable bulk production implementation. Document process timeout, lease and retry relationships before activation.
- Suppress credentials, raw personal invitation tokens and QR bearer values from logs, tracing and errors. Existing context-key filtering does not redact a token embedded in a `card_link`, rendered message or media URL; new invitation attempts need redacted stored payloads and protected transient/encrypted dispatch material with explicit retention.

### Honest progress and delivery evidence

Processing and delivery are separate dimensions. The proposed recipient work states are `queued`, `processing`, `outcome_unknown` and `completed`; completed send outcomes are `accepted`, `failed` after retries are exhausted, or `skipped` without a provider send. A retry waiting for its next attempt stays queued. One logical recipient contributes at most once to processed progress.

| UI quantity | Authoritative meaning |
|---|---|
| Total selected | Count of distinct frozen recipient rows in this batch |
| Queued / Processing | Work awaiting a worker / actively claimed work; neither proves sending |
| Needs checking | Unknown provider outcome awaiting reconciliation; counted separately from completed work |
| Sent | Provider accepted the request, with a valid message ID; wording/help must distinguish this from delivery |
| Delivered / Read | Authenticated, matched provider status evidence; no status is inferred from HTTP acceptance or opening the invitation |
| Failed | Label whether terminal send failure or later provider delivery failure; a delivery failure does not undo the processed send attempt |
| Skipped | No send occurred; show a safe reason such as unavailable provider or missing consent |
| Processed / Total | Distinct recipients with completed send outcome (`accepted`, terminal `failed`, `skipped`) divided by total; delivery updates do not increment this numerator |

Thus **217 / 320 processed** is not **217 delivered**. Queued + processing + needs checking + completed equals the batch total; sent/delivered/read overlap as stages and must not be added to that partition. A later delivery failure remains a completed dispatch. Empty selection cannot start a batch. Refresh/retries must recompute aggregates from records rather than incrementing browser counters. Event-level message summaries must label their scope (recipient outcomes versus individual attempts) and avoid counting the same recipient repeatedly after retry; RSVP summaries use their own records.

The webhook must authenticate the raw request before accepting business effects, verify the expected provider account and match the message ID to exactly one recorded attempt. Unknown or ambiguous message IDs do not update an arbitrary tenant. Store bounded minimal evidence, deduplicate replayed events and reconcile out-of-order updates using event time and a defined transition policy: an older sent/delivered event cannot regress an evidenced read state; a failure is attached to its specific attempt and cannot overwrite a later successful resend. Repeated callbacks must not increase counters or usage. Invalid signatures must not change data. The current acknowledgement stub provides none of this processing.

### Consent, language and release gate

The customer preview must use the actual approved Meta template and account-supported language mapping; the app's default Kiswahili labels do not prove a Swahili provider template is approved. Missing approval is an actionable unavailable state, not a silent substitution with free-form text. Verify consent, opt-out/suppression, supported API version, current provider rules, real callbacks and protected media reachability using the intended account before deployment. No external Meta policy/version validation or live send was performed for this documentation update.

Issue of a personal invitation is distinct from WhatsApp acceptance, delivery and RSVP response. `invited_at` means explicitly issued, and must be explained as such; a failed send does not fabricate attendance or remove an existing guest/pass. Complete the [messaging and end-to-end tests](TEST_STRATEGY.md) before activating this flow.
