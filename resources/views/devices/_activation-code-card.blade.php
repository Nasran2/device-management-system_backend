@if($canViewActivationCode)
@php
    $activation = $activationState['activation'];
    $activationCode = $activationState['plain'];
    $activationStatus = $activationState['status'];
    $displayTimezone = App\Models\SystemSetting::value('timezone', config('app.timezone'));
    $isCurrent = in_array($activationStatus, ['active', 'expiring_soon'], true);
    $badge = match($activationStatus) {
        'active' => ['Active', 'bg-emerald-100 text-emerald-800'],
        'expiring_soon' => ['Expiring Soon', 'bg-amber-100 text-amber-800'],
        'used' => ['Used', 'bg-slate-200 text-slate-700'],
        'revoked' => ['Revoked', 'bg-red-100 text-red-800'],
        'expired' => ['Expired', 'bg-red-100 text-red-800'],
        'temporarily_locked' => ['Temporarily Locked', 'bg-red-100 text-red-800'],
        default => ['Not Generated', 'bg-slate-100 text-slate-600'],
    };
@endphp
<section class="panel mb-6 overflow-hidden border-indigo-100" aria-labelledby="activation-code-title">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-indigo-50 to-white p-6">
        <div><p class="eyebrow">Activation</p><h2 id="activation-code-title" class="mt-1 text-2xl font-black text-slate-950">Device Activation Code</h2><p class="mt-2 text-sm text-slate-600">Enter this code in the DeviceGuard Android app to complete activation.</p></div>
        <span id="activation-code-status" class="status-pill {{ $badge[1] }}">{{ $badge[0] }}</span>
    </div>
    <div class="p-6">
        @if($activationCode && $activation)
            <div id="activation-code-live-area">
                <p id="device-activation-code" class="font-mono text-4xl font-black tracking-[.32em] text-indigo-950 sm:text-5xl">{{ $activationCode }}</p>
                <div class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Valid for</p><p id="activation-countdown" class="mt-1 font-black text-slate-900" data-expires-at="{{ $activation->expires_at->toIso8601String() }}">Calculating…</p></div>
                    <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Generated</p><p class="mt-1 font-bold">{{ $activation->created_at->timezone($displayTimezone)->format('d M Y, g:i A') }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-4"><p class="text-xs font-bold uppercase text-slate-500">Expires</p><p class="mt-1 font-bold">{{ $activation->expires_at->timezone($displayTimezone)->format('d M Y, g:i A') }}</p></div>
                </div>
            </div>
        @elseif($activationStatus === 'expired' && $activation)
            <h3 class="text-xl font-black text-red-800">Activation code expired</h3><p class="mt-2 text-sm text-slate-600">This activation code expired on: <strong>{{ $activation->expires_at->timezone($displayTimezone)->format('d M Y, g:i A') }}</strong></p>
        @elseif($activationStatus === 'used' && $activation)
            <h3 class="text-xl font-black text-slate-800">Activation completed</h3><p class="mt-2 text-sm text-slate-600">The code was used successfully on {{ $activation->used_at->timezone($displayTimezone)->format('d M Y, g:i A') }} and cannot be reused.</p>
        @elseif($activationStatus === 'revoked' && $activation)
            <h3 class="text-xl font-black text-red-800">Activation code revoked</h3><p class="mt-2 text-sm text-slate-600">The previous code is no longer accepted. Generate a replacement if the device still needs activation.</p>
        @elseif($activation && $isCurrent)
            <h3 class="text-xl font-black text-amber-800">Active legacy code cannot be displayed</h3><p class="mt-2 text-sm text-slate-600">Generate a new securely encrypted code to display it here.</p>
        @else
            <h3 class="text-xl font-black text-slate-800">No activation code has been generated.</h3><p class="mt-2 text-sm text-slate-600">Generate a code when this device is ready for Android app activation.</p>
        @endif

        <div class="mt-6 flex flex-wrap gap-3">
            @if($activationCode)<button id="copy-device-activation-code" type="button" class="primary-button">Copy Code</button>@endif
            @if($canGenerateActivationCode)<button type="button" class="secondary-button" onclick="document.getElementById('generate-activation-code-dialog').showModal()">{{ $isCurrent ? 'Generate New Code' : 'Generate Activation Code' }}</button>@endif
            @if($canRevokeActivationCode && $isCurrent)<button type="button" class="danger-button" onclick="document.getElementById('revoke-activation-code-dialog').showModal()">Revoke Code</button>@endif
        </div>

        @if(auth()->user()->isSuperAdmin() && $activationHistory->isNotEmpty())
            <details class="mt-6 rounded-2xl border border-slate-200 p-4"><summary class="cursor-pointer font-bold">Activation-code history</summary><div class="mt-4 overflow-x-auto"><table class="data-table"><thead><tr><th>Status</th><th>Generated</th><th>Expires</th><th>Generated by</th><th>Reason</th></tr></thead><tbody>@foreach($activationHistory as $item)<tr><td>{{ str($item->status())->replace('_',' ')->title() }}</td><td>{{ $item->created_at->timezone($displayTimezone)->format('d M Y H:i') }}</td><td>{{ $item->expires_at->timezone($displayTimezone)->format('d M Y H:i') }}</td><td>{{ $item->generatedBy?->name ?? 'System' }}</td><td>{{ str($item->generation_reason ?? 'legacy')->replace('_',' ')->title() }}</td></tr>@endforeach</tbody></table></div></details>
        @endif
    </div>
</section>

@if($canGenerateActivationCode)
<dialog id="generate-activation-code-dialog" class="w-[min(92vw,560px)] rounded-3xl p-0 shadow-2xl backdrop:bg-slate-950/50">
    <form method="post" action="{{ route('devices.activation-code.generate',$device) }}" class="p-6">@csrf
        <h2 class="text-xl font-black">Generate New Activation Code</h2><p class="mt-3 text-sm leading-6 text-slate-600">Generating a new activation code will immediately invalidate the current code. Continue?</p>
        <label class="field-label mt-5">Reason<textarea class="field-input" name="reason" maxlength="500" placeholder="Setup activation or replacement reason"></textarea></label>
        <label class="field-label mt-4">Your password <x-required/><input class="field-input" type="password" name="password" autocomplete="current-password" required></label>
        <label class="mt-4 flex gap-3 text-sm"><input type="checkbox" name="confirmed" value="1" required> I understand the current active code will stop working immediately.</label>
        <div class="mt-6 flex justify-end gap-3"><button type="button" class="secondary-button" onclick="this.closest('dialog').close()">Cancel</button><button class="primary-button">Generate New Code</button></div>
    </form>
</dialog>
@endif

@if($canRevokeActivationCode && $isCurrent)
<dialog id="revoke-activation-code-dialog" class="w-[min(92vw,560px)] rounded-3xl p-0 shadow-2xl backdrop:bg-slate-950/50">
    <form method="post" action="{{ route('devices.activation-code.revoke',$device) }}" class="p-6">@csrf
        <h2 class="text-xl font-black text-red-900">Revoke Activation Code</h2><p class="mt-3 text-sm leading-6 text-slate-600">The current code will immediately stop working.</p>
        <label class="field-label mt-5">Reason <x-required/><textarea class="field-input" name="reason" maxlength="500" required></textarea></label>
        <label class="field-label mt-4">Your password <x-required/><input class="field-input" type="password" name="password" autocomplete="current-password" required></label>
        <label class="mt-4 flex gap-3 text-sm"><input type="checkbox" name="confirmed" value="1" required> I confirm that this activation code should be revoked.</label>
        <div class="mt-6 flex justify-end gap-3"><button type="button" class="secondary-button" onclick="this.closest('dialog').close()">Cancel</button><button class="danger-button">Revoke Code</button></div>
    </form>
</dialog>
@endif

@if($activationCode && $activation)
<script>
document.addEventListener('DOMContentLoaded', () => {
    const code = document.getElementById('device-activation-code');
    const copy = document.getElementById('copy-device-activation-code');
    const countdown = document.getElementById('activation-countdown');
    const badge = document.getElementById('activation-code-status');
    copy?.addEventListener('click', async () => {
        await navigator.clipboard.writeText(code.textContent.trim());
        copy.textContent = 'Copied ✓';
    });
    const draw = () => {
        const seconds = Math.max(0, Math.floor((new Date(countdown.dataset.expiresAt).getTime() - Date.now()) / 1000));
        if (seconds <= 0) {
            countdown.textContent = 'Expired'; code.textContent = 'Expired'; copy.disabled = true;
            badge.textContent = 'Expired'; badge.className = 'status-pill bg-red-100 text-red-800'; return;
        }
        const days = Math.floor(seconds / 86400), hours = Math.floor((seconds % 86400) / 3600), minutes = Math.floor((seconds % 3600) / 60), secs = seconds % 60;
        countdown.textContent = [days ? `${days} day${days===1?'':'s'}` : '', `${hours} hour${hours===1?'':'s'}`, `${minutes} minute${minutes===1?'':'s'}`, `${secs} second${secs===1?'':'s'}`].filter(Boolean).join(' ');
        if (seconds < 7200) { badge.textContent = 'Expiring Soon'; badge.className = 'status-pill bg-amber-100 text-amber-800'; }
    };
    draw(); setInterval(draw, 1000);
});
</script>
@endif
@endif
