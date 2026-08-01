<?php

namespace App\Services;

use App\Models\DeviceSetupInstruction;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SetupInstructionCatalog
{
    public const WINDOWS_PLATFORM_TOOLS_URL = 'https://dl.google.com/android/repository/platform-tools-latest-windows.zip';

    public const MACOS_PLATFORM_TOOLS_URL = 'https://dl.google.com/android/repository/platform-tools-latest-darwin.zip';

    public const OSES = ['windows' => 'Windows', 'macos' => 'macOS'];

    public const BRANDS = [
        'samsung' => 'Samsung',
        'xiaomi' => 'Xiaomi / Redmi / POCO',
        'oppo' => 'OPPO / Realme / OnePlus',
        'vivo' => 'Vivo / iQOO',
        'pixel' => 'Google Pixel',
        'nokia_motorola' => 'Nokia / Motorola',
        'transsion' => 'Tecno / Infinix',
        'other' => 'Other Android',
    ];

    public function __construct(private DeviceGuardApkSettings $apk) {}

    public function for(string $os, string $brand): Collection
    {
        $brand = $this->normalizeBrand($brand);
        if (! DeviceSetupInstruction::where('computer_os', $os)->where('phone_brand', $brand)->exists()) {
            $this->syncDefaults($os, $brand);
        }
        $this->refreshSystemManagedDefaults($os, $brand);
        $this->refreshApkInstallInstruction($os, $brand);

        return DeviceSetupInstruction::where('computer_os', $os)
            ->where('phone_brand', $brand)->where('active', true)
            ->orderBy('display_order')->get();
    }

    public function syncDefaults(string $os, string $brand, ?int $userId = null, bool $overwrite = false): void
    {
        abort_unless(isset(self::OSES[$os]), 422, 'Unsupported computer OS.');
        $brand = $this->normalizeBrand($brand);
        abort_unless(isset(self::BRANDS[$brand]), 422, 'Unsupported phone brand group.');

        DB::transaction(function () use ($os, $brand, $userId, $overwrite) {
            foreach ($this->defaults($os, $brand) as $step) {
                $match = ['computer_os' => $os, 'phone_brand' => $brand, 'step_key' => $step['step_key']];
                $existing = DeviceSetupInstruction::where($match)->first();
                if ($existing && ! $overwrite) {
                    continue;
                }
                DeviceSetupInstruction::updateOrCreate($match, $step + [
                    'created_by' => $existing?->created_by ?: $userId,
                    'updated_by' => $userId,
                ]);
            }
        });
    }

    public function normalizeBrand(string $brand): string
    {
        return match ($brand) {
            'standard' => 'nokia_motorola',
            default => $brand,
        };
    }

    public static function platformToolsUrl(string $os): string
    {
        return $os === 'windows' ? self::WINDOWS_PLATFORM_TOOLS_URL : self::MACOS_PLATFORM_TOOLS_URL;
    }

    private function refreshSystemManagedDefaults(string $os, string $brand): void
    {
        foreach ($this->defaults($os, $brand) as $default) {
            $current = DeviceSetupInstruction::where('computer_os', $os)
                ->where('phone_brand', $brand)
                ->where('step_key', $default['step_key'])
                ->whereNull('updated_by')
                ->first();
            if ($current) {
                $current->fill(collect($default)->except(['computer_os', 'phone_brand', 'created_by', 'updated_by'])->all());
                if ($current->isDirty()) {
                    $current->save();
                }
            }
        }
    }

    private function refreshApkInstallInstruction(string $os, string $brand): void
    {
        $current = DeviceSetupInstruction::where('computer_os', $os)
            ->where('phone_brand', $brand)
            ->where('step_key', 'apk_install')
            ->first();
        $default = collect($this->defaults($os, $brand))->firstWhere('step_key', 'apk_install');

        if (! $current || ! $default) {
            return;
        }

        $fields = [
            'short_description',
            'why_required',
            'action_location',
            'numbered_instructions',
            'shell_type',
            'command',
            'run_from',
            'expected_output',
            'verification_items',
        ];
        $updates = collect($default)->only($fields)->all();

        if (collect($fields)->contains(fn (string $field) => $current->{$field} !== $updates[$field])) {
            $current->update($updates);
        }
    }

    private function defaults(string $os, string $brand): array
    {
        $isWin = $os === 'windows';
        $adb = 'adb';
        $shell = $isWin ? 'PowerShell' : 'Terminal (zsh)';
        $folder = $isWin ? 'Any PowerShell window after Platform Tools setup' : 'Any Terminal window after Platform Tools setup';
        $apkUrl = $this->apk->url();
        $apkChecksum = $this->apk->checksum();
        $toolsSettingPrefix = $isWin ? 'windows' : 'macos';
        $toolsUrl = SystemSetting::value($toolsSettingPrefix.'_platform_tools_url') ?: self::platformToolsUrl($os);
        $toolsChecksum = SystemSetting::value($toolsSettingPrefix.'_platform_tools_checksum');
        $brandName = self::BRANDS[$brand];
        $brandGuide = $this->brandGuide($brand);
        $commonAdbErrors = [
            $this->error('ADB_NOT_FOUND / command not recognized', 'Android Platform Tools is missing or this terminal has not reloaded its PATH.', 'Complete the official Platform Tools step. Then close and reopen PowerShell or Terminal and run adb version again.'),
            $this->error('unauthorized', 'The phone has not trusted this computer’s USB debugging fingerprint.', 'Unlock the phone, accept the RSA prompt, reconnect, and run the command again. If needed, revoke USB debugging authorizations in Developer options.'),
            $this->error('offline or no device', 'The cable, USB mode, driver, authorization, or ADB server is not ready.', 'Use a data-capable cable, try another direct USB port, select File transfer, restart ADB, and apply only this brand’s driver guidance.'),
        ];

        $raw = [
            $this->step('authorization', 'Confirm authorization, customer agreement, backup, and reset readiness',
                'This setup can factory-reset the phone and grant DeviceGuard Device Owner control.',
                'Before touching the phone or computer',
                ['Confirm the shop owns the phone or has written authority to manage it.', 'Confirm the customer accepted the DeviceGuard management terms.', 'Confirm photos, contacts, authenticator data, and other required content are backed up.', 'Confirm you understand that a factory reset erases the phone and that the wizard never performs it automatically.'],
                'All four confirmations are checked and the authorized work order is available.',
                [$this->error('A confirmation cannot be made', 'Authorization, consent, backup, or reset readiness is incomplete.', 'Stop setup. Resolve the missing authorization or backup with the customer; do not continue.')],
                ['Shop authorization confirmed', 'Customer agreement confirmed', 'Required backup confirmed', 'Factory-reset impact understood'],
                ['authorization', 'agreement', 'backup', 'reset_ack']),

            $this->step('computer_os', 'Identify the setup computer and record its operating system',
                'The commands and USB driver requirements differ between Windows and macOS.',
                $isWin ? 'Windows PowerShell' : 'Apple menu and Terminal',
                $isWin ? ['Click Start, type PowerShell, and open Windows PowerShell. Do not use Command Prompt for PowerShell commands.', 'Copy the complete command block with the Copy command button.', 'Click once inside PowerShell, right-click to paste, then press Enter.', 'Confirm Caption, Version, and OSArchitecture are displayed.'] : ['Open Apple menu › About This Mac and note the macOS version.', 'Press Command + Space, type Terminal, and press Return.', 'Copy the complete command block, paste it into Terminal with Command + V, then press Return.', 'Confirm ProductName, ProductVersion, and BuildVersion are displayed.'],
                $isWin ? 'Windows edition, version, and 64-bit/32-bit architecture are displayed.' : 'A macOS product version such as 14.x or 15.x is displayed.',
                [$this->error('Command unavailable', 'The selected setup computer does not match the OS chosen for this session.', 'Return to setup history and start the correct OS variant, or open the correct terminal application.')],
                ['Operating system matches this wizard', 'Version and architecture recorded'],
                [], $shell, $isWin ? 'Get-CimInstance Win32_OperatingSystem | Select-Object Caption, Version, OSArchitecture' : 'sw_vers', $isWin ? 'Any PowerShell folder' : 'Any Terminal folder'),

            $this->step('adb_check', 'Check whether Android Debug Bridge (ADB) is already available',
                'ADB is required for USB detection, APK installation, Device Owner assignment, and verification.',
                $shell,
                $isWin ? ['Open PowerShell from Start.', 'Copy and paste the complete detection command, then press Enter.', 'Select exactly the result shown: ADB_FOUND or ADB_NOT_FOUND.', 'If ADB_FOUND, the installation step is skipped and every later command uses adb from PATH.'] : ['Open Terminal with Command + Space, type Terminal, and press Return.', 'Copy and paste the complete detection command, then press Return.', 'Select exactly the result shown: ADB_FOUND or ADB_NOT_FOUND.', 'If ADB_FOUND, the installation step is skipped and every later command uses adb from PATH.'],
                'The command prints exactly ADB_FOUND or ADB_NOT_FOUND.',
                $commonAdbErrors,
                ['Detection result recorded', 'ADB installation is not repeated when already available'],
                [], $shell, $isWin ? "if (Get-Command adb -ErrorAction SilentlyContinue) { 'ADB_FOUND' } else { 'ADB_NOT_FOUND' }" : 'if command -v adb >/dev/null 2>&1; then echo ADB_FOUND; else echo ADB_NOT_FOUND; fi', $isWin ? 'Any PowerShell folder' : 'Any Terminal folder', false, 'adb_detection'),

            $this->step('adb_install', 'Install official Android Platform Tools only when ADB is missing',
                'Using Google’s official tools avoids modified ADB binaries and provides the commands required by the remaining steps.',
                $shell,
                $isWin
                    ? ["Keep PowerShell open. The command downloads Google Platform Tools from: {$toolsUrl}", 'Copy the entire command block, paste it once, and press Enter. It installs under your Windows user profile and does not require an administrator account.', 'If a checksum is configured, the command stops automatically when it does not match.', 'Wait for Android Debug Bridge version to appear.', 'Close and reopen PowerShell after this step so the saved PATH is available in future windows.']
                    : ["Keep Terminal open. The command downloads Google Platform Tools from: {$toolsUrl}", 'Copy the entire command block, paste it once, and press Return. Homebrew is not required.', 'If a checksum is configured, the command stops automatically when it does not match.', 'The tools are installed in ~/deviceguard-tools/platform-tools and safely added to ~/.zprofile.', 'Wait for Android Debug Bridge version to appear, then close and reopen Terminal.'],
                'Android Debug Bridge version information is displayed. Do not continue if the archive checksum mismatches.',
                [$this->error('Checksum mismatch', 'The downloaded archive is corrupted or not the approved file.', 'Delete the archive, download again from the configured official URL, and compare the SHA-256. Stop if it still differs.'), $commonAdbErrors[0]],
                ['Official Google HTTPS source used', 'Checksum verified automatically when configured', 'ADB version displayed', 'PowerShell or Terminal reopened after installation'],
                [], $shell, $this->platformToolsInstallCommand($isWin, $toolsUrl, $toolsChecksum), $isWin ? 'Any PowerShell folder' : 'Any Terminal folder'),

            $isWin
                ? $this->step('usb_driver', "Install or verify the official {$brandName} Windows USB driver",
                    'Windows may require a manufacturer USB driver before ADB can identify the phone. macOS never receives this step.',
                    'Manufacturer support site and Windows Device Manager',
                    ['Open Google’s official OEM USB driver directory: https://developer.android.com/studio/run/oem-usb', $brandGuide['driver'], 'Install a driver only from the manufacturer’s official support site linked by Google or from the manufacturer itself.', 'Connect the phone with a data-capable cable.', 'Right-click Start, choose Device Manager, and expand Portable Devices, Android Device, or Other devices.', 'Confirm the phone/Android ADB interface has no yellow warning icon, then run the PowerShell check below.'],
                    'A phone or Android ADB interface appears without an Unknown device or warning icon.',
                    [$this->error('Unknown USB device / warning icon', 'Windows has no suitable driver, the cable is power-only, or the USB mode is wrong.', 'Reconnect using a data cable, choose File transfer, install only the official manufacturer driver, then rescan hardware.'), $this->error('No matching PnP device', 'Windows has not enumerated the connected phone.', 'Try another direct USB port and cable, unlock the phone, then check Device Manager again.')],
                    ['Official driver guidance followed', 'No Device Manager warning icon', 'Windows detects the phone'],
                    [], $shell, "Get-PnpDevice | Where-Object { \$_.FriendlyName -match 'Android|ADB|{$brandName}' } | Format-Table Status, Class, FriendlyName", 'Any PowerShell folder')
                : $this->step('mac_usb_preflight', 'Prepare the Mac, USB cable, and direct connection',
                    'macOS does not need an Android USB driver, but charge-only cables, hubs, accessory restrictions, and damaged adapters can prevent detection.',
                    'Mac, USB cable, and phone',
                    ['Use a known data-capable USB cable, not a charge-only cable.', 'Connect the phone directly to the Mac when possible; avoid an unpowered hub.', 'Unlock the phone. If macOS asks to allow the USB accessory, choose Allow.', 'On the phone choose File transfer / Android Auto when the USB-use menu appears.', 'Run the USB report command and look for the phone manufacturer or an Android device.'],
                    'The phone appears in the Mac USB report. No Windows driver installation is required.',
                    [$this->error('Phone is absent from the USB report', 'The cable, adapter, port, hub, or Mac accessory permission is blocking the physical connection.', 'Try a verified data cable and another direct port, remove the hub, unlock the phone, choose File transfer, and approve the accessory prompt.'), $this->error('Phone charges but is not detected', 'The cable may carry power only or the phone is in Charge only mode.', 'Replace the cable and choose File transfer / Android Auto from the phone USB notification.')],
                    ['Data-capable cable confirmed', 'Direct USB connection used', 'Phone appears in the Mac USB report'],
                    [], $shell, "system_profiler SPUSBDataType | grep -i -E 'Android|{$brandName}' || echo PHONE_NOT_VISIBLE", 'Any Terminal folder'),

            $this->step('factory_reset', "Factory-reset and prepare the {$brandName} phone without accounts",
                'Device Owner assignment normally requires a clean device with only the system user and no accounts.',
                "{$brandName} phone",
                [$brandGuide['reset'], 'Read the erase warning and perform the reset on the phone only after authorization.', 'During first-run setup choose Set up as new and skip copy/restore.', 'Skip Google sign-in and skip any Samsung, Mi, HeyTap, Vivo, or other manufacturer account.', 'Connect Wi-Fi only when needed; reach the home screen.'],
                'The phone reaches the home screen as a new device with no Google or manufacturer account.',
                [$this->error('Factory Reset Protection requests a previous account', 'FRP ownership verification is active after reset.', 'The legitimate owner must sign in with the previously synced account. Never bypass FRP.'), $this->error('An account was added', 'Device Owner assignment can be rejected.', 'Remove it manually in Settings or repeat the authorized reset and skip all accounts.')],
                ['Reset was performed on the phone, not automatically', 'Set up as new selected', 'All account sign-ins skipped']),

            $this->step('developer_options', "Enable Developer options on the {$brandName} phone",
                'USB debugging is hidden until Developer options is enabled.',
                "{$brandName} phone Settings",
                [$brandGuide['developer'], 'Tap the build/version entry seven times.', 'Enter the phone PIN if prompted.', 'Confirm the “Developer mode has been turned on” message.'],
                'The phone confirms Developer mode and a Developer options menu becomes available.',
                [$this->error('Build number is not visible', 'This brand places the version entry in a different submenu.', $brandGuide['developer_help']), $this->error('Blocked by policy', 'An existing owner or work policy controls the phone.', 'Stop and remove the legitimate prior management through its supported offboarding process; do not bypass policy.')],
                ['Developer mode confirmation shown', 'Developer options menu is available']),

            $this->step('usb_debugging', 'Enable USB debugging and keep the phone unlocked',
                'USB debugging authorizes ADB commands from the setup computer.',
                "{$brandName} phone › Developer options",
                ['Open Developer options.', 'Turn on USB debugging.', 'Read and accept the phone’s warning.', 'Keep the phone unlocked and screen on.', $brandGuide['usb']],
                'USB debugging is on and the phone is ready to show the computer fingerprint prompt.',
                [$this->error('USB debugging switch immediately turns off', 'A security feature or device policy is preventing debugging.', $brandGuide['usb_help'])],
                ['USB debugging enabled', 'Phone remains unlocked']),

            $this->step('adb_connect', 'Connect, authorize, and confirm exactly one ADB device',
                'The correct phone must be uniquely identified before any installation or ownership command is run.',
                "{$shell} and {$brandName} phone",
                ['Connect directly with a data-capable USB cable.', 'On the phone choose File transfer if a USB-mode prompt appears.', 'Run kill-server, start-server, then devices.', 'Accept this computer’s RSA fingerprint on the phone and optionally select Always allow.', 'Run devices again and confirm exactly one serial ends with device.'],
                "The final list contains one line like SERIAL_NUMBER\tdevice.",
                $commonAdbErrors,
                ['Exactly one serial is listed', 'Status is device, not unauthorized/offline', 'Serial belongs to the phone being set up'],
                [], $shell, "{$adb} kill-server\n{$adb} start-server\n{$adb} devices", $folder),

            $this->step('users_check', 'Verify Android has only the primary system user',
                'Secondary users and profiles can block Device Owner assignment or create an unsafe target.',
                $shell,
                ['Run the user-list command.', 'Read every UserInfo line.', 'Continue only when the sole user is user 0 (Owner).', 'If another user/profile exists, remove it manually in Android Settings or repeat the authorized reset.'],
                'Only UserInfo{0:Owner...} is listed.',
                [$this->error('User 10, Guest, work profile, or another user is listed', 'The phone contains a secondary user or managed profile.', 'Remove it through Settings › System › Multiple users / Accounts, or factory-reset with authorization. Never delete users automatically.')],
                ['Only user 0 exists', 'No guest or work profile exists'],
                [], $shell, "{$adb} shell pm list users", $folder),

            $this->step('accounts_check', 'Verify Android contains no Google or manufacturer accounts',
                'Accounts are a common reason Android rejects Device Owner assignment.',
                $shell,
                ['Run the account command.', 'Inspect any line containing Account {.', 'Continue only when no Account { entries are returned.', 'If accounts exist, remove them manually in phone Settings and run the check again.'],
                'No Account { entries are returned.',
                [$this->error('Account {name=..., type=...} appears', 'At least one Android account is registered.', 'Remove every account manually under Settings › Accounts. If removal is unavailable, repeat the authorized reset and skip sign-in.')],
                ['No Account { entries', 'Account result recorded'],
                [], $shell, $isWin ? "{$adb} shell dumpsys account | Select-String 'Account \\{'" : "{$adb} shell dumpsys account | grep 'Account {' || echo NO_ACCOUNTS", $folder),

            $this->step('apk_install', 'Download, verify, and install the configured DeviceGuard APK',
                $apkChecksum
                    ? 'Only the Super Admin-approved HTTPS APK should be installed. Verify its configured SHA-256 before installation.'
                    : 'Only the Super Admin-approved HTTPS APK should be installed. APK checksum verification is not configured by Super Admin, so installation may continue with the visible warning.',
                $shell,
                array_values(array_filter([
                    "Use only the centrally configured APK URL: {$apkUrl}",
                    $isWin ? 'Open PowerShell. The command changes safely to your Downloads folder.' : 'Open Terminal. The command changes safely to ~/Downloads.',
                    'Download deviceguard.apk and verify that the file exists and has a size greater than zero.',
                    $apkChecksum
                        ? 'Calculate SHA-256 and compare it with the saved expected checksum before installation.'
                        : 'APK checksum verification is not configured by Super Admin. Continue only with the approved HTTPS URL.',
                    'Install with ADB, confirm Success, then verify the exact DeviceGuard package path.',
                ])),
                "The download exists, ADB prints:\nPerforming Streamed Install\nSuccess\n\nPackage verification returns:\npackage:/data/app/.../com.twinsofte.deviceguard.../base.apk",
                [$this->error('INSTALL_FAILED_USER_RESTRICTED', 'The phone denied USB installation or a brand security control requires approval.', $brandGuide['install_help']), $this->error('INSTALL_FAILED_UPDATE_INCOMPATIBLE / signature mismatch', 'A differently signed DeviceGuard build is already installed.', 'Stop. Confirm the package is legitimate, preserve required data, then uninstall only with authorization or use the matching signed APK.'), $this->error('Checksum mismatch', 'The APK does not match the configured release.', 'Delete it, download again from the configured HTTPS URL, and stop if the checksum still differs.')],
                array_values(array_filter([
                    'Approved HTTPS URL used',
                    'Downloaded deviceguard.apk exists and is not empty',
                    $apkChecksum ? 'SHA-256 matches the saved expected checksum' : null,
                    'ADB installation reports Success',
                    'Exact DeviceGuard package path returned',
                ])),
                [], $shell, $this->apkInstallCommand($isWin, $apkUrl, $apkChecksum), $isWin ? 'Windows Downloads folder' : '~/Downloads'),

            $this->step('package_receiver', 'Verify the DeviceGuard package and Device Admin receiver',
                'Device Owner can only be assigned when the exact production package and receiver are installed.',
                $shell,
                ['Run the package-path command.', 'Confirm package:/data/app/.../com.twinsofte.deviceguard... is returned.', 'Run the receiver query.', 'Confirm com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver appears.'],
                'The package path and DevicePolicyReceiver component are both present.',
                [$this->error('Package path is empty', 'The APK is not installed for the primary user.', 'Return to APK installation, resolve its reported error, and verify again.'), $this->error('Receiver not found', 'The APK build lacks the required receiver or the component name differs.', 'Stop and obtain the approved DeviceGuard build. Do not guess a component name.')],
                ['Package path returned', 'Exact receiver component returned'],
                [], $shell, "{$adb} shell pm path com.twinsofte.deviceguard\n{$adb} shell dumpsys package com.twinsofte.deviceguard | ".($isWin ? "Select-String 'DevicePolicyReceiver'" : 'grep DevicePolicyReceiver'), $folder),

            $this->step('device_owner', 'Assign DeviceGuard as Android Device Owner',
                'Device Owner is required for uninstall protection, reset protection, full lock, lock task, and offline enforcement.',
                $shell,
                ['Reconfirm only user 0 exists and no accounts exist.', 'Keep the phone unlocked.', 'Run the exact component command once.', 'Read the complete ADB response; do not repeat blindly after a failure.'],
                'ADB reports “Success: Device owner set to package ComponentInfo{com.twinsofte.deviceguard/com.twinsofte.deviceguard.devicepolicy.DevicePolicyReceiver}”.',
                [$this->error('Not allowed to set the device owner because there are already some accounts', 'Android still has one or more accounts.', 'Remove accounts manually or repeat the authorized factory reset; rerun the account check.'), $this->error('Unknown admin', 'The exact receiver is absent, disabled, or the wrong APK is installed.', 'Return to package/receiver verification and install the approved build.'), $this->error('MANAGE_DEVICE_ADMINS permission / SecurityException', 'The device is already provisioned, another owner exists, or this Android/OEM build blocks shell provisioning.', 'Check dumpsys device_policy. If another legitimate owner exists, use its supported removal process; otherwise reset with authorization. Never root or bypass security.')],
                ['Exact component command used', 'ADB reports Success'],
                [], $shell, "{$adb} shell dpm set-device-owner com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver", $folder),

            $this->step('device_owner_verify', 'Verify Device Owner from Android—not from a technician checkbox',
                'The server must not trust an assumption; Android’s device policy state is the source of truth.',
                $shell,
                ['Run the device-policy command.', 'Find the Device Owner section.', 'Confirm the exact DeviceGuard component is listed.', 'Open DeviceGuard afterward so it can report Device Owner to the server.'],
                'dumpsys shows Device Owner with com.twinsofte.deviceguard/.devicepolicy.DevicePolicyReceiver.',
                [$this->error('Device Owner section is empty', 'The assignment command did not succeed.', 'Return to the Device Owner step and resolve its exact Android error.'), $this->error('A different owner is shown', 'Another management application owns the device.', 'Stop. Use that owner’s authorized offboarding process; do not remove it by bypass.')],
                ['Android dumpsys lists DeviceGuard as Device Owner', 'Dashboard receives Device Owner=true'],
                [], $shell, "{$adb} shell dumpsys device_policy", $folder, true, 'device_owner'),

            $this->step('activation', 'Open DeviceGuard and complete server activation',
                'Activation links this physical phone to the registered customer/device record and creates authenticated API credentials.',
                "{$brandName} phone and DeviceGuard dashboard",
                ['Launch DeviceGuard using the command or its app icon.', 'Enter/scan the provisioning details for this registered device.', 'Complete the Management PIN step.', 'Wait for the app to show activation success.', 'Refresh server verification below.'],
                'DeviceGuard shows activation success; the server has a device UUID/token and a recent sync.',
                [$this->error('Incorrect Management PIN', 'The entered PIN does not match the server record.', 'Use the authorized Management PIN workflow in the dashboard; do not guess repeatedly.'), $this->error('Device reference mismatch / token invalid', 'The QR/token belongs to another device, expired, or was already used.', 'Generate a new provisioning token for this exact device and retry.'), $this->error('Cannot reach server', 'Network, TLS, URL, or hosting configuration prevents activation.', 'Check phone internet, server HTTPS certificate, API base URL, and application logs.')],
                ['Activation success shown in app', 'Authenticated device token exists', 'Recent sync displayed'],
                [], $shell, "{$adb} shell monkey -p com.twinsofte.deviceguard -c android.intent.category.LAUNCHER 1", $folder, true, 'activation'),

            $this->step('capabilities', 'Confirm FCM, management capabilities, sync, and offline policy',
                'A Device Owner label alone is insufficient; every control must be reported by the Android app and acknowledged by the server.',
                'DeviceGuard app and live server verification panel',
                ['Keep the phone online and DeviceGuard open.', 'Tap the app’s sync/refresh action if available.', 'Refresh the wizard verification panel.', 'Confirm Device Owner, Device Admin, FCM token, uninstall protection, reset protection, full lock, lock task, last sync, and offline-policy acknowledgement.', 'Resolve every Needs attention item before continuing.'],
                'All required server capability cards show Confirmed with a recent last-sync timestamp.',
                [$this->error('FCM token missing', 'Firebase registration failed or Google Play Services/network is unavailable.', 'Check internet, Google Play Services, Firebase configuration, notification permission, and app logs; then sync again.'), $this->error('Capability is false', 'Android did not grant or the app did not report that control.', 'Check Device Owner first, review Android/OEM restrictions, update the approved app if necessary, and resend capabilities.'), $this->error('Offline policy pending', 'The app has not acknowledged the latest signed offline policy.', 'Keep the app online, run a heartbeat/sync, verify device clock, and check the policy signature configuration.')],
                ['FCM token confirmed', 'All management capabilities confirmed', 'Last sync recent', 'Offline policy acknowledged'],
                [], null, null, null, true, 'capabilities'),

            $this->step('brand_background', "Apply {$brandName} background, battery, notification, and autostart settings",
                'OEM power management can delay FCM, heartbeat, boot handling, and offline-policy enforcement.',
                "{$brandName} phone Settings",
                $brandGuide['background'],
                $brandGuide['background_expected'],
                [$this->error('DeviceGuard is stopped or delayed in background', 'Battery optimization, sleeping-app controls, autostart, or notification restrictions are active.', $brandGuide['background_help'])],
                ['Battery/background restriction removed', 'Notifications allowed', 'Autostart/boot behavior enabled where available']),

            $this->step('lock_unlock', 'Run automatic lock and unlock tests and inspect Android logs',
                'A successful end-to-end command proves server delivery, authentication, Device Owner enforcement, and result reporting.',
                'DeviceGuard dashboard, phone, and terminal',
                ['From the device page send an authorized Lock command.', 'Wait for the phone to lock and the server command status to become completed.', 'Send Unlock and confirm the phone unlocks and reports completion.', 'Run the log command while testing and inspect DeviceGuard/FCM/command messages.', 'Do not accept merely “dispatched”; both commands must be completed.'],
                'The latest LOCK_DEVICE and UNLOCK_DEVICE commands both have status completed and the phone behavior matches.',
                [$this->error('Command stays pending/dispatched', 'FCM/background delivery or polling has not reached the app.', 'Check FCM token, network, Firebase response, queue worker, heartbeat, and this brand’s background settings.'), $this->error('NOT_DEVICE_OWNER / execution failed', 'The app cannot apply the requested policy.', 'Recheck Android Device Owner and capability report, then inspect the result_message and logcat output.')],
                ['Lock completed on phone and server', 'Unlock completed on phone and server', 'Relevant logcat output reviewed'],
                [], $shell, "{$adb} logcat -v time | ".($isWin ? "Select-String 'DeviceGuard|Firebase|LOCK_DEVICE|UNLOCK_DEVICE'" : "grep -E 'DeviceGuard|Firebase|LOCK_DEVICE|UNLOCK_DEVICE'"), $folder, true, 'lock_unlock'),

            $this->step('reboot_finish', 'Reboot and verify boot recovery before completing setup',
                'The final test proves DeviceGuard restarts after boot, preserves Device Owner, reconnects, and resumes offline protection.',
                "{$shell}, {$brandName} phone, and live server verification",
                ['Run the reboot command.', 'Wait for Android to boot fully and unlock it once if required by Android encrypted storage.', 'Do not manually launch DeviceGuard; wait for its boot receiver/worker.', 'Inspect boot logs and refresh the server panel.', 'Confirm Device Owner remains true, a new sync arrives, FCM remains present, and offline policy is acknowledged.', 'After every check passes, open Developer options on the phone, choose Revoke USB debugging authorizations, and turn USB debugging off.', 'Disconnect the USB cable and complete setup only when every final gate is confirmed.'],
                'After reboot, DeviceGuard starts automatically, a fresh sync reaches the server, Device Owner/capabilities remain confirmed, and offline policy is acknowledged.',
                [$this->error('No sync after reboot', 'Boot receiver, background execution, network, or OEM power controls prevented startup.', 'Inspect boot logcat, confirm RECEIVE_BOOT_COMPLETED in the approved APK, recheck brand background/autostart settings, and wait for network.'), $this->error('Device Owner lost', 'The device was reset, app state changed, or the server has not received the current Android report.', 'Run dumpsys device_policy. If DeviceGuard is not owner, stop and diagnose; never mark setup complete.')],
                ['Reboot completed', 'Boot logs reviewed', 'Fresh post-reboot sync received', 'Device Owner and all final gates confirmed', 'USB debugging authorizations revoked', 'USB debugging turned off and cable disconnected'],
                [], $shell, "{$adb} reboot\n# After Android boots:\n{$adb} logcat -d -v time | ".($isWin ? "Select-String 'BOOT_COMPLETED|DeviceGuard|WorkManager'" : "grep -E 'BOOT_COMPLETED|DeviceGuard|WorkManager'"), $folder, true, 'final'),
        ];

        return collect($raw)->values()->map(function (array $step, int $index) use ($os, $brand) {
            return $step + [
                'computer_os' => $os,
                'phone_brand' => $brand,
                'step_number' => $index + 1,
                'display_order' => $index + 1,
                'active' => true,
            ];
        })->all();
    }

    private function step(string $key, string $title, string $why, string $location, array $instructions, string $expected, array $errors, array $verification, array $confirmations = [], ?string $shell = null, ?string $command = null, ?string $runFrom = null, bool $auto = false, ?string $serverKey = null): array
    {
        return [
            'step_key' => $key,
            'title' => $title,
            'short_description' => $instructions[0],
            'why_required' => $why,
            'action_location' => $location,
            'numbered_instructions' => $instructions,
            'shell_type' => $shell,
            'command' => $command,
            'run_from' => $runFrom,
            'terminal_help' => $shell ? ($shell === 'PowerShell'
                ? 'Open Start, type PowerShell, and open Windows PowerShell. Use Copy command above, right-click once in PowerShell to paste, press Enter, and wait for the prompt to return. Do not use Command Prompt.'
                : 'Press Command + Space, type Terminal, and press Return. Use Copy command above, paste with Command + V, press Return, and wait for the prompt to return.') : null,
            'expected_output' => $expected,
            'possible_errors' => $errors,
            'troubleshooting' => collect($errors)->map(fn ($e) => ['problem' => $e['output'], 'solution' => $e['solution']])->all(),
            'verification_items' => $verification,
            'confirmation_items' => $confirmations,
            'required' => true,
            'auto_verifiable' => $auto,
            'server_check_key' => $serverKey,
        ];
    }

    private function apkInstallCommand(bool $windows, string $url, ?string $checksum): string
    {
        if ($windows) {
            $checksumCommand = $checksum
                ? "\n(Get-FileHash .\\deviceguard.apk -Algorithm SHA256).Hash\n# Compare with saved expected SHA-256: {$checksum}\n"
                : "\n# APK checksum verification is not configured by Super Admin.\n";

            return "Set-Location (Join-Path \$HOME 'Downloads')\n".
                "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12\n\n".
                "Invoke-WebRequest -UseBasicParsing `\n".
                "-Uri \"{$url}\" `\n".
                "-OutFile \".\\deviceguard.apk\"\n\n".
                "Get-Item .\\deviceguard.apk |\n".
                "Select-Object Name, Length, LastWriteTime\n".
                $checksumCommand."\n".
                "adb install -r -t .\\deviceguard.apk\n\n".
                "adb shell pm path {$this->apk->packageName()}";
        }

        $checksumCommand = $checksum
            ? "\nshasum -a 256 deviceguard.apk\n# Compare with saved expected SHA-256: {$checksum}\n"
            : "\n# APK checksum verification is not configured by Super Admin.\n";

        return "cd ~/Downloads\n\n".
            "curl -L \\\n".
            "\"{$url}\" \\\n".
            "-o deviceguard.apk\n\n".
            "ls -lh deviceguard.apk\n".
            $checksumCommand."\n".
            "adb install -r -t deviceguard.apk\n\n".
            "adb shell pm path {$this->apk->packageName()}";
    }

    private function platformToolsInstallCommand(bool $windows, string $url, ?string $checksum): string
    {
        if ($windows) {
            $checksumCheck = $checksum
                ? "if ((Get-FileHash \$zip -Algorithm SHA256).Hash.ToLower() -ne '".strtolower($checksum)."') { throw 'Platform Tools checksum mismatch. Delete the ZIP and stop.' }"
                : "Write-Warning 'Platform Tools checksum is not configured; continuing only because the download uses the approved Google HTTPS URL.'";

            return "\$root = Join-Path \$env:LOCALAPPDATA 'DeviceGuard'\n".
                "\$tools = Join-Path \$root 'platform-tools'\n".
                "\$zip = Join-Path \$root 'platform-tools.zip'\n".
                "New-Item -ItemType Directory -Force -Path \$root | Out-Null\n".
                "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12\n".
                "Invoke-WebRequest -UseBasicParsing -Uri '".str_replace("'", "''", $url)."' -OutFile \$zip\n".
                $checksumCheck."\n".
                "if (Test-Path \$tools) { Remove-Item \$tools -Recurse -Force }\n".
                "Expand-Archive -Path \$zip -DestinationPath \$root -Force\n".
                "if (-not (Test-Path (Join-Path \$tools 'adb.exe'))) { throw 'adb.exe was not found after extraction.' }\n".
                "\$env:Path = \"\$tools;\$env:Path\"\n".
                "\$userPath = [string][Environment]::GetEnvironmentVariable('Path', 'User')\n".
                "if ((\$userPath -split ';') -notcontains \$tools) { \$newPath = if ([string]::IsNullOrWhiteSpace(\$userPath)) { \$tools } else { \$userPath.TrimEnd(';') + ';' + \$tools }; [Environment]::SetEnvironmentVariable('Path', \$newPath, 'User') }\n".
                "adb version\n".
                "Write-Host 'PLATFORM_TOOLS_READY - close and reopen PowerShell before the next step.'";
        }

        $checksumCheck = $checksum
            ? 'echo '.escapeshellarg(strtolower($checksum)).'  "$ZIP" | shasum -a 256 -c -'
            : "printf 'WARNING: Platform Tools checksum is not configured; continuing only with the approved Google HTTPS URL.\\n'";

        return "TOOLS_ROOT=\"\$HOME/deviceguard-tools\"\n".
            "ZIP=\"\$TOOLS_ROOT/platform-tools.zip\"\n".
            "mkdir -p \"\$TOOLS_ROOT\"\n".
            "curl --fail --location \\\n".
            escapeshellarg($url)." \\\n".
            "--output \"\$ZIP\"\n".
            $checksumCheck."\n".
            "rm -rf \"\$TOOLS_ROOT/platform-tools\"\n".
            "ditto -x -k \"\$ZIP\" \"\$TOOLS_ROOT\"\n".
            "chmod +x \"\$TOOLS_ROOT/platform-tools/adb\"\n".
            "PATH_LINE='export PATH=\"\$HOME/deviceguard-tools/platform-tools:\$PATH\"'\n".
            "touch \"\$HOME/.zprofile\"\n".
            "grep -Fqx \"\$PATH_LINE\" \"\$HOME/.zprofile\" || printf '%s\\n' \"\$PATH_LINE\" >> \"\$HOME/.zprofile\"\n".
            "export PATH=\"\$HOME/deviceguard-tools/platform-tools:\$PATH\"\n".
            "adb version\n".
            "printf 'PLATFORM_TOOLS_READY - close and reopen Terminal before the next step.\\n'";
    }

    private function error(string $output, string $meaning, string $solution): array
    {
        return compact('output', 'meaning', 'solution');
    }

    private function brandGuide(string $brand): array
    {
        return match ($brand) {
            'samsung' => [
                'driver' => 'Use Samsung’s official Android USB Driver for Windows page; do not use a third-party driver mirror.',
                'reset' => 'Open Settings › General management › Reset › Factory data reset.',
                'developer' => 'Open Settings › About phone › Software information › Build number.',
                'developer_help' => 'On Samsung, Build number is under Settings › About phone › Software information.',
                'usb' => 'If USB debugging is blocked on a supported Samsung version, check Settings › Security and privacy › Auto Blocker. Temporarily disable it only when it is the documented cause, then restore the safest compatible setting after setup.',
                'usb_help' => 'Check Samsung Auto Blocker and USB restrictions. Do not bypass Knox or an existing administrator.',
                'install_help' => 'Unlock the phone, approve the installation prompt, and check Samsung Auto Blocker only if it explicitly blocked the authorized install.',
                'background' => ['Open Settings › Apps › DeviceGuard › Battery and choose Unrestricted.', 'Allow DeviceGuard notifications.', 'Open Settings › Battery and device care › Battery › Background usage limits › Never sleeping apps and add DeviceGuard.', 'Keep Mobile data › Allow background data enabled.'],
                'background_expected' => 'DeviceGuard is Unrestricted, notifications are allowed, background data is on, and it is in Never sleeping apps.',
                'background_help' => 'Remove DeviceGuard from Sleeping/Deep sleeping apps, add it to Never sleeping apps, allow background data and notifications.',
            ],
            'xiaomi' => [
                'driver' => 'Use Xiaomi’s official support/USB driver guidance for the exact model.',
                'reset' => 'Open Settings › About phone › Factory reset › Erase all data; the labels may be under Additional settings on older MIUI.',
                'developer' => 'Open Settings › About phone › Detailed info and specs, then tap MIUI version or OS version.',
                'developer_help' => 'On MIUI/HyperOS, repeatedly tap MIUI version or OS version under About phone.',
                'usb' => 'If shown, enable USB debugging (Security settings) and Install via USB only for this authorized setup.',
                'usb_help' => 'Check MIUI/HyperOS Developer options, USB debugging (Security settings), and any required legitimate Mi account/device verification.',
                'install_help' => 'Approve Install via USB and USB debugging (Security settings). Do not bypass Mi account verification.',
                'background' => ['Open App info › Battery saver and choose No restrictions.', 'Enable Autostart for DeviceGuard.', 'Allow notifications and background data.', 'Lock DeviceGuard in Recents when the model exposes that option.'],
                'background_expected' => 'Battery is No restrictions, Autostart and notifications are enabled, and background data is allowed.',
                'background_help' => 'Set No restrictions, enable Autostart, allow background data/notifications, and exclude DeviceGuard from Cleaner.',
            ],
            'oppo' => [
                'driver' => 'Use the exact OPPO, Realme, or OnePlus official support driver guidance matching the phone.',
                'reset' => 'Open Settings › System/Additional settings › Back up and reset › Reset phone › Erase all data.',
                'developer' => 'Open Settings › About device › Version, then tap Version number / Build number.',
                'developer_help' => 'Open About device › Version and tap Version number or Build number seven times.',
                'usb' => 'Enable USB debugging and, when present, Disable permission monitoring / USB installation only as documented for authorized development.',
                'usb_help' => 'Check Developer options and the phone’s USB installation prompt; do not disable unrelated security controls.',
                'install_help' => 'Approve the phone-side USB installation prompt and check the family-specific Developer options.',
                'background' => ['Open App management › DeviceGuard › Battery usage and allow background activity.', 'Enable Auto launch/Startup manager when present.', 'Allow notifications and background data.', 'Disable battery optimization for DeviceGuard.'],
                'background_expected' => 'Background activity, auto launch, notifications, and background data are allowed.',
                'background_help' => 'Allow background activity and auto launch; remove battery optimization and permit notifications/data.',
            ],
            'vivo' => [
                'driver' => 'Use Vivo/iQOO’s official support driver for the exact model.',
                'reset' => 'Open Settings › System management › Backup & reset › Erase all data.',
                'developer' => 'Open Settings › System management › About phone › Software version.',
                'developer_help' => 'On Vivo/iQOO, tap Software version under About phone until developer mode is enabled.',
                'usb' => 'Enable USB debugging and USB installation when those separate switches are present.',
                'usb_help' => 'Check USB debugging, USB installation, and the phone confirmation dialog in Developer options.',
                'install_help' => 'Enable USB installation for this authorized session and approve the phone prompt.',
                'background' => ['Open Settings › Battery › Background power consumption management.', 'Set DeviceGuard to high background power usage / unrestricted.', 'Enable Autostart in Permission management.', 'Allow notifications and background data.'],
                'background_expected' => 'High background power use, Autostart, notifications, and background data are allowed.',
                'background_help' => 'Allow high background power consumption and Autostart, then allow notifications and background data.',
            ],
            'pixel' => [
                'driver' => 'Use Google’s official Google USB Driver documentation (Android Studio SDK Manager) for Pixel on Windows.',
                'reset' => 'Open Settings › System › Reset options › Erase all data (factory reset).',
                'developer' => 'Open Settings › About phone › Build number.',
                'developer_help' => 'On Pixel, Build number is at the bottom of Settings › About phone.',
                'usb' => 'Use the standard USB debugging switch; no OEM USB-installation toggle is expected.',
                'usb_help' => 'Revoke USB debugging authorizations, toggle USB debugging off/on, reconnect, and accept the RSA prompt.',
                'install_help' => 'Unlock the phone and approve the standard Android installation/debugging prompt.',
                'background' => ['Open Settings › Apps › DeviceGuard › App battery usage.', 'Choose Unrestricted.', 'Allow notifications and background mobile data.'],
                'background_expected' => 'App battery usage is Unrestricted and notifications/background data are allowed.',
                'background_help' => 'Set App battery usage to Unrestricted and allow notifications/background data.',
            ],
            'nokia_motorola' => [
                'driver' => 'Use Nokia/HMD or Motorola’s official Windows USB driver/support page matching the device.',
                'reset' => 'Open Settings › System › Reset options › Erase all data (factory reset).',
                'developer' => 'Open Settings › About phone › Build number.',
                'developer_help' => 'Build number may be under Settings › About phone › Android version on some models.',
                'usb' => 'Enable the standard Android USB debugging switch.',
                'usb_help' => 'Revoke USB authorizations, reconnect with File transfer, and accept the fingerprint.',
                'install_help' => 'Unlock and approve the Android installation prompt.',
                'background' => ['Open App info › Battery / App battery usage.', 'Choose Unrestricted or Don’t optimize.', 'Allow notifications and background data.'],
                'background_expected' => 'DeviceGuard is unrestricted/not optimized and notifications/background data are allowed.',
                'background_help' => 'Remove battery optimization and enable background data and notifications.',
            ],
            'transsion' => [
                'driver' => 'Use Tecno or Infinix’s official support resources for the model; never use an anonymous driver pack.',
                'reset' => 'Open Settings › System › Reset options › Erase all data; on HiOS/XOS this may appear under Backup & reset.',
                'developer' => 'Open Settings › My phone/About phone › Build number.',
                'developer_help' => 'Look under My phone/About phone and tap Build number seven times.',
                'usb' => 'Enable USB debugging and approve any HiOS/XOS USB installation prompt.',
                'usb_help' => 'Check Developer options, Phone Master security prompts, and USB mode without bypassing system security.',
                'install_help' => 'Approve USB installation and ensure Phone Master did not quarantine the approved APK.',
                'background' => ['Enable Auto-start management for DeviceGuard.', 'Set Battery Lab/Power Marathon to unrestricted for DeviceGuard.', 'Allow notifications and background data.', 'Exclude DeviceGuard from Phone Master cleanup.'],
                'background_expected' => 'Autostart is enabled, battery use is unrestricted, and cleanup does not stop DeviceGuard.',
                'background_help' => 'Enable autostart, remove Battery Lab/Power Marathon limits, and exclude the app from Phone Master cleanup.',
            ],
            default => [
                'driver' => 'Identify the exact manufacturer/model and use only its official Windows USB driver page.',
                'reset' => 'Use the manufacturer’s Settings › System/General management › Reset › Erase all data path.',
                'developer' => 'Open Settings › About phone and locate Build number or Software version.',
                'developer_help' => 'Consult the manufacturer’s official support page for enabling Developer options on this exact model.',
                'usb' => 'Enable standard Android USB debugging and approve the RSA fingerprint.',
                'usb_help' => 'Use official manufacturer guidance; do not disable or bypass security controls.',
                'install_help' => 'Approve the standard Android installation prompt and consult official model guidance.',
                'background' => ['Open DeviceGuard App info › Battery and choose Unrestricted/Don’t optimize.', 'Allow Autostart when the manufacturer provides it.', 'Allow notifications and background data.', 'Exclude DeviceGuard from sleeping/cleanup tools.'],
                'background_expected' => 'DeviceGuard can start automatically and run in the background with notifications/data enabled.',
                'background_help' => 'Use the exact manufacturer’s official battery/autostart documentation for this model.',
            ],
        };
    }
}
