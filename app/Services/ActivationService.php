<?php

namespace App\Services;

use App\Exceptions\DeviceActivationException;
use App\Models\Device;
use App\Models\DeviceActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActivationService
{
    public function __construct(private AuditService $audit) {}

    public function issue(Device $device): string
    {
        $plain = strtoupper(Str::random(4).'-'.Str::random(4));
        $device->activations()->whereNull('used_at')->delete();
        $device->activations()->create(['code_hash' => Hash::make($plain), 'expires_at' => now()->addHours(24)]);
        $this->audit->record('activation_code_generated', 'Single-use activation code generated', auth()->user(), $device);

        return $plain;
    }

    public function activate(string $code, array $data): array
    {
        $activation = DeviceActivation::with('device')->latest()->limit(500)->get()
            ->first(fn ($item) => Hash::check(strtoupper($code), $item->code_hash));
        if (! $activation) {
            throw new DeviceActivationException('invalid_activation_code', 'The activation code is invalid.');
        }
        if ($activation->used_at) {
            throw new DeviceActivationException('activation_code_used', 'This activation code has already been used.', 409);
        }
        if ($activation->expires_at->isPast()) {
            throw new DeviceActivationException('activation_code_expired', 'This activation code has expired.', 410);
        }
        if ($activation->attempts >= $activation->max_attempts) {
            throw new DeviceActivationException('activation_attempts_exceeded', 'The maximum activation attempts have been exceeded.', 429);
        }

        return DB::transaction(function () use ($activation, $data) {
            $device = $activation->device;
            if ($device->isReleased()) {
                throw ValidationException::withMessages(['device' => 'This device has been permanently released.']);
            }
            $plainToken = Str::random(64);
            $device->update([
                'device_uuid' => $data['device_uuid'],
                'android_id_hash' => isset($data['android_id']) ? hash('sha256', $data['android_id']) : null,
                'fcm_token' => $data['fcm_token'] ?? null,
                'android_version' => $data['android_version'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'status' => 'active_unlocked',
                'management_status' => 'setup_required',
                'last_seen_at' => now(),
                'last_sync_at' => now(),
            ]);
            $activation->update(['used_at' => now()]);
            $device->tokens()->create(['token_hash' => hash('sha256', $plainToken)]);
            $this->audit->record('device_activated', 'Android device activated', null, $device);

            return ['device' => $device->fresh(), 'token' => $plainToken, 'verification_key' => hash_hmac('sha256', $device->uuid, config('device.command_signing_key'))];
        });
    }
}
