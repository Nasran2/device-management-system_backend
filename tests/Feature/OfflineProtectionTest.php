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
        $this->assertSame($device->device_uuid, $envelope['payload']['device_uuid']);
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

    public function test_heartbeat_returns_signed_offline_unlock_authorization_only_for_offline_timeout(): void
    {
        $device = $this->device($this->user());
        $plain = 'offline-recovery-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);

        $response = $this->withToken($plain)->postJson('/api/v1/devices/heartbeat', [
            'network_status' => 'online',
            'local_lock_reason' => 'OFFLINE_TIMEOUT',
        ])->assertOk()->assertJsonPath('data.offline_policy.payload.offline_unlock_authorized', true)
            ->assertJsonPath('data.offline_policy.payload.offline_unlock_reason', 'OFFLINE_TIMEOUT');

        $envelope = $response->json('data.offline_policy');
        $service = app(OfflineProtectionService::class);
        $this->assertSame(1, openssl_verify($service->canonical($envelope['payload']), base64_decode($envelope['signature']), file_get_contents(config('device.offline_policy_public_key')), OPENSSL_ALGO_SHA256));

        $this->withToken($plain)->postJson('/api/v1/devices/heartbeat', [
            'network_status' => 'online',
            'local_lock_reason' => 'INTEGRITY_FAILURE',
        ])->assertOk()->assertJsonPath('data.offline_policy.payload.offline_unlock_authorized', false)
            ->assertJsonPath('data.offline_policy.payload.offline_unlock_reason', 'NONE');
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
