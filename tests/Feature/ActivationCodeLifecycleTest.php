<?php

namespace Tests\Feature;

use App\Jobs\SendActivationCodeSms;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceActivation;
use App\Models\DeviceSetupSession;
use App\Models\Shop;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ActivationService;
use App\Services\SetupInstructionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivationCodeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function tenant(string $role = 'shop_owner'): array
    {
        $this->sequence++;
        $suffix = $this->sequence.'-'.Str::lower(Str::random(4));
        $shop = Shop::create([
            'name' => 'Activation Shop '.$suffix,
            'owner_name' => 'Owner',
            'email' => "shop-{$suffix}@example.test",
            'mobile' => '0770000000',
            'address' => 'Colombo',
            'reference_code' => 'ACT-'.strtoupper($suffix),
        ]);
        $user = User::create([
            'shop_id' => $shop->id,
            'name' => 'Owner '.$suffix,
            'email' => "owner-{$suffix}@example.test",
            'password' => 'Password@123',
            'role' => $role,
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'shop_id' => $shop->id,
            'admin_id' => $user->id,
            'created_by' => $user->id,
            'name' => 'Customer',
            'phone' => '0771111111',
        ]);
        $device = Device::create([
            'shop_id' => $shop->id,
            'admin_id' => $user->id,
            'customer_id' => $customer->id,
            'brand' => 'Samsung',
            'model' => 'A15',
            'imei' => (string) random_int(100000000000000, 999999999999999),
            'selling_price' => 45000,
            'currency' => 'LKR',
            'status' => 'pending_activation',
        ]);

        return [$shop, $user, $device];
    }

    private function activationPayload(Device $device, string $code): array
    {
        return [
            'device_reference' => $device->uuid,
            'activation_code' => $code,
            'device_uuid' => (string) Str::uuid(),
            'android_version' => '16',
            'app_version' => '1.0.3',
        ];
    }

    public function test_code_uses_installed_app_format_is_encrypted_visible_after_refresh_and_valid_for_24_hours(): void
    {
        [, $owner, $device] = $this->tenant();
        $result = app(ActivationService::class)->ensure($device, $owner, null, 'test_generation');

        $this->assertMatchesRegularExpression('/^DG-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{7}$/', $result['plain']);
        $this->assertTrue($result['activation']->expires_at->between(now()->addMinutes(1439), now()->addMinutes(1441)));
        $raw = DB::table('device_activations')->where('id', $result['activation']->id)->first();
        $this->assertNotSame($result['plain'], $raw->code_hash);
        $this->assertStringNotContainsString($result['plain'], (string) $raw->encrypted_code);

        $this->actingAs($owner)->get(route('devices.show', $device))->assertOk()
            ->assertSee('Device Activation Code')->assertSee($result['plain'])->assertSee('Copy Code')
            ->assertSee('including the DG- prefix.');
        $this->actingAs($owner)->get(route('devices.show', $device))->assertOk()->assertSee($result['plain']);
        $this->assertSame(1, $device->activations()->count());
    }

    public function test_activation_endpoint_accepts_installed_app_codes_and_normalizes_outer_spaces_and_case(): void
    {
        [, , $firstDevice] = $this->tenant();
        DeviceActivation::create([
            'device_id' => $firstDevice->id,
            'code_hash' => Hash::make('DG-7K4P2M9'),
            'encrypted_code' => 'DG-7K4P2M9',
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/devices/activate', $this->activationPayload($firstDevice, 'DG-7K4P2M9'))
            ->assertCreated()
            ->assertJsonStructure(['message', 'data' => ['device_uuid', 'device_token', 'command_verification_key', 'status']]);

        [, , $secondDevice] = $this->tenant();
        DeviceActivation::create([
            'device_id' => $secondDevice->id,
            'code_hash' => Hash::make('DG-ABC1234'),
            'encrypted_code' => 'DG-ABC1234',
            'expires_at' => now()->addDay(),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.91'])
            ->postJson('/api/v1/devices/activate', $this->activationPayload($secondDevice, '  dg-abc1234  '))
            ->assertCreated();
    }

    public function test_activation_endpoint_rejects_numeric_and_malformed_codes_with_compatible_error_structure(): void
    {
        [, , $device] = $this->tenant();
        $invalidCodes = [
            '546250',
            'DG-ABC123',
            'DG-ABC12345',
            'ABC1234',
            'DG-ABC 234',
        ];

        foreach ($invalidCodes as $index => $invalidCode) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 10)])
                ->postJson('/api/v1/devices/activate', $this->activationPayload($device, $invalidCode))
                ->assertUnprocessable()
                ->assertJsonPath('error_code', 'invalid_activation_code')
                ->assertJsonPath('message', 'The activation code is invalid, expired, or already used.')
                ->assertJsonStructure(['message', 'error_code', 'errors' => ['activation_code']]);
            RateLimiter::clear('activation-ip:127.0.0.1');
            RateLimiter::clear('activation-device:'.hash('sha256', $device->uuid));
        }

    }

    public function test_activation_endpoint_rejects_a_missing_activation_code(): void
    {
        [, , $device] = $this->tenant();
        $payload = $this->activationPayload($device, 'DG-ABC1234');
        unset($payload['activation_code']);

        $this->postJson('/api/v1/devices/activate', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'invalid_activation_code')
            ->assertJsonPath('message', 'The activation code is invalid, expired, or already used.');
    }

    public function test_opening_device_page_replaces_active_numeric_code_and_shows_compatibility_notice(): void
    {
        [, $owner, $device] = $this->tenant();
        $legacyPlain = '546250';
        $legacy = DeviceActivation::create([
            'device_id' => $device->id,
            'code_hash' => Hash::make($legacyPlain),
            'encrypted_code' => $legacyPlain,
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($owner)->get(route('devices.show', $device))->assertOk()
            ->assertSee('A new activation code was generated for compatibility with the installed Android app.');

        $replacement = $device->activations()->latest('id')->firstOrFail();
        $this->assertNotNull($legacy->fresh()->revoked_at);
        $this->assertMatchesRegularExpression('/^DG-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{7}$/', $replacement->encrypted_code);
        $response->assertSee($replacement->encrypted_code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACTIVATION_LEGACY_CODE_REPLACED', 'device_id' => $device->id]);
        $this->assertStringNotContainsString($legacyPlain, AuditLog::get()->toJson());
    }

    public function test_maintenance_command_replaces_only_active_unused_numeric_codes_without_outputting_codes(): void
    {
        [, $owner, $device] = $this->tenant();
        $activePlain = '654321';
        $active = DeviceActivation::create([
            'device_id' => $device->id,
            'code_hash' => Hash::make($activePlain),
            'encrypted_code' => $activePlain,
            'expires_at' => now()->addDay(),
        ]);
        $usedPlain = '123456';
        $used = DeviceActivation::create([
            'device_id' => $device->id,
            'code_hash' => Hash::make($usedPlain),
            'encrypted_code' => $usedPlain,
            'expires_at' => now()->addDay(),
            'used_at' => now(),
        ]);

        $this->artisan('deviceguard:replace-legacy-activation-codes')
            ->expectsOutput('Legacy activation codes replaced: 1')
            ->doesntExpectOutputToContain($activePlain)
            ->doesntExpectOutputToContain($usedPlain)
            ->assertSuccessful();

        $replacement = $device->activations()->latest('id')->firstOrFail();
        $this->assertNotNull($active->fresh()->revoked_at);
        $this->assertNull($used->fresh()->revoked_at);
        $this->assertMatchesRegularExpression('/^DG-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{7}$/', $replacement->encrypted_code);
    }

    public function test_super_admin_configured_expiry_is_applied_to_new_codes(): void
    {
        [, $owner, $device] = $this->tenant();
        SystemSetting::updateOrCreate(['key' => 'device_activation_code_expiry_minutes'], ['value' => '360', 'type' => 'integer']);

        $issued = app(ActivationService::class)->ensure($device, $owner);

        $this->assertTrue($issued['activation']->expires_at->between(now()->addMinutes(359), now()->addMinutes(361)));
    }

    public function test_setup_activation_stage_generates_once_and_reuses_code_on_refresh(): void
    {
        [, $owner, $device] = $this->tenant();
        $steps = app(SetupInstructionCatalog::class)->for('windows', 'samsung');
        $activationIndex = $steps->search(fn ($step) => $step->step_key === 'activation');
        $session = DeviceSetupSession::create([
            'uuid' => (string) Str::uuid(), 'shop_id' => $device->shop_id, 'device_id' => $device->id,
            'started_by' => $owner->id, 'computer_os' => 'windows', 'brand_group' => 'samsung',
            'mode' => 'manual_guided', 'current_step' => $activationIndex + 1, 'status' => 'in_progress',
        ]);

        $first = $this->actingAs($owner)->get(route('setup.show', $session))->assertOk()
            ->assertSee('Device Activation Code')->assertSee('Copy Code')->assertSee('Open Device Details');
        $activation = $device->activations()->firstOrFail();
        $plain = $activation->encrypted_code;
        $first->assertSee($plain);

        $this->actingAs($owner)->get(route('setup.show', $session))->assertOk()->assertSee($plain);
        $this->assertSame(1, $device->activations()->count());
        $this->assertSame($session->id, $activation->fresh()->setup_session_id);
    }

    public function test_regeneration_requires_password_revokes_old_code_and_keeps_history(): void
    {
        [, $owner, $device] = $this->tenant();
        $service = app(ActivationService::class);
        $old = $service->ensure($device, $owner);

        $this->actingAs($owner)->post(route('devices.activation-code.generate', $device), ['confirmed' => 1])
            ->assertSessionHasErrors('password');
        $this->actingAs($owner)->post(route('devices.activation-code.generate', $device), [
            'password' => 'Password@123', 'confirmed' => 1, 'reason' => 'Customer requested replacement',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'A new activation code was generated successfully.');

        $new = $device->activations()->latest('id')->firstOrFail();
        $this->assertNotSame($old['plain'], $new->encrypted_code);
        $this->assertNotNull($old['activation']->fresh()->revoked_at);
        $this->assertSame(2, $device->activations()->count());
        $this->assertSame(1, $device->activations()->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count());
    }

    public function test_regeneration_queues_activation_sms_without_waiting_for_the_provider(): void
    {
        [, $owner, $device] = $this->tenant();
        SystemSetting::updateOrCreate(['key' => 'send_activation_code_by_sms'], ['value' => 'true', 'type' => 'boolean']);
        Queue::fake();
        Http::preventStrayRequests();

        $this->actingAs($owner)->post(route('devices.activation-code.generate', $device), [
            'password' => 'Password@123',
            'confirmed' => 1,
            'reason' => 'Customer requested replacement',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $activation = $device->activations()->latest('id')->firstOrFail();
        Queue::assertPushed(SendActivationCodeSms::class, fn ($job) => $job->activationId === $activation->id
            && $job->requestedById === $owner->id
            && $job->connection === 'database');
        Http::assertNothingSent();
    }

    public function test_queued_activation_sms_sends_the_current_code_without_serializing_it_in_the_job(): void
    {
        [, $owner, $device] = $this->tenant();
        SystemSetting::updateOrCreate(['key' => 'send_activation_code_by_sms'], ['value' => 'true', 'type' => 'boolean']);
        SystemSetting::updateOrCreate(['key' => 'sms_enabled'], ['value' => 'true', 'type' => 'boolean']);
        SystemSetting::updateOrCreate(['key' => 'sms_api_key_encrypted'], ['value' => Crypt::encryptString('test-api-key'), 'type' => 'encrypted']);
        Http::fake(['*' => Http::response(['message_id' => 'queued-sms-123'], 200)]);
        $issued = app(ActivationService::class)->ensure($device, $owner);
        $job = new SendActivationCodeSms($issued['activation']->id, $owner->id);

        $this->assertStringNotContainsString($issued['plain'], serialize($job));
        $job->handle(app(ActivationService::class));

        $this->assertDatabaseHas('sms_logs', [
            'device_id' => $device->id,
            'template' => 'device_activation_code',
            'sent_status' => 'sent',
        ]);
        Http::assertSent(fn ($request) => str_contains($request['text'], $issued['plain']));
    }

    public function test_expired_used_and_revoked_codes_are_rejected(): void
    {
        [, $owner, $expiredDevice] = $this->tenant();
        $service = app(ActivationService::class);
        $expired = $service->ensure($expiredDevice, $owner);
        $expired['activation']->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/devices/activate', $this->activationPayload($expiredDevice, $expired['plain']))
            ->assertStatus(410)->assertJsonPath('error_code', 'activation_code_expired');

        [, $owner2, $usedDevice] = $this->tenant();
        $used = $service->ensure($usedDevice, $owner2);
        $payload = $this->activationPayload($usedDevice, $used['plain']);
        $this->postJson('/api/v1/devices/activate', $payload)->assertCreated();
        $this->postJson('/api/v1/devices/activate', $payload)->assertConflict()->assertJsonPath('error_code', 'activation_code_used');

        [, $owner3, $revokedDevice] = $this->tenant();
        $revoked = $service->ensure($revokedDevice, $owner3);
        $service->revoke($revokedDevice, $owner3, 'test');
        $this->postJson('/api/v1/devices/activate', $this->activationPayload($revokedDevice, $revoked['plain']))
            ->assertStatus(410)->assertJsonPath('error_code', 'activation_code_revoked');
    }

    public function test_successful_activation_marks_code_used_and_updates_dashboard_state(): void
    {
        [, $owner, $device] = $this->tenant();
        $issued = app(ActivationService::class)->ensure($device, $owner);

        $this->postJson('/api/v1/devices/activate', $this->activationPayload($device, $issued['plain']))->assertCreated();

        $this->assertNotNull($issued['activation']->fresh()->used_at);
        $this->assertSame('active_unlocked', $device->fresh()->status);
        $this->actingAs($owner)->get(route('devices.show', $device))->assertOk()->assertSee('Activation completed')->assertDontSee($issued['plain']);
    }

    public function test_shop_isolation_and_super_admin_activation_permissions_are_enforced(): void
    {
        [, $owner, $device] = $this->tenant();
        [, $other] = $this->tenant();
        $issued = app(ActivationService::class)->ensure($device, $owner);
        $super = User::create(['name' => 'Super', 'email' => 'super-activation@example.test', 'password' => 'Password@123', 'role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($other)->get(route('devices.show', $device))->assertForbidden();
        $this->actingAs($other)->post(route('devices.activation-code.generate', $device), ['password' => 'Password@123', 'confirmed' => 1])->assertForbidden();
        $this->actingAs($super)->get(route('devices.show', $device))->assertOk()->assertSee($issued['plain'])->assertSee('Activation-code history');
    }

    public function test_shop_staff_require_explicit_activation_code_permissions(): void
    {
        [$shop, $owner, $device] = $this->tenant();
        $issued = app(ActivationService::class)->ensure($device, $owner);
        $staff = User::create([
            'shop_id' => $shop->id, 'name' => 'Setup Staff', 'email' => 'activation-staff@example.test',
            'password' => 'Password@123', 'role' => 'shop_staff', 'is_active' => true, 'shop_permissions' => [],
        ]);

        $this->actingAs($staff)->get(route('devices.show', $device))->assertOk()->assertDontSee($issued['plain']);
        $this->actingAs($staff)->post(route('devices.activation-code.generate', $device), [
            'password' => 'Password@123', 'confirmed' => 1,
        ])->assertForbidden();

        $staff->update(['shop_permissions' => [
            'device_activation_code.view', 'device_activation_code.generate', 'device_activation_code.revoke',
        ]]);
        $this->actingAs($staff->fresh())->get(route('devices.show', $device))->assertOk()->assertSee($issued['plain']);
    }

    public function test_existing_device_can_generate_manually_and_sms_defaults_off(): void
    {
        [, $owner, $device] = $this->tenant();
        $this->assertFalse((bool) SystemSetting::value('send_activation_code_by_sms', false));
        $this->actingAs($owner)->get(route('devices.show', $device))->assertOk()
            ->assertSee('No activation code has been generated.')->assertSee('Generate Activation Code');

        $this->actingAs($owner)->post(route('devices.activation-code.generate', $device), [
            'password' => 'Password@123', 'confirmed' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $device->activations()->count());
        $this->assertDatabaseCount('sms_logs', 0);
    }

    public function test_failed_attempts_are_rate_limited_by_device_ip_and_code(): void
    {
        [, $owner, $device] = $this->tenant();
        app(ActivationService::class)->ensure($device, $owner);
        $payload = $this->activationPayload($device, 'DG-WRNG234');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])->postJson('/api/v1/devices/activate', $payload)->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])->postJson('/api/v1/devices/activate', $payload)->assertTooManyRequests();
        $activation = $device->activations()->latest()->firstOrFail();
        $this->assertSame(5, $activation->failed_attempts);
        $this->assertTrue($activation->locked_until->isFuture());
    }

    public function test_plain_code_never_appears_in_audit_logs_and_release_revokes_it(): void
    {
        [, $owner, $device] = $this->tenant();
        $issued = app(ActivationService::class)->ensure($device, $owner);
        $this->actingAs($owner)->get(route('devices.show', $device))->assertOk();
        $this->assertStringNotContainsString($issued['plain'], AuditLog::get()->toJson());

        $this->actingAs($owner)->post(route('devices.release', $device), [
            'password' => 'Password@123', 'reason' => 'Finance completed', 'confirmed' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($issued['activation']->fresh()->revoked_at);
    }

    public function test_optional_activation_sms_uses_customer_phone_and_redacts_persisted_content(): void
    {
        [, $owner, $device] = $this->tenant();
        SystemSetting::updateOrCreate(['key' => 'send_activation_code_by_sms'], ['value' => 'true', 'type' => 'boolean']);
        SystemSetting::updateOrCreate(['key' => 'sms_enabled'], ['value' => 'true', 'type' => 'boolean']);
        SystemSetting::updateOrCreate(['key' => 'sms_api_key_encrypted'], ['value' => Crypt::encryptString('test-api-key'), 'type' => 'encrypted']);
        Http::fake(['*' => Http::response(['message_id' => 'sms-123', 'echo' => 'provider may echo message'], 200)]);
        $service = app(ActivationService::class);
        $issued = $service->ensure($device, $owner);

        $service->sendSmsIfEnabled($device->load(['customer', 'shop']), $issued['plain'], $owner);

        $log = DB::table('sms_logs')->first();
        $this->assertNotNull($log);
        $this->assertSame('device_activation_code', $log->template);
        $this->assertStringNotContainsString($issued['plain'], (string) $log->message);
        $this->assertStringNotContainsString($issued['plain'], (string) $log->provider_response);
        $this->assertSame('sent', $log->sent_status);
        Http::assertSent(fn ($request) => $request['to'] === '94771111111' && str_contains($request['text'], $issued['plain']));
    }
}
