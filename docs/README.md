# Kadify documentation

Audit date: 2026-10-11 (Africa/Dar_es_Salaam). Scope: preserve the existing Laravel app and migrate incrementally. The master request and subsequent instruction to continue autonomously govern execution.

Start with the [plain-language project status](PROJECT_STATUS.md) for what exists, what has been checked and what is needed before changing real ownership.

Read the four canonical references before every task:

| Document | Authority |
|---|---|
| [Architecture](ARCHITECTURE.md) | Boundaries, invariants, reusable services |
| [Project plan](PROJECT_PLAN.md) | Sequence, status, acceptance gates |
| [API contract](API_CONTRACT.md) | Compatibility and future API standards |
| [Database conventions](DATABASE_CONVENTIONS.md) | Ownership, integrity and migrations |

## Documentation states

**CURRENT**: verified source, not necessarily deployed. **TARGET**: direction mandated by the master request. **PLANNED**: not implemented. **IMPLEMENTED**: completed with named successful checks. **REQUIRES VERIFICATION**: evidence missing. Proposed schema/API behavior is never current behavior.

## Specialist references

- Baseline: [Audit](CURRENT_SYSTEM_AUDIT.md), [Migration](SAAS_MIGRATION_PLAN.md), [Domain](DOMAIN_MODEL.md), [Tenancy](MULTI_TENANCY.md), [Roles](ROLES_AND_PERMISSIONS.md), [Workspace/event access](WORKSPACE_ACCESS.md), [Guest/QR access](GUEST_ACCESS.md), [Legacy single-Guest writes](LEGACY_GUEST_WRITES.md), [Legacy Guest/QR metadata writes](LEGACY_GUEST_QR_WRITES.md), [Guest deletion source contract](LEGACY_GUEST_DELETION.md), [Historical deletion design](LEGACY_GUEST_DELETION_DESIGN.md), [Guest/QR issuance source contract](LEGACY_GUEST_ISSUANCE.md), [Historical issuance design](LEGACY_GUEST_ISSUANCE_DESIGN.md), [Read/export/private-media design](LEGACY_READ_EXPORT_MEDIA_DESIGN.md), [Legacy event controls](LEGACY_EVENT_ADMINISTRATION.md), [Selected-event context](LEGACY_SELECTED_EVENT.md), [Offline resource parents](LEGACY_OFFLINE_RESOURCES.md), [Session cancel/close](LEGACY_OFFLINE_SESSION_ACTIONS.md), [Locked package delivery](LEGACY_OFFLINE_PACKAGE_DELIVERY.md), [Archive metadata](LEGACY_OFFLINE_FILE_ARCHIVES.md), [Locked package generation](LEGACY_OFFLINE_PACKAGE_GENERATION.md), [Package-generation engine checks](MYSQL_PACKAGE_GENERATION_REHEARSAL.md), [Legacy staff accounts](LEGACY_STAFF_ACCOUNTS.md), [Security](SECURITY_MODEL.md), [Risks](RISK_REGISTER.md).
- Identity: [Authentication](AUTHENTICATION_ARCHITECTURE.md), [Customer web identity](CUSTOMER_WEB_IDENTITY.md), [Recovery](ACCOUNT_RECOVERY.md), [Device/offline auth](DEVICE_AND_OFFLINE_AUTH.md).
- Product: [Website](PUBLIC_WEBSITE.md), [Customer portal](CUSTOMER_PORTAL.md), [Customer workspace setup](CUSTOMER_WORKSPACE_SETUP.md), [Onboarding](SELF_SERVICE_ONBOARDING.md), [Automatic workspace creation](WORKSPACE_PROVISIONING.md), [Journeys](USER_JOURNEYS.md), [Billing](BILLING_AND_ENTITLEMENTS.md).
- UI: [Design](DESIGN_SYSTEM.md), [Responsive layout](RESPONSIVE_LAYOUT.md), [Components](UI_COMPONENTS.md), [Localization](LOCALIZATION.md).
- Invitations: [Studio](INVITATION_STUDIO.md), [Engine](INVITATION_TEMPLATE_ENGINE.md), [Catalogue](INVITATION_TEMPLATE_CATALOGUE.md), [Reference screen mapping](REFERENCE_UX_FLOW.md), [RSVP and guest tickets](RSVP_AND_GUEST_TICKETS.md), [Customer-experience integration and pending tasks](phases/CUSTOMER_EXPERIENCE_INTEGRATION.md).
- Admission: [Scanner](SCANNER_ARCHITECTURE.md), [Android](ANDROID_SCANNER.md), [Offline sync](OFFLINE_SYNC.md).
- Offline file boundaries: [Upload audits, preview commit and deletion effects](OFFLINE_UPLOAD_FILE_EFFECTS.md), [bounded retry artifact recovery](OFFLINE_RETRY_ARTIFACT_RECOVERY.md), [secure-delete stages and outcomes](OFFLINE_SECURE_DELETE_BOUNDARIES.md).
- Messaging: [WhatsApp](WHATSAPP_INTEGRATION.md), [Existing hooks](whatsapp-notification-hooks.md).
- Operations: [cPanel](DEPLOYMENT_CPANEL.md), [Backup/rollback](BACKUP_AND_ROLLBACK.md), [Tests](TEST_STRATEGY.md), [CSV downloads](CSV_EXPORTS.md), [Demo seeding](DEMO_SEEDING.md), [Recovery apply safety](RECOVERY_SCRIPT_SAFETY.md), [MySQL seeder checks](MYSQL_DEMO_SEEDING_REHEARSAL.md), [MySQL recovery/token checks](MYSQL_RECOVERY_REHEARSAL.md), [Read bundle engine checks](MYSQL_READ_BUNDLE_REHEARSAL.md), [MySQL staff transaction checks](MYSQL_STAFF_REHEARSAL.md), [MySQL automatic-owner checks](MYSQL_WORKSPACE_PROVISIONING_REHEARSAL.md), [Support](OPERATIONS_AND_SUPPORT.md), [Route snapshot](CURRENT_ROUTE_REGISTRY.md), [Dependency remediation](DEPENDENCY_REMEDIATION.md), [Legacy inventory](LEGACY_DATA_INVENTORY.md), [Ownership mapping preflight](OWNERSHIP_MAPPING_PREFLIGHT.md), [Private ownership ledger](OWNERSHIP_LEDGER.md), [Dormant backfill preparation](WORKSPACE_BACKFILL_PREPARATION.md), [Synthetic MySQL rehearsal](MYSQL_REHEARSAL.md), [Exact backfill progress](BACKFILL_PROGRESS_INSPECTION.md), [Dormant authority context](AUTHORITY_CONTEXT.md), [MySQL worker concurrency](MYSQL_CONCURRENCY_REHEARSAL.md), [MySQL admission conservation](MYSQL_ADMISSION_REHEARSAL.md), [MySQL authority visibility](MYSQL_AUTHORITY_REHEARSAL.md).
- Decisions: [Existing app](decisions/ADR-001-existing-application.md), [Tenancy](decisions/ADR-002-workspace-tenancy.md), [Identity](decisions/ADR-003-shared-authentication.md), [Admission](decisions/ADR-004-scanner-admission.md).
- Execution: [Phase 0/1 report](PHASE_01_REPORT.md), [Phase 2 report](PHASE_02_REPORT.md), [Phase 2 tasks](phases/PHASE_02_AUTHENTICATION.md), [Phase 3 tasks](phases/PHASE_03_WORKSPACES.md), [Phase 3 report](PHASE_03_REPORT.md), [Phase 4 tasks](phases/PHASE_04_AUTHORIZATION.md), [Phase 4 report](PHASE_04_REPORT.md).

The root [setup guide](../README.md) remains the installation reference. Verified discrepancies are recorded in the audit. The existing hook document is retained and linked instead of duplicated.

Local project automation: [four-hour same-session continuation and stop instructions](LOCAL_CONTINUATION.md). This is separate from application scheduling and release gates.

Phase4 source completion: [Finite exit register](PHASE_04_EXIT_AUDIT.md) retains four family decisions. P4-043 deletion and P4-045 issuance source boundaries are accepted; read/export/media and scanner authority remain OPEN. Latest full gate: **2,567 tests / 46,329 assertions** (P4-047 slice 4, in progress; the latest completed-task gate is P4-045 at 2,288 / 44,727). Actual rollout and all 39 approved UI screens retain their prerequisites.

## Maintenance

READ → INSPECT → PLAN → IMPLEMENT → TEST → VERIFY → DOCUMENT. Work on one task at a time. Define scope, dependencies, acceptance criteria, tests and affected docs before coding. Preserve data and uncommitted changes. Record contradictions before changing behavior. Update affected references and report each phase with verification limits.

## Approved PangaEvents reference UI

The 2026-10-30 additive 39-screen adoption preserves the existing migration. [Implementation/measurements](REFERENCE_UI_IMPLEMENTATION.md), [screen matrix](REFERENCE_UI_SCREEN_MATRIX.md), [component matrix](REFERENCE_UI_COMPONENT_MATRIX.md), [visual QA](REFERENCE_UI_VISUAL_QA.md). All 39 references inspected; approved UI completion 0/39.

Historical P4-044 completed the read-only [issuance design](LEGACY_GUEST_ISSUANCE_DESIGN.md). P4-045 now supplies the bounded [implemented contract](LEGACY_GUEST_ISSUANCE.md), with **2,288 tests / 44,727 assertions**; families 3/4 remain OPEN. The original 39-screen simple UI and all customer/real-data/deployment prerequisites retain their owning phases.

P4-046 completed the independently reviewed [read/export/private-media design](LEGACY_READ_EXPORT_MEDIA_DESIGN.md) on 2026-11-05. P4-047 implementation is in progress: slice 1 (2026-11-07, independently reviewed and fixed) installs the read/export decoration, import-result binding, pre-serialized no-store CSV/XLSX and banner checks; slice 2 (2026-11-08, independently reviewed and fixed) installs the private `guest_qr` storage policy shared by issuance, deletion and buffered private PNG/ZIP delivery. Slice 3 (2026-11-08, independently reviewed and fixed) prepares the shared admin layout before rendering (no layout SQL while drawing) and makes the 11 family-3 HTML routes private no-store. Slice 4 (2026-11-09) reads each family-3 page, export and import preview in one locked attempt with immutable view values, and selected MySQL and MariaDB observations pass. **2,567 tests / 46,329 assertions** pass with all 2,288 prior cases exact except one documented query-count delta. No existing QR file is copied; until the gated cutover ([deployment item 10](DEPLOYMENT_CPANEL.md)) QR media, QR generation, guest creation and deletion of guests with a QR path fail closed. The final independent review, families 3/4 and Phase4 remain open.
