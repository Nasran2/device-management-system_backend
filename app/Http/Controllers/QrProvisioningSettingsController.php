<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\DeviceGuardApkSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QrProvisioningSettingsController extends Controller
{
    public function __construct(private DeviceGuardApkSettings $apk) {}

    private function super(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
    }

    public function edit(Request $request)
    {
        $this->super($request);

        return view('settings.qr-provisioning', [
            'apkUrl' => $this->apk->url(),
            'apkChecksum' => $this->apk->checksum(),
            'packageName' => $this->apk->packageName(),
            'deviceAdminReceiver' => $this->apk->receiver(),
        ]);
    }

    public function update(Request $request)
    {
        $this->super($request);
        $ssid = trim((string) $request->input('wifi_ssid'));
        $security = $ssid === '' ? null : ($request->input('wifi_security_type') ?: 'WPA');
        $savedSsid = (string) SystemSetting::value('provisioning_wifi_ssid', '');
        $hasSavedPassword = filled(SystemSetting::value('provisioning_wifi_password_encrypted'));
        $requiresNewPassword = $ssid !== '' && in_array($security, ['WPA', 'WEP'], true) && ! ($ssid === $savedSsid && $hasSavedPassword);

        $data = $request->validate([
            'provisioning_api_url' => ['required', 'url', 'starts_with:https://'],
            'provisioning_apk_url' => ['required', 'url', 'starts_with:https://'],
            'provisioning_apk_version' => ['required', 'string', 'max:50'],
            'provisioning_apk_checksum' => ['nullable', 'string', 'max:255'],
            'provisioning_package_name' => ['nullable', 'in:'.$this->apk->packageName()],
            'provisioning_device_admin_receiver' => ['nullable', 'in:'.$this->apk->receiver()],
            'provisioning_qr_expiry_minutes' => ['required', 'integer', 'between:5,1440'],
            'provisioning_support_phone' => ['nullable', 'string', 'max:30'],
            'windows_platform_tools_url' => ['nullable', 'url', 'starts_with:https://'],
            'windows_platform_tools_checksum' => ['nullable', 'string', 'size:64', 'required_with:windows_platform_tools_url'],
            'wifi_security_type' => ['nullable', Rule::in(['WPA', 'WEP', 'NONE'])],
            'wifi_ssid' => ['nullable', 'string', 'max:32'],
            'wifi_password' => ['nullable', 'string', 'max:255', Rule::requiredIf($requiresNewPassword)],
            'wifi_hidden' => ['nullable', 'boolean'],
        ]);
        if (filled($data['windows_platform_tools_url'] ?? null) && $this->isUnsafeUrl($data['windows_platform_tools_url'])) {
            throw ValidationException::withMessages(['windows_platform_tools_url' => 'Private or local Platform Tools URLs are forbidden.']);
        }
        $data['provisioning_package_name'] = $data['provisioning_package_name'] ?? $this->apk->packageName();
        $data['provisioning_device_admin_receiver'] = $data['provisioning_device_admin_receiver'] ?? $this->apk->receiver();

        foreach (['provisioning_api_url', 'provisioning_apk_url', 'provisioning_apk_version', 'provisioning_apk_checksum', 'provisioning_package_name', 'provisioning_device_admin_receiver', 'provisioning_qr_expiry_minutes', 'provisioning_support_phone', 'windows_platform_tools_url', 'windows_platform_tools_checksum'] as $key) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => $data[$key] ?? null, 'type' => $key === 'provisioning_qr_expiry_minutes' ? 'integer' : 'string']);
        }
        SystemSetting::updateOrCreate(['key' => 'qr_provisioning_enabled'], ['value' => $request->boolean('qr_provisioning_enabled') ? 'true' : 'false', 'type' => 'boolean']);

        if ($ssid === '') {
            SystemSetting::whereIn('key', ['provisioning_wifi_ssid', 'provisioning_wifi_security_type', 'provisioning_wifi_password_encrypted', 'provisioning_wifi_hidden', 'provisioning_branch_wifi_ssid', 'provisioning_branch_wifi_password'])->delete();
        } else {
            SystemSetting::updateOrCreate(['key' => 'provisioning_wifi_ssid'], ['value' => $ssid, 'type' => 'string']);
            SystemSetting::updateOrCreate(['key' => 'provisioning_wifi_security_type'], ['value' => $security, 'type' => 'string']);
            SystemSetting::updateOrCreate(['key' => 'provisioning_wifi_hidden'], ['value' => $request->boolean('wifi_hidden') ? 'true' : 'false', 'type' => 'boolean']);
            if ($security === 'NONE') {
                SystemSetting::where('key', 'provisioning_wifi_password_encrypted')->delete();
            } elseif (filled($data['wifi_password'] ?? null)) {
                SystemSetting::updateOrCreate(['key' => 'provisioning_wifi_password_encrypted'], ['value' => Crypt::encryptString($data['wifi_password']), 'type' => 'encrypted']);
            }
            SystemSetting::whereIn('key', ['provisioning_branch_wifi_ssid', 'provisioning_branch_wifi_password'])->delete();
        }

        return back()->with('success', 'QR provisioning settings saved.');
    }

    public function testApkUrl(Request $request)
    {
        $this->super($request);
        $result = $this->inspectApkDownload($this->apk->url(), false);

        return back()
            ->with('apk_url_test', $result)
            ->with($result['passed'] ? 'success' : 'error', $result['message']);
    }

    public function calculateOrVerifyChecksum(Request $request)
    {
        $this->super($request);
        $result = $this->inspectApkDownload($this->apk->url(), true);

        if ($result['passed'] && $this->apk->checksum()) {
            $result['matches'] = hash_equals(strtolower($this->apk->checksum()), strtolower($result['sha256']));
            $result['passed'] = $result['matches'];
            $result['message'] = $result['matches']
                ? 'The downloaded APK matches the saved SHA-256 checksum.'
                : 'The downloaded APK does not match the saved SHA-256 checksum.';
        } elseif ($result['passed']) {
            $result['message'] = 'APK checksum calculated. Save this value after independently confirming the approved release.';
        }

        return back()
            ->with('apk_checksum_result', $result)
            ->with($result['passed'] ? 'success' : 'error', $result['message']);
    }

    public function validateConfiguration(Request $request)
    {
        $this->super($request);
        $apiUrl = trim((string) SystemSetting::value('provisioning_api_url', ''));
        $apkUrl = $this->apk->url();
        $version = trim((string) SystemSetting::value('provisioning_apk_version', ''));
        $checksum = (string) ($this->apk->checksum() ?? '');
        $enabled = (bool) SystemSetting::value('qr_provisioning_enabled', false);
        $checks = [
            'API health endpoint' => ['passed' => false, 'message' => 'Not checked.'],
            'APK download' => ['passed' => false, 'message' => 'Not checked.'],
            'APK content type' => ['passed' => false, 'message' => 'Not checked.'],
            'Signing checksum configured' => ['passed' => $checksum !== '', 'message' => $checksum !== '' ? 'Configured.' : 'Signing certificate checksum is missing.'],
            'QR provisioning enabled' => ['passed' => $enabled, 'message' => $enabled ? 'Enabled.' : 'QR provisioning is disabled.'],
        ];
        $errors = [];

        foreach ([
            [$apiUrl, 'Production API URL is missing.', 'The production API URL must use HTTPS.'],
            [$apkUrl, 'APK download URL is missing.', 'The APK download URL must use HTTPS.'],
        ] as [$url, $missing, $https]) {
            if ($url === '') {
                $errors[] = $missing;
            } elseif (! str_starts_with(strtolower($url), 'https://')) {
                $errors[] = $https;
            } elseif ($this->isUnsafeUrl($url)) {
                $errors[] = 'Private or local addresses are forbidden.';
            }
        }
        if ($version === '') {
            $errors[] = 'APK version is missing.';
        }
        if ($checksum === '') {
            $errors[] = 'Signing certificate checksum is missing.';
        }
        if (! $enabled) {
            $errors[] = 'QR provisioning is disabled.';
        }

        if ($errors === []) {
            $healthUrl = rtrim($apiUrl, '/').'/health';
            try {
                $apiResponse = Http::timeout(15)->acceptJson()->get($healthUrl);
                if (! $apiResponse->successful()) {
                    $errors[] = "API health endpoint returned HTTP {$apiResponse->status()}.";
                    $checks['API health endpoint']['message'] = "Failed with HTTP {$apiResponse->status()}.";
                } elseif ($apiResponse->json('success') !== true) {
                    $errors[] = 'API returned an invalid response.';
                    $checks['API health endpoint']['message'] = 'The response did not contain success=true.';
                } else {
                    $checks['API health endpoint'] = ['passed' => true, 'message' => 'Passed: '.$healthUrl];
                }
            } catch (\Throwable $error) {
                $errors[] = 'API health endpoint is unreachable.';
                $checks['API health endpoint']['message'] = 'Connection failed: '.$error->getMessage();
            }

            try {
                $apkResponse = Http::timeout(20)->head($apkUrl);
                if (in_array($apkResponse->status(), [403, 405], true)) {
                    $apkResponse = Http::timeout(20)->withHeaders(['Range' => 'bytes=0-0'])->get($apkUrl);
                }
                if (! ($apkResponse->successful() || $apkResponse->status() === 206)) {
                    $errors[] = "APK URL returned HTTP {$apkResponse->status()}.";
                    $checks['APK download']['message'] = "Failed with HTTP {$apkResponse->status()}.";
                } else {
                    $checks['APK download'] = ['passed' => true, 'message' => 'Passed.'];
                    $contentType = strtolower((string) $apkResponse->header('Content-Type'));
                    $validType = str_contains($contentType, 'application/vnd.android.package-archive') || str_contains($contentType, 'application/octet-stream');
                    $checks['APK content type'] = ['passed' => $validType, 'message' => $contentType ?: 'No Content-Type header.'];
                    if (! $validType) {
                        $errors[] = 'APK content type is incorrect.';
                    }
                    $length = (int) ($apkResponse->header('Content-Length') ?: strlen($apkResponse->body()));
                    if ($length <= 0) {
                        $errors[] = 'APK content length is zero.';
                    }
                }
            } catch (\Throwable $error) {
                $errors[] = 'APK download URL is unreachable.';
                $checks['APK download']['message'] = 'Connection failed: '.$error->getMessage();
            }
        }

        $result = ['passed' => $errors === [], 'checks' => $checks, 'errors' => array_values(array_unique($errors))];
        $redirect = redirect()->route('settings.qr-provisioning')->with('configuration_validation', $result);

        return $errors === []
            ? $redirect->with('success', 'QR provisioning configuration is valid. The API and APK download URL are reachable.')
            : $redirect->withErrors(['configuration' => implode(' ', $result['errors'])]);
    }

    private function isUnsafeUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function inspectApkDownload(string $url, bool $calculateChecksum): array
    {
        $checks = [
            'HTTP status is 200' => false,
            'Filename is deviceguard.apk' => false,
            'File is not an HTML error page' => false,
            'File size is greater than zero' => false,
            'Content is downloadable' => false,
        ];
        if (! str_starts_with(strtolower($url), 'https://') || $this->isUnsafeUrl($url)) {
            return compact('url', 'checks') + ['passed' => false, 'message' => 'The APK URL must be a public HTTPS address.'];
        }

        $checks['Filename is deviceguard.apk'] = basename((string) parse_url($url, PHP_URL_PATH)) === 'deviceguard.apk';
        $temporaryPath = tempnam(sys_get_temp_dir(), 'deviceguard-apk-');
        if ($temporaryPath === false) {
            return compact('url', 'checks') + ['passed' => false, 'message' => 'A temporary file could not be created for APK verification.'];
        }

        try {
            $response = Http::timeout(90)->withOptions(['sink' => $temporaryPath])->get($url);
            $checks['HTTP status is 200'] = $response->status() === 200;
            $size = is_file($temporaryPath) ? (int) filesize($temporaryPath) : 0;
            $checks['File size is greater than zero'] = $size > 0;
            $contentType = strtolower((string) $response->header('Content-Type'));
            $prefix = $size > 0 ? strtolower((string) file_get_contents($temporaryPath, false, null, 0, 512)) : '';
            $isHtml = str_contains($contentType, 'text/html')
                || str_contains($prefix, '<!doctype html')
                || str_contains($prefix, '<html');
            $checks['File is not an HTML error page'] = ! $isHtml;
            $checks['Content is downloadable'] = str_contains($contentType, 'application/vnd.android.package-archive')
                || str_contains($contentType, 'application/octet-stream');
            $passed = ! in_array(false, $checks, true);
            $result = compact('url', 'checks', 'size', 'contentType', 'passed') + [
                'message' => $passed
                    ? 'APK URL test passed: deviceguard.apk is downloadable and non-empty.'
                    : 'APK URL test failed. Review each APK download check.',
            ];
            if ($calculateChecksum && $size > 0 && ! $isHtml) {
                $result['sha256'] = hash_file('sha256', $temporaryPath);
            }

            return $result;
        } catch (\Throwable $error) {
            return compact('url', 'checks') + [
                'passed' => false,
                'message' => 'APK download failed: '.$error->getMessage(),
            ];
        } finally {
            @unlink($temporaryPath);
        }
    }
}
