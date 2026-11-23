<?php

use App\Http\Controllers\Api\DeviceAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function (): void {
    Route::post('login', [DeviceAuthController::class, 'login'])->middleware('throttle:device-login')->name('login');
    Route::get('me', [DeviceAuthController::class, 'me'])->middleware('device.token:auth:read')->name('me');
    Route::post('logout', [DeviceAuthController::class, 'logout'])->middleware('device.token:auth:logout')->name('logout');
});
