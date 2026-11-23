# Invitation template catalogue

Audit date: 2026-10-11. **Verified invitation-template count in inspected repository: 0.** This counts reusable visual invitation designs, not QR output, import spreadsheets, or WhatsApp message templates.

## CURRENT

No visual invitation-template library, version model, structured template assets, or invitation editor was found in the inspected `app`, `database`, `resources`, and `routes` trees. [QR image service](../app/Services/GuestQrImageService.php), [Excel template export](../app/Services/ExcelExportService.php), and [notification text templates](../config/notification_templates.php) serve different functions and must not inflate the catalogue count.

## TARGET

Deliver **100 original, professionally designed templates progressively**. Categories should include wedding, send-off, religious, traditional, modern, floral, luxury, and other suitable events/styles. Originality requires meaningful composition and typography differences; color variants of one layout do not count as separate original templates.

| Stage | Acceptance gate | Current state |
| --- | --- | --- |
| Engine | Validated schema, rendering, Quick Edit, guest binding and export foundations | Planned, not implemented |
| Five templates | Five distinct designs, bilingual editing/overflow checks, guest-correct QR decoding, approved previews | 0 produced/validated |
| Twenty templates | Expanded categories without sacrificing quality; editor/render regression checks | 0 produced/validated |
| One hundred templates | Full catalogue review, originality/provenance, all design gates satisfied | 0 produced/validated |

Each catalogue record should track ID/name, category/style, schema/design version, author/provenance, asset/font licenses, preview, supported fields, optional-field behavior, canvas formats, language coverage, long-text limits/overflow policy, QR constraints, tested outputs, publication state, and review evidence. These are target metadata, not existing database fields.

## PLANNED

After engine/editor validation, select five intentionally different compositions, for example editorial typography, botanical border, restrained geometric, traditional motif, and architectural/religious ceremony. These examples are candidate design directions, not commissioned or completed templates. Approve diversity and original artwork rights before production. Expand in batches with a catalogue review rather than bulk placeholders.

Platform template publication and customer-owned designs must have separate authorization. A design/version used by an existing export should remain reproducible even when a newer template version is published. Withdrawn templates need explicit treatment for existing customer designs.

## REQUIRES VERIFICATION

No template should be marked ready without visual approval, bilingual long-text tests, final-output QR decoding, correct personalization for multiple guests, asset/font rights, mobile Quick Edit usability, and reproducible versioned rendering. Public gallery counts and marketing claims must derive from approved published records, never from target counts.
