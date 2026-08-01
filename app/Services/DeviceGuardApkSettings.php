<?php

namespace App\Services;

use App\Models\SystemSetting;

class DeviceGuardApkSettings
{
    public const SETTING_URL = 'provisioning_apk_url';

    public const SETTING_FILE_SHA256 = 'provisioning_apk_file_sha256';

    public const SETTING_SIGNATURE_CHECKSUM = 'provisioning_apk_signature_checksum';

    public const LEGACY_SETTING_CHECKSUM = 'provisioning_apk_checksum';

    public function url(): string
    {
        $saved = trim((string) SystemSetting::value(self::SETTING_URL, ''));

        return $saved === '' || $this->isRetiredValue($saved)
            ? (string) config('deviceguard.apk_url')
            : $saved;
    }

    public function fileSha256(): ?string
    {
        $saved = trim((string) SystemSetting::value(self::SETTING_FILE_SHA256, ''));

        return preg_match('/\A[0-9a-fA-F]{64}\z/', $saved) === 1 ? strtoupper($saved) : null;
    }

    public function signatureChecksum(): ?string
    {
        $saved = trim((string) SystemSetting::value(self::SETTING_SIGNATURE_CHECKSUM, ''));
        if ($saved === '') {
            $legacy = trim((string) SystemSetting::value(self::LEGACY_SETTING_CHECKSUM, ''));
            $saved = $this->isSignatureChecksum($legacy) ? $legacy : '';
        }

        return $this->isSignatureChecksum($saved) ? $saved : null;
    }

    public function isSignatureChecksum(string $value): bool
    {
        if ($value === '' || str_starts_with($value, 'CONFIG'.'URE_')) {
            return false;
        }

        if (preg_match('/\A[A-Za-z0-9+\/_-]+={0,2}\z/', $value) !== 1) {
            return false;
        }

        $withoutPadding = rtrim($value, '=');
        $remainder = strlen($withoutPadding) % 4;
        if ($remainder === 1) {
            return false;
        }

        $normalized = strtr($withoutPadding, '-_', '+/');
        $normalized .= str_repeat('=', (4 - $remainder) % 4);
        $decoded = base64_decode($normalized, true);

        return $decoded !== false && strlen($decoded) === 32;
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
        $retiredHost = 'phone.twinsofte.com';

        return str_starts_with($value, 'CONFIG'.'URE_')
            || strtolower((string) parse_url($value, PHP_URL_HOST)) === $retiredHost
            || preg_match('/deviceguard-1\.0\.[34]\.apk/i', $value) === 1;
    }
}
