<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceManagementPinController;
use App\Http\Controllers\DeviceProvisioningController;
use App\Http\Controllers\QrProvisioningSettingsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('devices', DeviceController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('/devices/{device}/commands', [DeviceController::class, 'command'])->middleware('throttle:5,1')->name('devices.command');
    Route::post('/devices/{device}/release', [DeviceController::class, 'release'])->middleware('throttle:5,1')->name('devices.release');
    Route::post('/devices/{device}/unlock-code', [DeviceController::class, 'generateUnlockCode'])->middleware('throttle:5,1')->name('devices.unlock-code');
    Route::post('/devices/{device}/management-pin/reveal', [DeviceManagementPinController::class, 'reveal'])->middleware('throttle:5,1')->name('devices.management-pin.reveal');
    Route::post('/devices/{device}/management-pin/change', [DeviceManagementPinController::class, 'change'])->middleware('throttle:5,1')->name('devices.management-pin.change');
    Route::post('/devices/{device}/management-pin/generate', [DeviceManagementPinController::class, 'generate'])->middleware('throttle:5,1')->name('devices.management-pin.generate');
    Route::post('/devices/{device}/management-pin/reset-attempts', [DeviceManagementPinController::class, 'resetAttempts'])->middleware('throttle:5,1')->name('devices.management-pin.reset-attempts');
    Route::post('/devices/{device}/provisioning', [DeviceProvisioningController::class, 'generate'])->middleware('throttle:5,1')->name('devices.provisioning.generate');
    Route::get('/devices/{device}/provisioning/{token}', [DeviceProvisioningController::class, 'show'])->name('devices.provisioning.show');
    Route::get('/devices/{device}/provisioning/{token}/image', [DeviceProvisioningController::class, 'image'])->name('devices.provisioning.image');
    Route::post('/devices/{device}/provisioning/{token}/revoke', [DeviceProvisioningController::class, 'revoke'])->name('devices.provisioning.revoke');
    Route::get('/settings/qr-provisioning', [QrProvisioningSettingsController::class, 'edit'])->name('settings.qr-provisioning');
    Route::put('/settings/qr-provisioning', [QrProvisioningSettingsController::class, 'update'])->name('settings.qr-provisioning.update');
    Route::post('/settings/qr-provisioning/validate', [QrProvisioningSettingsController::class, 'validateConfiguration'])->name('settings.qr-provisioning.validate');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
