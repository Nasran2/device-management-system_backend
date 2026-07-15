<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    public function __invoke(Request $request)
    {
        $device = $request->attributes->get('device');

        return response()->json(['data' => [
            'application_name' => SystemSetting::value('application_name', 'DeviceGuard'),
            'support_phone' => $device->support_phone ?: SystemSetting::value('support_phone', '+94 11 000 0000'),
            'lock_message' => SystemSetting::value('lock_message', 'Please complete your pending payment and contact the seller for assistance.'),
            'location_tracking_globally_enabled' => SystemSetting::value('location_tracking_enabled', true),
            'location_tracking_enabled' => $device->location_tracking_enabled,
            'tracking_mode' => $device->tracking_mode,
            'tracking_interval_minutes' => $device->tracking_interval_minutes,
            'powered_by' => 'twinsofte.com',
        ]]);
    }
}
