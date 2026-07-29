<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceSetupInstruction;
use App\Models\DeviceSetupSession;
use App\Models\Shop;
use App\Models\User;
use App\Services\SetupInstructionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'step_key' => 'adb_check', 'direction' => 'next', 'command_result' => 'ADB_FOUND',
        ])->assertRedirect();
        $session->refresh();
        $this->assertSame('found', $session->context['adb_status']);
        $this->assertDatabaseHas('device_setup_steps', [
            'device_setup_session_id' => $session->id,
            'step_key' => 'adb_check',
            'completed' => true,
            'verification_method' => 'command_output',
            'command_result' => 'ADB_FOUND',
        ]);
        $this->actingAs($user)->get(route('setup.show', $session))->assertOk()->assertDontSee('Install official Android Platform Tools only when ADB is missing');
    }

    public function test_checklist_only_step_does_not_require_command_result_key(): void
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
        ])->assertRedirect(route('setup.show', $session))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('device_setup_steps', [
            'device_setup_session_id' => $session->id,
            'step_key' => 'authorization',
            'completed' => true,
            'verification_method' => 'technician_checklist',
            'command_result' => null,
        ]);
    }

    public function test_server_verified_step_cannot_be_completed_from_checkbox_only(): void
    {
        [, $user, $device] = $this->tenant();
        $session = DeviceSetupSession::create(['uuid' => (string) Str::uuid(), 'shop_id' => $device->shop_id, 'device_id' => $device->id, 'started_by' => $user->id, 'computer_os' => 'windows', 'brand_group' => 'samsung', 'mode' => 'manual_guided', 'current_step' => 16, 'status' => 'in_progress']);
        $this->actingAs($user)->post(route('setup.step', $session), ['step_key' => 'activation', 'direction' => 'next'])
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
}
