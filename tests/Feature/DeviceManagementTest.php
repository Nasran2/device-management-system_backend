<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceActivation;
use App\Models\DeviceToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CommandService;
use App\Services\QrProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::create(['name' => ucfirst($role), 'email' => uniqid().'@example.com', 'password' => 'Password@123', 'role' => $role, 'is_active' => true, 'can_view_locations' => false]);
    }

    private function device(User $admin, array $overrides = []): Device
    {
        $customer = Customer::create(['admin_id' => $admin->id, 'name' => 'Customer', 'phone' => '+94770000000']);

        return Device::create(array_merge(['admin_id' => $admin->id, 'customer_id' => $customer->id, 'brand' => 'Samsung', 'model' => 'A15', 'imei' => (string) random_int(100000000000000, 999999999999999), 'selling_price' => 65000, 'currency' => 'LKR', 'management_mode' => 'standard'], $overrides));
    }

    public function test_admin_can_only_view_their_own_devices(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $device = $this->device($owner);

        $this->actingAs($other)->get(route('devices.show', $device))->assertForbidden();
        $this->actingAs($owner)->get(route('devices.show', $device))->assertOk();
    }

    public function test_super_admin_can_view_every_device(): void
    {
        $device = $this->device($this->user());
        $this->actingAs($this->user('super_admin'))->get(route('devices.show', $device))->assertOk();
    }

    public function test_lock_command_is_signed_and_does_not_prematurely_mark_device_locked(): void
    {
        $admin = $this->user();
        $device = $this->device($admin, ['status' => 'active_unlocked', 'lock_status' => 'unlocked']);
        $command = app(CommandService::class)->create($device, 'LOCK_DEVICE', ['reason' => 'Payment overdue'], $admin);

        $this->assertTrue(app(CommandService::class)->verify($command));
        $this->assertSame('lock_requested', $device->fresh()->status);
        $this->assertSame('unlocked', $device->fresh()->lock_status);
    }

    public function test_released_device_rejects_commands(): void
    {
        $admin = $this->user();
        $device = $this->device($admin, ['status' => 'permanently_released', 'released_at' => now()]);
        $this->expectException(ValidationException::class);
        app(CommandService::class)->create($device, 'LOCK_DEVICE', [], $admin);
    }

    public function test_activation_is_single_use_and_returns_device_token(): void
    {
        $device = $this->device($this->user());
        DeviceActivation::create(['device_id' => $device->id, 'code_hash' => Hash::make('ABCD-EFGH'), 'expires_at' => now()->addHour()]);
        $payload = ['activation_code' => 'ABCD-EFGH', 'device_uuid' => '5c371414-52aa-44a1-9ad7-ca4cbf867c94', 'android_version' => '16', 'app_version' => '1.0.0'];

        $this->postJson('/api/v1/devices/activate', $payload)->assertCreated()->assertJsonPath('data.status', 'active_unlocked')->assertJsonStructure(['data' => ['device_token', 'command_verification_key']]);
        $this->postJson('/api/v1/devices/activate', $payload)->assertConflict()->assertJsonPath('error_code', 'activation_code_used');
    }

    public function test_health_endpoint_is_public_and_safe(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'DeviceGuard API is running',
            'environment' => 'testing',
        ]);
    }

    public function test_invalid_and_expired_activation_codes_return_specific_errors(): void
    {
        $device = $this->device($this->user());
        DeviceActivation::create(['device_id' => $device->id, 'code_hash' => Hash::make('OLD1-CODE'), 'expires_at' => now()->subMinute()]);
        $payload = ['device_uuid' => '5c371414-52aa-44a1-9ad7-ca4cbf867c94', 'android_version' => '16', 'app_version' => '1.0.0'];

        $this->postJson('/api/v1/devices/activate', $payload + ['activation_code' => 'WRONG-CODE'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'invalid_activation_code');
        $this->postJson('/api/v1/devices/activate', $payload + ['activation_code' => 'OLD1-CODE'])
            ->assertStatus(410)->assertJsonPath('error_code', 'activation_code_expired');
    }

    public function test_global_and_device_location_switches_are_enforced(): void
    {
        $device = $this->device($this->user(), ['location_tracking_enabled' => true]);
        $plain = 'device-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);
        SystemSetting::create(['key' => 'location_tracking_enabled', 'value' => 'false', 'type' => 'boolean']);
        $location = ['latitude' => 6.9271, 'longitude' => 79.8612, 'recorded_at' => now()->toISOString()];

        $this->withToken($plain)->postJson('/api/v1/locations', $location)->assertForbidden();
        SystemSetting::where('key', 'location_tracking_enabled')->update(['value' => 'true']);
        $device->update(['location_tracking_enabled' => false]);
        $this->withToken($plain)->postJson('/api/v1/locations', $location)->assertForbidden();
        $device->update(['location_tracking_enabled' => true]);
        $this->withToken($plain)->postJson('/api/v1/locations', $location)->assertCreated();
    }

    public function test_lock_requires_correct_password_and_device_ownership(): void
    {
        $admin = $this->user();
        $other = $this->user();
        $device = $this->device($admin, ['can_full_lock' => true, 'status' => 'active_unlocked']);
        $payload = ['type' => 'LOCK_DEVICE', 'reason' => 'Payment overdue'];

        $this->actingAs($admin)->post(route('devices.command', $device), $payload)->assertSessionHasErrors('password');
        $this->actingAs($admin)->post(route('devices.command', $device), $payload + ['password' => 'wrong-password'])->assertSessionHasErrors('password');
        $this->actingAs($other)->post(route('devices.command', $device), $payload + ['password' => 'Password@123'])->assertForbidden();
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_super_admin_can_lock_any_managed_device_after_password_confirmation(): void
    {
        $device = $this->device($this->user(), ['can_full_lock' => true, 'status' => 'active_unlocked']);
        $super = $this->user('super_admin');

        $this->actingAs($super)->post(route('devices.command', $device), ['type' => 'LOCK_DEVICE', 'reason' => 'Authorized test', 'password' => 'Password@123'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'type' => 'LOCK_DEVICE']);
        $this->assertSame('lock_requested', $device->fresh()->status);
        $this->assertSame('unlocked', $device->fresh()->lock_status);
    }

    public function test_phone_confirmation_and_failure_update_confirmed_device_state(): void
    {
        $admin = $this->user();
        $device = $this->device($admin, ['status' => 'active_unlocked', 'lock_status' => 'unlocked']);
        $plain = 'phone-command-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plain)]);

        $successful = app(CommandService::class)->create($device, 'LOCK_DEVICE', [], $admin);
        $this->withToken($plain)->postJson("/api/v1/commands/{$successful->id}/result", ['success' => true, 'message' => 'Managed lock applied'])->assertOk();
        $this->assertSame('locked', $device->fresh()->status);

        $device->fresh()->update(['status' => 'active_unlocked', 'lock_status' => 'unlocked']);
        $failed = app(CommandService::class)->create($device->fresh(), 'LOCK_DEVICE', [], $admin);
        $this->withToken($plain)->postJson("/api/v1/commands/{$failed->id}/result", ['success' => false, 'message' => 'Device Owner setup is required', 'failure_code' => 'NOT_DEVICE_OWNER'])->assertOk();
        $this->assertDatabaseHas('device_commands', ['id' => $failed->id, 'status' => 'failed', 'failure_code' => 'NOT_DEVICE_OWNER']);
        $this->assertSame('active_unlocked', $device->fresh()->status);
        $this->assertSame('unlocked', $device->fresh()->lock_status);
    }

    public function test_permanent_release_requires_password(): void
    {
        $admin = $this->user();
        $device = $this->device($admin, ['status' => 'active_unlocked']);
        $payload = ['reason' => 'Finance complete', 'confirmed' => '1'];

        $this->actingAs($admin)->post(route('devices.release', $device), $payload)->assertSessionHasErrors('password');
        $this->actingAs($admin)->post(route('devices.release', $device), $payload + ['password' => 'Password@123'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'type' => 'PERMANENT_RELEASE']);
    }

    public function test_temporary_unlock_code_is_hashed_device_specific_and_single_use(): void
    {
        $admin = $this->user();
        $device = $this->device($admin, ['status' => 'locked', 'lock_status' => 'locked']);
        $plainToken = 'unlock-device-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $plainToken)]);

        $response = $this->actingAs($admin)->post(route('devices.unlock-code', $device), ['password' => 'Password@123']);
        $response->assertSessionHas('unlock_code');
        $plainCode = $response->getSession()->get('unlock_code');
        $stored = $device->accessCodes()->firstOrFail();
        $this->assertNotSame($plainCode, $stored->code_hash);
        $this->assertTrue(Hash::check($plainCode, $stored->code_hash));

        $this->withToken($plainToken)->postJson('/api/v1/access-codes/redeem', ['code' => $plainCode])->assertOk();
        $this->withToken($plainToken)->postJson('/api/v1/access-codes/redeem', ['code' => $plainCode])->assertUnprocessable();
        $this->assertSame('unlocked', $device->fresh()->lock_status);
    }

    public function test_device_registration_requires_a_confirmed_four_digit_management_pin(): void
    {
        $admin = $this->user();
        $payload = ['customer_name' => 'Customer', 'customer_phone' => '0770000000', 'brand' => 'Samsung', 'model' => 'A15', 'imei' => '123456789012345', 'selling_price' => 65000, 'currency' => 'LKR', 'management_mode' => 'standard'];
        $this->actingAs($admin)->post(route('devices.store'), $payload)->assertSessionHasErrors('management_pin');
        $this->actingAs($admin)->post(route('devices.store'), $payload + ['management_pin' => '987', 'management_pin_confirmation' => '987'])->assertSessionHasErrors('management_pin');
        $this->actingAs($admin)->post(route('devices.store'), $payload + ['management_pin' => '9876', 'management_pin_confirmation' => '6789'])->assertSessionHasErrors('management_pin');
        $this->actingAs($admin)->post(route('devices.store'), $payload + ['management_pin' => '9876', 'management_pin_confirmation' => '9876'])->assertSessionHasNoErrors();
        $device = Device::firstOrFail();
        $this->assertTrue(Hash::check('9876', $device->management_pin_hash));
        $this->assertSame('9876', Crypt::decryptString($device->management_pin_encrypted));
    }

    public function test_pin_reveal_requires_password_and_device_ownership(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $device = $this->device($owner, ['management_pin_hash' => Hash::make('9876'), 'management_pin_encrypted' => Crypt::encryptString('9876')]);
        $this->actingAs($owner)->post(route('devices.management-pin.reveal', $device), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->actingAs($other)->post(route('devices.management-pin.reveal', $device), ['password' => 'Password@123'])->assertForbidden();
        $this->actingAs($owner)->post(route('devices.management-pin.reveal', $device), ['password' => 'Password@123'])->assertSessionHas('revealed_management_pin', '9876');
    }

    public function test_device_pin_verification_succeeds_fails_and_locks_after_five_attempts(): void
    {
        $device = $this->device($this->user(), ['status' => 'active_unlocked', 'management_pin_hash' => Hash::make('9876'), 'management_pin_encrypted' => Crypt::encryptString('9876')]);
        $token = 'pin-test-token';
        DeviceToken::create(['device_id' => $device->id, 'token_hash' => hash('sha256', $token)]);
        $endpoint = '/api/v1/device/management-pin/verify';
        $this->withToken($token)->postJson($endpoint, ['pin' => '9876', 'purpose' => 'VIEW_MANAGEMENT_SETUP'])->assertOk()->assertJsonPath('message', 'PIN verified')->assertJsonStructure(['authorization_token', 'expires_in']);
        foreach (range(1, 4) as $attempt) {
            $this->withToken($token)->postJson($endpoint, ['pin' => '5555', 'purpose' => 'ENABLE_DEVICE_ADMIN'])->assertUnprocessable()->assertJsonPath('remaining_attempts', 5 - $attempt);
        }
        $this->withToken($token)->postJson($endpoint, ['pin' => '5555', 'purpose' => 'ENABLE_DEVICE_ADMIN'])->assertTooManyRequests()->assertJsonStructure(['locked_until']);
        $this->assertNotNull($device->fresh()->management_pin_locked_until);
    }

    public function test_management_pin_secrets_are_hidden_from_model_json_and_audit_logs(): void
    {
        $device = $this->device($this->user(), ['management_pin_hash' => Hash::make('9876'), 'management_pin_encrypted' => Crypt::encryptString('9876')]);
        $json = $device->toJson();
        $this->assertStringNotContainsString('management_pin_hash', $json);
        $this->assertStringNotContainsString('management_pin_encrypted', $json);
        $this->assertDatabaseMissing('audit_logs', ['description' => '9876']);
    }

    public function test_qr_payload_excludes_pin_and_regeneration_revokes_the_previous_token(): void
    {
        $admin=$this->user();$device=$this->device($admin,['management_pin_hash'=>Hash::make('9876'),'management_pin_encrypted'=>Crypt::encryptString('9876')]);
        foreach(['qr_provisioning_enabled'=>['true','boolean'],'provisioning_api_url'=>['https://manage.example.com/api/v1','string'],'provisioning_apk_url'=>['https://manage.example.com/deviceguard.apk','string'],'provisioning_apk_checksum'=>['checksum-value','string'],'provisioning_qr_expiry_minutes'=>['30','integer']] as $key=>[$value,$type])SystemSetting::create(compact('key','value','type'));
        $service=app(QrProvisioningService::class);[$first,$payload]=$service->generate($device,$admin->id);$json=json_encode($payload);
        $this->assertStringNotContainsString('9876',$json);$this->assertSame(QrProvisioningService::COMPONENT,$payload['android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME']);$this->assertNotEmpty($service->png($payload));
        [$second]=$service->generate($device,$admin->id);$this->assertSame('revoked',$first->fresh()->status);$this->assertSame('active',$second->fresh()->status);$this->assertDatabaseMissing('device_provisioning_tokens',['token_hash'=>$payload['android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE']['provisioning_token']]);
    }

    public function test_provisioning_token_is_single_use_and_returns_device_credentials(): void
    {
        $admin=$this->user();$device=$this->device($admin,['management_pin_hash'=>Hash::make('9876'),'management_pin_encrypted'=>Crypt::encryptString('9876')]);$plain=bin2hex(random_bytes(32));$token=$device->provisioningTokens()->create(['token_hash'=>hash('sha256',$plain),'status'=>'active','expires_at'=>now()->addMinutes(30),'created_by'=>$admin->id]);
        $payload=['device_reference'=>$device->uuid,'provisioning_token'=>$plain,'management_pin'=>'9876','device_uuid'=>'5c371414-52aa-44a1-9ad7-ca4cbf867c94','android_version'=>'16','app_version'=>'1.0.0','manufacturer'=>'Google','model'=>'Pixel','is_device_owner'=>true,'is_admin_active'=>true];
        $this->postJson('/api/v1/devices/provision',$payload)->assertOk()->assertJsonStructure(['data'=>['device_token','command_verification_key']]);$this->assertSame('completed',$token->fresh()->status);$this->assertTrue($device->fresh()->is_device_owner);
        $this->postJson('/api/v1/devices/provision',$payload)->assertStatus(410)->assertJsonPath('error_code','TOKEN_INVALID');
    }
}
