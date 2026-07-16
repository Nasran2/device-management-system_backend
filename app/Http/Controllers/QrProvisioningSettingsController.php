<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class QrProvisioningSettingsController extends Controller
{
    private function super(Request $request): void { abort_unless($request->user()->isSuperAdmin(), 403); }

    public function edit(Request $request)
    {
        $this->super($request);
        return view('settings.qr-provisioning');
    }

    public function update(Request $request)
    {
        $this->super($request);
        $ssid = trim((string) $request->input('wifi_ssid'));
        $security = $ssid === '' ? null : ($request->input('wifi_security_type') ?: 'WPA');
        $savedSsid = (string) SystemSetting::value('provisioning_wifi_ssid', '');
        $hasSavedPassword = filled(SystemSetting::value('provisioning_wifi_password_encrypted'));
        $requiresNewPassword = $ssid !== '' && in_array($security, ['WPA','WEP'], true) && ! ($ssid === $savedSsid && $hasSavedPassword);

        $data = $request->validate([
            'provisioning_api_url'=>['required','url','starts_with:https://'],
            'provisioning_apk_url'=>['required','url','starts_with:https://'],
            'provisioning_apk_version'=>['required','string','max:50'],
            'provisioning_apk_checksum'=>['required','string','max:255'],
            'provisioning_qr_expiry_minutes'=>['required','integer','between:5,1440'],
            'provisioning_support_phone'=>['nullable','string','max:30'],
            'wifi_security_type'=>['nullable',Rule::in(['WPA','WEP','NONE'])],
            'wifi_ssid'=>['nullable','string','max:32'],
            'wifi_password'=>['nullable','string','max:255',Rule::requiredIf($requiresNewPassword)],
            'wifi_hidden'=>['nullable','boolean'],
        ]);

        foreach (['provisioning_api_url','provisioning_apk_url','provisioning_apk_version','provisioning_apk_checksum','provisioning_qr_expiry_minutes','provisioning_support_phone'] as $key) {
            SystemSetting::updateOrCreate(['key'=>$key], ['value'=>$data[$key] ?? null, 'type'=>$key==='provisioning_qr_expiry_minutes'?'integer':'string']);
        }
        SystemSetting::updateOrCreate(['key'=>'qr_provisioning_enabled'], ['value'=>$request->boolean('qr_provisioning_enabled')?'true':'false','type'=>'boolean']);

        if ($ssid === '') {
            SystemSetting::whereIn('key',['provisioning_wifi_ssid','provisioning_wifi_security_type','provisioning_wifi_password_encrypted','provisioning_wifi_hidden','provisioning_branch_wifi_ssid','provisioning_branch_wifi_password'])->delete();
        } else {
            SystemSetting::updateOrCreate(['key'=>'provisioning_wifi_ssid'], ['value'=>$ssid,'type'=>'string']);
            SystemSetting::updateOrCreate(['key'=>'provisioning_wifi_security_type'], ['value'=>$security,'type'=>'string']);
            SystemSetting::updateOrCreate(['key'=>'provisioning_wifi_hidden'], ['value'=>$request->boolean('wifi_hidden')?'true':'false','type'=>'boolean']);
            if ($security === 'NONE') {
                SystemSetting::where('key','provisioning_wifi_password_encrypted')->delete();
            } elseif (filled($data['wifi_password'] ?? null)) {
                SystemSetting::updateOrCreate(['key'=>'provisioning_wifi_password_encrypted'], ['value'=>Crypt::encryptString($data['wifi_password']),'type'=>'encrypted']);
            }
            SystemSetting::whereIn('key',['provisioning_branch_wifi_ssid','provisioning_branch_wifi_password'])->delete();
        }

        return back()->with('success','QR provisioning settings saved.');
    }

    public function validateConfiguration(Request $request)
    {
        $this->super($request);
        $apiUrl = trim((string) SystemSetting::value('provisioning_api_url', ''));
        $apkUrl = trim((string) SystemSetting::value('provisioning_apk_url', ''));
        $version = trim((string) SystemSetting::value('provisioning_apk_version', ''));
        $checksum = trim((string) SystemSetting::value('provisioning_apk_checksum', ''));
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
            if ($url === '') $errors[] = $missing;
            elseif (! str_starts_with(strtolower($url), 'https://')) $errors[] = $https;
            elseif ($this->isUnsafeUrl($url)) $errors[] = 'Private or local addresses are forbidden.';
        }
        if ($version === '') $errors[] = 'APK version is missing.';
        if ($checksum === '') $errors[] = 'Signing certificate checksum is missing.';
        if (! $enabled) $errors[] = 'QR provisioning is disabled.';

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
                    if (! $validType) $errors[] = 'APK content type is incorrect.';
                    $length = (int) ($apkResponse->header('Content-Length') ?: strlen($apkResponse->body()));
                    if ($length <= 0) $errors[] = 'APK content length is zero.';
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
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return true;
        if (! filter_var($host, FILTER_VALIDATE_IP)) return false;

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
