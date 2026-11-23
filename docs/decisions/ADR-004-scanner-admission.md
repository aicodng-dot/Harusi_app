# ADR-004 — authoritative admission

**Status: TARGET mandated; service unification PLANNED.** Date2026-10-11.

Context: current web ScannerController has transactional admission; offline import has a separate applier. Real Android implementation is absent from this repo.

Decision: web and Android use the same authoritative admission service, authorization and capacity rules. Keep row locks/bounded updates, add retry idempotency and real MySQL simultaneous-last-entry verification. Preserve server offline lock/provenance and pending device logs.

Consequence: do not promise duplicate prevention across disconnected devices; define master authority/reconciliation and actual unlock credentials before Android integration. [Scanner](../SCANNER_ARCHITECTURE.md), [offline](../OFFLINE_SYNC.md).
