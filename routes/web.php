<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\OfflineScannerConflictController;
use App\Http\Controllers\Admin\OfflineScannerPackageController;
use App\Http\Controllers\Admin\OfflineScannerResultImportController;
use App\Http\Controllers\Admin\OfflineScannerSessionController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminEventController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerEventDashboardController;
use App\Http\Controllers\CustomerEventDetailsController;
use App\Http\Controllers\CustomerEventManageController;
use App\Http\Controllers\CustomerEventSetupController;
use App\Http\Controllers\CustomerEventTypeController;
use App\Http\Controllers\CustomerGuestController;
use App\Http\Controllers\CustomerHomeController;
use App\Http\Controllers\CustomerInvitationController;
use App\Http\Controllers\CustomerInvitationTemplateController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\CustomerShareController;
use App\Http\Controllers\InvitationMediaController;
use App\Http\Controllers\CustomerWorkspaceController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PublicInvitationController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\SplashScreenController;
use App\Http\Controllers\Webhooks\MetaWhatsappWebhookController;
use App\Http\Controllers\WelcomeScreenController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', [SplashScreenController::class, 'show'])->name('reference_ui.01_splash_screen.show');
Route::get('/welcome', [WelcomeScreenController::class, 'show'])->name('reference_ui.10_welcome_how_it_works.show');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
Route::view('/terms', 'auth.terms')->name('terms');
Route::get('/admin/login', [AuthController::class, 'showLogin'])->name('admin.login');
Route::get('/scanner/login', [AuthController::class, 'showLogin'])->middleware('scanner.https')->name('scanner.login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/forgot-password', [PasswordRecoveryController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordRecoveryController::class, 'email'])->middleware('throttle:password-recovery')->name('password.email');
Route::get('/reset-password/{token}', [PasswordRecoveryController::class, 'reset'])->name('password.reset');
Route::post('/reset-password', [PasswordRecoveryController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');

Route::get('/customer', [CustomerHomeController::class, 'index'])
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->name('customer.home');
Route::get('/customer/workspaces/create', [CustomerWorkspaceController::class, 'create'])
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->name('customer.workspaces.create');
Route::post('/customer/workspaces', [CustomerWorkspaceController::class, 'store'])
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER, 'throttle:customer-workspace-create'])->name('customer.workspaces.store');
Route::get('/customer/events/create', [CustomerEventTypeController::class, 'create'])
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->name('customer.events.create');
Route::post('/customer/events/create', [CustomerEventTypeController::class, 'store'])
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER, 'throttle:30,1'])->name('customer.events.type');
Route::get('/customer/events/details', [CustomerEventDetailsController::class, 'create'])
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->name('customer.events.details');
Route::post('/customer/events', [CustomerEventDetailsController::class, 'store'])
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER, 'throttle:20,1'])->name('customer.events.store');
Route::get('/customer/events/{event}/setup', [CustomerEventSetupController::class, 'show'])
    ->whereNumber('event')->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->name('customer.events.setup');
Route::get('/customer/events/{event}/dashboard', [CustomerEventDashboardController::class, 'show'])
    ->whereNumber('event')->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->name('customer.events.dashboard');
Route::get('/customer/events/{event}/template', [CustomerInvitationTemplateController::class, 'show'])
    ->whereNumber('event')->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->name('customer.events.template');
Route::post('/customer/events/{event}/template', [CustomerInvitationTemplateController::class, 'store'])
    ->whereNumber('event')->middleware(['auth', 'role:'.User::ROLE_CUSTOMER, 'throttle:30,1'])->name('customer.events.template.store');

// Invitation workflow: editor -> recipients -> per-guest preview -> exports / WhatsApp -> status.
Route::prefix('/customer/events/{event}/invitation')->whereNumber('event')->name('customer.events.invitation')
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->group(function () {
        Route::get('/', [CustomerInvitationController::class, 'edit'])->name('');
        Route::get('/continue', [CustomerInvitationController::class, 'resume'])->name('.continue');
        Route::post('/', [CustomerInvitationController::class, 'save'])->middleware('throttle:60,1')->name('.save');
        Route::post('/preview', [CustomerInvitationController::class, 'preview'])->middleware('throttle:120,1')->name('.preview');
        Route::post('/photo', [CustomerInvitationController::class, 'uploadPhoto'])->middleware('throttle:20,1')->name('.photo.store');
        Route::get('/photo/{photo}', [CustomerInvitationController::class, 'photo'])->where('photo', '[a-f0-9]{32}')->name('.photo');
        Route::get('/templates/{template}.webp', [CustomerInvitationController::class, 'thumbnail'])->where('template', '[a-z0-9_]{1,40}')->middleware('throttle:600,1')->name('.thumb');
        Route::get('/guests', [CustomerInvitationController::class, 'guests'])->name('.guests');
        Route::post('/generate', [CustomerInvitationController::class, 'generate'])->middleware('throttle:60,1')->name('.generate');
        Route::post('/send', [CustomerInvitationController::class, 'send'])->middleware('throttle:30,1')->name('.send');
        Route::post('/download', [CustomerInvitationController::class, 'download'])->middleware('throttle:20,1')->name('.download');
        Route::get('/guests/{guest}', [CustomerInvitationController::class, 'guest'])->whereNumber('guest')->name('.guest');
        Route::get('/guests/{guest}/card.{format}', [CustomerInvitationController::class, 'card'])->whereNumber('guest')->where('format', 'png|jpg|pdf')->name('.card');
        Route::get('/status', [CustomerInvitationController::class, 'status'])->name('.status');
    });
// Guests added by the event owner/manager: Add Guest (reference 15) and Import Guests (reference 16).
Route::prefix('/customer/events/{event}/guests')->whereNumber('event')->name('customer.events.guests')
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->group(function () {
        Route::get('/add', [CustomerGuestController::class, 'create'])->name('.create');
        Route::post('/add', [CustomerGuestController::class, 'store'])->middleware('throttle:60,1')->name('.store');
        Route::post('/add/review', [CustomerGuestController::class, 'review'])->middleware('throttle:60,1')->name('.review');
        Route::post('/add/bulk', [CustomerGuestController::class, 'bulk'])->middleware('throttle:30,1')->name('.bulk');
        Route::post('/bulk-delete', [CustomerGuestController::class, 'bulkDestroy'])->middleware('throttle:10,1')->name('.bulk_destroy');
        Route::get('/import', [CustomerGuestController::class, 'import'])->name('.import');
        Route::post('/import', [CustomerGuestController::class, 'preview'])->middleware('throttle:20,1')->name('.import.preview');
        Route::post('/import/confirm', [CustomerGuestController::class, 'confirm'])->middleware('throttle:20,1')->name('.import.confirm');
        Route::get('/import/template', [CustomerGuestController::class, 'sample'])->name('.import.sample');
        Route::get('/import/template.csv', [CustomerGuestController::class, 'sampleCsv'])->name('.import.sample_csv');
        Route::get('/{guest}/edit', [CustomerGuestController::class, 'edit'])->whereNumber('guest')->name('.edit');
        Route::put('/{guest}', [CustomerGuestController::class, 'update'])->whereNumber('guest')->middleware('throttle:60,1')->name('.update');
        Route::delete('/{guest}', [CustomerGuestController::class, 'destroy'])->whereNumber('guest')->middleware('throttle:30,1')->name('.destroy');
    });
// Invitation Sharing Center: the customer shares invitations through their own WhatsApp / SMS / share
// sheet. No messaging API; the server prepares text and links and records only what the customer did.
Route::prefix('/customer/events/{event}/share')->whereNumber('event')->name('customer.events.share')
    ->middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->group(function () {
        Route::get('/', [CustomerShareController::class, 'index'])->name('');
        Route::get('/new', [CustomerShareController::class, 'create'])->name('.create');
        Route::post('/select', [CustomerShareController::class, 'select'])->middleware('throttle:30,1')->name('.select');
        Route::post('/sessions', [CustomerShareController::class, 'store'])->middleware('throttle:20,1')->name('.sessions.store');
        Route::get('/sessions/{session}', [CustomerShareController::class, 'session'])->whereNumber('session')->name('.sessions.show');
        Route::post('/sessions/{session}', [CustomerShareController::class, 'progress'])->whereNumber('session')->middleware('throttle:240,1')->name('.sessions.progress');
        Route::get('/guests/{guest}', [CustomerShareController::class, 'guest'])->whereNumber('guest')->name('.guest');
        Route::post('/record', [CustomerShareController::class, 'record'])->middleware('throttle:240,1')->name('.record');
        Route::get('/history', [CustomerShareController::class, 'history'])->name('.history');
        Route::get('/templates', [CustomerShareController::class, 'templatesPage'])->name('.templates');
        Route::post('/templates', [CustomerShareController::class, 'storeTemplate'])->middleware('throttle:30,1')->name('.templates.store');
        Route::put('/templates/{template}', [CustomerShareController::class, 'updateTemplate'])->whereNumber('template')->middleware('throttle:30,1')->name('.templates.update');
        Route::delete('/templates/{template}', [CustomerShareController::class, 'destroyTemplate'])->whereNumber('template')->middleware('throttle:30,1')->name('.templates.destroy');
    });
// Customer self-service: own profile, and edit/delete of an event they own or manage.
Route::middleware(['auth', 'role:'.User::ROLE_CUSTOMER])->group(function () {
    Route::get('/customer/profile', [CustomerProfileController::class, 'show'])->name('customer.profile');
    Route::get('/customer/help', [CustomerProfileController::class, 'help'])->name('customer.help');
    Route::put('/customer/profile', [CustomerProfileController::class, 'update'])->middleware('throttle:20,1')->name('customer.profile.update');
    Route::put('/customer/profile/password', [CustomerProfileController::class, 'password'])->middleware('throttle:10,1')->name('customer.profile.password');
    Route::get('/customer/events/{event}/edit', [CustomerEventManageController::class, 'edit'])->whereNumber('event')->name('customer.events.edit');
    Route::put('/customer/events/{event}', [CustomerEventManageController::class, 'update'])->whereNumber('event')->middleware('throttle:30,1')->name('customer.events.update');
    Route::delete('/customer/events/{event}', [CustomerEventManageController::class, 'destroy'])->whereNumber('event')->middleware('throttle:10,1')->name('customer.events.destroy');
});
// Signed, expiring card image for the WhatsApp Cloud API media fetch (no session).
Route::get('/invitations/media/{card}/{v}', [InvitationMediaController::class, 'show'])
    ->middleware(['signed', 'throttle:120,1'])->name('invitations.media');

// Guest-facing personal invitation link shared by the customer (no login; key-scoped to one guest).
Route::get('/i/{key}', [PublicInvitationController::class, 'show'])->where('key', '[A-Za-z0-9_-]{32}')
    ->middleware('throttle:60,1')->name('invitations.public');
Route::get('/i/{key}/card.png', [PublicInvitationController::class, 'card'])->where('key', '[A-Za-z0-9_-]{32}')
    ->middleware('throttle:60,1')->name('invitations.public.card');

Route::get('/webhooks/whatsapp/meta', [MetaWhatsappWebhookController::class, 'verify'])
    ->name('webhooks.whatsapp.meta.verify');
Route::post('/webhooks/whatsapp/meta', [MetaWhatsappWebhookController::class, 'receive'])
    ->name('webhooks.whatsapp.meta.receive');

Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:'.User::ROLE_ADMIN])->group(function () {
    Route::redirect('/', '/admin/dashboard')->name('index');
    Route::get('/events', [AdminEventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [AdminEventController::class, 'create'])->name('events.create');
    Route::post('/events', [AdminEventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}/edit', [AdminEventController::class, 'edit'])->name('events.edit');
    Route::put('/events/{event}', [AdminEventController::class, 'update'])->name('events.update');
    Route::match(['get', 'post'], '/events/{event}/select', [AdminEventController::class, 'select'])->name('events.select');
    Route::match(['post', 'patch'], '/events/{event}/archive', [AdminEventController::class, 'archive'])->name('events.archive');
    Route::match(['post', 'patch'], '/events/{event}/cancel', [AdminEventController::class, 'cancel'])->name('events.cancel');

    Route::get('/events/{event}/offline-scanner', [OfflineScannerSessionController::class, 'create'])->name('offline-scanner-sessions.create');
    Route::post('/events/{event}/offline-scanner/sessions', [OfflineScannerSessionController::class, 'store'])->middleware('throttle:10,1')->name('offline-scanner-sessions.store');
    Route::middleware('admin.event')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->middleware('admin.private')->name('dashboard');
        Route::get('/offline-scanner/sessions/{offlineScannerSession}', [OfflineScannerSessionController::class, 'show'])->name('offline-scanner-sessions.show');
        Route::post('/offline-scanner/sessions/{offlineScannerSession}/generate-package', [OfflineScannerPackageController::class, 'generate'])->middleware('throttle:20,1')->name('offline-scanner-sessions.generate-package');
        Route::post('/offline-scanner/sessions/{offlineScannerSession}/cancel', [OfflineScannerSessionController::class, 'cancel'])->middleware('throttle:10,1')->name('offline-scanner-sessions.cancel');
        Route::post('/offline-scanner/sessions/{offlineScannerSession}/close', [OfflineScannerSessionController::class, 'close'])->middleware('throttle:10,1')->name('offline-scanner-sessions.close');
        Route::get('/offline-scanner/packages/{offlineScannerPackage}/download', [OfflineScannerPackageController::class, 'download'])->name('offline-scanner-packages.download');
        Route::post('/offline-scanner/packages/{offlineScannerPackage}/archive', [OfflineScannerPackageController::class, 'archive'])->middleware('throttle:10,1')->name('offline-scanner-packages.archive');
        Route::delete('/offline-scanner/packages/{offlineScannerPackage}', [OfflineScannerPackageController::class, 'destroy'])->middleware('throttle:5,1')->name('offline-scanner-packages.destroy');
        Route::post('/offline-scanner/results/preview', [OfflineScannerResultImportController::class, 'preview'])->middleware('throttle:10,1')->name('offline-scanner-results.preview');
        Route::post('/offline-scanner/results/{resultImport}/confirm', [OfflineScannerResultImportController::class, 'confirm'])->middleware('throttle:10,1')->name('offline-scanner-results.confirm');
        Route::get('/offline-scanner/results/{resultImport}', [OfflineScannerResultImportController::class, 'show'])->name('offline-scanner-results.show');
        Route::post('/offline-scanner/results/{resultImport}/archive', [OfflineScannerResultImportController::class, 'archive'])->middleware('throttle:10,1')->name('offline-scanner-results.archive');
        Route::delete('/offline-scanner/results/{resultImport}', [OfflineScannerResultImportController::class, 'destroy'])->middleware('throttle:5,1')->name('offline-scanner-results.destroy');
        Route::get('/offline-scanner/results/{resultImport}/logs', [OfflineScannerResultImportController::class, 'logs'])->name('offline-scanner-results.logs');
        Route::get('/offline-scanner/results/{resultImport}/conflicts', [OfflineScannerResultImportController::class, 'conflicts'])->name('offline-scanner-results.conflicts');
        Route::get('/offline-scanner/conflicts/{conflict}', [OfflineScannerConflictController::class, 'show'])->name('offline-scanner-conflicts.show');
        Route::post('/offline-scanner/conflicts/{conflict}/resolve', [OfflineScannerConflictController::class, 'resolve'])->middleware('throttle:20,1')->name('offline-scanner-conflicts.resolve');
        Route::get('/guests', [AdminController::class, 'guests'])->middleware('admin.private')->name('guests.index');
        Route::get('/qr-codes', [AdminController::class, 'qrCodes'])->middleware('admin.private')->name('qr-codes.index');
        Route::post('/qr-codes/generate-missing', [AdminController::class, 'generateMissingQrCodes'])->name('qr-codes.generate-missing');
        Route::get('/qr-codes/download-all', [AdminController::class, 'downloadAllQrCodes'])->name('qr-codes.download-all');
        Route::post('/qr-codes/download-selected', [AdminController::class, 'downloadSelectedQrCodes'])->name('qr-codes.download-selected');
        Route::get('/qr-codes/export', [AdminController::class, 'exportQrList'])->name('qr-codes.export');
        Route::patch('/qr-codes/{guest}/activate', [AdminController::class, 'activateQr'])->name('qr-codes.activate');
        Route::patch('/qr-codes/{guest}/deactivate', [AdminController::class, 'deactivateQr'])->name('qr-codes.deactivate');
        Route::get('/checkins', [AdminController::class, 'checkins'])->middleware('admin.private')->name('checkins.index');
        Route::get('/checkins/export', [AdminController::class, 'exportCheckins'])->name('checkins.export');
        Route::get('/notification-logs', [AdminController::class, 'notificationLogs'])->middleware('admin.private')->name('notification-logs.index');
        Route::get('/reports', [AdminController::class, 'reports'])->middleware('admin.private')->name('reports.index');
        Route::get('/reports/export/guest-list', [AdminController::class, 'exportGuestList'])->name('reports.export.guest-list');
        Route::get('/reports/export/checked-in-guests', [AdminController::class, 'exportCheckedInGuests'])->name('reports.export.checked-in-guests');
        Route::get('/reports/export/remaining-guests', [AdminController::class, 'exportRemainingGuests'])->name('reports.export.remaining-guests');
        Route::get('/reports/export/invalid-scans', [AdminController::class, 'exportInvalidScans'])->name('reports.export.invalid-scans');
    });
    // Customer accounts (self-registered): list, profile, edit, suspend/reactivate, safe delete.
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('admin.private')->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->middleware('admin.private')->name('customers.show');
    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->whereNumber('customer')->middleware('admin.private')->name('customers.edit');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->middleware('throttle:30,1')->name('customers.update');
    Route::post('/customers/{customer}/suspend', [CustomerController::class, 'suspend'])->whereNumber('customer')->middleware('throttle:20,1')->name('customers.suspend');
    Route::post('/customers/{customer}/reactivate', [CustomerController::class, 'reactivate'])->whereNumber('customer')->middleware('throttle:20,1')->name('customers.reactivate');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->whereNumber('customer')->middleware('throttle:10,1')->name('customers.destroy');
    Route::redirect('/scanner-users', '/admin/users')->name('scanner-users.index');
    Route::resource('/users', AdminUserController::class)->except(['show']);
    Route::get('/settings', [AdminController::class, 'settings'])->name('settings.index');

    Route::middleware('admin.event')->group(function () {
        Route::get('/guests/create', [AdminController::class, 'create'])->middleware('admin.private')->name('guests.create');
        Route::post('/guests', [AdminController::class, 'store'])->name('guests.store');
        Route::get('/guests/import', [AdminController::class, 'import'])->middleware('admin.private')->name('guests.import');
        Route::post('/guests/import', [AdminController::class, 'processGuestImport'])->middleware('admin.private')->name('guests.import.process');
        Route::get('/guests/import/sample', [AdminController::class, 'sampleGuestImport'])->name('guests.import.sample');
        Route::get('/guests/{guest}', [AdminController::class, 'show'])->middleware('admin.private')->name('guests.show');
        Route::get('/guests/{guest}/edit', [AdminController::class, 'edit'])->middleware('admin.private')->name('guests.edit');
        Route::put('/guests/{guest}', [AdminController::class, 'update'])->name('guests.update');
        Route::delete('/guests', [AdminController::class, 'bulkDestroy'])->name('guests.bulk-destroy');
        Route::delete('/guests/{guest}', [AdminController::class, 'destroy'])->name('guests.destroy');
        Route::post('/guests/{guest}/qr/generate', [AdminController::class, 'generateQr'])->name('guests.qr.generate');
        Route::get('/guests/{guest}/qr', [AdminController::class, 'qr'])->name('guests.qr');
        Route::get('/guests/{guest}/qr/download', [AdminController::class, 'downloadQr'])->name('guests.qr.download');
        Route::patch('/guests/{guest}/cancel', [AdminController::class, 'cancel'])->name('guests.cancel');
        Route::patch('/guests/{guest}/revoke', [AdminController::class, 'revoke'])->name('guests.revoke');
        Route::patch('/guests/{guest}/restore', [AdminController::class, 'restore'])->name('guests.restore');
    });
});

Route::prefix('scanner')->name('scanner.')->middleware(['scanner.https', 'auth', 'role:'.User::ROLE_SCANNER])->group(function () {
    Route::redirect('/', '/scanner/dashboard')->name('index');
    Route::get('/dashboard', [ScannerController::class, 'dashboard'])->name('dashboard');
    Route::get('/scan', [ScannerController::class, 'index'])->name('scan');
    Route::get('/manual-search', [ScannerController::class, 'manualSearch'])->name('manual-search');
    Route::post('/manual-admit', [ScannerController::class, 'manualAdmit'])->middleware(['throttle:60,1', 'offline.admission'])->name('manual-admit');
    Route::get('/recent-scans', [ScannerController::class, 'recentScans'])->name('recent-scans');

    Route::get('/ticket/{token}', [ScannerController::class, 'ticket'])->name('ticket');
    Route::get('/verify/{token}', [ScannerController::class, 'ticket'])->name('verify-token');
    Route::post('/validate', [ScannerController::class, 'validateQr'])->middleware(['throttle:180,1', 'offline.admission'])->name('validate');
    Route::post('/verify', [ScannerController::class, 'verify'])->middleware(['throttle:180,1', 'offline.admission'])->name('verify');
    Route::post('/admit', [ScannerController::class, 'admit'])->middleware(['throttle:60,1', 'offline.admission'])->name('admit');
});
