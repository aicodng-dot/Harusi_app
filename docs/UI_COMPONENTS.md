# UI components

The approved 39-screen PangaEvents component extraction is mapped in [REFERENCE_UI_COMPONENT_MATRIX](REFERENCE_UI_COMPONENT_MATRIX.md). Reuse existing Blade/CSS/JS and separate public/customer/guest/scanner/platform shells; preserve per-screen variants. The inventory below describes existing source, not adoption of the approved designs. No component code was created by the reference audit.

Audit date: 2026-10-11. Source inventory; no component migration performed.

## CURRENT

| Existing unit | Evidence | Observation |
| --- | --- | --- |
| Admin shell | [layout](../resources/views/layouts/admin.blade.php) | Navigation, selected event, offline-lock banner, logout, shared confirmation dialog |
| Scanner shell | [layout](../resources/views/layouts/scanner.blade.php), [bottom nav](../resources/views/scanner/_bottom-nav.blade.php) | Shared dark body and four destination links; page headers remain duplicated |
| Cards/statistics | [CSS](../resources/css/app.css) | `app-card`, `metric-card`, `dashboard-stat-card`, scanner variants |
| Actions | [CSS](../resources/css/app.css) | Primary/secondary/danger/table actions and scanner variants; not all reach target touch height |
| Fields | [guest](../resources/views/admin/guests/_form.blade.php), [event](../resources/views/admin/events/_form.blade.php), [user](../resources/views/admin/users/_form.blade.php) forms | Shared partials and classes; field/error markup still repeated |
| Status | [scan view](../resources/views/scanner/index.blade.php), [manual view](../resources/views/scanner/manual-search.blade.php), [JavaScript](../resources/js/app.js) | Text and color present; English status mapping duplicated across surfaces |
| Confirmation | [layout](../resources/views/layouts/admin.blade.php), [JavaScript](../resources/js/app.js) | Native dialog with `window.confirm` fallback; text assigned with `textContent` |
| QR preview/selection | [QR list](../resources/views/admin/qr-codes.blade.php), [JavaScript](../resources/js/app.js) | Dialog, bulk selection count, selection-dependent submission |
| Admission quantity | [scan](../resources/views/scanner/index.blade.php), [manual](../resources/views/scanner/manual-search.blade.php) | Plus/minus and number input; server remains authoritative |
| Submit/copy states | [JavaScript](../resources/js/app.js) | Submit disabling and temporary copy feedback; several English strings in script |

There is no inspected dedicated `resources/views/components` library. Existing class reuse is not equivalent to a complete semantic component system. Dynamic scanner results do not declare a live region in the inspected view; assistive announcement behavior needs validation.

## TARGET

Define shared components with small explicit interfaces: button/link action, labeled field with error/help text, status badge, alert, card, empty state, page header, workspace/event selector, mobile navigation drawer, responsive list/table, dialog, progress steps, upload field, guest preview, and generation progress. Each should provide:

- Localizable visible text and accessible names, with stable internal status identifiers.
- Visible keyboard focus, sensible tab order, and text alongside meaningful icons/colors.
- Loading/disabled/error/success/empty states where relevant; preserve entered values on validation failure.
- Responsive action wrapping and approximately 44×44px interaction targets where appropriate.
- Explicit association of labels, help text and errors; intentional dialog labeling and focus restoration.

### TARGET — shared simplicity across Kadify (2026-10-26)

The [design system's app-wide principles](DESIGN_SYSTEM.md) govern public pages, authentication, customer and platform administration, scanner web, future Android, reports, settings, billing and support. Reuse the reference's clear hierarchy and easy progression through tasks. A report table, camera scanner and recovery form need different layouts; they share Kadify tokens, terminology, action hierarchy and predictable feedback.

| Reusable pattern | Component responsibility |
| --- | --- |
| Task heading and primary action | Explain the current task and useful next action; keep workspace/event context visible where relevant; subordinate optional actions |
| Minimal initial form | Ask for the information needed now, with persistent labels; expose optional/advanced fields progressively without losing entered values |
| Guided progression | Show only necessary steps, preserve Back/resume behavior and communicate saved/unsaved state; do not force every task into a wizard |
| List/report summary | Present the useful summary and common filters first; keep detailed records, comparison, bulk controls and export reachable for professional work |
| Safe consequential action | State effect and scope, retain appropriate confirmation and cancellation, and show actual completion/failure; visual simplicity cannot remove authorization or validation |
| Recoverable feedback | Provide concise loading, empty, validation, retry and success states; retain safe input/selection and prevent duplicate effects through the appropriate service contract |

Define these behaviors once in shared web components and reuse equivalent interaction contracts in future native Android components. Keep scanner identity, remaining allowance and admission confirmation prominent; progressive disclosure must never hide information needed to avoid an incorrect admission. Surface-specific controls remain available when needed, including audit, permission, billing and support details. Introduce each component through its scheduled phase; this addendum does not authorize a cross-application runtime rewrite.

## PLANNED

Phase 6 should use the existing Blade/Tailwind stack unless an approved task establishes a need to change it. Consolidate shared presentation without moving authorization into the browser. Introduce component examples for both languages and long-content cases. Scanner quantity controls must never imply that client validation replaces transactional server checks.

## REQUIRES VERIFICATION

Check native dialog Escape/focus behavior, action announcements, screen-reader names for plus/minus controls, autofocus/keyboard usability, icon semantics, contrast, active navigation indication, and duplicate-submit behavior. Existing PHP interface assertions are not visual or assistive-technology certification; see [test strategy](TEST_STRATEGY.md).
