# ADR-003 — shared authentication

**Status: ACCEPTED; implemented through P2-004 in source/tests (2026-10-16); dependency remediation pending.** Original decision date2026-10-11.

Original audit context (2026-10-11): shared email/password web identity without account phone/reset/token API; remember support was broken on the migrated test schema. P2-001 through P2-003 now verify session repairs, email/phone identity, active-account enforcement and registered-email recovery. Actual schema/mail rollout remains unverified.

Decision: one users table/password across customer/admin/scanner/Android. Email or canonical +255 phone plus password; web sessions and secure revocable API tokens. Recovery resolves phone/email to registered email through Laravel broker; no paid SMS/WhatsApp OTP. Different entry points label legitimate modes without creating duplicate identities.

Consequence: additive account migration, active-account checks, safe role redirect and tests before registration. P2-004 records and implements Sanctum4.3.3,24-hour bearer expiry, a ten-token bound and transactional credential revocation; Android/offline verification remains separate. [Auth](../AUTHENTICATION_ARCHITECTURE.md), [Phase2](../phases/PHASE_02_AUTHENTICATION.md).
