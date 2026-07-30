<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DeviceGuard distribution defaults
    |--------------------------------------------------------------------------
    |
    | The database-backed APK & Provisioning settings may override the APK
    | URL. When that value is empty (or still contains a retired placeholder),
    | every setup surface falls back to this single production URL.
    |
    */
    'apk_url' => env('DEVICEGUARD_APK_URL', 'https://phone.twinsofte.com/downloads/deviceguard.apk'),
    'package_name' => 'com.twinsofte.deviceguard',
    'device_admin_receiver' => 'com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver',
];
