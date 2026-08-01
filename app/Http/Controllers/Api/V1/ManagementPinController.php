<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\ActivationService;
use App\Services\CommandService;
use App\Services\OfflineProtectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ManagementPinController extends Controller
{
    private const PURPOSES = ['ENABLE_DEVICE_ADMIN', 'VIEW_MANAGEMENT_SETUP', 'DISABLE_DEVICE_ADMIN', 'REQUEST_RELEASE', 'LOCAL_RELEASE', 'OPEN_MANAGEMENT_SETTINGS', 'PERMANENT_RELEASE'];

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

    public function release(Request $request, CommandService $commands, OfflineProtectionService $offline, AuditService $audit, ActivationService $activations)
    {
        $data = $request->validate(['authorization_token' => ['required', 'string', 'size:64']]);
        $device = $request->attributes->get('device');
        abort_if($device->isReleased(), 409, 'Device is already permanently released.');

        $cacheKey = 'management_pin_auth:'.hash('sha256', $data['authorization_token']);
        $authorization = Cache::pull($cacheKey);
        if (! is_array($authorization)
            || (int) ($authorization['device_id'] ?? 0) !== (int) $device->id
            || ($authorization['purpose'] ?? null) !== 'PERMANENT_RELEASE'
            || ($authorization['used'] ?? true)) {
            $audit->record('MANAGEMENT_PIN_RELEASE_AUTHORIZATION_REJECTED', 'Management PIN release authorization rejected', null, $device);
            return response()->json(['success' => false, 'message' => 'Release authorization is invalid, expired, or already used.'], 403);
        }

        $reason = 'Permanent release authorized with the device Management PIN';
        $offline->permanentRelease($device, null, $reason);
        $activations->revokeAll($device, null, 'management_pin_permanent_release');
        $command = $commands->create($device, 'PERMANENT_RELEASE', [
            'reason' => $reason,
            'authorization_method' => 'management_pin',
        ], null);
        $audit->record('MANAGEMENT_PIN_RELEASE_AUTHORIZED', 'Permanent release authorized with verified Management PIN', null, $device, [], ['command_uuid' => $command->uuid]);

        return response()->json([
            'success' => true,
            'message' => 'Permanent release authorized. The signed release command is ready.',
            'data' => ['command_id' => $command->id, 'command_uuid' => $command->uuid],
        ]);
    }
}
