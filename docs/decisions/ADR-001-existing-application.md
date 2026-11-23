# ADR-001 — preserve the existing application

**Status: TARGET accepted by master request; Phase0/1 documentation IMPLEMENTED.** Date2026-10-11.

Context: working Laravel app with multi-event admin, scanner/QR/admission, imports/reports, offline protocols and notification foundation. Rebuilding would discard proven behavior/data and existing dirty work.

Decision: evolve the monolith incrementally, reuse existing services, keep web scanner operational and preserve IDs/tokens/codes/history. Read four canonical docs and inspect actual code before each task. Document contradictions before implementation; verify and report each phase.

Consequence: target modules are future boundaries, not parallel duplicate services. External/production behavior must be verified separately. [Architecture](../ARCHITECTURE.md), [audit](../CURRENT_SYSTEM_AUDIT.md).
