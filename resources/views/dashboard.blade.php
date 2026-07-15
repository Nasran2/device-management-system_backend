<x-layouts.app title="Dashboard">
    <div class="mb-7 flex flex-wrap items-end justify-between gap-4"><div><p class="eyebrow">Operations overview</p><h1 class="page-title">Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ Str::before(auth()->user()->name, ' ') }}</h1><p class="page-copy">Confirmed device state, value, and connectivity at a glance.</p></div><a class="primary-button" href="{{ route('devices.create') }}">+ Register device</a></div>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([['Devices',$stats['devices'],'indigo'],['Portfolio value',number_format($stats['value'],2).' LKR','blue'],['Locked',$stats['locked'],'red'],['Unlocked',$stats['unlocked'],'emerald'],['Offline',$stats['offline'],'slate'],['Released',$stats['released'],'cyan']] as [$label,$value,$tone])
            <article class="metric-card"><div class="mb-5 flex items-center justify-between"><p class="text-sm font-semibold text-slate-500">{{ $label }}</p><span class="size-2.5 rounded-full bg-{{ $tone }}-500"></span></div><p class="text-2xl font-black tracking-tight text-slate-950">{{ $value }}</p></article>
        @endforeach
        @if($stats['admins'] !== null)<article class="metric-card"><p class="mb-5 text-sm font-semibold text-slate-500">Active admins</p><p class="text-2xl font-black">{{ $stats['admins'] }}</p></article>@endif
    </div>
    <section class="panel mt-7"><div class="panel-header"><div><h2 class="section-title">Recent devices</h2><p class="section-copy">State changes are shown only after confirmation from the Android app.</p></div><a class="text-sm font-semibold text-indigo-600" href="{{ route('devices.index') }}">View all →</a></div>
        <div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Customer</th><th>Device</th><th>Value</th><th>Mode</th><th>Status</th><th>Last online</th></tr></thead><tbody>
        @forelse($devices as $device)<tr class="cursor-pointer" onclick="location.href='{{ route('devices.show',$device) }}'"><td><p class="font-semibold">{{ $device->customer->name }}</p><p class="text-xs text-slate-500">{{ $device->customer->phone }}</p></td><td>{{ $device->brand }} {{ $device->model }}</td><td>{{ number_format($device->selling_price,2) }} {{ $device->currency }}</td><td class="capitalize">{{ $device->management_mode }}</td><td><span class="status-pill {{ $device->lock_status === 'locked' ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' }}">{{ str_replace('_',' ',$device->status) }}</span></td><td>{{ $device->last_seen_at?->diffForHumans() ?? 'Never' }}</td></tr>@empty<tr><td colspan="6" class="py-12 text-center text-slate-500">No devices registered yet.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</x-layouts.app>
