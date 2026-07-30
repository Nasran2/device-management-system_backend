<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceSetupInstruction;
use App\Models\DeviceSetupSession;
use App\Models\Shop;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SetupInstructionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceSetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): array
    {
        $shop = Shop::create(['name' => 'Setup Shop', 'owner_name' => 'Owner', 'email' => 'shop@example.test', 'mobile' => '0770000000', 'address' => 'Colombo', 'reference_code' => 'SETUP-1']);
        $user = User::create(['shop_id' => $shop->id, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Password@123', 'role' => 'shop_owner', 'is_active' => true]);
        $customer = Customer::create(['shop_id' => $shop->id, 'admin_id' => $user->id, 'created_by' => $user->id, 'name' => 'Customer', 'phone' => '0771111111', 'address' => 'Colombo']);
        $device = Device::create(['shop_id' => $shop->id, 'admin_id' => $user->id, 'customer_id' => $customer->id, 'brand' => 'Samsung', 'model' => 'A15', 'imei' => '359999999999999', 'selling_price' => 50000, 'currency' => 'LKR', 'status' => 'pending_activation']);

        return [$shop, $user, $device];
    }

    public function test_windows_samsung_has_complete_twenty_step_catalog(): void
    {
        $steps = app(SetupInstructionCatalog::class)->for('windows', 'samsung');
        $this->assertCount(20, $steps);
        $this->assertSame(range(1, 20), $steps->pluck('step_number')->all());
        foreach ($steps as $step) {
            $this->assertNotEmpty($step->title);
            $this->assertNotEmpty($step->why_required);
            $this->assertNotEmpty($step->action_location);
            $this->assertNotEmpty($step->numbered_instructions);
            $this->assertNotEmpty($step->expected_output);
            $this->assertNotEmpty($step->possible_errors);
            $this->assertNotEmpty($step->verification_items);
        }
        $this->assertStringContainsString('Get-CimInstance Win32_OperatingSystem', $steps->firstWhere('step_key', 'computer_os')->command);
        $this->assertStringContainsString('pm list users', $steps->firstWhere('step_key', 'users_check')->command);
        $this->assertStringContainsString('dpm set-device-owner com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver', $steps->firstWhere('step_key', 'device_owner')->command);
    }

    public function test_macos_excludes_windows_driver_and_brand_variants_do_not_mix(): void
    {
        $catalog = app(SetupInstructionCatalog::class);
        $mac = $catalog->for('macos', 'samsung');
        $this->assertFalse($mac->contains('step_key', 'usb_driver'));
        $this->assertStringNotContainsString('Get-PnpDevice', $mac->pluck('command')->filter()->implode("\n"));

        $xiaomi = $catalog->for('windows', 'xiaomi')->pluck('numbered_instructions')->flatten()->implode(' ');
        $this->assertStringContainsString('MIUI', $xiaomi);
        $this->assertStringNotContainsString('Never sleeping apps', $xiaomi);
    }

    public function test_adb_found_skips_installation_and_progress_is_persisted(): void
    {
        [$shop, $user, $device] = $this->tenant();
        $this->actingAs($user)->post(route('setup.start', $device), [
            'computer_os' => 'windows', 'brand_group' => 'samsung', 'mode' => 'manual_guided', 'authorized' => 1,
        ])->assertRedirect();
        $session = DeviceSetupSession::first();
        $session->update(['current_step' => 3]);
        $this->actingAs($user)->post(route('setup.step', $session), [
            'step_key' => 'adb_check',
            'direction' => 'next',
            'adb_detection_result' => 'ADB_FOUND',
            'command_result' => 'expected_output_confirmed',
            'verification_items' => [0, 1],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $session->refresh();
        $this->assertSame('found', $session->context['adb_status']);
        $this->assertDatabaseHas('device_setup_steps', [
            'device_setup_session_id' => $session->id,
            'step_key' => 'adb_check',
            'completed' => true,
            'verification_method' => 'command_output',
            'command_result' => 'expected_output_confirmed',
        ]);
        $this->actingAs($user)->get(route('setup.show', $session))->assertOk()->assertDontSee('Install official Android Platform Tools only when ADB is missing');
    }

    public function test_checklist_only_step_uses_the_automatic_expected_result(): void
    {
        [, $user, $device] = $this->tenant();
        $this->actingAs($user)->post(route('setup.start', $device), [
            'computer_os' => 'windows', 'brand_group' => 'samsung', 'mode' => 'manual_guided', 'authorized' => 1,
        ])->assertRedirect();
        $session = DeviceSetupSession::first();

        $this->actingAs($user)->post(route('setup.step', $session), [
            'step_key' => 'authorization',
            'direction' => 'next',
            'confirmations' => ['authorization', 'agreement', 'backup', 'reset_ack'],
            'verification_items' => [0, 1, 2, 3],
        ])->assertRedirect(route('setup.show', $session))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('device_setup_steps', [
            'device_setup_session_id' => $session->id,
            'step_key' => 'authorization',
            'completed' => true,
            'verification_method' => 'technician_checklist',
            'command_result' => 'expected_output_confirmed',
        ]);
    }

    public function test_server_verified_step_cannot_be_completed_from_checkbox_only(): void
    {
        [, $user, $device] = $this->tenant();
        $session = DeviceSetupSession::create(['uuid' => (string) Str::uuid(), 'shop_id' => $device->shop_id, 'device_id' => $device->id, 'started_by' => $user->id, 'computer_os' => 'windows', 'brand_group' => 'samsung', 'mode' => 'manual_guided', 'current_step' => 16, 'status' => 'in_progress']);
        $this->actingAs($user)->post(route('setup.step', $session), [
            'step_key' => 'activation',
            'direction' => 'next',
            'command_result' => 'expected_output_confirmed',
            'verification_items' => [0, 1, 2],
        ])
            ->assertSessionHasErrors('verification');
        $this->assertDatabaseMissing('device_setup_steps', ['device_setup_session_id' => $session->id, 'step_key' => 'activation', 'completed' => true]);
    }

    public function test_only_super_admin_can_edit_structured_instructions(): void
    {
        [, $owner] = $this->tenant();
        $instruction = app(SetupInstructionCatalog::class)->for('windows', 'samsung')->first();
        $this->actingAs($owner)->get(route('setup-instructions.edit', $instruction))->assertForbidden();
        $super = User::create(['name' => 'Super', 'email' => 'super@example.test', 'password' => 'Password@123', 'role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($super)->get(route('setup-instructions.edit', $instruction))->assertOk()->assertSee('Possible errors JSON');
        $this->assertSame(20, DeviceSetupInstruction::where('computer_os', 'windows')->where('phone_brand', 'samsung')->count());
    }

    public function test_wizard_uses_central_production_apk_url_and_exact_platform_commands(): void
    {
        $catalog = app(SetupInstructionCatalog::class);
        $windows = $catalog->for('windows', 'samsung')->firstWhere('step_key', 'apk_install');
        $macos = $catalog->for('macos', 'samsung')->firstWhere('step_key', 'apk_install');
        $allText = $windows->command."\n".$macos->command."\n".
            implode("\n", $windows->numbered_instructions)."\n".
            implode("\n", $macos->numbered_instructions);

        $this->assertStringContainsString('https://phone.twinsofte.com/downloads/deviceguard.apk', $allText);
        $this->assertStringNotContainsString('CONFIGURE_APK_URL_IN_SUPER_ADMIN', $allText);
        $this->assertStringNotContainsString('CONFIGURE_SHA256_IN_SUPER_ADMIN', $allText);
        $this->assertStringNotContainsString('phonelock.twinsofte.com/downloads', $allText);
        $this->assertStringContainsString("Invoke-WebRequest `\n-Uri \"https://phone.twinsofte.com/downloads/deviceguard.apk\" `\n-OutFile \".\\deviceguard.apk\"", $windows->command);
        $this->assertStringContainsString('Get-Item .\\deviceguard.apk |', $windows->command);
        $this->assertStringContainsString('.\\adb.exe install -r -t .\\deviceguard.apk', $windows->command);
        $this->assertSame('C:\platform-tools', $windows->run_from);
        $this->assertStringContainsString("cd ~/Downloads\n\ncurl -L \\\n\"https://phone.twinsofte.com/downloads/deviceguard.apk\" \\\n-o deviceguard.apk", $macos->command);
        $this->assertStringContainsString('adb install -r -t deviceguard.apk', $macos->command);
        $this->assertSame('~/Downloads', $macos->run_from);
    }

    public function test_helper_scripts_use_the_same_central_apk_url_and_no_checksum_placeholder(): void
    {
        [, $user, $device] = $this->tenant();
        $session = DeviceSetupSession::create([
            'uuid' => (string) Str::uuid(),
            'shop_id' => $device->shop_id,
            'device_id' => $device->id,
            'started_by' => $user->id,
            'computer_os' => 'windows',
            'brand_group' => 'samsung',
            'mode' => 'manual_guided',
            'status' => 'in_progress',
        ]);
        $windowsUrl = URL::temporarySignedRoute('setup.helper', now()->addMinute(), ['setup' => $session, 'os' => 'windows']);
        $windows = $this->actingAs($user)->get($windowsUrl)->assertOk()->getContent();
        $this->assertStringContainsString('-Uri "https://phone.twinsofte.com/downloads/deviceguard.apk"', $windows);
        $this->assertStringContainsString('APK checksum verification is not configured by Super Admin.', $windows);

        $session->update(['computer_os' => 'macos']);
        $macUrl = URL::temporarySignedRoute('setup.helper', now()->addMinute(), ['setup' => $session, 'os' => 'macos']);
        $mac = $this->actingAs($user)->get($macUrl)->assertOk()->getContent();
        $this->assertStringContainsString("\"https://phone.twinsofte.com/downloads/deviceguard.apk\" \\\n-o deviceguard.apk", $mac);
        $this->assertStringNotContainsString('CONFIGURE_', $windows.$mac);
    }

    public function test_new_step_has_read_only_expected_result_card_and_automatic_default(): void
    {
        [, $user, $device] = $this->tenant();
        $this->actingAs($user)->post(route('setup.start', $device), [
            'computer_os' => 'windows', 'brand_group' => 'samsung', 'mode' => 'manual_guided', 'authorized' => 1,
        ]);
        $session = DeviceSetupSession::first();
        $response = $this->actingAs($user)->get(route('setup.show', $session))->assertOk();

        $response->assertSee('Expected output confirmed')
            ->assertSee('My output is different')
            ->assertSee('Output fixed — confirm expected result')
            ->assertSee('name="command_result" value="expected_output_confirmed"', false)
            ->assertDontSee('Record the result');
        $this->assertDatabaseHas('device_setup_steps', [
            'device_setup_session_id' => $session->id,
            'step_key' => 'authorization',
            'command_result' => 'expected_output_confirmed',
            'completed' => false,
        ]);
    }

    public function test_different_output_is_saved_and_keeps_step_open_until_fixed(): void
    {
        [, $user, $device] = $this->tenant();
        $this->actingAs($user)->post(route('setup.start', $device), [
            'computer_os' => 'windows', 'brand_group' => 'samsung', 'mode' => 'manual_guided', 'authorized' => 1,
        ]);
        $session = DeviceSetupSession::first();
        $base = [
            'step_key' => 'authorization',
            'direction' => 'next',
            'confirmations' => ['authorization', 'agreement', 'backup', 'reset_ack'],
            'verification_items' => [0, 1, 2, 3],
        ];

        $this->actingAs($user)->post(route('setup.step', $session), $base + [
            'command_result' => 'different_error_output',
            'error_encountered' => 'The displayed output did not match.',
            'troubleshooting_used' => 'Rechecked the authorized preparation.',
        ])->assertSessionHasErrors('command_result');
        $this->assertSame(1, $session->fresh()->current_step);
        $this->assertDatabaseHas('device_setup_steps', [
            'device_setup_session_id' => $session->id,
            'step_key' => 'authorization',
            'command_result' => 'different_error_output',
            'completed' => false,
        ]);

        $this->actingAs($user)->post(route('setup.step', $session), $base + [
            'command_result' => 'expected_output_confirmed',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('device_setup_steps', [
            'device_setup_session_id' => $session->id,
            'step_key' => 'authorization',
            'command_result' => 'expected_output_confirmed',
            'completed' => true,
        ]);
    }

    public function test_server_side_checklist_is_required_even_when_expected_result_is_defaulted(): void
    {
        [, $user, $device] = $this->tenant();
        $session = DeviceSetupSession::create([
            'uuid' => (string) Str::uuid(),
            'shop_id' => $device->shop_id,
            'device_id' => $device->id,
            'started_by' => $user->id,
            'computer_os' => 'windows',
            'brand_group' => 'samsung',
            'mode' => 'manual_guided',
            'status' => 'in_progress',
        ]);

        $this->actingAs($user)->post(route('setup.step', $session), [
            'step_key' => 'authorization',
            'confirmations' => ['authorization', 'agreement', 'backup', 'reset_ack'],
            'command_result' => 'expected_output_confirmed',
        ])->assertSessionHasErrors('verification_items');
        $this->assertDatabaseMissing('device_setup_steps', [
            'device_setup_session_id' => $session->id,
            'step_key' => 'authorization',
            'completed' => true,
        ]);
    }

    public function test_apk_settings_show_defaults_warning_and_all_required_actions(): void
    {
        $super = User::create([
            'name' => 'Super',
            'email' => 'apk-settings@example.test',
            'password' => 'Password@123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($super)->get(route('settings.qr-provisioning'))->assertOk();
        $response->assertSee('https://phone.twinsofte.com/downloads/deviceguard.apk')
            ->assertSee('APK checksum verification is not configured by Super Admin.')
            ->assertSee('com.twinsofte.deviceguard')
            ->assertSee('com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver')
            ->assertSee('Test APK URL')
            ->assertSee('Copy APK URL')
            ->assertSee('Calculate or verify checksum')
            ->assertSee('Download APK');
    }

    public function test_apk_url_action_verifies_status_filename_content_size_and_non_html_response(): void
    {
        $super = User::create([
            'name' => 'Super',
            'email' => 'apk-test@example.test',
            'password' => 'Password@123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        SystemSetting::create([
            'key' => 'provisioning_apk_url',
            'value' => 'https://phone.twinsofte.com/downloads/deviceguard.apk',
            'type' => 'string',
        ]);
        Http::fake([
            'https://phone.twinsofte.com/downloads/deviceguard.apk' => Http::response('PK-deviceguard-apk', 200, [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Content-Length' => '18',
            ]),
        ]);

        $response = $this->actingAs($super)->post(route('settings.qr-provisioning.test-apk'));
        $response->assertRedirect()->assertSessionHas('apk_url_test.passed', true);
        $checks = $response->getSession()->get('apk_url_test')['checks'];
        $this->assertSame([
            'HTTP status is 200' => true,
            'Filename is deviceguard.apk' => true,
            'File is not an HTML error page' => true,
            'File size is greater than zero' => true,
            'Content is downloadable' => true,
        ], $checks);
    }
}
