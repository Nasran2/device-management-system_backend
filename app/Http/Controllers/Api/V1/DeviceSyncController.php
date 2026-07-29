<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\OfflineProtectionService;

class DeviceSyncController extends Controller
{
    public function heartbeat(Request $request, OfflineProtectionService $offline)
    {
        $data = $request->validate([
            'battery_percentage' => ['nullable', 'integer', 'between:0,100'],
            'gps_status' => ['nullable', 'in:enabled,disabled,unknown'],
            'network_status' => ['nullable', 'string', 'max:30'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]);
        $device = $request->attributes->get('device');
        $device->update($data + ['last_seen_at' => now(), 'last_sync_at' => now()]);

        $signedPolicy = $offline->issue($device->fresh());
        $commands = $device->commands()->whereIn('status', ['pending', 'dispatched'])->where('expires_at', '>', now())->oldest()->get()
            ->map->only(['id', 'uuid', 'type', 'payload', 'signature', 'expires_at', 'status']);

        return response()->json(['data' => [
            'server_utc_time' => now('UTC')->toIso8601String(),
            'status' => $device->fresh()->status,
            'lock_status' => $device->fresh()->lock_status,
            'commands' => $commands,
            'offline_policy' => $signedPolicy,
        ]]);
    }

    public function capabilities(Request $request)
    {
        $data = $request->validate([
            'management_mode' => ['required', 'in:standard,managed'],
            'is_device_owner' => ['required', 'boolean'],
            'is_admin_active' => ['required', 'boolean'],
            'can_block_uninstall' => ['required', 'boolean'],
            'can_block_reset' => ['required', 'boolean'],
            'can_full_lock' => ['required', 'boolean'],
            'can_use_lock_task' => ['required', 'boolean'],
            'is_lock_task_permitted' => ['required', 'boolean'],
            'location_permission_status' => ['required', 'string', 'max:30'],
            'background_location_permission_status' => ['required', 'string', 'max:30'],
            'notification_permission_status' => ['required', 'string', 'max:30'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
        ]);
        $device = $request->attributes->get('device');
        
        $updateData = $data + ['management_status' => $data['is_device_owner'] && $data['is_admin_active'] ? 'active' : 'setup_required', 'last_sync_at' => now()];
        if (isset($data['fcm_token'])) {
            $updateData['fcm_token'] = $data['fcm_token'];
        }
        $device->update($updateData);

        return response()->json(['message' => 'Capabilities updated.']);
    }
}
