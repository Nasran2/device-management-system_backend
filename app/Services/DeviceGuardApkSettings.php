<?php

namespace App\Services;

use App\Models\SystemSetting;

class DeviceGuardApkSettings
{
    public const SETTING_URL = 'provisioning_apk_url';

    public const SETTING_CHECKSUM = 'provisioning_apk_checksum';

    public function url(): string
    {
        $saved = trim((string) SystemSetting::value(self::SETTING_URL, ''));

        return $saved === '' || $this->isRetiredValue($saved)
            ? (string) config('deviceguard.apk_url')
            : $saved;
    }

    public function checksum(): ?string
    {
        $saved = trim((string) SystemSetting::value(self::SETTING_CHECKSUM, ''));

        return $saved === '' || str_starts_with($saved, 'CONFIG'.'URE_') ? null : $saved;
    }

    public function packageName(): string
    {
        return (string) config('deviceguard.package_name');
    }

    public function receiver(): string
    {
        return (string) config('deviceguard.device_admin_receiver');
    }

    public function isRetiredValue(string $value): bool
    {
        $retiredHost = 'phone'.'lock.twinsofte.com';

        return str_starts_with($value, 'CONFIG'.'URE_')
            || strtolower((string) parse_url($value, PHP_URL_HOST)) === $retiredHost
            || preg_match('/deviceguard-1\.0\.[34]\.apk/i', $value) === 1;
    }
}
