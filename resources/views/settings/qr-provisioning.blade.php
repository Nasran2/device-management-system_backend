<x-layouts.app title="APK & Provisioning">
<div class="mx-auto max-w-5xl">
    <p class="eyebrow">Super Admin · Settings</p>
    <h1 class="page-title">APK & Provisioning</h1>
    <p class="page-copy">Manage the single approved DeviceGuard APK source and Android provisioning configuration.</p>

    @unless($apkChecksum)
        <section class="mt-6 rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-950">
            <h2 class="font-black">APK checksum verification is not configured by Super Admin.</h2>
            <p class="mt-1 text-sm">Manual wizard installation remains available from the approved HTTPS URL. Configure the verified SHA-256 value before enabling secure QR provisioning.</p>
        </section>
    @endunless

    <form method="post" action="{{ route('settings.qr-provisioning.update') }}" class="panel mt-6 space-y-6 p-6">
        @csrf
        @method('put')

        <section>
            <h2 class="section-title">Approved Android application</h2>
            <div class="form-grid mt-4">
                <label class="field-label">APK download URL <x-required/>
                    <input id="apk-download-url" class="field-input" type="url" name="provisioning_apk_url" required value="{{ old('provisioning_apk_url', $apkUrl) }}">
                </label>
                <label class="field-label">APK version
                    <input class="field-input" name="provisioning_apk_version" required maxlength="50" value="{{ old('provisioning_apk_version', App\Models\SystemSetting::value('provisioning_apk_version', '')) }}">
                </label>
                <label class="field-label">APK SHA-256
                    <input class="field-input font-mono" name="provisioning_apk_checksum" maxlength="255" value="{{ old('provisioning_apk_checksum', $apkChecksum) }}" placeholder="Optional until the approved checksum is available">
                    <span class="mt-1 text-xs font-normal text-slate-500">Leave empty only when checksum verification has not yet been configured. The wizard will show a warning without blocking manual installation.</span>
                </label>
                <label class="field-label">API URL <x-required/>
                    <input class="field-input" type="url" name="provisioning_api_url" required value="{{ old('provisioning_api_url', App\Models\SystemSetting::value('provisioning_api_url', '')) }}">
                </label>
                <label class="field-label">Package name
                    <input class="field-input bg-slate-50 font-mono" name="provisioning_package_name" readonly value="{{ $packageName }}">
                </label>
                <label class="field-label">Device Admin receiver
                    <input class="field-input bg-slate-50 font-mono" name="provisioning_device_admin_receiver" readonly value="{{ $deviceAdminReceiver }}">
                </label>
                <label class="field-label">QR expiry time in minutes <x-required/>
                    <input class="field-input" type="number" min="5" max="1440" name="provisioning_qr_expiry_minutes" required value="{{ old('provisioning_qr_expiry_minutes', App\Models\SystemSetting::value('provisioning_qr_expiry_minutes', 30)) }}">
                </label>
                <label class="field-label">Default support phone
                    <input class="field-input" name="provisioning_support_phone" maxlength="30" value="{{ old('provisioning_support_phone', App\Models\SystemSetting::value('provisioning_support_phone', '')) }}">
                </label>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 p-5">
            <h2 class="font-bold">Approved Windows Platform Tools</h2>
            <p class="mt-1 text-sm text-slate-500">Use an official Android Platform Tools HTTPS ZIP URL and its SHA-256 checksum. The helper downloads it only when ADB is missing.</p>
            <div class="form-grid mt-4">
                <label class="field-label">Official ZIP URL
                    <input class="field-input" type="url" name="windows_platform_tools_url" value="{{ old('windows_platform_tools_url', App\Models\SystemSetting::value('windows_platform_tools_url', '')) }}">
                </label>
                <label class="field-label">ZIP SHA-256 checksum
                    <input class="field-input" name="windows_platform_tools_checksum" minlength="64" maxlength="64" value="{{ old('windows_platform_tools_checksum', App\Models\SystemSetting::value('windows_platform_tools_checksum', '')) }}">
                </label>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 p-5">
            <h2 class="font-bold">Optional branch Wi-Fi</h2>
            <div class="form-grid mt-4">
                <label class="field-label">Wi-Fi SSID
                    <input class="field-input" type="text" name="wifi_ssid" maxlength="32" value="{{ old('wifi_ssid', App\Models\SystemSetting::value('provisioning_wifi_ssid', '')) }}">
                    <span class="mt-1 text-xs font-normal text-slate-500">Optional. Leave blank to select Wi-Fi manually during phone setup.</span>
                </label>
                <label class="field-label">Wi-Fi security type
                    <select class="field-input" name="wifi_security_type">
                        <option value="WPA" @selected(old('wifi_security_type', App\Models\SystemSetting::value('provisioning_wifi_security_type', 'WPA')) === 'WPA')>WPA/WPA2</option>
                        <option value="WEP" @selected(old('wifi_security_type', App\Models\SystemSetting::value('provisioning_wifi_security_type')) === 'WEP')>WEP</option>
                        <option value="NONE" @selected(old('wifi_security_type', App\Models\SystemSetting::value('provisioning_wifi_security_type')) === 'NONE')>Open network</option>
                    </select>
                </label>
                <label class="field-label">Wi-Fi password
                    <input class="field-input" type="password" name="wifi_password" value="" autocomplete="new-password">
                    <span class="mt-1 text-xs font-normal text-slate-500">Leave blank to keep the saved password when the SSID is unchanged.</span>
                </label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="wifi_hidden" value="1" @checked(old('wifi_hidden', App\Models\SystemSetting::value('provisioning_wifi_hidden', false)))> Hidden Wi-Fi network</label>
            </div>
        </section>

        <label class="flex gap-3"><input type="checkbox" name="qr_provisioning_enabled" value="1" @checked(old('qr_provisioning_enabled', App\Models\SystemSetting::value('qr_provisioning_enabled', false)))> QR provisioning enabled</label>
        <button class="primary-button">Save settings</button>
    </form>

    <section class="panel mt-6 p-6">
        <h2 class="section-title">APK actions</h2>
        <p class="section-copy">Test and verify the saved central APK URL before giving it to a Shop Owner.</p>
        <div class="mt-4 flex flex-wrap gap-3">
            <form method="post" action="{{ route('settings.qr-provisioning.test-apk') }}">@csrf<button class="secondary-button">Test APK URL</button></form>
            <button id="copy-apk-url" type="button" class="secondary-button">Copy APK URL</button>
            <form method="post" action="{{ route('settings.qr-provisioning.checksum') }}">@csrf<button class="secondary-button">Calculate or verify checksum</button></form>
            <a class="secondary-button" href="{{ $apkUrl }}" target="_blank" rel="noopener noreferrer" download="deviceguard.apk">Download APK</a>
            <form method="post" action="{{ route('settings.qr-provisioning.validate') }}">@csrf<button class="secondary-button">Validate Configuration</button></form>
        </div>
    </section>

    @if(session('apk_url_test'))
        @php($test = session('apk_url_test'))
        <section class="mt-6 rounded-2xl border p-5 {{ $test['passed'] ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }}">
            <h2 class="font-black {{ $test['passed'] ? 'text-emerald-900' : 'text-rose-900' }}">APK URL test {{ $test['passed'] ? 'passed' : 'needs attention' }}</h2>
            <p class="mt-1 text-sm">{{ $test['message'] }}</p>
            <div class="mt-4 grid gap-2 sm:grid-cols-2">@foreach($test['checks'] as $label => $passed)<div class="rounded-xl bg-white/80 px-4 py-3 text-sm"><strong class="{{ $passed ? 'text-emerald-700' : 'text-rose-700' }}">{{ $passed ? '✓' : '✕' }} {{ $label }}</strong></div>@endforeach</div>
        </section>
    @endif

    @if(session('apk_checksum_result'))
        @php($checksumResult = session('apk_checksum_result'))
        <section class="mt-6 rounded-2xl border p-5 {{ $checksumResult['passed'] ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }}">
            <h2 class="font-black">APK checksum result</h2>
            <p class="mt-1 text-sm">{{ $checksumResult['message'] }}</p>
            @if(isset($checksumResult['sha256']))<code class="mt-3 block overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs text-emerald-300">{{ $checksumResult['sha256'] }}</code>@endif
        </section>
    @endif

    @if(session('configuration_validation'))
        @php($validation = session('configuration_validation'))
        <section class="mt-6 rounded-2xl border p-5 {{ $validation['passed'] ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50' }}">
            <div class="flex items-center justify-between gap-3"><h2 class="font-black {{ $validation['passed'] ? 'text-emerald-900' : 'text-red-900' }}">Configuration validation {{ $validation['passed'] ? 'passed' : 'needs attention' }}</h2><span class="status-pill {{ $validation['passed'] ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">{{ $validation['passed'] ? 'Valid' : 'Failed' }}</span></div>
            <div class="mt-4 grid gap-2">@foreach($validation['checks'] as $label => $check)<div class="flex items-start justify-between gap-4 rounded-xl bg-white/80 px-4 py-3 text-sm"><div><strong>{{ $label }}</strong><p class="mt-1 text-xs text-slate-600">{{ $check['message'] }}</p></div><strong class="{{ $check['passed'] ? 'text-emerald-700' : 'text-red-700' }}">{{ $check['passed'] ? 'Passed' : 'Failed' }}</strong></div>@endforeach</div>
            @if($validation['errors'])<ul class="mt-4 list-disc space-y-1 pl-5 text-sm text-red-800">@foreach($validation['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        </section>
    @endif
</div>

<script>
document.getElementById('copy-apk-url')?.addEventListener('click', async function () {
    await navigator.clipboard.writeText(document.getElementById('apk-download-url').value);
    this.textContent = 'APK URL copied ✓';
});
</script>
</x-layouts.app>
