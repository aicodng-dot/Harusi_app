# Public website

Audit date: 2026-10-11. This is a Phase 7 target brief, not a built public site.

## CURRENT

The root route redirects to `/login`; [routes](../routes/web.php) expose no public marketing, pricing, template-gallery or registration pages. P2-003 adds [forgot/reset password pages](ACCOUNT_RECOVERY.md) for existing accounts. A stock [welcome view](../resources/views/welcome.blade.php) exists but is not routed from `/`. P2-001 (2026-10-12) labels shared Kadify/admin/scanner login entries and removes public sample credentials from the [login](../resources/views/auth/login.blade.php). This does not deliver the public website/customer portal or revoke seeded accounts. Do not advertise planned SaaS features as delivered.

## TARGET

Kadify's public site should explain event management in plain language, with original visuals and bilingual navigation. The user-supplied reference was reviewed on 2026-10-11: it presents services, event categories (wedding, send-off, kitchen party and other events), testimonials, pricing, FAQ, contact, and Login/Anza sasa entry actions. Its copy describes a team providing design, delivery and scanning services. That is useful information-architecture inspiration; Kadify's intended operating model is customer self-service. [Source: Mialiko public website](https://mialiko.com).

Use this reference for navigation clarity, CTA placement, product explanation, and onboarding simplicity. Do not reuse its branding, wording, images, source, exact layout, testimonials, or claims as Kadify proof. See also [current audit](CURRENT_SYSTEM_AUDIT.md).

| Navigation | Suggested destinations |
| --- | --- |
| Product | Digital invitations, guest management, QR admission, scanner, WhatsApp invitations, reports |
| Solutions | Wedding, send-off, religious ceremonies, event planners, corporate events |
| Invitation Templates | Only validated published templates; clear preview and availability |
| Pricing | Approved offers and entitlements when ready; no invented prices |
| Resources | How it works, help, FAQs, contact |
| Entry actions | Scanner Login, Sign In, Get Started |

Hero copy: **Simamia kila kitu mwenyewe, kwa urahisi, sehemu moja.** / **Manage everything yourself, easily, from one place.** Primary CTA: **Anza Sasa / Get Started**. Secondary: **Angalia Inavyofanya Kazi / See How It Works**.

Proposed content sequence: navigation; hero; verified trust/proof when available; supported events; how it works; guest management; invitation studio; QR passes; web/Android scanner; WhatsApp communication; reports; benefits; real testimonials when available; invitation gallery; pricing; FAQ; contact; final CTA; footer. Condense sections on small screens without burying the main action. Omit unverifiable testimonials, customer counts, claims of Android parity, template counts, and delivery claims.

## PLANNED

Phase 7 follows design/localization and tenant authorization foundations. Registration launch remains gated by Phase 10; a public CTA must not link to a nonexistent or insecure sign-up flow. Pricing/billing functionality remains Phase 25. Preserve an obvious Scanner Login for venue staff, distinct from customer and platform entry. Use [design system](DESIGN_SYSTEM.md) and [responsive acceptance](RESPONSIVE_LAYOUT.md).

## REQUIRES VERIFICATION

Confirm final brand assets, original content rights, actual available features, contact destination, privacy/legal copy ownership, and approved pricing before publishing. Test menu opening/closing and keyboard focus, CTA destinations, both locales, loading performance, metadata, long copy, and all requested widths. No public-site implementation or hosting change occurred in this audit.
