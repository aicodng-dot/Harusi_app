# Product and design specification

## Goal
Let an authorized event owner select an existing premium invitation template, customize the card, save a master design, and generate a personalized invitation for each actual guest with that guest's existing unique admission QR credential.

## Event information
Use authoritative event data already stored by the application:
- event type
- couple / celebrant names
- host family names and optional host introduction
- event date and time
- venue / location
- optional dress code and additional notes

Do not fabricate missing values. Let the owner edit invitation wording without changing the underlying event record unless they explicitly use the event-details editor.

## Template and editor requirements
- Reuse all existing premium templates and preserve their stable IDs.
- Support template categories, search/filter, preview and pagination or lazy loading.
- Keep artwork, photos, text, decorative elements and QR placement as separate editable layers.
- Support user photo upload, crop, reposition, replace and remove.
- Support editable text, font family from an approved font set, size, weight, alignment, line spacing and position.
- Support background, text, accent and decorative color customization.
- Support undo/redo, reset-to-template, save draft and preview.
- Provide safe-area guides and prevent text clipping/overlap.
- Never flatten the master design into a single image during editing.
- Keep preview and final export rendering consistent.
- Export print-quality PNG/JPEG/PDF at a minimum 1500×2000 px (or equivalent 300-DPI print dimensions), while preserving a crisp QR code.

## Host-family wording
Allow optional fields for:
- bride's family / host family one
- groom's family / host family two
- host introduction
- invitation message

Example wording is illustrative only, not fixed copy:
“Familia za Bw. [jina] na Bi. [jina] zinayo furaha kukukaribisha kushiriki nasi katika sherehe ya harusi ya watoto wao.”
The owner must be able to rewrite, replace, reorder or remove this wording. Support Kiswahili and English.

## Guest card personalization
Bind each generated card to an authorized real guest record:
- guest's display name
- phone number only where policy permits; never expose it unnecessarily on the card
- pass type: Single, Double, Special or Family
- allowed admissions from the authoritative guest/pass record
- existing unique QR credential
- existing manual code, if the application already supports it

Each guest receives a distinct personalized card. A group/family guest record may have one QR credential with its configured allowance, following the existing admission model.

## QR and admission safety
- Reuse existing QR token generation, storage, scanner validation and admission services.
- Do not create a parallel QR system.
- Do not derive a QR from a guest name, phone number, sequential ID or other guessable data.
- Never put raw database IDs or secrets in a QR.
- Rendering/downloading must not create admissions, reset used counts, increase allowances, rotate credentials or create duplicate guest records.
- Repeated exports must use the same valid credential unless the existing security policy explicitly rotates it.
- Ensure adequate QR contrast, quiet zone, error correction and minimum physical size.
- Test exported QR images using the existing scanner decoder / verification path.

## Privacy and authorization
Recheck event/workspace ownership and guest membership on every read, edit, render and download. Prevent cross-event template drafts, guest lists, images and generated cards from being accessed by changing URL parameters. Store uploads privately where appropriate and validate file type, size and image dimensions. Do not expose private guest-card URLs as guessable public paths.

## Suggested implementation sequence
1. Import/catalogue existing premium templates.
2. Invitation editor and editable layers.
3. Custom photo upload and image editing.
4. Invitation preview and persistence.
5. Guest list and guest creation.
6. Guest import and validation.
7. Guest detail and pass validation.
8. Personalized card generation with existing QR credentials.
9. Bulk export and download.
10. End-to-end scanner verification and production readiness.
