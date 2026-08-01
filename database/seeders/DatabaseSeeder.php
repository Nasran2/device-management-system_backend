<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceLocation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Shop;
use App\Models\DeviceCommission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $super = User::create(['name' => 'Super Admin', 'email' => 'superadmin@example.com', 'password' => 'Password@123', 'role' => 'super_admin', 'phone' => '+94 11 000 0000', 'business_name' => 'Head Office', 'is_active' => true, 'can_view_locations' => true]);
        $admins = collect([
            User::create(['name' => 'Demo Admin', 'email' => 'admin@example.com', 'password' => 'Password@123', 'role' => 'admin', 'phone' => '+94 77 100 2000', 'business_name' => 'Colombo Branch', 'is_active' => true, 'can_view_locations' => true]),
            User::create(['name' => 'Kandy Admin', 'email' => 'kandy@example.com', 'password' => 'Password@123', 'role' => 'admin', 'phone' => '+94 77 300 4000', 'business_name' => 'Kandy Branch', 'is_active' => true, 'can_view_locations' => false]),
        ]);
        $admins->each(function (User $admin) {
            $shop = Shop::create(['name'=>$admin->business_name ?: $admin->name."'s Shop",'owner_name'=>$admin->name,'email'=>$admin->email,'mobile'=>$admin->phone ?: 'Not provided','address'=>$admin->address ?: 'Demo address','reference_code'=>'SHOP-'.str_pad((string)$admin->id,6,'0',STR_PAD_LEFT),'commission_percentage'=>5,'commission_basis'=>'selling_price_percentage','status'=>'active','created_by'=>1]);
            $admin->update(['shop_id'=>$shop->id,'role'=>'shop_owner']);
        });

        foreach (['super_admin', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        $super->assignRole('super_admin');
        $admins->each->assignRole('admin');

        SystemSetting::upsert([
            ['key' => 'application_name', 'value' => 'DeviceGuard', 'type' => 'string', 'is_public' => true],
            ['key' => 'location_tracking_enabled', 'value' => 'true', 'type' => 'boolean', 'is_public' => false],
            ['key' => 'admins_can_view_locations', 'value' => 'true', 'type' => 'boolean', 'is_public' => false],
            ['key' => 'support_phone', 'value' => '+94 11 000 0000', 'type' => 'string', 'is_public' => true],
            ['key' => 'lock_message', 'value' => 'Please complete your pending payment and contact the seller for assistance.', 'type' => 'string', 'is_public' => true],
            ['key' => 'location_retention_days', 'value' => '30', 'type' => 'integer', 'is_public' => false],
            ['key' => 'device_activation_code_expiry_minutes', 'value' => '1440', 'type' => 'integer', 'is_public' => false],
            ['key' => 'send_activation_code_by_sms', 'value' => 'false', 'type' => 'boolean', 'is_public' => false],
            ['key' => 'provisioning_api_url', 'value' => 'https://phonelock.twinsofte.com/api/v1/', 'type' => 'string', 'is_public' => false],
            ['key' => 'provisioning_apk_url', 'value' => 'https://phonelock.twinsofte.com/downloads/deviceguard.apk', 'type' => 'string', 'is_public' => false],
            ['key' => 'provisioning_apk_version', 'value' => '1.0.3', 'type' => 'string', 'is_public' => false],
            ['key' => 'provisioning_apk_file_sha256', 'value' => '6c4c34d1c2da39b514c9864d0b4846d9324d91c194e04479a428cc2190e8f49a', 'type' => 'string', 'is_public' => false],
            ['key' => 'provisioning_apk_signature_checksum', 'value' => 'wXo23R0TbQ4_eWWGoPLvruPXTrrwnUgdGwS02_qphMo', 'type' => 'string', 'is_public' => false],
        ], ['key'], ['value', 'type', 'is_public']);

        $statuses = ['pending_activation', 'active_unlocked', 'locked', 'offline', 'active_unlocked', 'locked', 'permanently_released', 'active_unlocked', 'offline', 'active_unlocked'];
        $brands = [['Samsung', 'Galaxy A15'], ['Xiaomi', 'Redmi Note 13'], ['OPPO', 'A58'], ['Vivo', 'Y27'], ['Samsung', 'Galaxy A25']];
        foreach ($statuses as $index => $status) {
            $admin = $admins[$index % 2];
            $customer = Customer::create(['shop_id'=>$admin->shop_id,'admin_id' => $admin->id,'created_by'=>$admin->id, 'name' => 'Demo Customer '.($index + 1), 'phone' => '+94 77 '.str_pad((string) (1000000 + $index), 7, '0', STR_PAD_LEFT), 'address' => 'Demo address']);
            [$brand, $model] = $brands[$index % count($brands)];
            $device = Device::create([
                'shop_id'=>$admin->shop_id,'admin_id' => $admin->id, 'customer_id' => $customer->id, 'brand' => $brand, 'model' => $model,
                'imei' => '86000000000'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'selling_price' => 55000 + ($index * 7250), 'currency' => 'LKR',
                'shop_branch' => $admin->business_name, 'support_phone' => $admin->phone, 'management_mode' => $index % 3 === 0 ? 'managed' : 'standard',
                'is_device_owner' => $index % 3 === 0, 'can_block_uninstall' => $index % 3 === 0, 'can_block_reset' => $index % 3 === 0,
                'can_full_lock' => $index % 3 === 0, 'status' => $status, 'lock_status' => $status === 'locked' ? 'locked' : 'unlocked',
                'location_tracking_enabled' => ! in_array($status, ['pending_activation', 'permanently_released'], true), 'tracking_mode' => $status === 'locked' ? 'continuous' : 'locked_only',
                'location_permission_status' => 'granted', 'background_location_permission_status' => 'granted', 'notification_permission_status' => 'granted', 'gps_status' => 'enabled',
                'last_seen_at' => $status === 'offline' ? now()->subHours(5) : now()->subMinutes($index * 3), 'released_at' => $status === 'permanently_released' ? now()->subDay() : null,
            ]);
            DeviceCommission::create(['shop_id'=>$admin->shop_id,'device_id'=>$device->id,'captured_percentage'=>5,'calculation_basis'=>'selling_price_percentage','base_amount'=>$device->selling_price,'commission_amount'=>round((float)$device->selling_price*.05,2),'outstanding_amount'=>round((float)$device->selling_price*.05,2),'status'=>'outstanding']);
            if ($device->location_tracking_enabled) {
                DeviceLocation::create(['device_id' => $device->id, 'latitude' => 6.9271 + ($index / 100), 'longitude' => 79.8612 + ($index / 100), 'accuracy' => 12, 'battery_percentage' => 80 - $index, 'gps_status' => 'enabled', 'network_status' => 'online', 'lock_status' => $device->lock_status, 'tracking_mode' => $device->tracking_mode, 'recorded_at' => now()->subMinutes($index * 3)]);
            }
            if ($index < 4) {
                DeviceCommand::create(['uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'requested_by' => $super->id, 'type' => $index % 2 ? 'LOCK_DEVICE' : 'SYNC_DEVICE', 'payload' => [], 'signature' => hash('sha256', "demo-{$index}"), 'status' => 'completed', 'expires_at' => now()->addHour(), 'executed_at' => now()]);
            }
        }
    }
}
