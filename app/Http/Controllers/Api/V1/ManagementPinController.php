<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ManagementPinController extends Controller
{
    private const PURPOSES = ['ENABLE_DEVICE_ADMIN', 'VIEW_MANAGEMENT_SETUP', 'DISABLE_DEVICE_ADMIN', 'REQUEST_RELEASE', 'LOCAL_RELEASE', 'OPEN_MANAGEMENT_SETTINGS'];

    public function verify(Request $request, AuditService $audit)
    {
        $data = $request->validate(['pin' => ['required', 'digits:4'], 'purpose' => ['required', 'in:'.implode(',', self::PURPOSES)]]);
        $device = $request->attributes->get('device');
        abort_if($device->isReleased() || ! in_array($device->status, ['active_unlocked', 'locked'], true), 403, 'Device is not active.');

        if ($device->management_pin_locked_until?->isFuture()) {
            return response()->json(['success' => false, 'message' => 'Too many incorrect attempts. Try again later.', 'locked_until' => $device->management_pin_locked_until->toIso8601String()], 429);
        }
        if (! $device->management_pin_hash || ! Hash::check($data['pin'], $device->management_pin_hash)) {
            $attempts = $device->management_pin_failed_attempts + 1;
            $lockedUntil = $attempts >= 5 ? now()->addMinutes(15) : null;
            $device->update(['management_pin_failed_attempts' => $attempts, 'management_pin_locked_until' => $lockedUntil]);
            $audit->record($lockedUntil ? 'MANAGEMENT_PIN_TEMPORARILY_LOCKED' : 'MANAGEMENT_PIN_VERIFICATION_FAILED', $lockedUntil ? 'Management PIN verification temporarily locked' : 'Management PIN verification failed', null, $device, [], ['purpose' => $data['purpose']]);

            return response()->json($lockedUntil
                ? ['success' => false, 'message' => 'Too many incorrect attempts. Try again later.', 'locked_until' => $lockedUntil->toIso8601String()]
                : ['success' => false, 'message' => 'Incorrect management PIN', 'remaining_attempts' => 5 - $attempts], $lockedUntil ? 429 : 422);
        }

        $device->update(['management_pin_failed_attempts' => 0, 'management_pin_locked_until' => null]);
        $plainToken = Str::random(64);
        Cache::put('management_pin_auth:'.hash('sha256', $plainToken), ['device_id' => $device->id, 'purpose' => $data['purpose'], 'used' => false], now()->addSeconds(60));
        $audit->record('MANAGEMENT_PIN_VERIFICATION_SUCCEEDED', 'Management PIN verification succeeded', null, $device, [], ['purpose' => $data['purpose']]);

        return response()->json(['success' => true, 'message' => 'PIN verified', 'authorization_token' => $plainToken, 'expires_in' => 60]);
    }
}
