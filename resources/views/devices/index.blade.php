<x-layouts.app title="Devices">
<div class="mb-7 flex flex-wrap items-end justify-between gap-4"><div><p class="eyebrow">Inventory</p><h1 class="page-title">{{ auth()->user()->isSuperAdmin() ? 'All devices' : 'My devices' }}</h1><p class="page-copy">Registered devices cannot be permanently deleted by normal Admins.</p></div><a class="primary-button" href="{{ route('devices.create') }}">+ Register device</a></div>
<section class="panel"><form class="panel-header flex-wrap" method="get"><input class="field-input max-w-md" name="search" value="{{ request('search') }}" placeholder="Search customer, brand, or model"><select class="field-input max-w-52" name="status"><option value="">All statuses</option>@foreach(['pending_activation','active_unlocked','locked','offline','permanently_released','demo'] as $status)<option @selected(request('status')===$status) value="{{ $status }}">{{ ucwords(str_replace('_',' ',$status)) }}</option>@endforeach</select><button class="secondary-button">Filter</button></form>
<div class="overflow-x-auto"><table class="data-table"><thead><tr>
    @if(auth()->user()->isSuperAdmin())
        <th class="w-8"><input type="checkbox" onchange="document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked)" class="rounded border-slate-300"></th>
    @endif
    <th>Customer</th><th>Device</th><th>IMEI</th><th>Price</th><th>Added by</th><th>Mode</th><th>Status</th><th>Actions</th>
</tr></thead><tbody>
@forelse($devices as $device)<tr>
    @if(auth()->user()->isSuperAdmin())
        <td><input form="bulk-delete-form" type="checkbox" name="device_ids[]" value="{{ $device->id }}" class="row-checkbox rounded border-slate-300"></td>
    @endif
    <td><strong>{{ $device->customer->name }}</strong><br><small>{{ $device->customer->phone }}</small></td>
    <td>{{ $device->brand }} {{ $device->model }}</td>
    <td>{{ $device->masked_imei }}</td>
    <td>{{ number_format($device->selling_price,2) }} {{ $device->currency }}</td>
    <td>{{ $device->admin->name }}</td>
    <td>{{ $device->management_mode }}</td>
    <td>{{ str_replace('_',' ',$device->status) }}</td>
    <td>
        <button type="button" onclick="document.getElementById('modal-{{$device->id}}').showModal()" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 transition-colors bg-indigo-50 px-3 py-1.5 rounded-full">Actions &rarr;</button>
        
        <dialog id="modal-{{$device->id}}" class="m-auto rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50 backdrop:backdrop-blur-sm w-full max-w-sm border-0 open:animate-in open:fade-in open:zoom-in-95">
            <div class="p-6">
                <div class="flex justify-between items-start mb-5">
                    <div>
                        <h3 class="font-bold text-lg text-slate-900">Device Actions</h3>
                        <p class="text-xs text-slate-500 mt-1">{{ $device->brand }} {{ $device->model }}</p>
                    </div>
                    <button type="button" onclick="document.getElementById('modal-{{$device->id}}').close()" class="text-slate-400 hover:text-slate-700 text-xl leading-none">&times;</button>
                </div>
                
                <div class="space-y-3">
                    <a class="secondary-button block text-center w-full" href="{{ route('devices.show',$device) }}">View Details</a>
                    @can('update',$device)
                    <a class="secondary-button block text-center w-full" href="{{ route('devices.edit',$device) }}">Edit Record</a>
                    @endcan
                    
                    @can('archive',$device)
                    <details class="group">
                        <summary class="secondary-button w-full cursor-pointer list-none text-center marker:hidden">Archive Device</summary>
                        <form method="post" action="{{ route('devices.archive',$device) }}" class="mt-2 space-y-3 rounded-xl border bg-slate-50 p-4">
                            @csrf
                            <p class="text-xs text-slate-600">Archiving will cancel pending commands and remove it from the active list.</p>
                            <input class="field-input" type="password" name="password" placeholder="Account password" required>
                            <button class="secondary-button w-full bg-white">Confirm Archive</button>
                        </form>
                    </details>
                    @endcan 
                    
                    @if(auth()->user()->isSuperAdmin())
                    <details class="group">
                        <summary class="danger-button w-full cursor-pointer list-none text-center marker:hidden mt-2">Delete Permanently</summary>
                        <form method="post" action="{{ route('devices.destroy',$device->id) }}" class="mt-2 space-y-3 rounded-xl border border-red-200 bg-red-50 p-4">
                            @csrf @method('delete')
                            <p class="text-xs text-red-700 font-semibold">Warning: This action cannot be undone.</p>
                            <textarea class="field-input" name="reason" placeholder="Deletion reason" required></textarea>
                            <input class="field-input" type="password" name="password" placeholder="Super Admin password" required>
                            <input class="field-input" name="confirmation" placeholder="Type DELETE" pattern="DELETE" required>
                            <label class="flex gap-2 text-xs text-red-900"><input type="checkbox" name="confirmed" value="1" required class="mt-0.5 rounded border-red-300 text-red-600 focus:ring-red-600"> I understand this removes the record.</label>
                            <button class="danger-button w-full">Delete</button>
                        </form>
                    </details>
                    @endif
                </div>
            </div>
        </dialog>
    </td>
</tr>
@empty<tr><td colspan="9" class="py-12 text-center">No matching devices.</td></tr>@endforelse
</tbody></table></div><div class="p-5">{{ $devices->links() }}</div></section>
@if(auth()->user()->isSuperAdmin())<form id="bulk-delete-form" method="post" action="{{ route('devices.bulk-delete') }}" class="panel mt-6 space-y-3 p-6 border-red-100 bg-red-50/30">
    @csrf @method('delete')
    <div class="mb-4">
        <h2 class="text-lg font-bold text-red-700">Bulk Delete Selected Devices</h2>
        <p class="text-sm text-slate-600">Only eligible devices (unmanaged, demo, or pending) will be deleted. Actively managed devices will be skipped.</p>
    </div>
    <div class="grid sm:grid-cols-2 gap-4">
        <input class="field-input bg-white" name="reason" placeholder="Deletion reason" required>
        <input class="field-input bg-white" type="password" name="password" placeholder="Super Admin password" required>
    </div>
    <div class="flex items-center gap-4 mt-2">
        <input class="field-input bg-white max-w-xs" name="confirmation" placeholder="Type DELETE" pattern="DELETE" required>
        <label class="flex items-center gap-2 text-sm text-red-900"><input type="checkbox" name="confirmed" value="1" required class="rounded border-red-300 text-red-600 focus:ring-red-600"> I confirm I want to permanently remove selected records.</label>
        <button class="danger-button ml-auto">Delete Selected Devices</button>
    </div>
</form>@endif
</x-layouts.app>
