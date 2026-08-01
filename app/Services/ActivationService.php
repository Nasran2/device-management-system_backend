<?php

namespace App\Services;

use App\Exceptions\DeviceActivationException;
use App\Models\Device;
use App\Models\DeviceActivation;
use App\Models\DeviceSetupSession;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ActivationService
{
    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function __construct(private AuditService $audit, private SmsService $sms) {}

    public function issue(
        Device $device,
        ?User $user = null,
        ?DeviceSetupSession $setup = null,
        string $reason = 'device_registration',
        bool $force = false,
    ): string {
        return $this->ensure($device, $user, $setup, $reason, $force)['plain'];
    }

    /** @return array{activation: DeviceActivation, plain: string, generated: bool} */
    public function ensure(
        Device $device,
        ?User $user = null,
        ?DeviceSetupSession $setup = null,
        string $reason = 'setup_activation_stage',
        bool $force = false,
    ): array {
        $user ??= auth()->user();

        return DB::transaction(function () use ($device, $user, $setup, $reason, $force) {
            Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            // Serializes code issuance across devices so two concurrent setup sessions
            // cannot claim the same six-digit code while it is active.
            DB::table('system_settings')->where('key', 'device_activation_code_expiry_minutes')->lockForUpdate()->first();
            $active = $device->activations()
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            if ($active && ! $force) {
                $plain = $this->plainCode($active);
                if ($plain !== null) {
                    if ($setup && $active->setup_session_id === null) {
                        $active->update(['setup_session_id' => $setup->id]);
                    }

                    return ['activation' => $active->fresh(), 'plain' => $plain, 'generated' => false];
                }
            }

            $device->activations()
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            $plain = $this->uniqueNumericCode();
            $activation = $device->activations()->create([
                'setup_session_id' => $setup?->id,
                'code_hash' => Hash::make($plain),
                'code_fingerprint' => $this->fingerprint($plain),
                'encrypted_code' => $plain,
                'generated_by' => $user?->id,
                'generation_reason' => $reason,
                'expires_at' => now()->addMinutes($this->expiryMinutes()),
                'max_attempts' => self::MAX_FAILED_ATTEMPTS,
            ]);
            $this->audit->record(
                'ACTIVATION_CODE_GENERATED',
                'Device activation code generated',
                $user,
                $device,
                [],
                ['activation_uuid' => $activation->uuid, 'reason' => $reason, 'expires_at' => $activation->expires_at->toIso8601String()],
            );

            return ['activation' => $activation, 'plain' => $plain, 'generated' => true];
        });
    }

    public function expiryMinutes(): int
    {
        return min(10080, max(60, (int) SystemSetting::value('device_activation_code_expiry_minutes', 1440)));
    }

    public function plainCode(DeviceActivation $activation): ?string
    {
        if (! $activation->isUsable() || blank($activation->encrypted_code)) {
            return null;
        }

        try {
            $plain = (string) $activation->encrypted_code;

            return Hash::check($plain, $activation->code_hash) ? $plain : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{activation: ?DeviceActivation, plain: ?string, status: string} */
    public function displayState(Device $device): array
    {
        $activation = $device->activations()->with(['generatedBy', 'setupSession'])->latest('id')->first();
        if (! $activation) {
            return ['activation' => null, 'plain' => null, 'status' => 'none'];
        }

        $status = $activation->status();

        return [
            'activation' => $activation,
            'plain' => in_array($status, ['active', 'expiring_soon'], true) ? $this->plainCode($activation) : null,
            'status' => $status,
        ];
    }

    public function recordViewed(DeviceActivation $activation, User $user): void
    {
        $this->audit->record('ACTIVATION_CODE_VIEWED', 'Active device activation code viewed', $user, $activation->device, [], [
            'activation_uuid' => $activation->uuid,
        ]);
    }

    public function recordExpiry(DeviceActivation $activation): void
    {
        if ($activation->status() !== 'expired' || $activation->expired_audited_at !== null) {
            return;
        }
        if (DeviceActivation::whereKey($activation->id)->whereNull('expired_audited_at')->update(['expired_audited_at' => now()])) {
            $this->audit->record('ACTIVATION_CODE_EXPIRED', 'Device activation code expired', null, $activation->device, [], [
                'activation_uuid' => $activation->uuid,
                'expired_at' => $activation->expires_at->toIso8601String(),
            ]);
        }
    }

    public function revoke(Device $device, ?User $user, string $reason = 'manual_revoke'): bool
    {
        $activation = $device->activations()
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
        if (! $activation) {
            return false;
        }
        $activation->update(['revoked_at' => now()]);
        $this->audit->record('ACTIVATION_CODE_REVOKED', 'Device activation code revoked', $user, $device, [], [
            'activation_uuid' => $activation->uuid,
            'reason' => $reason,
        ]);

        return true;
    }

    public function revokeAll(Device $device, ?User $user, string $reason): int
    {
        $ids = $device->activations()->whereNull('used_at')->whereNull('revoked_at')->pluck('id');
        $count = DeviceActivation::whereIn('id', $ids)->update(['revoked_at' => now()]);
        if ($count > 0) {
            $this->audit->record('ACTIVATION_CODES_REVOKED', 'All active activation codes revoked', $user, $device, [], [
                'reason' => $reason,
                'count' => $count,
            ]);
        }

        return $count;
    }

    public function sendSmsIfEnabled(Device $device, string $plain, ?User $user): void
    {
        if (! SystemSetting::value('send_activation_code_by_sms', false)) {
            return;
        }
        $phone = trim((string) $device->customer?->phone);
        $digits = preg_replace('/\D+/', '', $phone);
        if ($phone === '' || strlen((string) $digits) < 9 || strlen((string) $digits) > 15) {
            return;
        }
        $this->sms->send('device_activation_code', $phone, [
            'activation_code' => $plain,
            'phone_model' => $device->model,
            'shop_name' => $device->shop?->name ?? $user?->business_name ?? 'DeviceGuard',
            'valid_hours' => (string) ceil($this->expiryMinutes() / 60),
        ], $device->shop_id, $device->customer_id, $device->id, $user);
    }

    public function activate(string $code, array $data): array
    {
        $normalized = $this->normalize($code);
        $device = filled($data['device_reference'] ?? null)
            ? Device::where('uuid', $data['device_reference'])->first()
            : null;
        $query = DeviceActivation::with('device')->where('code_fingerprint', $this->fingerprint($normalized));
        if (filled($data['device_reference'] ?? null)) {
            if (! $device) {
                throw new DeviceActivationException('invalid_activation_code', 'The activation details are invalid.');
            }
            $query->where('device_id', $device->id);
        }
        $activation = $query->latest('id')->get()->first(fn ($item) => Hash::check($normalized, $item->code_hash));

        // Backward compatibility for activation records created before fingerprints were introduced.
        if (! $activation) {
            $legacy = DeviceActivation::with('device')->whereNull('code_fingerprint')
                ->when($device, fn ($q) => $q->where('device_id', $device->id))
                ->latest('id')->limit(500)->get();
            $activation = $legacy->first(fn ($item) => Hash::check($normalized, $item->code_hash));
        }
        if (! $activation) {
            $this->failedAttempt($device);
            throw new DeviceActivationException('invalid_activation_code', 'The activation details are invalid.');
        }
        if ($device && $activation->device_id !== $device->id) {
            $this->failedAttempt($device);
            throw new DeviceActivationException('invalid_activation_code', 'The activation details are invalid.');
        }
        if ($activation->used_at) {
            throw new DeviceActivationException('activation_code_used', 'This activation code has already been used.', 409);
        }
        if ($activation->revoked_at) {
            throw new DeviceActivationException('activation_code_revoked', 'This activation code is no longer active.', 410);
        }
        if ($activation->expires_at->isPast()) {
            $this->recordExpiry($activation);
            throw new DeviceActivationException('activation_code_expired', 'This activation code has expired.', 410);
        }
        if ($activation->locked_until?->isFuture()) {
            throw new DeviceActivationException('activation_attempts_exceeded', 'Activation is temporarily locked. Try again later.', 429);
        }
        if ($activation->locked_until?->isPast()) {
            $activation->update(['failed_attempts' => 0, 'attempts' => 0, 'locked_until' => null]);
        }

        return DB::transaction(function () use ($activation, $data) {
            $activation = DeviceActivation::with('device')->lockForUpdate()->findOrFail($activation->id);
            if (! $activation->isUsable()) {
                throw new DeviceActivationException('invalid_activation_code', 'The activation details are invalid.');
            }
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
            $activation->update(['used_at' => now(), 'failed_attempts' => 0, 'attempts' => 0, 'locked_until' => null]);
            $device->activations()->where('id', '!=', $activation->id)->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $device->tokens()->create(['token_hash' => hash('sha256', $plainToken)]);
            $this->audit->record('DEVICE_ACTIVATED', 'Android device activated with a single-use code', null, $device, [], [
                'activation_uuid' => $activation->uuid,
            ]);

            return ['device' => $device->fresh(), 'token' => $plainToken, 'verification_key' => hash_hmac('sha256', $device->uuid, config('device.command_signing_key'))];
        });
    }

    private function failedAttempt(?Device $device): void
    {
        if (! $device) {
            return;
        }
        $activation = $device->activations()->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $activation) {
            return;
        }
        $attempts = $activation->locked_until?->isPast()
            ? 1
            : min(255, (int) $activation->failed_attempts + 1);
        $lockedUntil = $attempts >= min(self::MAX_FAILED_ATTEMPTS, (int) $activation->max_attempts)
            ? now()->addMinutes(self::LOCK_MINUTES)
            : null;
        $activation->update([
            'failed_attempts' => $attempts,
            'attempts' => $attempts,
            'last_failed_at' => now(),
            'locked_until' => $lockedUntil,
        ]);
        $this->audit->record(
            $lockedUntil ? 'ACTIVATION_TEMPORARILY_LOCKED' : 'ACTIVATION_ATTEMPT_FAILED',
            $lockedUntil ? 'Device activation temporarily locked after repeated failures' : 'Device activation attempt failed',
            null,
            $device,
            [],
            ['activation_uuid' => $activation->uuid, 'failed_attempts' => $attempts],
        );
    }

    private function uniqueNumericCode(): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $plain = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $inUse = DeviceActivation::where('code_fingerprint', $this->fingerprint($plain))
                ->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
            if (! $inUse) {
                return $plain;
            }
        }

        throw new \RuntimeException('A unique activation code could not be generated safely.');
    }

    private function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function fingerprint(string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), (string) config('app.key'));
    }
}
