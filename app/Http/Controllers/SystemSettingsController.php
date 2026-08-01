<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    private const SECTIONS = ['general', 'commission', 'device', 'setup-instructions', 'roles'];

    public function edit(Request $request, string $section)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        return view('settings.system', [
            'section' => $section,
            'users' => $section === 'roles'
                ? \App\Models\User::with('shop')->latest()->paginate(25)
                : null,
        ]);
    }

    public function update(Request $request, string $section, AuditService $audit)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_unless(in_array($section, ['general', 'commission', 'device', 'setup-instructions'], true), 404);
        $request->validate(['password' => ['required', 'current_password']]);

        $rules = match ($section) {
            'general' => [
                'platform_name' => ['required', 'string', 'max:120'],
                'timezone' => ['required', 'timezone'],
                'currency' => ['required', 'string', 'size:3'],
                'support_phone' => ['nullable', 'string', 'max:30'],
                'support_email' => ['nullable', 'email'],
            ],
            'commission' => [
                'default_commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
                'minimum_commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
                'maximum_commission_percentage' => ['required', 'numeric', 'gte:minimum_commission_percentage', 'max:100'],
                'allow_custom_shop_percentage' => ['nullable', 'boolean'],
                'default_commission_basis' => ['required', 'in:selling_price_percentage,financed_balance_percentage,fixed_per_device,custom_per_device'],
                'default_settlement_allocation' => ['required', 'in:oldest_first,manual,unallocated_credit'],
            ],
            'device' => [
                'management_pin_length' => ['required', 'integer', 'between:4,8'],
                'device_command_expiry_minutes' => ['required', 'integer', 'between:1,1440'],
                'device_activation_code_expiry_minutes' => ['required', 'integer', 'between:60,10080', 'multiple_of:60'],
                'send_activation_code_by_sms' => ['nullable', 'boolean'],
                'default_lock_enabled' => ['nullable', 'boolean'],
                'default_unlock_enabled' => ['nullable', 'boolean'],
            ],
            default => [
                'macos_setup_instructions' => ['required', 'string', 'max:20000'],
                'windows_setup_instructions' => ['required', 'string', 'max:20000'],
                'setup_troubleshooting' => ['nullable', 'string', 'max:20000'],
            ],
        };

        $data = $request->validate($rules + ['password' => ['required', 'current_password']]);
        unset($data['password']);
        foreach (['allow_custom_shop_percentage', 'default_lock_enabled', 'default_unlock_enabled', 'send_activation_code_by_sms'] as $flag) {
            if (array_key_exists($flag, $rules)) {
                $data[$flag] = $request->boolean($flag);
            }
        }
        $before = [];
        foreach ($data as $key => $value) {
            $before[$key] = SystemSetting::value($key);
            SystemSetting::updateOrCreate(
                ['key' => $key],
                ['value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 'type' => is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string')]
            );
        }
        $audit->record('SYSTEM_SETTINGS_UPDATED', ucfirst(str_replace('-', ' ', $section)).' settings updated', $request->user(), null, $before, $data);

        return back()->with('success', 'Settings saved and audited.');
    }
}
