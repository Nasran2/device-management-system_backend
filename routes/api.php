<?php

use App\Http\Controllers\Api\V1\AccessCodeController;
use App\Http\Controllers\Api\V1\ActivationController;
use App\Http\Controllers\Api\V1\CommandController;
use App\Http\Controllers\Api\V1\ConfigurationController;
use App\Http\Controllers\Api\V1\DeviceSyncController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\ManagementPinController;
use App\Http\Controllers\Api\V1\ProvisioningEnrollmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::get('health', fn () => response()->json([
        'success' => true,
        'message' => 'DeviceGuard API is running',
        'environment' => app()->environment(),
    ]));
    Route::post('devices/activate', ActivationController::class)->middleware('throttle:5,1');
    Route::post('devices/provision', ProvisioningEnrollmentController::class)->middleware('throttle:5,1');
    Route::middleware('device.auth')->group(function () {
        Route::post('devices/heartbeat', [DeviceSyncController::class, 'heartbeat']);
        Route::post('devices/capabilities', [DeviceSyncController::class, 'capabilities']);
        Route::get('commands', [CommandController::class, 'index']);
        Route::post('commands/{command}/acknowledge', [CommandController::class, 'acknowledge']);
        Route::post('commands/{command}/executing', [CommandController::class, 'executing']);
        Route::post('commands/{command}/result', [CommandController::class, 'result']);
        Route::post('locations', [LocationController::class, 'store'])->middleware('throttle:120,1');
        Route::get('configuration', ConfigurationController::class);
        Route::post('access-codes/redeem', [AccessCodeController::class, 'redeem'])->middleware('throttle:5,1');
        Route::post('device/management-pin/verify', [ManagementPinController::class, 'verify'])->middleware('throttle:10,1');
    });
});
