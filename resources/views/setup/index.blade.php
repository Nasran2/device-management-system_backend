<x-layouts.app title="Device Setup Wizard">
    <div class="mx-auto max-w-7xl">
        <div class="mb-7 flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="eyebrow">Authorized enrollment</p>
                <h1 class="page-title">Device Setup Wizard</h1>
                <p class="page-copy">A complete technician workflow for Device Owner enrollment, activation, capability checks, and end-to-end testing.</p>
            </div>
            @if(auth()->user()->isSuperAdmin())<a class="secondary-button" href="{{ route('setup-instructions.index') }}">Manage instructions</a>@endif
        </div>

        @if($devices->isNotEmpty())
            <section class="panel mb-7 overflow-hidden">
                <div class="border-b border-slate-200 bg-gradient-to-r from-indigo-600 to-violet-600 p-6 text-white">
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-indigo-100">New setup</p>
                    <h2 class="mt-1 text-2xl font-black">Choose the exact environment</h2>
                    <p class="mt-1 text-sm text-indigo-100">The selected OS and phone family control every command, menu path, error, and recovery instruction.</p>
                </div>
                <form class="grid gap-6 p-6 lg:grid-cols-2" method="post" action="" id="setup-start-form">
                    @csrf
                    <label class="field-label lg:col-span-2">Registered device <x-required/>
                        <select class="field-input" id="setup-device" required>
                            <option value="">Choose a customer device</option>
                            @foreach($devices as $device)
                                <option value="{{ route('setup.start',$device) }}">{{ $device->customer->name }} — {{ $device->brand }} {{ $device->model }} · {{ $device->masked_imei }}</option>
                            @endforeach
                        </select>
                    </label>
                    <fieldset>
                        <legend class="field-label">Setup computer <x-required/></legend>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            @foreach($oses as $value => $label)
                                <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
                                    <input type="radio" name="computer_os" value="{{ $value }}" class="mr-2" {{ $value==='windows'?'checked':'' }}> <strong>{{ $label }}</strong>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <label class="field-label">Phone manufacturer family <x-required/>
                        <select class="field-input" name="brand_group" required>
                            @foreach($brands as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        <span class="mt-1 text-xs font-normal text-slate-500">Choose the actual family. Guidance from other manufacturers will be excluded.</span>
                    </label>
                    <fieldset class="lg:col-span-2">
                        <legend class="field-label">Setup mode <x-required/></legend>
                        <div class="mt-2 grid gap-3 md:grid-cols-2">
                            <label class="cursor-pointer rounded-2xl border border-slate-200 p-5 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
                                <div class="flex items-start gap-3"><input type="radio" name="mode" value="manual_guided" checked class="mt-1"><div><strong>Manual Guided Setup</strong><p class="mt-1 text-sm font-normal text-slate-600">Technician follows and verifies each structured step.</p></div></div>
                            </label>
                            <label class="cursor-pointer rounded-2xl border border-slate-200 p-5 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
                                <div class="flex items-start gap-3"><input type="radio" name="mode" value="setup_helper" class="mt-1"><div><strong>Setup Helper</strong><p class="mt-1 text-sm font-normal text-slate-600">Adds a signed local helper download; Android and server verification remain mandatory.</p></div></div>
                            </label>
                        </div>
                    </fieldset>
                    <label class="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm lg:col-span-2">
                        <input type="checkbox" name="authorized" value="1" required class="mt-1">
                        <span><strong>Authorization required.</strong> I confirm this shop owns or is authorized to manage the phone and the customer accepted the device-management terms. I understand the wizard never bypasses FRP, removes accounts, or factory-resets automatically.</span>
                    </label>
                    <div class="flex justify-end lg:col-span-2">
                        <button class="primary-button" onclick="const d=document.getElementById('setup-device'); if(d.value){this.form.action=d.value}">Start structured setup →</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="panel overflow-x-auto">
            <div class="panel-header"><div><h2 class="section-title">Setup history</h2><p class="section-copy">Resume without losing the technician’s command results, verification source, notes, or troubleshooting record.</p></div></div>
            <table class="data-table">
                <thead><tr><th>Device</th><th>Customer</th><th>Variant</th><th>Mode</th><th>Progress</th><th>Status</th><th>Updated</th><th></th></tr></thead>
                <tbody>
                @forelse($sessions as $session)
                    <tr>
                        <td class="font-semibold">{{ $session->device->brand }} {{ $session->device->model }}</td>
                        <td>{{ $session->device->customer->name }}</td>
                        <td>{{ strtoupper($session->computer_os) }} · {{ \App\Services\SetupInstructionCatalog::BRANDS[$session->brand_group] ?? ucfirst($session->brand_group) }}</td>
                        <td>{{ str($session->mode ?? 'manual_guided')->replace('_',' ')->title() }}</td>
                        <td>Step {{ $session->current_step }} · {{ $session->steps()->where('completed',true)->count() }} verified</td>
                        <td><span class="status-pill {{ $session->status==='completed'?'bg-emerald-50 text-emerald-700':'bg-amber-50 text-amber-700' }}">{{ str($session->status)->replace('_',' ')->title() }}</span></td>
                        <td>{{ $session->updated_at->diffForHumans() }}</td>
                        <td><a class="font-bold text-indigo-600" href="{{ route('setup.show',$session) }}">{{ $session->status==='completed'?'Review':'Resume' }} →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-10 text-center text-slate-500">No setup sessions yet.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-5">{{ $sessions->links() }}</div>
        </section>
    </div>
</x-layouts.app>
