# Current route registry

## CURRENT — runtime registration snapshot

Verified through 2026-10-23 with `php artisan route:list --json` (Laravel 12.70.0). **100 routes** after P4-009; Phase 0/1 baseline was 88. A listed route does not certify deployed workflow or visual UI. Middleware is registered order; framework priority affects execution. The web group includes persisted-account and password-session enforcement. Parent: [API contract](API_CONTRACT.md).

| Method | Path | Action | Registered middleware |
|---|---|---|---|
| GET, HEAD | `/customer` | `CustomerHomeController@index` | web, auth, role:customer |
| GET, HEAD | `/customer/workspaces/create` | `CustomerWorkspaceController@create` | web, auth, role:customer |
| POST | `/customer/workspaces` | `CustomerWorkspaceController@store` | web, auth, role:customer, throttle:customer-workspace-create |
| GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS | `/` | `Illuminate\Routing\RedirectController` | web |
| GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS | `/admin` | `Illuminate\Routing\RedirectController` | web, auth, role:admin |
| GET, HEAD | `/admin/checkins` | `AdminController@checkins` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/checkins/export` | `AdminController@exportCheckins` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/dashboard` | `AdminController@dashboard` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/events` | `AdminEventController@index` | web, auth, role:admin |
| POST | `/admin/events` | `AdminEventController@store` | web, auth, role:admin |
| GET, HEAD | `/admin/events/create` | `AdminEventController@create` | web, auth, role:admin |
| PUT | `/admin/events/{event}` | `AdminEventController@update` | web, auth, role:admin |
| POST, PATCH | `/admin/events/{event}/archive` | `AdminEventController@archive` | web, auth, role:admin |
| POST, PATCH | `/admin/events/{event}/cancel` | `AdminEventController@cancel` | web, auth, role:admin |
| GET, HEAD | `/admin/events/{event}/edit` | `AdminEventController@edit` | web, auth, role:admin |
| GET, HEAD | `/admin/events/{event}/offline-scanner` | `Admin\OfflineScannerSessionController@create` | web, auth, role:admin |
| POST | `/admin/events/{event}/offline-scanner/sessions` | `Admin\OfflineScannerSessionController@store` | web, auth, role:admin, throttle:10,1 |
| GET, POST, HEAD | `/admin/events/{event}/select` | `AdminEventController@select` | web, auth, role:admin |
| GET, HEAD | `/admin/guests` | `AdminController@guests` | web, auth, role:admin, admin.event |
| POST | `/admin/guests` | `AdminController@store` | web, auth, role:admin, admin.event |
| DELETE | `/admin/guests` | `AdminController@bulkDestroy` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/guests/create` | `AdminController@create` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/guests/import` | `AdminController@import` | web, auth, role:admin, admin.event |
| POST | `/admin/guests/import` | `AdminController@processGuestImport` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/guests/import/sample` | `AdminController@sampleGuestImport` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/guests/{guest}` | `AdminController@show` | web, auth, role:admin, admin.event |
| PUT | `/admin/guests/{guest}` | `AdminController@update` | web, auth, role:admin, admin.event |
| DELETE | `/admin/guests/{guest}` | `AdminController@destroy` | web, auth, role:admin, admin.event |
| PATCH | `/admin/guests/{guest}/cancel` | `AdminController@cancel` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/guests/{guest}/edit` | `AdminController@edit` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/guests/{guest}/qr` | `AdminController@qr` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/guests/{guest}/qr/download` | `AdminController@downloadQr` | web, auth, role:admin, admin.event |
| POST | `/admin/guests/{guest}/qr/generate` | `AdminController@generateQr` | web, auth, role:admin, admin.event |
| PATCH | `/admin/guests/{guest}/restore` | `AdminController@restore` | web, auth, role:admin, admin.event |
| PATCH | `/admin/guests/{guest}/revoke` | `AdminController@revoke` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/login` | `AuthController@showLogin` | web |
| GET, HEAD | `/admin/notification-logs` | `AdminController@notificationLogs` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/offline-scanner/conflicts/{conflict}` | `Admin\OfflineScannerConflictController@show` | web, auth, role:admin, admin.event |
| POST | `/admin/offline-scanner/conflicts/{conflict}/resolve` | `Admin\OfflineScannerConflictController@resolve` | web, auth, role:admin, admin.event, throttle:20,1 |
| DELETE | `/admin/offline-scanner/packages/{offlineScannerPackage}` | `Admin\OfflineScannerPackageController@destroy` | web, auth, role:admin, admin.event, throttle:5,1 |
| POST | `/admin/offline-scanner/packages/{offlineScannerPackage}/archive` | `Admin\OfflineScannerPackageController@archive` | web, auth, role:admin, admin.event, throttle:10,1 |
| GET, HEAD | `/admin/offline-scanner/packages/{offlineScannerPackage}/download` | `Admin\OfflineScannerPackageController@download` | web, auth, role:admin, admin.event |
| POST | `/admin/offline-scanner/results/preview` | `Admin\OfflineScannerResultImportController@preview` | web, auth, role:admin, admin.event, throttle:10,1 |
| GET, HEAD | `/admin/offline-scanner/results/{resultImport}` | `Admin\OfflineScannerResultImportController@show` | web, auth, role:admin, admin.event |
| DELETE | `/admin/offline-scanner/results/{resultImport}` | `Admin\OfflineScannerResultImportController@destroy` | web, auth, role:admin, admin.event, throttle:5,1 |
| POST | `/admin/offline-scanner/results/{resultImport}/archive` | `Admin\OfflineScannerResultImportController@archive` | web, auth, role:admin, admin.event, throttle:10,1 |
| POST | `/admin/offline-scanner/results/{resultImport}/confirm` | `Admin\OfflineScannerResultImportController@confirm` | web, auth, role:admin, admin.event, throttle:10,1 |
| GET, HEAD | `/admin/offline-scanner/results/{resultImport}/conflicts` | `Admin\OfflineScannerResultImportController@conflicts` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/offline-scanner/results/{resultImport}/logs` | `Admin\OfflineScannerResultImportController@logs` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/offline-scanner/sessions/{offlineScannerSession}` | `Admin\OfflineScannerSessionController@show` | web, auth, role:admin, admin.event |
| POST | `/admin/offline-scanner/sessions/{offlineScannerSession}/cancel` | `Admin\OfflineScannerSessionController@cancel` | web, auth, role:admin, admin.event, throttle:10,1 |
| POST | `/admin/offline-scanner/sessions/{offlineScannerSession}/close` | `Admin\OfflineScannerSessionController@close` | web, auth, role:admin, admin.event, throttle:10,1 |
| POST | `/admin/offline-scanner/sessions/{offlineScannerSession}/generate-package` | `Admin\OfflineScannerPackageController@generate` | web, auth, role:admin, admin.event, throttle:20,1 |
| GET, HEAD | `/admin/qr-codes` | `AdminController@qrCodes` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/qr-codes/download-all` | `AdminController@downloadAllQrCodes` | web, auth, role:admin, admin.event |
| POST | `/admin/qr-codes/download-selected` | `AdminController@downloadSelectedQrCodes` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/qr-codes/export` | `AdminController@exportQrList` | web, auth, role:admin, admin.event |
| POST | `/admin/qr-codes/generate-missing` | `AdminController@generateMissingQrCodes` | web, auth, role:admin, admin.event |
| PATCH | `/admin/qr-codes/{guest}/activate` | `AdminController@activateQr` | web, auth, role:admin, admin.event |
| PATCH | `/admin/qr-codes/{guest}/deactivate` | `AdminController@deactivateQr` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/reports` | `AdminController@reports` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/reports/export/checked-in-guests` | `AdminController@exportCheckedInGuests` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/reports/export/guest-list` | `AdminController@exportGuestList` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/reports/export/invalid-scans` | `AdminController@exportInvalidScans` | web, auth, role:admin, admin.event |
| GET, HEAD | `/admin/reports/export/remaining-guests` | `AdminController@exportRemainingGuests` | web, auth, role:admin, admin.event |
| GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS | `/admin/scanner-users` | `Illuminate\Routing\RedirectController` | web, auth, role:admin |
| GET, HEAD | `/admin/settings` | `AdminController@settings` | web, auth, role:admin |
| GET, HEAD | `/admin/users` | `AdminUserController@index` | web, auth, role:admin |
| POST | `/admin/users` | `AdminUserController@store` | web, auth, role:admin |
| GET, HEAD | `/admin/users/create` | `AdminUserController@create` | web, auth, role:admin |
| PUT, PATCH | `/admin/users/{user}` | `AdminUserController@update` | web, auth, role:admin |
| DELETE | `/admin/users/{user}` | `AdminUserController@destroy` | web, auth, role:admin |
| GET, HEAD | `/admin/users/{user}/edit` | `AdminUserController@edit` | web, auth, role:admin |
| POST | `/api/v1/auth/login` | `Api\DeviceAuthController@login` | api, throttle:device-login; global API HTTPS/privacy boundary |
| GET, HEAD | `/api/v1/auth/me` | `Api\DeviceAuthController@me` | api, device.token:auth:read; global API HTTPS/privacy boundary |
| POST | `/api/v1/auth/logout` | `Api\DeviceAuthController@logout` | api, device.token:auth:logout; global API HTTPS/privacy boundary |
| GET, HEAD | `/forgot-password` | `PasswordRecoveryController@request` | web |
| POST | `/forgot-password` | `PasswordRecoveryController@email` | web, throttle:password-recovery |
| GET, HEAD | `/reset-password/{token}` | `PasswordRecoveryController@reset` | web |
| POST | `/reset-password` | `PasswordRecoveryController@update` | web, throttle:password-reset |
| GET, HEAD | `/login` | `AuthController@showLogin` | web |
| POST | `/login` | `AuthController@login` | web, throttle:login |
| POST | `/logout` | `AuthController@logout` | web, auth |
| GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS | `/scanner` | `Illuminate\Routing\RedirectController` | web, scanner.https, auth, role:scanner |
| POST | `/scanner/admit` | `ScannerController@admit` | web, scanner.https, auth, role:scanner, throttle:60,1, offline.admission |
| GET, HEAD | `/scanner/dashboard` | `ScannerController@dashboard` | web, scanner.https, auth, role:scanner |
| GET, HEAD | `/scanner/login` | `AuthController@showLogin` | web, scanner.https |
| POST | `/scanner/manual-admit` | `ScannerController@manualAdmit` | web, scanner.https, auth, role:scanner, throttle:60,1, offline.admission |
| GET, HEAD | `/scanner/manual-search` | `ScannerController@manualSearch` | web, scanner.https, auth, role:scanner |
| GET, HEAD | `/scanner/recent-scans` | `ScannerController@recentScans` | web, scanner.https, auth, role:scanner |
| GET, HEAD | `/scanner/scan` | `ScannerController@index` | web, scanner.https, auth, role:scanner |
| GET, HEAD | `/scanner/ticket/{token}` | `ScannerController@ticket` | web, scanner.https, auth, role:scanner |
| POST | `/scanner/validate` | `ScannerController@validateQr` | web, scanner.https, auth, role:scanner, throttle:180,1, offline.admission |
| POST | `/scanner/verify` | `ScannerController@verify` | web, scanner.https, auth, role:scanner, throttle:180,1, offline.admission |
| GET, HEAD | `/scanner/verify/{token}` | `ScannerController@ticket` | web, scanner.https, auth, role:scanner |
| GET, HEAD | `/storage/{path}` | `Closure` | framework handling |
| PUT | `/storage/{path}` | `Closure` | framework handling |
| GET, HEAD | `/up` | `Closure` | framework handling |
| GET, HEAD | `/webhooks/whatsapp/meta` | `Webhooks\MetaWhatsappWebhookController@verify` | web |
| POST | `/webhooks/whatsapp/meta` | `Webhooks\MetaWhatsappWebhookController@receive` | web |

Framework local storage handlers enforce signatures; they are not customer file APIs. Public static storage access is separate from this registry.
