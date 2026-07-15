<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function store(Request $request)
    {
        $device = $request->attributes->get('device');
        abort_unless(SystemSetting::value('location_tracking_enabled', true), 403, 'Location tracking is disabled globally.');
        abort_unless($device->location_tracking_enabled && ! $device->isReleased(), 403, 'Location tracking is disabled for this device.');
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'], 'battery_percentage' => ['nullable', 'integer', 'between:0,100'],
            'gps_status' => ['nullable', 'string', 'max:30'], 'network_status' => ['nullable', 'string', 'max:30'],
            'recorded_at' => ['required', 'date', 'before_or_equal:now'],
        ]);
        $location = $device->locations()->create($data + ['lock_status' => $device->lock_status, 'tracking_mode' => $device->tracking_mode]);
        $device->update(['last_latitude' => $data['latitude'], 'last_longitude' => $data['longitude'], 'last_location_accuracy' => $data['accuracy'] ?? null, 'last_location_at' => $data['recorded_at'], 'last_seen_at' => now()]);

        return response()->json(['data' => ['id' => $location->id]], 201);
    }
}
