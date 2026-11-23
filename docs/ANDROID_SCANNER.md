# Android scanner

Audit: 2026-10-11, Phase 0/1. This document separates Laravel support from evidence of a working Android application.

| Capability | State | Evidence or next check |
|---|---|---|
| Native Android Java/XML source, Gradle project, manifest | **REQUIRES VERIFICATION** | No Java/Kotlin/Gradle/AndroidManifest files found in this repository; no Android repository path supplied |
| Browser scanner | **CURRENT** | [Scanner architecture](SCANNER_ARCHITECTURE.md); retain it |
| Encrypted event package generation | **CURRENT** | [Package builder](../app/Services/OfflineScannerPackageBuilder.php) emits `.wqrpkg` |
| Authenticated master result file validation/import | **CURRENT** | [Offline sync](OFFLINE_SYNC.md) describes the Laravel `.wqrresults` contract |
| Real camera, local database, hotspot pairing, master/client admission | **REQUIRES VERIFICATION** | PHP fixtures/schema names do not demonstrate physical-device behavior |
| Same-account token authentication server | **IMPLEMENTED P2-004 in source/tests** | HTTPS login/me/logout, expiry and revocation; see [API contract](API_CONTRACT.md). Native client/device proof absent |
| Android guest/scanner APIs and client integration | **PLANNED** | Authentication foundation does not implement scanning endpoints or a native application |

**TARGET:** Native Java, XML layouts and Retrofit/OkHttp, using the same Laravel identity as the website. Workflow: login → dashboard → scan → validate → select count → confirm → next scan; manual phone/4-digit-code lookup, recent scans and logout. Android and browser must share event authorization and the authoritative admission domain service. Local PIN/biometric only unlocks an already authenticated cache. See [device authentication](DEVICE_AND_OFFLINE_AUTH.md).

**TARGET:** Preserve verified offline capabilities if an existing app is provided. Current Laravel vocabulary is `master`/`client`; the product request's “slave” means client and is not a new wire role. A connected local master can coordinate counters; fully disconnected scanners cannot guarantee global duplicate prevention without an explicit allocation or reconciliation design.

**PLANNED acceptance evidence, Phase 14/15:** Identify the Android repository and build instructions; build reproducibly; verify HTTPS login/logout/token expiry and event isolation; use shared serialization/hash/crypto fixtures; import a real signed package; pair master/client devices; test the same last entry concurrently; interrupt network/power and retry the same request; restart and logout with unsynced records; export a real result file and reconcile it exactly once in Laravel. Record device/OS/app versions and test results. A server-side synthetic fixture passing is insufficient to mark this workflow complete.

No Android build, emulator or device test was performed in Phase 0/1. No APK, pairing compatibility or production readiness is claimed.
