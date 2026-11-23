# Design system

LATEST TARGET (2026-10-30): the 39 approved PangaEvents PNGs now fix visual appearance at their mapped interfaces; the earlier original-Kadify/TikTok styling guidance below is historical where it conflicts. Product-wide task simplicity still applies. Preserve measured colors, proportions, artwork, layout variants and actual components; permitted accessibility/responsiveness/correctness/security exceptions must be recorded. [Measured strategy](REFERENCE_UI_IMPLEMENTATION.md), [components](REFERENCE_UI_COMPONENT_MATRIX.md), [QA](REFERENCE_UI_VISUAL_QA.md). No production token/UI change occurs in this audit.

Audit date: 2026-10-11. Scope: Phase 0/1 documentation only. `CURRENT` describes inspected source, not proof of browser behavior. `TARGET` records the requested product direction; `PLANNED` is future work requiring a task contract and verification.

## CURRENT

The application uses Blade, Tailwind CSS v4 source conventions, Vite, and vanilla JavaScript. Shared CSS classes cover cards, buttons, inputs, badges, navigation, dialogs, QR thumbnails, and scanner controls. Admin uses white/stone surfaces with zinc text and emerald accents; scanner uses dark zinc surfaces with emerald actions. The sans stack names Instrument Sans; no font download is declared in the active layouts, so actual font availability requires verification. Sources: [CSS](../resources/css/app.css), [package](../package.json), [admin layout](../resources/views/layouts/admin.blade.php), [scanner layout](../resources/views/layouts/scanner.blade.php).

Existing reusable classes are a useful baseline, but layout, labels, status mappings, and button variants remain distributed across Blade and JavaScript. Branding is still “Wedding QR”/“Wedding QR Admission System.” Letter abbreviations serve as navigation icons. The dashboard presents eleven admission metrics rather than a setup-oriented customer overview. See [dashboard](../resources/views/admin/dashboard.blade.php) and [UI inventory](UI_COMPONENTS.md).

Code specifies 40px minimum heights for primary/secondary/danger buttons, 36px for table actions/filter pills, 44px for inputs and quantity controls, and 48–56px for principal scanner actions at the default 16px root size. This is a source-level gap against the requested approximate 44px minimum; rendered sizes were not measured by this specialist audit.

## TARGET

Create an original premium Kadify identity that remains legible and usable on phones. Prioritize hierarchy, plain language, predictable actions, and clear selected workspace/event context. Preserve recognizable scanner statuses and working admission flows during later visual changes.

### TARGET — simplicity across every Kadify interface (2026-10-26)

The user has extended the RSVP reference into a **product-wide design requirement**. Every existing screen as it is improved, and every new screen, must use the same approachable Kadify visual language and task clarity: readable typography, consistent colors and spacing, rounded surfaces, obvious controls and useful feedback. This applies to all roles and modules below, without a separate opt-in. The objective is simple, easy task completion. Adapt the reference's hierarchy and interaction principles to the user's task; an invitation card, two-button RSVP panel or decorative phone layout is not a universal page template.

Mandatory design rules:

1. **Make the purpose and next action clear.** Give each screen a plain-language title and one dominant next action where appropriate. Genuine alternatives, such as Yes/No or safe cancellation, remain visible and understandable; do not force an artificial primary choice.
2. **Ask only what is needed now.** Use sensible, editable defaults and retain known information. Request essential fields first; reveal optional or specialist settings when useful with clear labels. Preserve access to advanced capabilities and explain any prerequisite that blocks progress.
3. **Remove unnecessary work.** Avoid repeated data entry, duplicate confirmations, decorative steps and navigation through unrelated configuration. Use a single compact form for a small task; add steps only when they reduce decision load or clarify dependent choices. Fewer clicks alone is not proof of easier use.
4. **Show information in useful order.** Put the event/person/task context and decision-relevant details first. Summarize reports and dashboards before detailed tables or filters. Keep status distinctions, totals, prices, permissions and consequences visible when they affect the decision.
5. **Keep navigation predictable.** Use consistent labels, action placement and Back/Cancel behavior. Show the current workspace/event where relevant. Preserve form values, filters, selection and saved drafts on errors or return navigation; make save state clear.
6. **Respond clearly to actions.** Show honest loading, saving, completion and retry states near the action. Empty states explain how to start; errors say what can be corrected. Never substitute animation for real progress or expose technical implementation details as user guidance.
7. **Design for touch, keyboard and both languages.** Apply the shared spacing, control-size, focus, contrast and responsive requirements throughout. Kiswahili is default with equivalent English functionality. No action depends solely on an icon, color, hover or hidden gesture.
8. **Keep important controls available.** Simplicity must preserve admission quantities, capacity/eligibility checks, recipient/consent review, meaningful destructive-action confirmation, billing terms and permission boundaries. Hide optional complexity, never information needed for an informed action.
9. **Reuse patterns, then verify the task.** Shared components and semantic tokens establish consistency. Adapt density to context: a scanner can prioritize rapid readable entry, and staff may need a searchable table. Judge the result by whether people complete tasks and recover from mistakes with less confusion.

| Interface coverage | How the same standard applies | Delivery boundary |
|---|---|---|
| Public website, pricing and help | Clear explanation, concise choices and a useful next action; only real available offers/features | P7, P25 and relevant support work |
| Sign-in, registration and recovery | Short labeled forms, clear destination, retained safe input and actionable errors | Existing identity UI when improved in P6; P10 onboarding |
| Customer workspace, event setup and dashboard | Visible event context, essential details, Continue Setup and a small useful summary | P8–P10 |
| Guests, imports, teams and scanner assignment | Simple selection/search, understandable row errors, clear permissions and preserved selections | P9, P11–P12 |
| Invitations, template library, editor, RSVP and tickets | Instant preview, optional advanced editing, readable response choices and exact own-pass information | P16–P23 |
| Messages and sending progress | Recipient/message review, honest state labels and useful recovery for failed or uncertain work | P24 |
| Scanner Website and future Android scanner | Large controls, obvious guest/allowance/status, quick Scan Next, clear manual/offline recovery | Existing scanner UI in P6; P13–P15 integration |
| Reports, attendance, check-ins and exports | Summary first, labeled measures, discoverable filtering/detail and clear export state | P5/P9 and each reporting feature |
| Platform/legacy administration, settings and support | Group related tasks, searchable operational detail and clear effects; role-specific actions remain explicit | P6 presentation work plus each authorized platform/support task |
| Billing, plans and future agency modules | Understandable options, transparent totals/limits, clear review and recoverable forms | P25–P26 |

Shared color, typography, spacing and component semantics remain recognizable across these surfaces. Appropriate light/dark themes and desktop layouts may differ while keeping the same meanings and interaction conventions. Future modules inherit this standard automatically. Feature/security contracts and existing phase gates still govern implementation; this clarification changes documentation, not live screens.

Before accepting any screen, record its intended user, main task, essential inputs, next action and recovery path. Exercise representative tasks with users unfamiliar with that screen and compare completion, hesitation, mistakes, requests for help and recovery against the previous version where available. Simplify based on observed friction; do not claim usability from visual similarity or invented performance scores. See [shared components](UI_COMPONENTS.md) and [acceptance tests](TEST_STRATEGY.md).

| Foundation | Target |
| --- | --- |
| Spacing scale | 4, 8, 12, 16, 20, 24, 32, 40, 48, 64px |
| Page padding | Mobile 16px; tablet 24px; desktop 32px |
| Card padding | Mobile 16px; desktop 20–24px |
| Header | Mobile approximately 56–64px; desktop 64–72px |
| Sidebar | Desktop approximately 256px; mobile drawer/sheet |
| Touch targets | Approximately 44×44px minimum where appropriate; larger scanner actions |
| Typography | Clear heading/body/caption roles; readable line height; verified font loading and fallbacks |
| Accessibility | Practical WCAG AA goal: contrast, labels, keyboard access, visible focus, status text alongside color |
| Motion | Minimal; respect reduced-motion preferences |
| Languages | Kiswahili default and English; see [localization](LOCALIZATION.md) |

Exact brand colors, fonts, icon set, and typography sizes remain design decisions. Do not label contrast or accessibility as compliant until measured. Spacing tokens govern layout rhythm; control sizes such as 44px are deliberate interaction dimensions.

### TARGET — reference-flow components (received 2026-10-25)

Use the [eight reference screens](REFERENCE_UX_FLOW.md) for interaction hierarchy only. Create original Kadify visuals and typography; do not copy the demonstrated logo, wedding artwork, decorative frames, watermarks or surrounding TikTok interface. Rounded surfaces, a restrained palette, comfortable whitespace and short headings should make the invitation the focus. Exact visual tokens remain subject to the existing design review.

| Component | Required behavior |
| --- | --- |
| Event-category tile | Original visual, translated name, generous touch area and visible selected/focus state; two columns where labels fit; one activation selects and continues |
| Minimal event form | Persistent labels for names/title, date/time and venue; inline validation; one Create Invitation action |
| Invitation preview | Readable card with a separate accessible text summary; primary Send to Guests action; secondary design/detail/color/text/save controls |
| Recipient row and progress | Name, phone and explicit status text/icon; labelled processed/total progress; distinguish queued, processing, sent, delivered, failed and skipped |
| RSVP panel | Large Yes/No buttons with confirmation/error state; visible response-change guidance when allowed; keyboard/screen-reader status feedback |
| Guest ticket | Own name, authoritative QR/manual code where permitted, allowed entries, optional table and entrance instruction; no decorative overlap on QR |
| Customer next action | Event identity, one useful CTA and a small count summary; separate replies from actual admissions |

Inputs and ordinary touch controls should generally be at least 44px high; primary mobile actions, including RSVP choices, should preferably be at least 48px. Use text as well as color for selection, success and failure. Progress changes need accessible announcements at sensible intervals without repeatedly interrupting reading. Loading, empty, denied, expired-link, retry and successful states are part of the component contract. This is documentation for future work, not a claim that these components are implemented or visually verified.

## PLANNED

Phase 6 should establish semantic tokens and shared Blade components, then migrate one representative form, table, dialog, and scanner status view before broader changes. Document loading, disabled, empty, error, success, focus, and destructive-action states. Introduce a coherent labeled icon system without relying on color or icons alone. Keep feature implementation and tenant authorization separate from visual refactoring.

## REQUIRES VERIFICATION

Run the [responsive matrix](RESPONSIVE_LAYOUT.md), keyboard and screen-reader checks, contrast measurements, 200% text zoom, reduced-motion checks, and short-screen dialog tests. Real browser findings belong in [current audit](CURRENT_SYSTEM_AUDIT.md); source inspection alone does not establish that layouts pass.
