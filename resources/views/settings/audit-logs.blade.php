<x-layouts.app title="Audit Logs">
    <div class="mb-7"><p class="eyebrow">Settings</p><h1 class="page-title">Audit Logs</h1><p class="page-copy">Security, financial, device, shop, and setup actions with request context.</p></div>
    <section class="panel">
        <form class="panel-header flex-wrap" method="get"><input class="field-input max-w-sm" name="search" value="{{ request('search') }}" placeholder="Action, description or user"><button class="secondary-button">Search</button></form>
        <div class="overflow-x-auto"><table class="data-table"><thead><tr><th>Date</th><th>User</th><th>Shop</th><th>Action</th><th>Description</th><th>Device</th><th>IP address</th></tr></thead><tbody>@forelse($logs as $log)<tr><td>{{ $log->created_at->format('d M Y H:i:s') }}</td><td>{{ $log->user?->name??'System' }}</td><td>{{ $log->shop_id?:'Platform' }}</td><td><span class="status-pill bg-slate-100 text-slate-700">{{ $log->action }}</span></td><td>{{ $log->description }}</td><td>{{ $log->device?->brand }} {{ $log->device?->model }}</td><td>{{ $log->ip_address?:'—' }}</td></tr>@empty<tr><td colspan="7" class="py-10 text-center">No audit entries found.</td></tr>@endforelse</tbody></table></div><div class="p-5">{{ $logs->links() }}</div>
    </section>
</x-layouts.app>
