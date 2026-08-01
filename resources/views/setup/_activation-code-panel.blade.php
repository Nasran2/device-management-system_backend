@php
    $activation = $activationState['activation'];
    $activationCode = $activationState['plain'];
    $activationStatus = $activationState['status'];
@endphp
<section class="rounded-2xl border-2 border-indigo-300 bg-indigo-50 p-5" aria-live="polite">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-black uppercase tracking-wider text-indigo-700">Device Activation Code</p>
            <h3 class="mt-1 text-xl font-black text-indigo-950">Enter this code in the DeviceGuard Android app</h3>
        </div>
        @if($activation)<span class="status-pill {{ in_array($activationStatus,['active','expiring_soon'],true)?($activationStatus==='active'?'bg-emerald-100 text-emerald-800':'bg-amber-100 text-amber-800'):'bg-red-100 text-red-800' }}">{{ str($activationStatus)->replace('_',' ')->title() }}</span>@endif
    </div>
    @if($activationCode)
        <p id="wizard-activation-code" class="mt-5 font-mono text-4xl font-black tracking-[.3em] text-indigo-950">{{ $activationCode }}</p>
        <p class="mt-3 text-sm text-indigo-900">Expires {{ $activation->expires_at->timezone(App\Models\SystemSetting::value('timezone',config('app.timezone')))->format('d M Y, g:i A') }}</p>
        <div class="mt-4 flex flex-wrap gap-3"><button type="button" class="primary-button" onclick="navigator.clipboard.writeText(@js($activationCode));this.textContent='Copied ✓'">Copy Code</button><a class="secondary-button" href="{{ route('devices.show',$setup->device) }}">Open Device Details</a></div>
    @elseif($activation)
        <p class="mt-4 text-sm font-semibold text-red-800">The latest code is {{ str($activationStatus)->replace('_',' ') }}. Generate a replacement from Device Details if activation is still required.</p>
        <a class="primary-button mt-4" href="{{ route('devices.show',$setup->device) }}">Open Device Details</a>
    @else
        <p class="mt-4 text-sm text-amber-900">You do not have permission to view or generate this device’s activation code. Ask the Shop Owner or Super Admin.</p>
    @endif
</section>
