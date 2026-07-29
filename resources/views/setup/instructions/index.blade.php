<x-layouts.app title="Setup Instructions">
<div class="mx-auto max-w-7xl">
    <div class="mb-7"><p class="eyebrow">Super Admin</p><h1 class="page-title">Structured Setup Instructions</h1><p class="page-copy">Manage a complete, isolated instruction set for each computer OS and phone family.</p></div>
    <section class="panel mb-6 p-5">
        <form class="flex flex-wrap items-end gap-4" method="get">
            <label class="field-label">Computer OS<select class="field-input mt-1" name="computer_os">@foreach($oses as $value=>$label)<option value="{{ $value }}" @selected($os===$value)>{{ $label }}</option>@endforeach</select></label>
            <label class="field-label">Phone family<select class="field-input mt-1" name="phone_brand">@foreach($brands as $value=>$label)<option value="{{ $value }}" @selected($brand===$value)>{{ $label }}</option>@endforeach</select></label>
            <button class="primary-button">Load variant</button>
        </form>
    </section>
    <section class="panel overflow-hidden">
        <div class="panel-header flex flex-wrap items-center justify-between gap-3"><div><h2 class="section-title">{{ $oses[$os] }} · {{ $brands[$brand] }}</h2><p class="section-copy">{{ $instructions->count() }} structured steps. Inactive steps are excluded from new and resumed sessions.</p></div>
            <form method="post" action="{{ route('setup-instructions.sync') }}" onsubmit="return !this.overwrite.checked || confirm('Restore every step in this variant to the built-in defaults?')">@csrf<input type="hidden" name="computer_os" value="{{ $os }}"><input type="hidden" name="phone_brand" value="{{ $brand }}"><label class="mr-3 text-xs"><input type="checkbox" name="overwrite" value="1"> Restore defaults</label><button class="secondary-button">Generate / sync</button></form>
        </div>
        <div class="overflow-x-auto"><table class="data-table"><thead><tr><th>#</th><th>Step</th><th>Location</th><th>Command</th><th>Server check</th><th>Status</th><th></th></tr></thead><tbody>
        @foreach($instructions as $instruction)<tr><td>{{ $instruction->step_number }}</td><td><p class="font-bold">{{ $instruction->title }}</p><p class="mt-1 max-w-xl text-xs text-slate-500">{{ $instruction->short_description }}</p></td><td>{{ $instruction->action_location }}</td><td>{{ $instruction->shell_type ?: 'Phone/UI' }}</td><td>{{ $instruction->server_check_key ?: 'Technician' }}</td><td><span class="status-pill {{ $instruction->active?'bg-emerald-50 text-emerald-700':'bg-slate-100 text-slate-600' }}">{{ $instruction->active?'Active':'Inactive' }}</span></td><td><a class="font-bold text-indigo-600" href="{{ route('setup-instructions.edit',$instruction) }}">Edit →</a></td></tr>@endforeach
        </tbody></table></div>
    </section>
</div>
</x-layouts.app>
