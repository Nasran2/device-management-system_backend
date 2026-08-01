<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const APK_URL = 'https://phonelock.twinsofte.com/downloads/deviceguard.apk';

    private const APK_FILE_SHA256 = '6c4c34d1c2da39b514c9864d0b4846d9324d91c194e04479a428cc2190e8f49a';

    private const SIGNATURE_CHECKSUM = 'wXo23R0TbQ4_eWWGoPLvruPXTrrwnUgdGwS02_qphMo';

    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $url = trim((string) DB::table('system_settings')->where('key', 'provisioning_apk_url')->value('value'));
        $isDeviceGuardHost = in_array($url, [self::APK_URL, 'https://phone.twinsofte.com/downloads/deviceguard.apk'], true);
        if ($isDeviceGuardHost) {
            $this->save('provisioning_apk_url', self::APK_URL);
            $this->save('provisioning_apk_version', '1.0.3');
            $this->save('provisioning_apk_file_sha256', self::APK_FILE_SHA256);
            if (blank(DB::table('system_settings')->where('key', 'provisioning_api_url')->value('value'))) {
                $this->save('provisioning_api_url', 'https://phonelock.twinsofte.com/api/v1/');
            }
        }

        $signature = trim((string) DB::table('system_settings')->where('key', 'provisioning_apk_signature_checksum')->value('value'));
        if ($signature === '') {
            $legacy = trim((string) DB::table('system_settings')->where('key', 'provisioning_apk_checksum')->value('value'));
            if ($legacy !== '') {
                $this->save('provisioning_apk_signature_checksum', $legacy);
            } elseif ($isDeviceGuardHost) {
                $this->save('provisioning_apk_signature_checksum', self::SIGNATURE_CHECKSUM);
            }
        }
    }

    public function down(): void
    {
        // Deliberately keep production integrity settings during rollback.
    }

    private function save(string $key, string $value): void
    {
        $values = ['value' => $value, 'type' => 'string', 'is_public' => false, 'updated_at' => now()];
        if (! DB::table('system_settings')->where('key', $key)->exists()) {
            $values['created_at'] = now();
        }
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            $values,
        );
    }
};
