# Billing and entitlements

## CURRENT

No plan/subscription/payment/entitlement/usage tables or billing module exist. `PaymentCompleted` notification hooks do not implement payment processing.

## TARGET / PLANNED — Phase 25

Prepare packages for Single Event, Premium Event and Professional Organizer. Entitlements may include `max_events`, `max_guests_per_event`, `max_scanners`, `premium_templates`, `whatsapp_enabled`, `max_whatsapp_messages` and `advanced_reports`. Do not scatter plan-name checks through controllers or introduce billing before tenancy/self-service gates pass.

Use server-side entitlement decisions for workspace-owned operations and atomic usage/reservations where a limit can race. Store money as integer minor units or exact decimals with ISO currency, never binary floating point. Keep provider payments/webhook idempotency, refunds and subscription history auditable. Gate archive/cancel/degraded behavior without losing event data or unsynchronized admissions.

## REQUIRES VERIFICATION

Commercial prices, payment provider/currency support, tax/refund terms, grace periods, usage reset/overage rules and hosting reliability. No price or payment integration is approved by documentation alone. See [migration](SAAS_MIGRATION_PLAN.md) and [database conventions](DATABASE_CONVENTIONS.md).
