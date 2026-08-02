<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceOfflinePolicy;
use App\Models\OfflineProtectionAudit;
use App\Models\OfflineProtectionSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class OfflineProtectionService
{
    public const MIN_SECONDS = 21600;
    public const MAX_SECONDS = 7776000;
    public const DEFAULT_SECONDS = 432000;

    public function seconds(int $value, string $unit): int
    {
        $seconds = $value * ($unit === 'days' ? 86400 : 3600);
        if ($value < 1 || ! in_array($unit, ['hours', 'days'], true) || $seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new \InvalidArgumentException('Offline period must be a whole value from 6 hours through 90 days.');
        }
        return $seconds;
    }

    public function policyFor(Device $device): DeviceOfflinePolicy
    {
        $global = OfflineProtectionSetting::current();
        $policy = $device->offlinePolicy()->firstOrCreate([], [
            'enabled' => $global->enabled,
            'uses_global_default' => true,
            'period_value' => $global->default_period_value,
            'period_unit' => $global->default_period_unit,
            'max_offline_seconds' => $global->default_period_seconds,
            'permanent_release' => $device->isReleased(),
            'policy_version' => 1,
        ]);
        if ($policy->uses_global_default) {
            $policy->fill([
                'enabled' => $global->enabled && ! $policy->permanent_release,
                'period_value' => $global->default_period_value,
                'period_unit' => $global->default_period_unit,
                'max_offline_seconds' => $global->default_period_seconds,
            ]);
            if ($policy->isDirty()) $policy->save();
        }
        return $policy;
    }

    public function issue(Device $device, bool $offlineRecoveryRequested = false): array
    {
        $policy = $this->policyFor($device);
        $global = OfflineProtectionSetting::current();
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $deadline = $now->addSeconds($policy->max_offline_seconds);
        $nonce = Str::random(48);
        // Recovery is deliberately narrow: the authenticated phone must report an
        // OFFLINE_TIMEOUT lock and the server must still consider it fully unlocked.
        // Manual, payment, administrator, pending-lock and released states never qualify.
        $offlineUnlockAuthorized = $offlineRecoveryRequested
            && ! $device->isReleased()
            && $device->status === 'active_unlocked'
            && $device->lock_status === 'unlocked';
        $payload = [
            // Activation and Device Owner provisioning both return devices.uuid to
            // the APK as data.device_uuid. The verifier compares the signed policy
            // against that stored enrollment identity, not the phone-reported
            // devices.device_uuid value.
            'device_uuid' => (string) $device->uuid,
            'expires_at' => $deadline->addDay()->toIso8601String(),
            'final_warning_seconds' => (int) $global->final_warning_seconds,
            'first_warning_seconds' => (int) $global->first_warning_seconds,
            'issued_at' => $now->toIso8601String(),
            'max_offline_seconds' => (int) $policy->max_offline_seconds,
            'nonce' => $nonce,
            'offline_deadline_at' => $deadline->toIso8601String(),
            'offline_protection_enabled' => (bool) $policy->enabled,
            'offline_unlock_authorized' => $offlineUnlockAuthorized,
            'offline_unlock_reason' => $offlineUnlockAuthorized ? 'OFFLINE_TIMEOUT' : 'NONE',
            'permanent_release' => (bool) $policy->permanent_release,
            'policy_version' => (int) $policy->policy_version,
            'server_utc_time' => $now->toIso8601String(),
            'warning_notification_enabled' => (bool) $global->warning_notification_enabled,
        ];
        $signature = $this->sign($payload);

        $policy->update([
            'policy_issued_at' => $now,
            'policy_expires_at' => $deadline->addDay(),
            'last_issued_nonce' => hash('sha256', $nonce),
        ]);
        $this->audit($device, 'POLICY_SENT', $policy, null, ['deadline' => $payload['offline_deadline_at']]);
        if ($offlineRecoveryRequested) {
            $this->audit(
                $device,
                $offlineUnlockAuthorized ? 'OFFLINE_UNLOCK_AUTHORIZED' : 'OFFLINE_UNLOCK_DENIED',
                $policy,
                null,
                ['server_status' => $device->status, 'server_lock_status' => $device->lock_status],
                $offlineUnlockAuthorized ? 'Fresh signed server authorization issued.' : 'Server lock state does not permit offline recovery.'
            );
        }

        return ['payload' => $payload, 'signature' => $signature, 'algorithm' => 'SHA256withRSA'];
    }

    public function acknowledge(Device $device, array $data): DeviceOfflinePolicy
    {
        return DB::transaction(function () use ($device, $data) {
            $this->policyFor($device);
            $policy = DeviceOfflinePolicy::where('device_id', $device->id)->lockForUpdate()->firstOrFail();
            $valid = $data['signature_verified'] && $data['stored_successfully']
                && (int) $data['policy_version'] === (int) $policy->policy_version
                && hash_equals((string) $policy->last_issued_nonce, hash('sha256', $data['nonce']));
            if (! $valid) {
                $this->audit($device, 'VERIFICATION_FAILED', $policy, null, ['safe_error' => 'Policy acknowledgement did not match the last signed policy.']);
                throw \Illuminate\Validation\ValidationException::withMessages(['policy' => 'Policy acknowledgement rejected.']);
            }
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $deadline = $policy->enabled && ! $policy->permanent_release ? $now->addSeconds($policy->max_offline_seconds) : null;
            $phoneDeadline = $data['local_deadline_at'] ?? null;
            $phoneLocalLocked = array_key_exists('local_locked', $data)
                ? (bool) $data['local_locked']
                : (bool) $policy->phone_local_locked;
            $policy->update([
                'last_verified_at' => $now,
                'offline_deadline_at' => $deadline,
                'policy_acknowledged_at' => $now,
                'phone_reported_deadline_at' => $phoneDeadline,
                'last_network_status' => $data['network_status'] ?? $policy->last_network_status,
                'phone_local_locked' => $phoneLocalLocked,
            ]);
            $this->audit($device, 'POLICY_ACKNOWLEDGED', $policy, null, ['phone_deadline' => $phoneDeadline]);
            $this->audit($device, 'VERIFICATION_SUCCESS', $policy);
            $this->audit($device, 'DEADLINE_RESET', $policy, null, ['deadline' => $deadline?->toIso8601String()]);
            return $policy->fresh();
        });
    }

    public function changeDevice(Device $device, array $data, User $user): DeviceOfflinePolicy
    {
        $policy = $this->policyFor($device);
        $before = $policy->only(['enabled', 'uses_global_default', 'period_value', 'period_unit', 'max_offline_seconds']);
        $usesGlobal = (bool) ($data['uses_global_default'] ?? false);
        $global = OfflineProtectionSetting::current();
        $seconds = $usesGlobal ? $global->default_period_seconds : $this->seconds((int) $data['period_value'], $data['period_unit']);
        $policy->update([
            'enabled' => $usesGlobal ? $global->enabled : (bool) $data['enabled'],
            'uses_global_default' => $usesGlobal,
            'period_value' => $usesGlobal ? $global->default_period_value : $data['period_value'],
            'period_unit' => $usesGlobal ? $global->default_period_unit : $data['period_unit'],
            'max_offline_seconds' => $seconds,
            'policy_version' => $policy->policy_version + 1,
            'policy_acknowledged_at' => null,
            'updated_by' => $user->id,
        ]);
        $this->audit($device, $usesGlobal ? 'POLICY_CREATED' : 'PERIOD_CHANGED', $policy, $user, ['before' => $before, 'after' => $policy->fresh()->only(array_keys($before)), 'ip_address' => request()->ip()], $data['reason'] ?? null);
        return $policy->fresh();
    }

    public function permanentRelease(Device $device, ?User $user, string $reason): void
    {
        $policy = $this->policyFor($device);
        $policy->update(['enabled' => false, 'permanent_release' => true, 'offline_deadline_at' => null, 'policy_version' => $policy->policy_version + 1, 'updated_by' => $user?->id]);
        $this->audit($device, 'PROTECTION_DISABLED', $policy, $user, [], $reason);
        $this->audit($device, 'PERMANENT_RELEASED', $policy, $user, [], $reason);
    }

    public function audit(Device $device, string $event, ?DeviceOfflinePolicy $policy = null, ?User $user = null, array $metadata = [], ?string $reason = null): void
    {
        OfflineProtectionAudit::create([
            'device_id' => $device->id, 'event_type' => $event,
            'policy_version' => $policy?->policy_version, 'reason' => $reason,
            'requested_by' => $user?->id, 'occurred_at' => now(), 'metadata' => $metadata ?: null,
        ]);
    }

    private function sign(array $payload): string
    {
        $path = config('device.offline_policy_private_key');
        $diagnostics = [
            'configured_path' => is_string($path) ? $path : null,
            'path_is_string' => is_string($path),
            'file_exists' => is_string($path) && is_file($path),
            'file_readable' => is_string($path) && is_readable($path),
            'file_size' => is_string($path) && is_file($path) ? @filesize($path) : null,
            'php_sapi' => PHP_SAPI,
        ];

        if (config('device.offline_policy_diagnostics', false)) {
            Log::info('Offline policy signing key diagnostics.', $diagnostics);
        }

        if (
            ! is_string($path)
            || trim($path) === ''
            || ! $diagnostics['file_exists']
            || ! $diagnostics['file_readable']
        ) {
            Log::error('Offline policy private key file is unavailable.', $diagnostics);

            throw new RuntimeException('Offline policy signing key file is unavailable.');
        }

        $pem = @file_get_contents($path);
        if ($pem === false || trim($pem) === '') {
            Log::error('Offline policy private key file is empty.', $diagnostics);

            throw new RuntimeException('Offline policy signing key file is empty.');
        }

        $this->clearOpenSslErrors();
        $privateKey = openssl_pkey_get_private($pem);
        if ($privateKey === false) {
            Log::error('Unable to load offline policy private key.', $diagnostics + [
                'pem_length' => strlen($pem),
                'openssl_errors' => $this->openSslErrors(),
            ]);

            throw new RuntimeException('Unable to load offline policy signing key.');
        }

        $expectedFingerprint = strtolower(trim((string) config('device.offline_policy_expected_public_key_sha256')));
        $keyDetails = openssl_pkey_get_details($privateKey);
        $actualFingerprint = is_array($keyDetails)
            ? $this->publicKeyFingerprint($keyDetails['key'] ?? null)
            : null;
        if (
            preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint) !== 1
            || $actualFingerprint === null
            || ! hash_equals($expectedFingerprint, $actualFingerprint)
        ) {
            Log::critical('Offline policy signing key is incompatible with the installed Android app.', $diagnostics + [
                'expected_public_key_sha256' => $expectedFingerprint !== '' ? $expectedFingerprint : null,
                'actual_public_key_sha256' => $actualFingerprint,
                'private_key_bits' => is_array($keyDetails) ? ($keyDetails['bits'] ?? null) : null,
            ]);

            throw new RuntimeException('Offline policy signing key is incompatible with the installed Android app.');
        }

        $canonicalPayload = $this->canonical($payload);
        $signed = openssl_sign($canonicalPayload, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if ($signed !== true) {
            Log::error('Offline policy signing operation failed.', $diagnostics + [
                'payload_length' => strlen($canonicalPayload),
                'openssl_errors' => $this->openSslErrors(),
            ]);

            throw new RuntimeException('Offline policy signing operation failed.');
        }

        return base64_encode($signature);
    }

    private function publicKeyFingerprint(mixed $publicKeyPem): ?string
    {
        if (! is_string($publicKeyPem) || trim($publicKeyPem) === '') {
            return null;
        }

        $encoded = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $publicKeyPem);
        $der = is_string($encoded) ? base64_decode($encoded, true) : false;

        return $der === false ? null : hash('sha256', $der);
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Remove errors left by unrelated OpenSSL operations in this PHP process.
        }
    }

    /** @return list<string> */
    private function openSslErrors(): array
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors;
    }

    public function canonical(array $payload): string
    {
        ksort($payload, SORT_STRING);
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
