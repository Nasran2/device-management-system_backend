<?php

namespace App\Http\Controllers;

use App\Models\OfflineProtectionSetting;
use App\Services\OfflineProtectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class OfflineProtectionSettingsController extends Controller
{
    public function edit(Request $request)
    {
        return view('settings.offline-protection', ['settings' => OfflineProtectionSetting::current()]);
    }

    public function update(Request $request, OfflineProtectionService $service)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'], 'period_value' => ['required', 'integer', 'min:1'],
            'period_unit' => ['required', 'in:hours,days'], 'warning_notification_enabled' => ['nullable', 'boolean'],
            'first_warning_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'final_warning_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'allow_admin_override' => ['nullable', 'boolean'], 'require_password_confirmation' => ['nullable', 'boolean'],
            'password' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000'],
        ]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The account password is incorrect.']);
        }
        $seconds = $service->seconds($data['period_value'], $data['period_unit']);
        if ($data['final_warning_hours'] >= $data['first_warning_hours']) {
            throw ValidationException::withMessages(['final_warning_hours' => 'The final warning must occur after the first warning.']);
        }
        $settings = OfflineProtectionSetting::current();
        $before = $settings->toArray();
        $settings->update([
            'enabled' => $request->boolean('enabled'), 'default_period_value' => $data['period_value'],
            'default_period_unit' => $data['period_unit'], 'default_period_seconds' => $seconds,
            'warning_notification_enabled' => $request->boolean('warning_notification_enabled'),
            'first_warning_seconds' => $data['first_warning_hours'] * 3600,
            'final_warning_seconds' => $data['final_warning_hours'] * 3600,
            'allow_admin_override' => $request->boolean('allow_admin_override'),
            'require_password_confirmation' => $request->boolean('require_password_confirmation'),
            'updated_by' => $request->user()->id,
        ]);
        \App\Models\DeviceOfflinePolicy::where('uses_global_default', true)->update([
            'enabled' => $request->boolean('enabled'),
            'period_value' => $data['period_value'],
            'period_unit' => $data['period_unit'],
            'max_offline_seconds' => $seconds,
            'policy_version' => DB::raw('policy_version + 1'),
            'policy_acknowledged_at' => null,
            'updated_by' => $request->user()->id,
            'updated_at' => now(),
        ]);
        \App\Models\AuditLog::create([
            'user_id' => $request->user()->id, 'action' => 'OFFLINE_GLOBAL_POLICY_CHANGED',
            'description' => $data['reason'], 'previous_values' => $before,
            'new_values' => $settings->fresh()->toArray(), 'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
        return back()->with('success', 'Offline protection policy updated. Device deadlines change only after signed-policy acknowledgement.');
    }
}
