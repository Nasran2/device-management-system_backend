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
        foreach(['provisioning_api_url','provisioning_apk_url'] as $key){$url=SystemSetting::value($key);abort_unless(str_starts_with((string)$url,'https://'),422,"$key must use HTTPS.");$host=parse_url($url,PHP_URL_HOST);abort_if(in_array($host,['localhost','127.0.0.1','::1'])||filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false&&filter_var($host,FILTER_VALIDATE_IP),422,'Private addresses are forbidden.');}
        $api=Http::timeout(10)->get(rtrim(SystemSetting::value('provisioning_api_url'),'/').'/health');$apk=Http::timeout(15)->head(SystemSetting::value('provisioning_apk_url'));abort_unless($api->successful()&&$apk->successful(),422,'API or APK URL is unreachable.');
        return back()->with('success','Configuration validation passed.');
    }
}
