<x-layouts.app title="Device Setup Wizard">
    <div class="mb-7 flex flex-wrap items-end justify-between gap-3">
        <div><p class="eyebrow">Devices</p><h1 class="page-title">Device Setup Wizard</h1><p class="page-copy">Start a guided setup or resume an incomplete session.</p></div>
    </div>
    @if($devices->isNotEmpty())
        <details class="panel mb-6 p-5">
            <summary class="cursor-pointer font-bold">Start setup for a registered device</summary>
            <form class="form-grid mt-5" method="post" action="" id="setup-start-form">
                @csrf
                <label class="field-label">Device <x-required/><select class="field-input" id="setup-device" required><option value="">Choose a device</option>@foreach($devices as $device)<option value="{{ route('setup.start',$device) }}">{{ $device->customer->name }} — {{ $device->brand }} {{ $device->model }}</option>@endforeach</select></label>
                <label class="field-label">Computer OS <x-required/><select class="field-input" name="computer_os" required><option value="macos">macOS</option><option value="windows">Windows</option></select></label>
                <label class="field-label">Phone group <x-required/><select class="field-input" name="brand_group" required>@foreach(['samsung','xiaomi','oppo','vivo','standard','transsion','other'] as $brand)<option value="{{ $brand }}">{{ ucfirst($brand) }}</option>@endforeach</select></label>
                <label class="flex gap-2 rounded-xl bg-amber-50 p-4 text-sm"><input type="checkbox" name="authorized" value="1" required> I confirm this shop is authorized to manage the phone and the customer agreed.</label>
                <button class="primary-button" onclick="const d=document.getElementById('setup-device'); if(d.value){this.form.action=d.value}">Start guided setup</button>
            </form>
        </details>
    @endif
    <section class="panel overflow-x-auto">
        <div class="panel-header"><h2 class="section-title">Setup history</h2></div>
        <table class="data-table"><thead><tr><th>Device</th><th>Customer</th><th>OS</th><th>Brand group</th><th>Progress</th><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>
        @forelse($sessions as $session)<tr><td>{{ $session->device->brand }} {{ $session->device->model }}</td><td>{{ $session->device->customer->name }}</td><td>{{ ucfirst($session->computer_os) }}</td><td>{{ ucfirst($session->brand_group) }}</td><td>Step {{ $session->current_step }}</td><td><span class="status-pill {{ $session->status==='completed'?'bg-emerald-50 text-emerald-700':'bg-amber-50 text-amber-700' }}">{{ $session->status }}</span></td><td>{{ $session->updated_at->diffForHumans() }}</td><td><a class="text-indigo-600 font-bold" href="{{ route('setup.show',$session) }}">{{ $session->status==='completed'?'View':'Resume' }}</a></td></tr>@empty<tr><td colspan="8" class="py-10 text-center">No setup sessions yet.</td></tr>@endforelse
        </tbody></table><div class="p-5">{{ $sessions->links() }}</div>
    </section>
</x-layouts.app>
