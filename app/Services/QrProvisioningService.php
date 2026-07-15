<?php
namespace App\Services;
use App\Models\Device;
use App\Models\DeviceProvisioningToken;
use App\Models\SystemSetting;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class QrProvisioningService {
    public const COMPONENT='com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver';
    public function configured(): bool{return SystemSetting::value('qr_provisioning_enabled',false)&&SystemSetting::value('provisioning_api_url')&&SystemSetting::value('provisioning_apk_url')&&SystemSetting::value('provisioning_apk_checksum');}
    public function generate(Device $device,int $userId): array{return DB::transaction(function()use($device,$userId){$device->provisioningTokens()->whereIn('status',['active','provisioning_started','waiting_for_pin'])->update(['status'=>'revoked','revoked_at'=>now()]);$plain=bin2hex(random_bytes(32));$token=$device->provisioningTokens()->create(['token_hash'=>hash('sha256',$plain),'expires_at'=>now()->addMinutes(SystemSetting::value('provisioning_qr_expiry_minutes',30)),'created_by'=>$userId]);return [$token,$this->payload($device,$plain)];});}
    public function payload(Device $device,string $plain): array{$payload=['android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME'=>self::COMPONENT,'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION'=>SystemSetting::value('provisioning_apk_url'),'android.app.extra.PROVISIONING_DEVICE_ADMIN_SIGNATURE_CHECKSUM'=>SystemSetting::value('provisioning_apk_checksum'),'android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE'=>array_filter(['device_reference'=>$device->uuid,'provisioning_token'=>$plain,'api_url'=>rtrim(SystemSetting::value('provisioning_api_url'),'/').'/','require_management_pin'=>true,'support_phone'=>$device->support_phone?:SystemSetting::value('provisioning_support_phone'),'branch_reference'=>$device->shop_branch])];$ssid=trim((string)SystemSetting::value('provisioning_wifi_ssid',''));if($ssid!==''){$security=SystemSetting::value('provisioning_wifi_security_type','WPA');$payload['android.app.extra.PROVISIONING_WIFI_SSID']=$ssid;$payload['android.app.extra.PROVISIONING_WIFI_SECURITY_TYPE']=$security;$payload['android.app.extra.PROVISIONING_WIFI_HIDDEN']=(bool)SystemSetting::value('provisioning_wifi_hidden',false);if($security!=='NONE'&&($encrypted=SystemSetting::value('provisioning_wifi_password_encrypted'))){$payload['android.app.extra.PROVISIONING_WIFI_PASSWORD']=Crypt::decryptString($encrypted);}}return $payload;}
    public function png(array $payload): string{$result=(new Builder(writer:new PngWriter(),data:json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),encoding:new Encoding('UTF-8'),errorCorrectionLevel:ErrorCorrectionLevel::Medium,size:900,margin:30))->build();return $result->getString();}
}
