<?php

namespace App\Services;

use RuntimeException;

class ApkFileIntegrity
{
    public function verify(string $path, string $configuredSha256, bool $deleteOnMismatch = false): array
    {
        $expectedHash = strtoupper(trim($configuredSha256));
        if (preg_match('/\A[0-9A-F]{64}\z/', $expectedHash) !== 1) {
            throw new RuntimeException('APK file SHA-256 must contain exactly 64 hexadecimal characters.');
        }
        if (! is_file($path)) {
            throw new RuntimeException('The downloaded APK file does not exist.');
        }

        $actualHash = strtoupper((string) hash_file('sha256', $path));
        $passed = hash_equals($expectedHash, $actualHash);
        $removed = false;
        if (! $passed && $deleteOnMismatch) {
            $removed = @unlink($path);
        }

        return compact('expectedHash', 'actualHash', 'passed', 'removed');
    }
}
