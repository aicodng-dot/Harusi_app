# ADR-002 — workspace tenancy

**Status: TARGET mandated; schema/backfill PLANNED.** Date2026-10-11.

Context: current event-scoped global admin model cannot safely open customer registration. A user is not an event or wedding.

Decision: user → workspace membership → workspace → events. Multiple memberships/events are supported; scanner event assignments remain separate from workspace permissions. Add ownership safely, backfill verified legacy mapping, prove isolation and only then tighten constraints/open customer access.

Consequence: no inferred owner from seed users/default event; preserve historical relations and admission capabilities. [Tenancy](../MULTI_TENANCY.md), [migration](../SAAS_MIGRATION_PLAN.md).
