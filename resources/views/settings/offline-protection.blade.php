<x-layouts.app title="Offline Protection">
    <div class="mb-7"><p class="eyebrow">Settings · Device Management</p><h1 class="page-title">Offline Protection</h1><p class="page-copy">DeviceGuard must successfully verify with the management server before the offline period expires. The default period is five days. If the device remains offline beyond this period, DeviceGuard will apply the managed lock locally.</p></div>
    <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Important:</strong> Temporary internet interruptions will not immediately lock the phone. The device will lock only after the complete configured offline period has passed.</div>
    @unless(auth()->user()->isSuperAdmin())<section class="panel p-6"><h2 class="section-title">Current global policy</h2><dl class="details-grid mt-5"><div><dt>Status</dt><dd>{{ $settings->enabled ? 'Enabled' : 'Disabled' }}</dd></div><div><dt>Default period</dt><dd>{{ $settings->default_period_value }} {{ $settings->default_period_unit }} ({{ $settings->default_period_seconds / 3600 }} hours)</dd></div><div><dt>Admin overrides</dt><dd>{{ $settings->allow_admin_override ? 'Allowed' : 'Blocked' }}</dd></div><div><dt>Automatic deadline reset</dt><dd>Always enabled after verified acknowledgement</dd></div></dl></section>
    @else
    <form method="post" action="{{ route('settings.offline-protection.update') }}" class="panel p-6 space-y-6">@csrf @method('put')
        <div class="form-grid">
            <label class="field-label"><span class="flex gap-2"><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$settings->enabled))> Offline protection enabled</span></label>
            <label class="field-label"><span class="flex gap-2"><input type="checkbox" name="warning_notification_enabled" value="1" @checked(old('warning_notification_enabled',$settings->warning_notification_enabled))> Warning notifications enabled</span></label>
            <label class="field-label">Default offline period
                <select id="offline-preset" class="field-input">
                    @foreach([['24','hours','24 hours'],['2','days','2 days (48 hours)'],['3','days','3 days (72 hours)'],['5','days','5 days (120 hours)'],['7','days','7 days (168 hours)'],['14','days','14 days (336 hours)'],['30','days','30 days (720 hours)']] as [$v,$u,$label])<option value="{{ $v }}:{{ $u }}" @selected($settings->default_period_value==$v && $settings->default_period_unit===$u)>{{ $label }}</option>@endforeach
                    <option value="custom">Custom period</option>
                </select>
            </label>
            <div class="grid grid-cols-2 gap-3"><label class="field-label">Value<input id="period-value" class="field-input" type="number" min="1" name="period_value" value="{{ old('period_value',$settings->default_period_value) }}" required></label><label class="field-label">Unit<select id="period-unit" class="field-input" name="period_unit"><option @selected($settings->default_period_unit==='hours') value="hours">Hours</option><option @selected($settings->default_period_unit==='days') value="days">Days</option></select></label></div>
            <label class="field-label">First warning (hours before lock)<input class="field-input" type="number" name="first_warning_hours" value="{{ old('first_warning_hours',$settings->first_warning_seconds/3600) }}" required></label>
            <label class="field-label">Final warning (hours before lock)<input class="field-input" type="number" name="final_warning_hours" value="{{ old('final_warning_hours',$settings->final_warning_seconds/3600) }}" required></label>
            <label class="field-label"><span class="flex gap-2"><input type="checkbox" name="allow_admin_override" value="1" @checked(old('allow_admin_override',$settings->allow_admin_override))> Allow Admin per-device overrides</span></label>
            <label class="field-label"><span class="flex gap-2"><input type="checkbox" name="require_password_confirmation" value="1" @checked(old('require_password_confirmation',$settings->require_password_confirmation))> Require account password</span></label>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4 text-sm"><strong id="period-summary"></strong><p class="mt-1 text-slate-500">Allowed range: 6 hours through 90 days. Automatic reset after verified server acknowledgement is always enabled.</p></div>
        <label class="field-label">Reason for change<textarea class="field-input" name="reason" required>{{ old('reason') }}</textarea></label>
        <label class="field-label">Account password<input class="field-input" type="password" name="password" required autocomplete="current-password"></label>
        <button class="primary-button">Save global policy</button>
    </form>
    <script>document.addEventListener('DOMContentLoaded',()=>{const p=document.querySelector('#offline-preset'),v=document.querySelector('#period-value'),u=document.querySelector('#period-unit'),s=document.querySelector('#period-summary');const draw=()=>{const n=Number(v.value||0),h=n*(u.value==='days'?24:1);s.textContent=`Selected: ${n} ${u.value} (${h} hours)`};p.addEventListener('change',()=>{if(p.value!=='custom'){const [a,b]=p.value.split(':');v.value=a;u.value=b}draw()});v.addEventListener('input',draw);u.addEventListener('change',draw);draw()});</script>
    @endunless
</x-layouts.app>
