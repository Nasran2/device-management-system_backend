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

        <section class="mb-7 rounded-2xl border border-sky-200 bg-sky-50 p-6">
            <p class="text-xs font-black uppercase tracking-wider text-sky-700">Before starting</p>
            <h2 class="mt-1 text-xl font-black text-sky-950">Place these items beside you</h2>
            <div class="mt-4 grid gap-3 text-sm text-sky-950 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl bg-white p-4"><strong>✓ Setup computer</strong><p class="mt-1 text-sky-800">Windows or Mac with internet access and at least 2 GB free space.</p></div>
                <div class="rounded-xl bg-white p-4"><strong>✓ USB data cable</strong><p class="mt-1 text-sky-800">A cable that transfers files—not a charge-only cable.</p></div>
                <div class="rounded-xl bg-white p-4"><strong>✓ Customer backup</strong><p class="mt-1 text-sky-800">Photos, contacts, authenticator access, and required files safely copied.</p></div>
                <div class="rounded-xl bg-white p-4"><strong>✓ Ownership details</strong><p class="mt-1 text-sky-800">Customer consent and legitimate previous Google account available if FRP asks for it.</p></div>
            </div>
            <p class="mt-4 rounded-xl bg-sky-100 p-3 text-sm font-semibold text-sky-950">Choose the operating system of the <u>computer</u> you will connect to the phone—not the phone’s Android version.</p>
        </section>

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
                        <p class="mt-2 text-xs font-normal text-slate-500">Select Windows for a Windows PC. Select macOS for an Apple MacBook, iMac, Mac mini, or Mac Studio.</p>
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
                                <div class="flex items-start gap-3"><input type="radio" name="mode" value="manual_guided" class="mt-1"><div><strong>Manual Guided Setup</strong><p class="mt-1 text-sm font-normal text-slate-600">Follow, copy, and verify one browser step at a time.</p></div></div>
                            </label>
                            <label class="cursor-pointer rounded-2xl border border-slate-200 p-5 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50">
                                <div class="flex items-start gap-3"><input type="radio" name="mode" value="setup_helper" checked class="mt-1"><div><strong>Setup Helper — recommended for beginners</strong><p class="mt-1 text-sm font-normal text-slate-600">Downloads official tools, checks the phone safely, and explains exactly when it must stop. Browser verification remains mandatory.</p></div></div>
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
                        <td><span class="status-pill {{ $session->status==='completed'?'bg-emerald-50 text-emerald-700':($session->status==='cancelled'?'bg-slate-100 text-slate-600':'bg-amber-50 text-amber-700') }}">{{ str($session->status)->replace('_',' ')->title() }}</span></td>
                        <td>{{ $session->updated_at->diffForHumans() }}</td>
                        <td><a class="font-bold text-indigo-600" href="{{ route('setup.show',$session) }}">{{ $session->status==='in_progress'?'Resume':'Review' }} →</a></td>
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
