<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\OfflineProtectionSetting;
use App\Services\CommandService;
use App\Services\OfflineProtectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DeviceOfflineProtectionController extends Controller
{
    public function update(Request $request, Device $device, OfflineProtectionService $service)
    {
        $this->authorize('update', $device);
        $global = OfflineProtectionSetting::current();
        abort_if(! $request->user()->isSuperAdmin() && ! $global->allow_admin_override, 403, 'Admin device overrides are disabled.');
        if ($device->isReleased()) {
            throw ValidationException::withMessages(['device' => 'A permanently released device must be re-enrolled before protection can be enabled.']);
        }
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'], 'uses_global_default' => ['nullable', 'boolean'],
            'period_value' => ['required_unless:uses_global_default,1', 'nullable', 'integer', 'min:1'],
            'period_unit' => ['required_unless:uses_global_default,1', 'nullable', 'in:hours,days'],
            'password' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['accepted'],
        ]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The account password is incorrect.']);
        }
        $data['enabled'] = $request->boolean('enabled');
        $data['uses_global_default'] = $request->boolean('uses_global_default');
        $service->changeDevice($device, $data, $request->user());
        return back()->with('success', 'Offline policy updated and is pending phone acknowledgement.');
    }

    public function refresh(Request $request, Device $device, CommandService $commands, OfflineProtectionService $service)
    {
        $this->authorize('control', $device);
        $commands->create($device, 'SYNC_DEVICE', ['offline_policy_refresh' => '1'], $request->user());
        $service->audit($device, 'POLICY_SENT', $service->policyFor($device), $request->user(), [], 'Dashboard policy refresh');
        return back()->with('success', 'Policy refresh requested.');
    }

    public function recalculate(Request $request, Device $device, OfflineProtectionService $service)
    {
        $this->authorize('update', $device);
        $data = $request->validate(['password' => ['required'], 'reason' => ['required', 'string', 'max:1000']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The account password is incorrect.']);
        }
        $policy = $service->policyFor($device);
        $policy->update(['offline_deadline_at' => $policy->last_verified_at?->copy()->addSeconds($policy->max_offline_seconds), 'policy_version' => $policy->policy_version + 1, 'policy_acknowledged_at' => null]);
        $service->audit($device, 'DEADLINE_RESET', $policy, $request->user(), [], $data['reason']);
        return back()->with('success', 'Deadline recalculated from the last successful verification. A new signed policy is pending.');
    }
}
