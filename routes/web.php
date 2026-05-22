<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ScannerController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::prefix('admin')->name('admin.')->middleware(['auth', 'role:'.User::ROLE_ADMIN])->group(function () {
    Route::redirect('/', '/admin/dashboard')->name('index');
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/guests', [AdminController::class, 'guests'])->name('guests.index');
    Route::get('/qr-codes', [AdminController::class, 'qrCodes'])->name('qr-codes.index');
    Route::post('/qr-codes/generate-missing', [AdminController::class, 'generateMissingQrCodes'])->name('qr-codes.generate-missing');
    Route::get('/qr-codes/download-all', [AdminController::class, 'downloadAllQrCodes'])->name('qr-codes.download-all');
    Route::get('/qr-codes/export', [AdminController::class, 'exportQrList'])->name('qr-codes.export');
    Route::patch('/qr-codes/{guest}/activate', [AdminController::class, 'activateQr'])->name('qr-codes.activate');
    Route::patch('/qr-codes/{guest}/deactivate', [AdminController::class, 'deactivateQr'])->name('qr-codes.deactivate');
    Route::get('/checkins', [AdminController::class, 'checkins'])->name('checkins.index');
    Route::get('/checkins/export', [AdminController::class, 'exportCheckins'])->name('checkins.export');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports.index');
    Route::get('/reports/export/guest-list', [AdminController::class, 'exportGuestList'])->name('reports.export.guest-list');
    Route::get('/reports/export/checked-in-guests', [AdminController::class, 'exportCheckedInGuests'])->name('reports.export.checked-in-guests');
    Route::get('/reports/export/remaining-guests', [AdminController::class, 'exportRemainingGuests'])->name('reports.export.remaining-guests');
    Route::get('/reports/export/invalid-scans', [AdminController::class, 'exportInvalidScans'])->name('reports.export.invalid-scans');
    Route::get('/scanner-users', [AdminController::class, 'scannerUsers'])->name('scanner-users.index');
    Route::get('/settings', [AdminController::class, 'settings'])->name('settings.index');

    Route::get('/guests/create', [AdminController::class, 'create'])->name('guests.create');
    Route::post('/guests', [AdminController::class, 'store'])->name('guests.store');
    Route::get('/guests/{guest}', [AdminController::class, 'show'])->name('guests.show');
    Route::get('/guests/{guest}/edit', [AdminController::class, 'edit'])->name('guests.edit');
    Route::put('/guests/{guest}', [AdminController::class, 'update'])->name('guests.update');
    Route::delete('/guests/{guest}', [AdminController::class, 'destroy'])->name('guests.destroy');
    Route::post('/guests/{guest}/qr/generate', [AdminController::class, 'generateQr'])->name('guests.qr.generate');
    Route::get('/guests/{guest}/qr', [AdminController::class, 'qr'])->name('guests.qr');
    Route::get('/guests/{guest}/qr/download', [AdminController::class, 'downloadQr'])->name('guests.qr.download');
    Route::patch('/guests/{guest}/cancel', [AdminController::class, 'cancel'])->name('guests.cancel');
    Route::patch('/guests/{guest}/revoke', [AdminController::class, 'revoke'])->name('guests.revoke');
    Route::patch('/guests/{guest}/restore', [AdminController::class, 'restore'])->name('guests.restore');
});

Route::prefix('scanner')->name('scanner.')->middleware(['auth', 'role:'.User::ROLE_SCANNER])->group(function () {
    Route::redirect('/', '/scanner/dashboard')->name('index');
    Route::get('/dashboard', [ScannerController::class, 'dashboard'])->name('dashboard');
    Route::get('/scan', [ScannerController::class, 'index'])->name('scan');
    Route::get('/recent-scans', [ScannerController::class, 'recentScans'])->name('recent-scans');

    Route::get('/ticket/{token}', [ScannerController::class, 'ticket'])->name('ticket');
    Route::get('/verify/{token}', [ScannerController::class, 'ticket'])->name('verify-token');
    Route::post('/validate', [ScannerController::class, 'validateQr'])->name('validate');
    Route::post('/verify', [ScannerController::class, 'verify'])->name('verify');
    Route::post('/admit', [ScannerController::class, 'admit'])->name('admit');
});
