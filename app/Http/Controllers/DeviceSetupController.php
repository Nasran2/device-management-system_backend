<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceSetupInstruction;
use App\Models\DeviceSetupSession;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\SetupInstructionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceSetupController extends Controller
{
    public function __construct(private SetupInstructionCatalog $catalog) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->canShop('setup.manage'), 403);
        $sessions = DeviceSetupSession::with(['device.customer'])
            ->when(! $request->user()->isSuperAdmin(), fn ($query) => $query->where('shop_id', $request->user()->shop_id))
            ->latest()->paginate(30);
        $devices = Device::visibleTo($request->user())
            ->whereDoesntHave('setupSessions', fn ($query) => $query->where('status', 'in_progress'))
            ->with('customer')->latest()->limit(100)->get();

        return view('setup.index', [
            'sessions' => $sessions,
            'devices' => $devices,
            'oses' => SetupInstructionCatalog::OSES,
            'brands' => SetupInstructionCatalog::BRANDS,
        ]);
    }

    public function start(Request $request, Device $device, AuditService $audit)
    {
        $this->authorize('view', $device);
        abort_unless($request->user()->canShop('setup.manage'), 403);
        $data = $request->validate([
            'computer_os' => ['required', 'in:macos,windows'],
            'brand_group' => ['required', 'in:'.implode(',', array_keys(SetupInstructionCatalog::BRANDS)).',standard'],
            'mode' => ['nullable', 'in:manual_guided,setup_helper'],
            'authorized' => ['accepted'],
        ]);
        $data['brand_group'] = $this->catalog->normalizeBrand($data['brand_group']);
        $data['mode'] = $data['mode'] ?? 'manual_guided';
        $session = DeviceSetupSession::firstOrCreate(
            ['device_id' => $device->id, 'status' => 'in_progress'],
            ['uuid' => (string) Str::uuid(), 'shop_id' => $device->shop_id, 'started_by' => $request->user()->id, 'context' => []] + $data
        );
        if ($session->wasRecentlyCreated) {
            $audit->record('SETUP_STARTED', 'Authorized structured device setup started', $request->user(), $device, [], [
                'computer_os' => $data['computer_os'], 'brand_group' => $data['brand_group'], 'mode' => $data['mode'],
            ]);
        }

        return redirect()->route('setup.show', $session);
    }

    public function show(Request $request, DeviceSetupSession $setup)
    {
        $this->tenant($request, $setup);
        $setup->load(['device.tokens', 'device.offlinePolicy', 'device.commands', 'steps']);
        $allSteps = $this->catalog->for($setup->computer_os, $setup->brand_group);
        $steps = $this->visibleSteps($setup, $allSteps);
        $currentIndex = max(0, min($steps->count() - 1, (int) $setup->current_step - 1));
        $step = $steps[$currentIndex];
        $progress = $setup->steps->keyBy('step_key');
        $setup->steps()->updateOrCreate(
            ['step_key' => $step->step_key],
            ['device_setup_instruction_id' => $step->id, 'started_at' => $progress->get($step->step_key)?->started_at ?: now()]
        );

        return view('setup.show', [
            'setup' => $setup->fresh()->load(['device.tokens', 'device.offlinePolicy', 'device.commands', 'steps']),
            'steps' => $steps,
            'step' => $step,
            'currentIndex' => $currentIndex,
            'progress' => $progress,
            'serverChecks' => $this->serverChecks($setup->device),
            'helperUrl' => URL::temporarySignedRoute('setup.helper', now()->addMinutes(15), ['setup' => $setup, 'os' => $setup->computer_os]),
        ]);
    }

    public function step(Request $request, DeviceSetupSession $setup, AuditService $audit)
    {
        $this->tenant($request, $setup);
        abort_unless($request->user()->canShop('setup.manage'), 403);
        $steps = $this->visibleSteps($setup, $this->catalog->for($setup->computer_os, $setup->brand_group));
        $data = $request->validate([
            'step_key' => ['required', 'string'],
            'direction' => ['nullable', 'in:previous,next,verify'],
            'command_result' => ['nullable', 'string', 'max:80'],
            'confirmations' => ['nullable', 'array'],
            'confirmations.*' => ['string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'error_encountered' => ['nullable', 'string', 'max:2000'],
            'troubleshooting_used' => ['nullable', 'string', 'max:2000'],
        ]);
        $index = $steps->search(fn ($item) => $item->step_key === $data['step_key']);
        abort_if($index === false, 422, 'This step is not active for the selected setup.');
        $step = $steps[$index];

        if (($data['direction'] ?? 'next') === 'previous') {
            $setup->update(['current_step' => max(1, $index)]);
            return redirect()->route('setup.show', $setup);
        }

        $requiredConfirmations = $step->confirmation_items ?: [];
        $given = $data['confirmations'] ?? [];
        if (array_diff($requiredConfirmations, $given)) {
            throw ValidationException::withMessages(['confirmations' => 'Complete every required confirmation before continuing.']);
        }

        if ($step->step_key === 'adb_check') {
            if (! in_array($data['command_result'] ?? null, ['ADB_FOUND', 'ADB_NOT_FOUND'], true)) {
                throw ValidationException::withMessages(['command_result' => 'Record exactly ADB_FOUND or ADB_NOT_FOUND from the detection command.']);
            }
            $context = $setup->context ?: [];
            $context['adb_status'] = $data['command_result'] === 'ADB_FOUND' ? 'found' : 'missing';
            $setup->update(['context' => $context]);
            $steps = $this->visibleSteps($setup->fresh(), $this->catalog->for($setup->computer_os, $setup->brand_group));
            $index = $steps->search(fn ($item) => $item->step_key === $step->step_key);
        }
        if ($step->command && ! $step->auto_verifiable && $step->step_key !== 'adb_check' && blank($data['command_result'] ?? null)) {
            throw ValidationException::withMessages(['command_result' => 'Record the command/output result before continuing.']);
        }
        if (($data['command_result'] ?? null) === 'ERROR_RECORDED') {
            throw ValidationException::withMessages(['command_result' => 'The different/error output has been recorded. Apply the matching solution and verify the expected output before continuing.']);
        }

        $serverPassed = $step->server_check_key ? $this->serverCheckPassed($setup->device->fresh(), $step->server_check_key) : false;
        if ($step->auto_verifiable && ! $serverPassed && ! in_array($step->server_check_key, ['device_owner'], true)) {
            throw ValidationException::withMessages(['verification' => 'Server verification is not confirmed yet. Refresh after the Android app reports this result.']);
        }
        if ($step->step_key === 'device_owner_verify' && ! $serverPassed && ($data['command_result'] ?? null) !== 'ANDROID_CONFIRMED') {
            throw ValidationException::withMessages(['command_result' => 'Run dumpsys device_policy and record ANDROID_CONFIRMED only when Android lists the exact DeviceGuard owner component.']);
        }

        $setup->steps()->updateOrCreate(
            ['step_key' => $step->step_key],
            [
                'device_setup_instruction_id' => $step->id,
                'started_at' => $setup->steps()->where('step_key', $step->step_key)->value('started_at') ?: now(),
                'completed' => true,
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
                'verification_method' => $serverPassed ? 'server_report' : (filled($data['command_result'] ?? null) ? 'command_output' : 'technician_checklist'),
                'command_result' => $data['command_result'] ?? null,
                'notes' => $data['notes'] ?? null,
                'error_encountered' => $data['error_encountered'] ?? null,
                'troubleshooting_used' => $data['troubleshooting_used'] ?? null,
                'safe_metadata' => ['confirmations' => $given, 'server_passed_at_completion' => $serverPassed],
            ]
        );

        $next = min($steps->count(), $index + 2);
        $setup->update(['current_step' => $next]);
        if ($index === $steps->count() - 1) {
            $checks = $this->serverChecks($setup->device->fresh());
            $required = collect($checks)->where('required', true);
            $completedKeys = $setup->steps()->where('completed', true)->pluck('step_key');
            $requiredStepKeys = $steps->where('required', true)->pluck('step_key');
            if ($required->contains(fn ($check) => ! $check['ok']) || $requiredStepKeys->diff($completedKeys)->isNotEmpty()) {
                $setup->update(['current_step' => $steps->count()]);
                throw ValidationException::withMessages(['final' => 'Setup remains in progress. Every required step and every live server verification must be confirmed.']);
            }
            $setup->update(['status' => 'completed', 'completed_by' => $request->user()->id, 'completed_at' => now()]);
            $audit->record('SETUP_COMPLETED', 'Structured device setup completed with server verification', $request->user(), $setup->device);
        }

        return redirect()->route('setup.show', $setup)->with('success', 'Step verified and progress saved.');
    }

    public function helper(Request $request, DeviceSetupSession $setup, string $os)
    {
        abort_unless($request->hasValidSignature(), 403);
        $this->tenant($request, $setup);
        abort_unless($os === $setup->computer_os, 403);
        $url = SystemSetting::value('provisioning_apk_url');
        $checksum = SystemSetting::value('provisioning_apk_checksum');
        abort_unless($url && str_starts_with($url, 'https://'), 422, 'A trusted HTTPS APK URL must be configured.');
        $script = $os === 'macos'
            ? $this->macScript($url, $checksum)
            : $this->windowsScript($url, $checksum, SystemSetting::value('windows_platform_tools_url'), SystemSetting::value('windows_platform_tools_checksum'));

        return response($script)->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="deviceguard-setup.'.($os === 'macos' ? 'sh' : 'ps1').'"');
    }

    private function visibleSteps(DeviceSetupSession $setup, $steps)
    {
        return $steps->reject(fn (DeviceSetupInstruction $step) => $step->step_key === 'adb_install' && data_get($setup->context, 'adb_status') === 'found')->values();
    }

    private function serverChecks(Device $device): array
    {
        $completedLock = $device->commands->first(fn ($c) => $c->type === 'LOCK_DEVICE' && $c->status === 'completed');
        $completedUnlock = $device->commands->first(fn ($c) => $c->type === 'UNLOCK_DEVICE' && $c->status === 'completed');
        $recentSync = $device->last_sync_at && $device->last_sync_at->gt(now()->subMinutes(15));
        return [
            ['key' => 'activation', 'label' => 'App activation', 'ok' => filled($device->device_uuid) && $device->tokens->isNotEmpty(), 'detail' => filled($device->device_uuid) ? 'Authenticated device identity received' : 'Waiting for Android activation', 'required' => true],
            ['key' => 'device_owner', 'label' => 'Device Owner', 'ok' => (bool) $device->is_device_owner, 'detail' => $device->is_device_owner ? 'Android reported Device Owner=true' : 'Waiting for Android report', 'required' => true],
            ['key' => 'admin', 'label' => 'Device Admin', 'ok' => (bool) $device->is_admin_active, 'detail' => $device->is_admin_active ? 'Admin receiver active' : 'Admin receiver not confirmed', 'required' => true],
            ['key' => 'fcm', 'label' => 'FCM token', 'ok' => filled($device->fcm_token), 'detail' => filled($device->fcm_token) ? 'Push token received' : 'Token missing', 'required' => true],
            ['key' => 'uninstall', 'label' => 'Uninstall protection', 'ok' => (bool) $device->can_block_uninstall, 'detail' => $device->can_block_uninstall ? 'Confirmed' : 'Needs attention', 'required' => true],
            ['key' => 'reset', 'label' => 'Reset protection', 'ok' => (bool) $device->can_block_reset, 'detail' => $device->can_block_reset ? 'Confirmed' : 'Needs attention', 'required' => true],
            ['key' => 'full_lock', 'label' => 'Full lock', 'ok' => (bool) $device->can_full_lock, 'detail' => $device->can_full_lock ? 'Confirmed' : 'Needs attention', 'required' => true],
            ['key' => 'lock_task', 'label' => 'Lock task permitted', 'ok' => (bool) ($device->is_lock_task_permitted || $device->can_use_lock_task), 'detail' => ($device->is_lock_task_permitted || $device->can_use_lock_task) ? 'Confirmed' : 'Needs attention', 'required' => true],
            ['key' => 'sync', 'label' => 'Recent sync', 'ok' => (bool) $recentSync, 'detail' => $device->last_sync_at ? $device->last_sync_at->diffForHumans() : 'Never synced', 'required' => true],
            ['key' => 'offline', 'label' => 'Offline policy', 'ok' => (bool) $device->offlinePolicy?->policy_acknowledged_at, 'detail' => $device->offlinePolicy?->policy_acknowledged_at ? 'Acknowledged '.$device->offlinePolicy->policy_acknowledged_at->diffForHumans() : 'Acknowledgement pending', 'required' => true],
            ['key' => 'lock', 'label' => 'Lock test', 'ok' => (bool) $completedLock, 'detail' => $completedLock ? 'Completed command #'.$completedLock->id : 'No completed lock command', 'required' => true],
            ['key' => 'unlock', 'label' => 'Unlock test', 'ok' => (bool) $completedUnlock, 'detail' => $completedUnlock ? 'Completed command #'.$completedUnlock->id : 'No completed unlock command', 'required' => true],
        ];
    }

    private function serverCheckPassed(Device $device, string $key): bool
    {
        $checks = collect($this->serverChecks($device->loadMissing(['tokens', 'commands', 'offlinePolicy'])));
        return match ($key) {
            'capabilities' => $checks->whereIn('key', ['activation', 'device_owner', 'admin', 'fcm', 'uninstall', 'reset', 'full_lock', 'lock_task', 'sync', 'offline'])->every(fn ($check) => $check['ok']),
            'final' => $checks->where('required', true)->every(fn ($check) => $check['ok']),
            'lock_unlock' => $checks->whereIn('key', ['lock', 'unlock'])->every(fn ($check) => $check['ok']),
            default => (bool) data_get($checks->firstWhere('key', $key), 'ok'),
        };
    }

    private function tenant(Request $request, DeviceSetupSession $session): void
    {
        abort_unless($request->user()->isSuperAdmin() || $session->shop_id === $request->user()->shop_id, 403);
    }

    private function macScript(string $url, ?string $checksum): string
    {
        return "#!/bin/sh\nset -eu\nprintf 'AUTHORIZED setup only. Type YES to continue: '; read ok; [ \"\$ok\" = YES ] || exit 1\nif command -v adb >/dev/null 2>&1; then echo ADB_FOUND; else echo ADB_NOT_FOUND; command -v brew >/dev/null || { echo 'Install official Platform Tools first.'; exit 2; }; brew install android-platform-tools; fi\nadb version\nadb kill-server; adb start-server; adb devices\nadb shell pm list users\nadb shell dumpsys account | grep 'Account {' || echo NO_ACCOUNTS\ncurl -fL ".escapeshellarg($url)." -o deviceguard.apk\n".($checksum ? "echo ".escapeshellarg($checksum)."  deviceguard.apk | shasum -a 256 -c -\n" : '')."adb install -r -t deviceguard.apk\nadb shell pm path com.twinsofte.deviceguard\nadb shell dumpsys package com.twinsofte.deviceguard | grep DevicePolicyReceiver\nprintf 'Checks clean and Device Owner authorized? Type SET-OWNER: '; read own; [ \"\$own\" = SET-OWNER ] || exit 0\nadb shell dpm set-device-owner com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver\nadb shell dumpsys device_policy\nadb shell monkey -p com.twinsofte.deviceguard -c android.intent.category.LAUNCHER 1\nprintf 'HELPER_RESULT: commands finished. Android and server verification are still required in the wizard.\\n'\n";
    }

    private function windowsScript(string $url, ?string $checksum, ?string $toolsUrl, ?string $toolsChecksum): string
    {
        $url = str_replace("'", "''", $url);
        $toolsUrl = str_replace("'", "''", (string) $toolsUrl);
        return "\$ErrorActionPreference='Stop'\nif ((Read-Host 'AUTHORIZED setup only. Type YES to continue') -ne 'YES') { exit 1 }\nif ((Get-Command adb -ErrorAction SilentlyContinue) -or (Test-Path 'C:\\platform-tools\\adb.exe')) { Write-Host ADB_FOUND } else { Write-Host ADB_NOT_FOUND\n".($toolsUrl && $toolsChecksum ? "Invoke-WebRequest -Uri '$toolsUrl' -OutFile platform-tools.zip\nif ((Get-FileHash platform-tools.zip -Algorithm SHA256).Hash.ToLower() -ne '".strtolower($toolsChecksum)."') { throw 'Platform Tools checksum mismatch' }\nExpand-Archive platform-tools.zip C:\\ -Force\n" : "throw 'Configure the official Platform Tools URL and SHA-256 in Super Admin settings.'\n")."}\nSet-Location C:\\platform-tools\n.\\adb.exe version\n.\\adb.exe kill-server; .\\adb.exe start-server; .\\adb.exe devices\n.\\adb.exe shell pm list users\n.\\adb.exe shell dumpsys account | Select-String 'Account \\{'\nInvoke-WebRequest -Uri '$url' -OutFile deviceguard.apk\n".($checksum ? "if ((Get-FileHash deviceguard.apk -Algorithm SHA256).Hash.ToLower() -ne '".strtolower($checksum)."') { throw 'APK checksum mismatch' }\n" : '').".\\adb.exe install -r -t deviceguard.apk\n.\\adb.exe shell pm path com.twinsofte.deviceguard\n.\\adb.exe shell dumpsys package com.twinsofte.deviceguard | Select-String DevicePolicyReceiver\nif ((Read-Host 'Checks clean and Device Owner authorized? Type SET-OWNER') -eq 'SET-OWNER') { .\\adb.exe shell dpm set-device-owner com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver; .\\adb.exe shell dumpsys device_policy; .\\adb.exe shell monkey -p com.twinsofte.deviceguard -c android.intent.category.LAUNCHER 1 }\nWrite-Host 'HELPER_RESULT: commands finished. Android and server verification are still required in the wizard.'\n";
    }
}
