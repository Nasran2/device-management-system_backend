<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceSetupInstruction;
use App\Models\DeviceSetupSession;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\DeviceGuardApkSettings;
use App\Services\SetupInstructionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceSetupController extends Controller
{
    public const EXPECTED_OUTPUT_CONFIRMED = 'expected_output_confirmed';

    public const DIFFERENT_ERROR_OUTPUT = 'different_error_output';

    public function __construct(
        private SetupInstructionCatalog $catalog,
        private DeviceGuardApkSettings $apk,
    ) {}

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
        $setupStep = $setup->steps()->firstOrCreate(
            ['step_key' => $step->step_key],
            [
                'device_setup_instruction_id' => $step->id,
                'started_at' => now(),
                'command_result' => self::EXPECTED_OUTPUT_CONFIRMED,
            ]
        );
        if (! $setupStep->device_setup_instruction_id || ! $setupStep->started_at) {
            $setupStep->update([
                'device_setup_instruction_id' => $step->id,
                'started_at' => $setupStep->started_at ?: now(),
            ]);
        }

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
        abort_unless($setup->status === 'in_progress', 422, 'This setup session is read-only because it is no longer in progress.');
        $steps = $this->visibleSteps($setup, $this->catalog->for($setup->computer_os, $setup->brand_group));
        $data = $request->validate([
            'step_key' => ['required', 'string'],
            'direction' => ['nullable', 'in:previous,next,verify'],
            'command_result' => ['nullable', 'string', 'max:80'],
            'adb_detection_result' => ['nullable', 'in:ADB_FOUND,ADB_NOT_FOUND'],
            'confirmations' => ['nullable', 'array'],
            'confirmations.*' => ['string', 'max:80'],
            'verification_items' => ['nullable', 'array'],
            'verification_items.*' => ['integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'error_encountered' => ['nullable', 'string', 'max:2000', 'required_if:command_result,'.self::DIFFERENT_ERROR_OUTPUT.',ERROR_RECORDED'],
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
        $requiredChecklist = array_keys($step->verification_items ?: []);
        $givenChecklist = array_values(array_unique(array_map('intval', $data['verification_items'] ?? [])));
        if (array_diff($requiredChecklist, $givenChecklist)) {
            throw ValidationException::withMessages(['verification_items' => 'Complete every verification checklist item before continuing.']);
        }

        $commandResult = $data['command_result'] ?? self::EXPECTED_OUTPUT_CONFIRMED;
        if ($step->step_key === 'adb_check') {
            $adbDetectionResult = $data['adb_detection_result'] ?? null;
            if (! in_array($adbDetectionResult, ['ADB_FOUND', 'ADB_NOT_FOUND'], true)) {
                throw ValidationException::withMessages(['adb_detection_result' => 'Record exactly ADB_FOUND or ADB_NOT_FOUND from the detection command.']);
            }
            $context = $setup->context ?: [];
            $context['adb_status'] = $adbDetectionResult === 'ADB_FOUND' ? 'found' : 'missing';
            $setup->update(['context' => $context]);
            $steps = $this->visibleSteps($setup->fresh(), $this->catalog->for($setup->computer_os, $setup->brand_group));
            $index = $steps->search(fn ($item) => $item->step_key === $step->step_key);
        }
        if (! in_array($commandResult, [
            self::EXPECTED_OUTPUT_CONFIRMED,
            self::DIFFERENT_ERROR_OUTPUT,
            'EXPECTED_OUTPUT_CONFIRMED',
            'ERROR_RECORDED',
        ], true)) {
            throw ValidationException::withMessages(['command_result' => 'The command/output result is invalid.']);
        }
        if (in_array($commandResult, [self::DIFFERENT_ERROR_OUTPUT, 'ERROR_RECORDED'], true)) {
            $this->recordDifferentOutput($setup, $step, $given, $givenChecklist, $data);
            throw ValidationException::withMessages(['command_result' => 'The different/error output has been recorded. Apply the matching solution, then choose “Output fixed — confirm expected result”.']);
        }
        if ($commandResult === 'EXPECTED_OUTPUT_CONFIRMED') {
            $commandResult = self::EXPECTED_OUTPUT_CONFIRMED;
        }

        $serverPassed = $step->server_check_key ? $this->serverCheckPassed($setup->device->fresh(), $step->server_check_key) : false;
        if ($step->auto_verifiable && $step->server_check_key && ! $serverPassed) {
            throw ValidationException::withMessages(['verification' => 'Server verification is not confirmed yet. Refresh after the Android app reports this result.']);
        }

        $setup->steps()->updateOrCreate(
            ['step_key' => $step->step_key],
            [
                'device_setup_instruction_id' => $step->id,
                'started_at' => $setup->steps()->where('step_key', $step->step_key)->value('started_at') ?: now(),
                'completed' => true,
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
                'verification_method' => $serverPassed ? 'server_report' : ($step->command ? 'command_output' : 'technician_checklist'),
                'command_result' => $commandResult,
                'notes' => $data['notes'] ?? null,
                'error_encountered' => $data['error_encountered'] ?? null,
                'troubleshooting_used' => $data['troubleshooting_used'] ?? null,
                'safe_metadata' => [
                    'confirmations' => $given,
                    'verification_items' => $givenChecklist,
                    'adb_detection_result' => $data['adb_detection_result'] ?? null,
                    'server_passed_at_completion' => $serverPassed,
                ],
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
        abort_unless($request->user()->canShop('setup.manage'), 403);
        abort_unless($setup->status === 'in_progress', 410, 'This setup helper link is no longer active.');
        abort_unless($os === $setup->computer_os, 403);
        $url = $this->apk->url();
        $fileSha256 = $this->apk->fileSha256();
        $headers = [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
        if ($fileSha256 === null) {
            return response('APK file SHA-256 is not configured correctly by Super Admin.', 422, $headers);
        }
        $toolsPrefix = $os === 'macos' ? 'macos' : 'windows';
        $toolsUrl = SystemSetting::value($toolsPrefix.'_platform_tools_url') ?: SetupInstructionCatalog::platformToolsUrl($os);
        $toolsChecksum = SystemSetting::value($toolsPrefix.'_platform_tools_checksum');
        $script = $os === 'macos'
            ? $this->macScript($url, $fileSha256, $toolsUrl, $toolsChecksum)
            : $this->windowsScript($url, $fileSha256, $toolsUrl, $toolsChecksum);

        $headers['Content-Disposition'] = 'attachment; filename="deviceguard-setup.'.($os === 'macos' ? 'sh' : 'ps1').'"';

        return response($script, 200, $headers);
    }

    public function restart(Request $request, DeviceSetupSession $setup, AuditService $audit)
    {
        $this->tenant($request, $setup);
        abort_unless($request->user()->canShop('setup.manage'), 403);
        abort_unless($setup->status === 'in_progress', 422, 'Only an in-progress setup can be restarted.');
        $data = $request->validate([
            'computer_os' => ['required', 'in:macos,windows'],
            'brand_group' => ['required', 'in:'.implode(',', array_keys(SetupInstructionCatalog::BRANDS))],
            'mode' => ['required', 'in:manual_guided,setup_helper'],
            'confirmed' => ['accepted'],
        ]);

        $newSetup = DB::transaction(function () use ($setup, $data, $request) {
            $setup->update(['status' => 'cancelled']);

            return DeviceSetupSession::create([
                'uuid' => (string) Str::uuid(),
                'shop_id' => $setup->shop_id,
                'device_id' => $setup->device_id,
                'started_by' => $request->user()->id,
                'computer_os' => $data['computer_os'],
                'brand_group' => $data['brand_group'],
                'mode' => $data['mode'],
                'current_step' => 1,
                'status' => 'in_progress',
                'context' => [],
            ]);
        });
        $audit->record('SETUP_RESTARTED', 'Setup restarted with a corrected computer or phone environment', $request->user(), $setup->device, [
            'setup_id' => $setup->id, 'computer_os' => $setup->computer_os, 'brand_group' => $setup->brand_group,
        ], [
            'setup_id' => $newSetup->id, 'computer_os' => $newSetup->computer_os, 'brand_group' => $newSetup->brand_group,
        ]);

        return redirect()->route('setup.show', $newSetup)->with('success', 'A new setup was started with the corrected environment. The previous progress remains in history.');
    }

    private function visibleSteps(DeviceSetupSession $setup, $steps)
    {
        return $steps->reject(fn (DeviceSetupInstruction $step) => $step->step_key === 'adb_install' && data_get($setup->context, 'adb_status') === 'found')->values();
    }

    private function recordDifferentOutput(
        DeviceSetupSession $setup,
        DeviceSetupInstruction $step,
        array $confirmations,
        array $verificationItems,
        array $data,
    ): void {
        $setup->steps()->updateOrCreate(
            ['step_key' => $step->step_key],
            [
                'device_setup_instruction_id' => $step->id,
                'started_at' => $setup->steps()->where('step_key', $step->step_key)->value('started_at') ?: now(),
                'completed' => false,
                'completed_at' => null,
                'completed_by' => null,
                'verification_method' => 'command_output',
                'command_result' => self::DIFFERENT_ERROR_OUTPUT,
                'notes' => $data['notes'] ?? null,
                'error_encountered' => $data['error_encountered'],
                'troubleshooting_used' => $data['troubleshooting_used'] ?? null,
                'safe_metadata' => [
                    'confirmations' => $confirmations,
                    'verification_items' => $verificationItems,
                    'adb_detection_result' => $data['adb_detection_result'] ?? null,
                    'server_passed_at_completion' => false,
                ],
            ]
        );
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

    private function macScript(string $url, ?string $fileSha256, string $toolsUrl, ?string $toolsChecksum): string
    {
        $configuredHash = escapeshellarg(strtolower((string) $fileSha256));
        $checksumVerification = "EXPECTED_APK_SHA256={$configuredHash}\n".
            "printf '%s' \"\$EXPECTED_APK_SHA256\" | grep -Eq '^[0-9A-Fa-f]{64}\$' || { rm -f deviceguard.apk; printf 'APK file SHA-256 is not configured correctly by Super Admin.\\n' >&2; exit 8; }\n".
            "ACTUAL_APK_SHA256=\$(shasum -a 256 deviceguard.apk | awk '{print toupper(\$1)}')\n".
            "EXPECTED_APK_SHA256=\$(printf '%s' \"\$EXPECTED_APK_SHA256\" | tr '[:lower:]' '[:upper:]')\n".
            "printf 'Expected APK SHA-256: %s\\n' \"\$EXPECTED_APK_SHA256\"\n".
            "printf 'Actual APK SHA-256:   %s\\n' \"\$ACTUAL_APK_SHA256\"\n".
            "if [ \"\$ACTUAL_APK_SHA256\" != \"\$EXPECTED_APK_SHA256\" ]; then rm -f deviceguard.apk; printf 'APK checksum mismatch. The downloaded APK was removed for safety.\\n' >&2; exit 9; fi\n".
            "printf 'APK checksum verified successfully.\\n'\n";
        $toolsChecksumVerification = $toolsChecksum
            ? 'echo '.escapeshellarg(strtolower($toolsChecksum))."  \"\$TOOLS_ZIP\" | shasum -a 256 -c -\n"
            : "printf 'WARNING: Platform Tools checksum is not configured; using the approved Google HTTPS source.\\n'\n";
        $package = $this->apk->packageName();
        $receiver = $this->apk->receiver();

        return "#!/bin/sh\nset -eu\n".
            "printf '\\nDeviceGuard guided macOS helper\\nThis helper never resets the phone or bypasses Android security.\\n\\n'\n".
            "printf 'Before continuing: backup is complete, the phone has no accounts, USB debugging is on, and one phone is connected.\\nType YES to confirm: '; read ok; [ \"\$ok\" = YES ] || exit 1\n".
            "if ! command -v adb >/dev/null 2>&1; then\n".
            "  printf '\\n[1/7] Installing official Android Platform Tools (Homebrew is not required)...\\n'\n".
            "  TOOLS_ROOT=\"\$HOME/deviceguard-tools\"; TOOLS_ZIP=\"\$TOOLS_ROOT/platform-tools.zip\"; mkdir -p \"\$TOOLS_ROOT\"\n".
            '  curl --fail --location '.escapeshellarg($toolsUrl)." --output \"\$TOOLS_ZIP\"\n".
            "  {$toolsChecksumVerification}  rm -rf \"\$TOOLS_ROOT/platform-tools\"; ditto -x -k \"\$TOOLS_ZIP\" \"\$TOOLS_ROOT\"; chmod +x \"\$TOOLS_ROOT/platform-tools/adb\"\n".
            "  PATH_LINE='export PATH=\"\$HOME/deviceguard-tools/platform-tools:\$PATH\"'; touch \"\$HOME/.zprofile\"; grep -Fqx \"\$PATH_LINE\" \"\$HOME/.zprofile\" || printf '%s\\n' \"\$PATH_LINE\" >> \"\$HOME/.zprofile\"\n".
            "  export PATH=\"\$HOME/deviceguard-tools/platform-tools:\$PATH\"\n".
            "else printf '\\n[1/7] ADB_FOUND\\n'; fi\n".
            "adb version\n".
            "printf '\\n[2/7] Checking the connected phone...\\n'\nadb kill-server >/dev/null; adb start-server >/dev/null\n".
            "ROWS=\"\$(adb devices | sed '1d' | sed '/^[[:space:]]*\$/d')\"\nCOUNT=\$(printf '%s\\n' \"\$ROWS\" | grep -c . || true)\n[ \"\$COUNT\" -eq 1 ] || { printf 'STOP: Connect exactly one Android phone. Found %s.\\n' \"\$COUNT\"; adb devices; exit 3; }\nprintf '%s\\n' \"\$ROWS\" | grep -Eq '[[:space:]]device\$' || { printf 'STOP: Unlock the phone and accept the USB debugging fingerprint, then run the helper again.\\n'; adb devices; exit 4; }\n".
            "printf '\\n[3/7] Checking Android users and accounts...\\n'\nUSERS=\"\$(adb shell pm list users)\"; printf '%s\\n' \"\$USERS\"\nUSER_COUNT=\$(printf '%s\\n' \"\$USERS\" | grep -c 'UserInfo{' || true)\n[ \"\$USER_COUNT\" -eq 1 ] && printf '%s\\n' \"\$USERS\" | grep -q 'UserInfo{0:' || { printf 'STOP: Android must contain only primary user 0. Remove extra users manually or repeat the authorized reset.\\n'; exit 5; }\n".
            "if adb shell dumpsys account | grep -q 'Account {'; then printf 'STOP: Remove every Google/manufacturer account in phone Settings, then run the helper again.\\n'; exit 6; else echo NO_ACCOUNTS; fi\n".
            "printf '\\n[4/7] Downloading and verifying DeviceGuard...\\n'\ncd \"\$HOME/Downloads\"\nprintf 'APK URL: %s\\n' ".escapeshellarg($url)."\nif ! curl --fail --location ".escapeshellarg($url)." --output deviceguard.apk; then rm -f deviceguard.apk; printf 'APK download failed. Confirm the URL is reachable and does not return HTTP 404.\\n' >&2; exit 7; fi\n[ -s deviceguard.apk ] || { rm -f deviceguard.apk; echo 'APK download failed: DeviceGuard APK is empty.'; exit 7; }\n{$checksumVerification}".
            "printf '\\n[5/7] Installing DeviceGuard...\\n'\nadb install -r -t deviceguard.apk\nadb shell pm path {$package} | grep -q '^package:' || { echo 'STOP: DeviceGuard package verification failed.'; exit 8; }\nadb shell dumpsys package {$package} | grep -q DevicePolicyReceiver || { echo 'STOP: Device Admin receiver is missing.'; exit 9; }\n".
            "printf '\\n[6/7] Device Owner is the sensitive final computer action.\\nType SET-OWNER only after the wizard confirms authorization, primary user 0, and no accounts: '; read own; [ \"\$own\" = SET-OWNER ] || { echo 'Stopped safely before Device Owner assignment.'; exit 0; }\nadb shell dpm set-device-owner {$receiver}\nadb shell dumpsys device_policy | grep -q '{$package}' || { echo 'STOP: Android did not confirm DeviceGuard as Device Owner.'; exit 10; }\n".
            "printf '\\n[7/7] Opening DeviceGuard...\\n'\nadb shell monkey -p {$package} -c android.intent.category.LAUNCHER 1 >/dev/null\nprintf '\\nHELPER_RESULT: Local checks passed. Return to the browser wizard for activation, server checks, lock/unlock, reboot, and USB-debugging cleanup.\\n'\n";
    }

    private function windowsScript(string $url, ?string $fileSha256, ?string $toolsUrl, ?string $toolsChecksum): string
    {
        $url = str_replace("'", "''", $url);
        $toolsUrl = str_replace("'", "''", (string) $toolsUrl);
        $configuredHash = strtolower((string) $fileSha256);
        $checksumVerification = "\$ExpectedApkSha256 = '{$configuredHash}'\n".
            "\$apkPath = Join-Path (Get-Location) 'deviceguard.apk'\n".
            "if (\$ExpectedApkSha256 -notmatch '^[0-9A-Fa-f]{64}\$') { Remove-Item \$apkPath -Force -ErrorAction SilentlyContinue; throw \"APK file SHA-256 is not configured correctly by Super Admin.\" }\n".
            "\$ActualApkSha256 = (Get-FileHash \$apkPath -Algorithm SHA256).Hash.Trim().ToUpperInvariant()\n".
            "\$ExpectedApkSha256 = \$ExpectedApkSha256.Trim().ToUpperInvariant()\n".
            "Write-Host \"Expected APK SHA-256: \$ExpectedApkSha256\"\n".
            "Write-Host \"Actual APK SHA-256:   \$ActualApkSha256\"\n".
            "if (\$ActualApkSha256 -ne \$ExpectedApkSha256) { Remove-Item \$apkPath -Force -ErrorAction SilentlyContinue; throw \"APK checksum mismatch. The downloaded APK was removed for safety.\" }\n".
            "Write-Host \"APK checksum verified successfully.\" -ForegroundColor Green\n";

        $toolsChecksumVerification = $toolsChecksum
            ? "if ((Get-FileHash \$toolsZip -Algorithm SHA256).Hash.ToLower() -ne '".strtolower($toolsChecksum)."') { throw 'Platform Tools checksum mismatch. Delete the ZIP and stop.' }\n"
            : "Write-Warning 'Platform Tools checksum is not configured; using the approved Google HTTPS source.'\n";
        $package = $this->apk->packageName();
        $receiver = $this->apk->receiver();

        return "\$ErrorActionPreference = 'Stop'\n[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12\n".
            "Write-Host \"`nDeviceGuard guided Windows helper`nThis helper never resets the phone or bypasses Android security.`n\"\n".
            "if ((Read-Host 'Before continuing: backup is complete, the phone has no accounts, USB debugging is on, and one phone is connected. Type YES') -ne 'YES') { exit 1 }\n".
            "if (-not (Get-Command adb -ErrorAction SilentlyContinue)) {\n".
            "  Write-Host \"`n[1/7] Installing official Android Platform Tools (administrator access is not required)...\"\n".
            "  \$toolsRoot = Join-Path \$env:LOCALAPPDATA 'DeviceGuard'; \$toolsDir = Join-Path \$toolsRoot 'platform-tools'; \$toolsZip = Join-Path \$toolsRoot 'platform-tools.zip'\n".
            "  New-Item -ItemType Directory -Force -Path \$toolsRoot | Out-Null\n".
            "  Invoke-WebRequest -UseBasicParsing -Uri '".str_replace("'", "''", (string) $toolsUrl)."' -OutFile \$toolsZip\n{$toolsChecksumVerification}".
            "  if (Test-Path \$toolsDir) { Remove-Item \$toolsDir -Recurse -Force }; Expand-Archive \$toolsZip \$toolsRoot -Force\n".
            "  if (-not (Test-Path (Join-Path \$toolsDir 'adb.exe'))) { throw 'adb.exe was not found after extraction.' }\n".
            "  \$env:Path = \"\$toolsDir;\$env:Path\"\n".
            "  \$userPath = [string][Environment]::GetEnvironmentVariable('Path', 'User'); if ((\$userPath -split ';') -notcontains \$toolsDir) { \$newPath = if ([string]::IsNullOrWhiteSpace(\$userPath)) { \$toolsDir } else { \$userPath.TrimEnd(';') + ';' + \$toolsDir }; [Environment]::SetEnvironmentVariable('Path', \$newPath, 'User') }\n".
            "} else { Write-Host \"`n[1/7] ADB_FOUND\" }\nadb version\n".
            "Write-Host \"`n[2/7] Checking the connected phone...\"\nadb kill-server | Out-Null; adb start-server | Out-Null\n".
            "\$deviceRows = @(adb devices | Select-Object -Skip 1 | Where-Object { \$_.Trim() -ne '' })\nif (\$deviceRows.Count -ne 1) { adb devices; throw \"Connect exactly one Android phone. Found \$(\$deviceRows.Count).\" }\nif (\$deviceRows[0] -notmatch '\\sdevice\$') { adb devices; throw 'Unlock the phone and accept the USB debugging fingerprint, then run the helper again.' }\n".
            "Write-Host \"`n[3/7] Checking Android users and accounts...\"\n\$users = @(adb shell pm list users); \$users | Write-Host\n\$userRows = @(\$users | Select-String 'UserInfo\\{')\nif (\$userRows.Count -ne 1 -or \$userRows[0].Line -notmatch 'UserInfo\\{0:') { throw 'Android must contain only primary user 0. Remove extra users manually or repeat the authorized reset.' }\nif (adb shell dumpsys account | Select-String 'Account \\{') { throw 'Remove every Google/manufacturer account in phone Settings, then run the helper again.' } else { Write-Host NO_ACCOUNTS }\n".
            "Write-Host \"`n[4/7] Downloading and verifying DeviceGuard...\"\nSet-Location (Join-Path \$HOME 'Downloads')\n\$ApkUrl = '{$url}'\nWrite-Host \"APK URL: \$ApkUrl\"\ntry { Invoke-WebRequest -UseBasicParsing -Uri \$ApkUrl -OutFile \".\\deviceguard.apk\" } catch { Remove-Item \".\\deviceguard.apk\" -Force -ErrorAction SilentlyContinue; throw \"APK download failed (including possible HTTP 404): \$(\$_.Exception.Message)\" }\nif (-not (Test-Path .\\deviceguard.apk) -or (Get-Item .\\deviceguard.apk).Length -le 0) { Remove-Item \".\\deviceguard.apk\" -Force -ErrorAction SilentlyContinue; throw 'APK download failed: DeviceGuard APK is empty.' }\n{$checksumVerification}".
            "Write-Host \"`n[5/7] Installing DeviceGuard...\"\nadb install -r -t .\\deviceguard.apk\nif (-not (adb shell pm path {$package} | Select-String '^package:')) { throw 'DeviceGuard package verification failed.' }\nif (-not (adb shell dumpsys package {$package} | Select-String DevicePolicyReceiver)) { throw 'Device Admin receiver is missing.' }\n".
            "Write-Host \"`n[6/7] Device Owner is the sensitive final computer action.\"\nif ((Read-Host 'Type SET-OWNER only after the wizard confirms authorization, primary user 0, and no accounts') -ne 'SET-OWNER') { Write-Host 'Stopped safely before Device Owner assignment.'; exit 0 }\nadb shell dpm set-device-owner {$receiver}\nif (-not (adb shell dumpsys device_policy | Select-String '{$package}')) { throw 'Android did not confirm DeviceGuard as Device Owner.' }\n".
            "Write-Host \"`n[7/7] Opening DeviceGuard...\"\nadb shell monkey -p {$package} -c android.intent.category.LAUNCHER 1 | Out-Null\nWrite-Host \"`nHELPER_RESULT: Local checks passed. Return to the browser wizard for activation, server checks, lock/unlock, reboot, and USB-debugging cleanup.\"\n";
    }
}
