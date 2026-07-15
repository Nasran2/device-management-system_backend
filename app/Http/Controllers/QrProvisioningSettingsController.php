<?php
namespace App\Http\Controllers;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
class QrProvisioningSettingsController extends Controller {
    private function super(Request $r):void{abort_unless($r->user()->isSuperAdmin(),403);}
    public function edit(Request $r){$this->super($r);return view('settings.qr-provisioning');}
    public function update(Request $r){$this->super($r);$d=$r->validate(['provisioning_api_url'=>['required','url','starts_with:https://'],'provisioning_apk_url'=>['required','url','starts_with:https://'],'provisioning_apk_version'=>['required','string','max:50'],'provisioning_apk_checksum'=>['required','string','max:255'],'provisioning_qr_expiry_minutes'=>['required','integer','between:5,1440'],'provisioning_support_phone'=>['nullable','string','max:30'],'provisioning_branch_wifi_ssid'=>['nullable','string','max:100'],'provisioning_branch_wifi_password'=>['nullable','string','max:255']]);foreach($d as $k=>$v)SystemSetting::updateOrCreate(['key'=>$k],['value'=>$v,'type'=>$k==='provisioning_qr_expiry_minutes'?'integer':'string']);SystemSetting::updateOrCreate(['key'=>'qr_provisioning_enabled'],['value'=>$r->boolean('qr_provisioning_enabled')?'true':'false','type'=>'boolean']);return back()->with('success','QR provisioning settings saved.');}
    public function validateConfiguration(Request $r){$this->super($r);foreach(['provisioning_api_url','provisioning_apk_url'] as $key){$url=SystemSetting::value($key);abort_unless(str_starts_with((string)$url,'https://'),422,"$key must use HTTPS.");$host=parse_url($url,PHP_URL_HOST);abort_if(in_array($host,['localhost','127.0.0.1','::1'])||filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false&&filter_var($host,FILTER_VALIDATE_IP),422,'Private addresses are forbidden.');}$api=Http::timeout(10)->get(rtrim(SystemSetting::value('provisioning_api_url'),'/').'/health');$apk=Http::timeout(15)->head(SystemSetting::value('provisioning_apk_url'));abort_unless($api->successful()&&$apk->successful(),422,'API or APK URL is unreachable.');return back()->with('success','Configuration validation passed.');}
}
