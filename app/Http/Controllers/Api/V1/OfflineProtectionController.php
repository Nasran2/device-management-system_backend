<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OfflineProtectionService;
use Illuminate\Http\Request;

class OfflineProtectionController extends Controller
{
    public function acknowledge(Request $request, OfflineProtectionService $service)
    {
        $data = $request->validate([
            'policy_version' => ['required', 'integer', 'min:1'], 'nonce' => ['required', 'string', 'max:100'],
            'signature_verified' => ['required', 'accepted'], 'stored_successfully' => ['required', 'accepted'],
            'local_deadline_at' => ['required', 'date'], 'last_trusted_server_time' => ['required', 'date'],
            'local_locked' => ['required', 'boolean'], 'network_status' => ['nullable', 'string', 'max:30'],
        ]);
        $policy = $service->acknowledge($request->attributes->get('device'), $data);
        return response()->json(['message' => 'Signed policy verified and acknowledged.', 'data' => ['last_verified_at' => $policy->last_verified_at?->toIso8601String(), 'offline_deadline_at' => $policy->offline_deadline_at?->toIso8601String()]]);
    }

    public function events(Request $request, OfflineProtectionService $service)
    {
        $data = $request->validate(['events' => ['required', 'array', 'max:100'], 'events.*.type' => ['required', 'in:WARNING_SHOWN,OFFLINE_LOCK_TRIGGERED,OFFLINE_LOCK_FAILED,INTERNET_RESTORED,CLOCK_TAMPERING'], 'events.*.occurred_at' => ['required', 'date'], 'events.*.metadata' => ['nullable', 'array']]);
        $device = $request->attributes->get('device');
        $policy = $service->policyFor($device);
        foreach ($data['events'] as $event) {
            $service->audit($device, $event['type'], $policy, null, ($event['metadata'] ?? []) + ['phone_occurred_at' => $event['occurred_at']]);
            if ($event['type'] === 'OFFLINE_LOCK_TRIGGERED') $policy->update(['last_offline_lock_at' => now(), 'last_offline_lock_result' => 'success', 'phone_local_locked' => true]);
        }
        return response()->json(['message' => 'Offline events uploaded.']);
    }
}
