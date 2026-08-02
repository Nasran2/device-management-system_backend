<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceToken;
use App\Models\OfflineProtectionAudit;
use App\Models\OfflineProtectionSetting;
use App\Models\User;
use App\Services\OfflineProtectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class OfflineProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::create(['name' => $role, 'email' => uniqid()."@example.com", 'password' => 'Password@123', 'role' => $role, 'is_active' => true]);
    }

    private function device(User $admin): Device
    {
        $customer = Customer::create(['admin_id' => $admin->id, 'name' => 'Customer', 'phone' => '+94770000000']);
        return Device::create(['admin_id' => $admin->id, 'customer_id' => $customer->id, 'brand' => 'Samsung', 'model' => 'A15', 'imei' => (string) random_int(100000000000000, 999999999999999), 'selling_price' => 65000, 'currency' => 'LKR', 'status' => 'active_unlocked', 'device_uuid' => fake()->uuid()]);
    }

    public function test_default_period_is_five_days_and_432000_seconds(): void
    {
        $setting = OfflineProtectionSetting::current();
        $this->assertSame(5, $setting->default_period_value);
        $this->assertSame('days', $setting->default_period_unit);
        $this->assertSame(432000, $setting->default_period_seconds);
    }

    public function test_period_conversion_and_boundaries(): void
    {
        $service = app(OfflineProtectionService::class);
        $this->assertSame(86400, $service->seconds(24, 'hours'));
        $this->assertSame(172800, $service->seconds(2, 'days'));
        $this->assertSame(432000, $service->seconds(5, 'days'));
        $this->assertSame(21600, $service->seconds(6, 'hours'));
        $this->assertSame(7776000, $service->seconds(90, 'days'));
    }

    public function test_super_admin_changes_global_period_with_audit(): void
    {
        $super = $this->user('super_admin');
        $this->actingAs($super)->put(route('settings.offline-protection.update'), [
            'enabled' => 1, 'period_value' => 7, 'period_unit' => 'days', 'warning_notification_enabled' => 1,
            'first_warning_hours' => 24, 'final_warning_hours' => 6, 'allow_admin_override' => 1,
            'require_password_confirmation' => 1, 'password' => 'Password@123', 'reason' => 'Business policy update',
        ])->assertRedirect();
        $this->assertSame(604800, OfflineProtectionSetting::current()->fresh()->default_period_seconds);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OFFLINE_GLOBAL_POLICY_CHANGED']);
    }

    public function test_admin_can_override_only_their_device_when_allowed(): void
    {
        $owner = $this->user(); $other = $this->user(); $device = $this->device($owner);
        $payload = ['enabled' => 1, 'period_value' => 36, 'period_unit' => 'hours', 'password' => 'Password@123', 'reason' => 'Customer agreement', 'confirmed' => 1];
        $this->actingAs($other)->put(route('devices.offline-protection.update', $device), $payload)->assertForbidden();
        $this->actingAs($owner)->put(route('devices.offline-protection.update', $device), $payload)->assertRedirect();
        $this->assertDatabaseHas('device_offline_policies', ['device_id' => $device->id, 'max_offline_seconds' => 129600, 'uses_global_default' => false]);
        $this->assertDatabaseHas('offline_protection_audits', ['device_id' => $device->id, 'event_type' => 'PERIOD_CHANGED']);
    }

    public function test_admin_override_is_blocked_by_global_policy(): void
    {
        $admin = $this->user(); $device = $this->device($admin);
        OfflineProtectionSetting::current()->update(['allow_admin_override' => false]);
        $this->actingAs($admin)->put(route('devices.offline-protection.update', $device), [
            'enabled' => 1, 'period_value' => 2, 'period_unit' => 'days', 'password' => 'Password@123', 'reason' => 'Attempt', 'confirmed' => 1,
        ])->assertForbidden();
    }

    public function test_minimum_and_maximum_period_validation(): void
    {
        $service = app(OfflineProtectionService::class);
        foreach ([[5, 'hours'], [91, 'days']] as [$value, $unit]) {
            try { $service->seconds($value, $unit); $this->fail('Expected invalid period.'); }
            catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function test_policy_is_asymmetrically_signed_for_enrolled_device(): void
    {
        $device = $this->device($this->user());
        $service = app(OfflineProtectionService::class);
        $envelope = $service->issue($device);
        $public = file_get_contents(config('device.offline_policy_public_key'));
        $this->assertSame(1, openssl_verify($service->canonical($envelope['payload']), base64_decode($envelope['signature']), $public, OPENSSL_ALGO_SHA256));
        $this->assertNotSame($device->uuid, $device->device_uuid);
        $this->assertSame($device->uuid, $envelope['payload']['device_uuid']);
    }

    public function test_canonical_payload_is_consistent_regardless_of_top_level_key_order(): void
    {
        $service = app(OfflineProtectionService::class);
        $first = ['policy_version' => 3, 'device_uuid' => 'device-123', 'enabled' => true];
        $second = ['enabled' => true, 'device_uuid' => 'device-123', 'policy_version' => 3];

        $this->assertSame($service->canonical($first), $service->canonical($second));
        $this->assertSame('{"device_uuid":"device-123","enabled":true,"policy_version":3}', $service->canonical($first));
    }

    public function test_missing_private_key_path_fails_safely(): void
    {
        $device = $this->device($this->user());
        $missingPath = storage_path('app/private/missing-offline-policy-key.pem');
        config(['device.offline_policy_private_key' => $missingPath]);
        Log::spy();

        try {
            app(OfflineProtectionService::class)->issue($device);
            $this->fail('Expected the missing signing key to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Offline policy signing key file is unavailable.', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')->with(
            'Offline policy private key file is unavailable.',
            \Mockery::on(fn (array $context) => $context['configured_path'] === $missingPath
                && $context['file_exists'] === false
                && $context['file_readable'] === false
                && $context['php_sapi'] === PHP_SAPI),
        )->once();
    }

    public function test_unreadable_private_key_is_rejected_before_openssl_loading(): void
    {
        $device = $this->device($this->user());
        $path = tempnam(sys_get_temp_dir(), 'deviceguard-unreadable-key-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'not-sensitive-test-content');
        chmod($path, 0000);
        clearstatcache(true, $path);

        if (is_readable($path)) {
            chmod($path, 0600);
            unlink($path);
            $this->markTestSkipped('The current test user can read mode-000 files.');
        }

        config(['device.offline_policy_private_key' => $path]);
        try {
            app(OfflineProtectionService::class)->issue($device);
            $this->fail('Expected the unreadable signing key to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Offline policy signing key file is unavailable.', $exception->getMessage());
        } finally {
            chmod($path, 0600);
            unlink($path);
        }
    }

    public function test_invalid_private_key_pem_is_rejected_without_logging_key_content(): void
    {
        $device = $this->device($this->user());
        $path = tempnam(sys_get_temp_dir(), 'deviceguard-invalid-key-');
        $invalidPem = "-----BEGIN PRIVATE KEY-----\ninvalid-private-key-test-content\n-----END PRIVATE KEY-----\n";
        file_put_contents($path, $invalidPem);
        config(['device.offline_policy_private_key' => $path]);
        Log::spy();

        try {
            app(OfflineProtectionService::class)->issue($device);
            $this->fail('Expected the invalid signing key to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to load offline policy signing key.', $exception->getMessage());
        } finally {
            unlink($path);
        }

        Log::shouldHaveReceived('error')->with(
            'Unable to load offline policy private key.',
            \Mockery::on(fn (array $context) => $context['configured_path'] === $path
                && $context['pem_length'] === strlen($invalidPem)
                && ! str_contains(json_encode($context), $invalidPem)),
        )->once();
    }

    public function test_valid_but_different_signing_key_is_rejected_as_incompatible_with_installed_apk(): void
    {
        $device = $this->device($this->user());
        $differentKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($differentKey);
        $this->assertTrue(openssl_pkey_export($differentKey, $differentPem));
        $path = tempnam(sys_get_temp_dir(), 'deviceguard-different-key-');
        file_put_contents($path, $differentPem);
        config(['device.offline_policy_private_key' => $path]);
        Log::spy();

        try {
            app(OfflineProtectionService::class)->issue($device);
            $this->fail('Expected a signing key that does not match the installed APK to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Offline policy signing key is incompatible with the installed Android app.', $exception->getMessage());
        } finally {
            unlink($path);
        }

        Log::shouldHaveReceived('critical')->with(
            'Offline policy signing key is incompatible with the installed Android app.',
            \Mockery::on(fn (array $context) => $context['expected_public_key_sha256'] === config('device.offline_policy_expected_public_key_sha256')
                && is_string($context['actual_public_key_sha256'])
                && $context['actual_public_key_sha256'] !== $context['expected_public_key_sha256']
                && $context['private_key_bits'] === 2048
                && ! str_contains(json_encode($context), $differentPem)),
        )->once();
    }

    public function test_configured_signing_key_matches_installed_apk_public_key_fingerprint(): void
    {
        $privateKey = openssl_pkey_get_private(file_get_contents(config('device.offline_policy_private_key')));
        $details = openssl_pkey_get_details($privateKey);
        $encoded = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']);

        $this->assertSame(
            config('device.offline_policy_expected_public_key_sha256'),
            hash('sha256', base64_decode($encoded, true)),
        );
    }

    public function test_opt_in_web_process_diagnostics_contain_only_safe_key_metadata(): void
    {
        $device = $this->device($this->user());
        $path = config('device.offline_policy_private_key');
        $pem = file_get_contents($path);
        config(['device.offline_policy_diagnostics' => true]);
        Log::spy();

        app(OfflineProtectionService::class)->issue($device);

        Log::shouldHaveReceived('info')->with(
            'Offline policy signing key diagnostics.',
            \Mockery::on(fn (array $context) => $context['configured_path'] === $path
                && $context['file_exists'] === true
                && $context['file_readable'] === true
                && $context['file_size'] === filesize($path)
                && $context['php_sapi'] === PHP_SAPI
                && ! str_contains(json_encode($context), $pem)),
        )->once();
    }

    public function test_old_or_mismatched_acknowledgement_is_rejected(): void
    {
        $device = $this->device($this->user());
        $service = app(OfflineProtectionService::class);
        $envelope = $service->issue($device);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->acknowledge($device, ['policy_version' => 0, 'nonce' => $envelope['payload']['nonce'], 'signature_verified' => true, 'stored_successfully' => true, 'local_deadline_at' => now()->addDays(5), 'local_locked' => false]);
    }

    public function test_authenticated_acknowledgement_updates_verification_and_deadline(): void
    {
        $device = $this->device($this->user()); $plain = 'device-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        $envelope = app(OfflineProtectionService::class)->issue($device);
        $this->withToken($plain)->postJson('/api/v1/devices/offline-policy/acknowledge', [
            'policy_version' => $envelope['payload']['policy_version'], 'nonce' => $envelope['payload']['nonce'],
            'signature_verified' => true, 'stored_successfully' => true,
            'local_deadline_at' => $envelope['payload']['offline_deadline_at'],
            'last_trusted_server_time' => $envelope['payload']['server_utc_time'], 'local_locked' => false, 'network_status' => 'online',
        ])->assertOk();
        $policy = $device->offlinePolicy()->first();
        $this->assertNotNull($policy->last_verified_at);
        $this->assertNotNull($policy->offline_deadline_at);
        $this->assertNotNull($policy->policy_acknowledged_at);
    }

    public function test_older_apk_can_acknowledge_without_optional_telemetry_fields(): void
    {
        $device = $this->device($this->user());
        $plain = 'older-apk-device-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        $envelope = app(OfflineProtectionService::class)->issue($device);
        Log::spy();

        $response = $this->withToken($plain)->postJson('/api/v1/devices/offline-policy/acknowledge', [
            'policy_version' => $envelope['payload']['policy_version'],
            'nonce' => $envelope['payload']['nonce'],
            'signature_verified' => true,
            'stored_successfully' => true,
        ])->assertOk()
            ->assertJsonPath('message', 'Signed policy verified and acknowledged.')
            ->assertJsonStructure(['message', 'data' => ['last_verified_at', 'offline_deadline_at']]);

        $policy = $device->offlinePolicy()->firstOrFail();
        $this->assertNotNull($policy->policy_acknowledged_at);
        $this->assertNotNull($policy->last_verified_at);
        $this->assertNotNull($policy->offline_deadline_at);
        $this->assertNull($policy->phone_reported_deadline_at);
        $this->assertFalse($policy->phone_local_locked);
        $this->assertSame($policy->last_verified_at->copy()->addSeconds($policy->max_offline_seconds)->timestamp, $policy->offline_deadline_at->timestamp);
        $this->assertSame($policy->last_verified_at->toIso8601String(), $response->json('data.last_verified_at'));

        Log::shouldHaveReceived('info')->with(
            'Offline policy acknowledgement request received.',
            \Mockery::on(fn (array $context) => $context['device_id'] === $device->id
                && $context['policy_version'] === $envelope['payload']['policy_version']
                && $context['nonce_fingerprint'] === hash('sha256', $envelope['payload']['nonce'])
                && ! in_array('last_trusted_server_time', $context['provided_fields'], true)
                && ! str_contains(json_encode($context), $envelope['payload']['nonce'])),
        )->once();
        Log::shouldHaveReceived('info')->with(
            'Offline policy acknowledgement completed successfully.',
            \Mockery::on(fn (array $context) => $context['device_id'] === $device->id
                && $context['policy_acknowledged_at'] !== null
                && $context['last_verified_at'] !== null
                && $context['offline_deadline_at'] !== null),
        )->once();
    }

    public function test_nullable_legacy_acknowledgement_telemetry_is_accepted(): void
    {
        $device = $this->device($this->user());
        $plain = 'nullable-legacy-fields-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        $envelope = app(OfflineProtectionService::class)->issue($device);

        $this->withToken($plain)->postJson('/api/v1/devices/offline-policy/acknowledge', [
            'policy_version' => $envelope['payload']['policy_version'],
            'nonce' => $envelope['payload']['nonce'],
            'signature_verified' => true,
            'stored_successfully' => true,
            'local_deadline_at' => null,
            'last_trusted_server_time' => null,
            'local_locked' => null,
            'network_status' => null,
        ])->assertOk();

        $this->assertNotNull($device->offlinePolicy()->firstOrFail()->policy_acknowledged_at);
    }

    public function test_acknowledgement_validation_failure_is_logged_and_preserves_validation_response(): void
    {
        $device = $this->device($this->user());
        $plain = 'invalid-acknowledgement-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        $envelope = app(OfflineProtectionService::class)->issue($device);
        Log::spy();

        $this->withToken($plain)->postJson('/api/v1/devices/offline-policy/acknowledge', [
            'policy_version' => $envelope['payload']['policy_version'],
            'nonce' => $envelope['payload']['nonce'],
            'signature_verified' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('stored_successfully');

        $this->assertNull($device->offlinePolicy()->firstOrFail()->policy_acknowledged_at);
        Log::shouldHaveReceived('warning')->with(
            'Offline policy acknowledgement validation failed.',
            \Mockery::on(fn (array $context) => $context['device_id'] === $device->id
                && in_array('stored_successfully', $context['failed_fields'], true)),
        )->once();
    }

    public function test_acknowledgement_nonce_mismatch_exception_is_logged_and_rejected(): void
    {
        $device = $this->device($this->user());
        $plain = 'mismatched-acknowledgement-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        $envelope = app(OfflineProtectionService::class)->issue($device);
        Log::spy();

        $this->withToken($plain)->postJson('/api/v1/devices/offline-policy/acknowledge', [
            'policy_version' => $envelope['payload']['policy_version'],
            'nonce' => str_repeat('x', 48),
            'signature_verified' => true,
            'stored_successfully' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('policy');

        $this->assertNull($device->offlinePolicy()->firstOrFail()->policy_acknowledged_at);
        Log::shouldHaveReceived('warning')->with(
            'Offline policy acknowledgement was rejected.',
            \Mockery::on(fn (array $context) => $context['device_id'] === $device->id
                && in_array('policy', $context['failed_fields'], true)),
        )->once();
    }

    public function test_heartbeat_returns_signed_offline_unlock_authorization_only_for_offline_timeout(): void
    {
        $device = $this->device($this->user());
        $plain = 'offline-recovery-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);

        $response = $this->withToken($plain)->postJson('/api/v1/devices/heartbeat', [
            'network_status' => 'online',
            'local_lock_reason' => 'OFFLINE_TIMEOUT',
        ])->assertOk()->assertJsonPath('data.offline_policy.payload.offline_unlock_authorized', true)
            ->assertJsonPath('data.offline_policy.payload.offline_unlock_reason', 'OFFLINE_TIMEOUT')
            ->assertJsonPath('data.offline_policy.algorithm', 'SHA256withRSA')
            ->assertJsonStructure(['data' => ['server_utc_time', 'status', 'lock_status', 'commands', 'offline_policy' => ['payload', 'signature', 'algorithm']]]);

        $envelope = $response->json('data.offline_policy');
        $service = app(OfflineProtectionService::class);
        $this->assertSame(1, openssl_verify($service->canonical($envelope['payload']), base64_decode($envelope['signature']), file_get_contents(config('device.offline_policy_public_key')), OPENSSL_ALGO_SHA256));

        $this->withToken($plain)->postJson('/api/v1/devices/heartbeat', [
            'network_status' => 'online',
            'local_lock_reason' => 'INTEGRITY_FAILURE',
        ])->assertOk()->assertJsonPath('data.offline_policy.payload.offline_unlock_authorized', false)
            ->assertJsonPath('data.offline_policy.payload.offline_unlock_reason', 'NONE');
    }

    public function test_existing_apk_sync_contract_heartbeats_then_acknowledges_policy(): void
    {
        $device = $this->device($this->user());
        $plain = 'existing-apk-sync-contract-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        Log::spy();

        $heartbeat = $this->withToken($plain)->postJson('/api/v1/devices/heartbeat', [
            'battery_percentage' => null,
            'gps_status' => 'enabled',
            'network_status' => 'online',
            'fcm_token' => null,
            'app_version' => '1.0.3',
            'local_lock_reason' => 'NONE',
        ])->assertOk();

        $payload = $heartbeat->json('data.offline_policy.payload');
        $this->assertSame($device->uuid, $payload['device_uuid']);
        $this->assertNotSame($device->device_uuid, $payload['device_uuid']);
        $this->withToken($plain)->postJson('/api/v1/devices/offline-policy/acknowledge', [
            'policy_version' => $payload['policy_version'],
            'nonce' => $payload['nonce'],
            'signature_verified' => true,
            'stored_successfully' => true,
            'local_deadline_at' => $payload['offline_deadline_at'],
            'last_trusted_server_time' => $payload['server_utc_time'],
            'local_locked' => false,
            'network_status' => 'online',
        ])->assertOk();

        $policy = $device->offlinePolicy()->firstOrFail();
        $this->assertNotNull($device->fresh()->last_sync_at);
        $this->assertNotNull($policy->policy_acknowledged_at);
        $this->assertNotNull($policy->last_verified_at);
        $this->assertNotNull($policy->offline_deadline_at);
        Log::shouldHaveReceived('info')->with('Device heartbeat request received.', \Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Device heartbeat completed successfully.', \Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Offline policy acknowledgement request received.', \Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('Offline policy acknowledgement completed successfully.', \Mockery::type('array'))->once();
    }

    public function test_heartbeat_authentication_and_validation_failures_are_logged_safely(): void
    {
        Log::spy();
        $this->withToken('unknown-device-token')->postJson('/api/v1/devices/heartbeat', [
            'gps_status' => 'enabled',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated device.']);
        Log::shouldHaveReceived('warning')->with(
            'Device sync authentication failed.',
            \Mockery::on(fn (array $context) => $context['request_path'] === 'api/v1/devices/heartbeat'
                && $context['bearer_token_present'] === true
                && $context['token_record_found'] === false
                && ! str_contains(json_encode($context), 'unknown-device-token')),
        )->once();

        $device = $this->device($this->user());
        $plain = 'heartbeat-validation-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        $this->withToken($plain)->postJson('/api/v1/devices/heartbeat', [
            'gps_status' => 'not-a-valid-status',
        ])->assertUnprocessable()->assertJsonValidationErrors('gps_status');
        Log::shouldHaveReceived('warning')->with(
            'Device heartbeat validation failed.',
            \Mockery::on(fn (array $context) => $context['device_id'] === $device->id
                && in_array('gps_status', $context['failed_fields'], true)),
        )->once();
    }

    public function test_heartbeat_returns_controlled_error_and_does_not_mark_sync_success_when_signing_fails(): void
    {
        $device = $this->device($this->user());
        $plain = 'signing-failure-device-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        $path = tempnam(sys_get_temp_dir(), 'deviceguard-heartbeat-invalid-key-');
        $invalidPem = "-----BEGIN PRIVATE KEY-----\nheartbeat-invalid-key-content\n-----END PRIVATE KEY-----\n";
        file_put_contents($path, $invalidPem);
        config(['device.offline_policy_private_key' => $path]);
        Log::spy();

        try {
            $response = $this->withToken($plain)->postJson('/api/v1/devices/heartbeat', [
                'network_status' => 'online',
                'local_lock_reason' => 'NONE',
            ])->assertStatus(503)->assertExactJson([
                'message' => 'Device synchronization is temporarily unavailable.',
                'error_code' => 'OFFLINE_POLICY_SIGNING_FAILED',
            ]);
        } finally {
            unlink($path);
        }

        $this->assertStringNotContainsString($invalidPem, $response->getContent());
        $this->assertNull($device->fresh()->last_sync_at);
        $this->assertNull($device->fresh()->last_seen_at);
        $this->assertDatabaseMissing('offline_protection_audits', [
            'device_id' => $device->id,
            'event_type' => 'POLICY_SENT',
        ]);
        $this->assertNull($device->offlinePolicy()->firstOrFail()->last_issued_nonce);

        Log::shouldHaveReceived('error')->with(
            'Device heartbeat failed while issuing the signed offline policy.',
            \Mockery::on(fn (array $context) => $context['device_id'] === $device->id
                && $context['device_uuid'] === $device->uuid
                && $context['request_path'] === 'api/v1/devices/heartbeat'
                && ! array_key_exists('exception', $context)),
        )->once();
    }

    public function test_manual_or_payment_server_lock_never_receives_offline_unlock_authorization(): void
    {
        $device = $this->device($this->user());
        $device->update(['status' => 'locked', 'lock_status' => 'locked']);
        $plain = 'server-locked-recovery-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);

        $this->withToken($plain)->postJson('/api/v1/devices/heartbeat', [
            'network_status' => 'online',
            'local_lock_reason' => 'OFFLINE_TIMEOUT',
        ])->assertOk()->assertJsonPath('data.offline_policy.payload.offline_unlock_authorized', false)
            ->assertJsonPath('data.status', 'locked')
            ->assertJsonPath('data.lock_status', 'locked');
    }

    public function test_permanent_release_disables_protection_and_is_audited(): void
    {
        $user = $this->user('super_admin'); $device = $this->device($user);
        $service = app(OfflineProtectionService::class);
        $service->permanentRelease($device, $user, 'Paid in full');
        $policy = $device->offlinePolicy()->first();
        $this->assertFalse($policy->enabled);
        $this->assertTrue($policy->permanent_release);
        $this->assertNull($policy->offline_deadline_at);
        $this->assertDatabaseHas('offline_protection_audits', ['device_id' => $device->id, 'event_type' => 'PERMANENT_RELEASED']);
    }
}
