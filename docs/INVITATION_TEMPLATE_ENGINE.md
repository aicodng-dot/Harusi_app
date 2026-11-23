# Invitation template engine

Audit date: 2026-10-11. Architecture proposal for Phases 16–20; no new application schema or renderer is implemented.

## CURRENT

The reusable image services encode QR symbols and a fixed QR/manual-code PNG. They do not support arbitrary invitation text, layers, backgrounds, typography, design versions, or batch invitation rendering. [GuestQrImageService](../app/Services/GuestQrImageService.php) validates four numeric code characters and uses a four-module QR margin. Its restricted bitmap alphabet is not a general text layout system. [NotificationService](../app/Services/Notifications/NotificationService.php) receives card context for delivery but does not render visual templates.

## TARGET

Pipeline: **Saved event/category → appropriate default template or explicit template choice → validated design version → preview → authorized guest personalization → renderer → export → authorized download/delivery**. Use one structured engine for original templates and uploaded artwork. Preview and export share layout rules and assets so final cards match the approved preview. The 2026-10-25 UX update simplifies access to this engine; it does not replace its professional 100-template target.

Proposed template contract, subject to Phase 16 design review:

| Area | Required concepts |
| --- | --- |
| Identity/version | Stable template ID, schema version, immutable published design version, category, publication state |
| Canvas | Explicit width/height/unit, output aspect ratio, safe bounds, background treatment |
| Assets | Managed asset IDs, MIME/dimensions, content hash, ownership, font/license provenance |
| Layers | Stable ID, type, z-order, position/size, permitted edits, visibility rules, lock state |
| Text | Field binding, allowed font/size/color/alignment, wrapping, overflow policy, optional-field behavior |
| Guest fields | Allowlisted bindings to authorized guest display name, current QR, manual code and approved event data |
| QR | Authoritative payload reference; protected quiet zone, contrast and minimum readable output rules |
| Provenance | Event/workspace/design/version/guest/export relationships and source QR generation identity |

These are proposed concepts, not existing columns or a frozen JSON/API contract. Follow [database conventions](DATABASE_CONVENTIONS.md) and [API contract](API_CONTRACT.md) before selecting actual tables/endpoints.

Security and correctness invariants:

- Resolve workspace, event, design, assets and guest from authorized server scope; browser IDs alone are insufficient.
- Use the same guest record for name, QR and manual code. Do not accept arbitrary client QR payloads or fetch arbitrary remote assets.
- Treat uploaded PNG/JPEG as validated image content with constrained dimensions/size; reject malformed/unsupported input, store safely, and keep the background locked in normal editing.
- Preserve QR quiet zones and prohibit clipping/decoration over protected QR bounds. Numeric positions must remain finite and inside valid render bounds.
- Version designs/assets and associate exports with the exact version and guest QR generation used. QR regeneration must mark old exports stale or require regeneration before reuse.
- Protect generated files and bulk archives by tenant/event authorization. Define link access/expiry when a guest or messaging provider must retrieve an artifact.
- Make repeated jobs safe to retry; report per-guest failures and completion totals without silently mixing output between jobs.

## PLANNED

Phase 16 selects a renderer after hosting/font/memory feasibility tests and introduces schema validation plus deterministic preview fixtures. Phase 17 adds instant default preview and Quick Edit; Phase 18 uploads; Phase 19 personalization; Phase 20 individual/selected/bulk exports with hosting-compatible job execution. Advanced layout controls follow the same validator as Quick Edit.

Category-to-default selection must resolve a published compatible template version deterministically, with a validated original fallback when optional fields are absent. The initial category chooser is not a template gallery: Harusi, Send-Off, Kitchen Party and Sherehe appear first; Engagement, Church Wedding, Islamic/Nikah, Traditional Ceremony, Graduation, Birthday, Corporate Event and Other remain supported. Templates may support several categories; category labels must not inflate the count of distinct designs. Preserve modern, traditional and religious compositions, uploaded artwork, typography/colors/text, family/couple/event names, ceremony/reception/place of worship, dress codes, guest personalization, authoritative QR and manual code.

Build one valid default for the instant-preview gate before full catalogue expansion; count it toward a later five-template gate only after it passes that gate's complete criteria. A target of 100 must never appear as 100 currently available designs. The owner preview before guest selection may omit guest-specific fields or use clearly labelled sample values; it must contain no borrowed real guest's QR or functional invented admission credential. Guest previews and exports resolve the exact authorized existing pass. [Studio](INVITATION_STUDIO.md) owns the simple controls; [RSVP/tickets](RSVP_AND_GUEST_TICKETS.md) owns public access/release policy.

No mandatory long-running worker or new paid service is assumed. Exact background processing, image dimensions, bulk limits, and file retention remain decisions until deployment capabilities are verified.

## REQUIRES VERIFICATION

Test two guests with visibly distinct names/codes/QR payloads in the same batch; decode every sample and assert ownership. Include regenerated/revoked QR, stale exports, long/multiline content, missing fields, font fallback, malicious URLs/uploads, cross-workspace asset/export IDs, repeated jobs, partial failures, and memory/runtime limits. Validate real print/phone scanning; visual inspection alone is insufficient. See [test strategy](TEST_STRATEGY.md).
