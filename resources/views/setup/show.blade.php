<x-layouts.app title="Device Setup Wizard">
@php
    $completed = $setup->steps->where('completed', true)->keyBy('step_key');
    $percent = round($completed->count() / max(1, $steps->count()) * 100);
    $brandLabel = \App\Services\SetupInstructionCatalog::BRANDS[$setup->brand_group] ?? ucfirst($setup->brand_group);
@endphp
<div class="mx-auto max-w-[1500px]">
    <header class="mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a class="text-sm font-bold text-indigo-600" href="{{ route('setup.index') }}">← Setup history</a>
                <p class="eyebrow mt-4">Structured {{ str($setup->mode)->replace('_',' ')->title() }}</p>
                <h1 class="page-title">{{ $setup->device->brand }} {{ $setup->device->model }}</h1>
                <p class="page-copy">{{ $brandLabel }} instructions · {{ \App\Services\SetupInstructionCatalog::OSES[$setup->computer_os] }} computer · {{ $setup->device->customer?->name }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-right shadow-sm"><p class="text-2xl font-black text-indigo-700">{{ $percent }}%</p><p class="text-xs font-semibold text-slate-500">{{ $completed->count() }} of {{ $steps->count() }} verified</p></div>
        </div>
        <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-indigo-600 transition-all" style="width:{{ $percent }}%"></div></div>
    </header>

    @if($setup->mode === 'setup_helper')
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-indigo-200 bg-indigo-50 p-5">
            <div><p class="font-black text-indigo-950">Setup Helper is enabled</p><p class="text-sm text-indigo-800">The signed helper runs only on this authorized computer. It cannot prove Android/server state, so every wizard verification still applies.</p></div>
            <a class="primary-button" href="{{ $helperUrl }}">Download authenticated {{ $setup->computer_os==='macos'?'macOS shell':'Windows PowerShell' }} helper</a>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <main class="min-w-0 space-y-5">
            <section class="panel overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-950 p-6 text-white">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0"><p class="text-xs font-bold uppercase tracking-[.18em] text-indigo-300">Step {{ $currentIndex + 1 }} of {{ $steps->count() }}</p><h2 class="mt-2 text-2xl font-black">{{ $step->title }}</h2><p class="mt-2 break-all text-sm text-slate-300 sm:break-words">{{ $step->short_description }}</p></div>
                        <span class="rounded-full bg-indigo-500/20 px-4 py-2 text-xs font-bold text-indigo-100">📍 {{ $step->action_location }}</span>
                    </div>
                </div>

                <div class="space-y-7 p-6 lg:p-8">
                    <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-5">
                        <h3 class="text-sm font-black uppercase tracking-wider text-indigo-900">Why this is required</h3>
                        <p class="mt-2 text-sm leading-6 text-indigo-950">{{ $step->why_required }}</p>
                    </div>

                    @if($step->screenshot_path)
                        <figure class="overflow-hidden rounded-2xl border border-slate-200"><img class="w-full" src="{{ asset('storage/'.$step->screenshot_path) }}" alt="{{ $step->title }} reference"><figcaption class="p-3 text-xs text-slate-500">Super Admin reference screenshot</figcaption></figure>
                    @endif

                    <section>
                        <h3 class="section-title">Numbered instructions</h3>
                        <ol class="mt-4 space-y-3">
                            @foreach($step->numbered_instructions as $instruction)
                                <li class="flex min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-4"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-black text-indigo-700">{{ $loop->iteration }}</span><p class="min-w-0 break-all pt-1 text-sm leading-6 text-slate-700 sm:break-words">{{ $instruction }}</p></li>
                            @endforeach
                        </ol>
                    </section>

                    @if($step->command)
                        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-950 text-white">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 px-4 py-3">
                                <div><span class="rounded bg-slate-800 px-2 py-1 text-xs font-bold text-cyan-300">{{ $step->shell_type }}</span><span class="ml-2 text-xs text-slate-400">Run from: {{ $step->run_from }}</span></div>
                                <button type="button" class="rounded-lg bg-white/10 px-3 py-2 text-xs font-bold hover:bg-white/20" onclick="navigator.clipboard.writeText(document.getElementById('setup-command').innerText); this.textContent='Copied ✓'">Copy command</button>
                            </div>
                            <pre id="setup-command" class="max-w-full overflow-x-auto whitespace-pre-wrap break-all p-5 text-sm leading-7 text-emerald-300 sm:break-words">{{ $step->command }}</pre>
                            @if($step->terminal_help)<p class="border-t border-slate-800 px-5 py-3 text-xs text-slate-400">Terminal help: {{ $step->terminal_help }}</p>@endif
                        </section>
                    @endif

                    <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                        <h3 class="text-sm font-black uppercase tracking-wider text-emerald-900">Expected result</h3>
                        <p class="mt-2 text-sm leading-6 text-emerald-950">{{ $step->expected_output }}</p>
                    </section>

                    <section>
                        <h3 class="section-title">If the result is different</h3>
                        <div class="mt-3 space-y-3">
                            @foreach($step->possible_errors as $error)
                                <details class="group rounded-2xl border border-rose-200 bg-rose-50 p-4">
                                    <summary class="cursor-pointer list-none font-bold text-rose-900"><span class="mr-2">⚠</span>{{ $error['output'] }} <span class="float-right group-open:rotate-180">⌄</span></summary>
                                    <div class="mt-4 grid gap-3 text-sm md:grid-cols-2"><div><p class="text-xs font-black uppercase text-rose-700">What it means</p><p class="mt-1 leading-6 text-rose-950">{{ $error['meaning'] }}</p></div><div><p class="text-xs font-black uppercase text-rose-700">How to fix it</p><p class="mt-1 leading-6 text-rose-950">{{ $error['solution'] }}</p></div></div>
                                </details>
                            @endforeach
                        </div>
                    </section>

                    @if($step->server_check_key)
                        <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5">
                            <div class="flex items-center justify-between gap-3"><div><h3 class="font-black text-sky-950">Live verification required</h3><p class="mt-1 text-sm text-sky-800">This step checks Android-reported data saved by the server. Refresh the page after the phone syncs.</p></div><a class="secondary-button" href="{{ route('setup.show',$setup) }}">Refresh status</a></div>
                        </section>
                    @endif

                    <form method="post" action="{{ route('setup.step',$setup) }}" class="space-y-6" id="step-form">
                        @csrf
                        <input type="hidden" name="step_key" value="{{ $step->step_key }}">
                        <input type="hidden" name="direction" value="next">

                        @if($step->confirmation_items)
                            <fieldset class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-5">
                                <legend class="px-2 font-black text-amber-950">Required confirmations</legend>
                                <div class="space-y-3">
                                    @foreach($step->confirmation_items as $confirmation)
                                        <label class="flex gap-3 text-sm font-medium text-amber-950"><input class="step-check mt-1" type="checkbox" name="confirmations[]" value="{{ $confirmation }}" required><span>{{ str($confirmation)->replace('_',' ')->title() }} confirmed</span></label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif

                        <fieldset class="rounded-2xl border border-slate-200 p-5">
                            <legend class="px-2 font-black">Verification checklist</legend>
                            <div class="space-y-3">
                                @foreach($step->verification_items as $item)
                                    <label class="flex gap-3 text-sm text-slate-700"><input class="step-check mt-1" type="checkbox" required><span>{{ $item }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>

                        @if($step->step_key === 'adb_check')
                            <fieldset><legend class="field-label">Recorded command result <x-required/></legend><div class="mt-2 flex flex-wrap gap-3">@foreach(['ADB_FOUND','ADB_NOT_FOUND'] as $result)<label class="rounded-xl border border-slate-200 px-4 py-3 font-mono text-sm has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50"><input type="radio" name="command_result" value="{{ $result }}" required class="mr-2">{{ $result }}</label>@endforeach</div></fieldset>
                        @elseif($step->step_key === 'device_owner_verify')
                            <label class="field-label">Android dumpsys result <x-required/><select class="field-input" name="command_result" required><option value="">Choose only after reading Android output</option><option value="ANDROID_CONFIRMED">ANDROID_CONFIRMED — exact DeviceGuard owner shown</option><option value="ERROR_RECORDED">Different/error output — keep step open</option></select></label>
                        @elseif($step->command && !$step->auto_verifiable)
                            <label class="field-label">Command/output result <x-required/><select class="field-input" name="command_result" required><option value="">Record the result</option><option value="EXPECTED_OUTPUT_CONFIRMED">Expected output confirmed</option><option value="ERROR_RECORDED">Different/error output — keep step open</option></select></label>
                        @endif

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="field-label">Error encountered (optional)<textarea class="field-input mt-1" name="error_encountered" rows="3" placeholder="Paste only non-sensitive error text"></textarea></label>
                            <label class="field-label">Troubleshooting used (optional)<textarea class="field-input mt-1" name="troubleshooting_used" rows="3" placeholder="What was changed before verification succeeded?"></textarea></label>
                        </div>
                        <label class="field-label">Technician notes (optional)<textarea class="field-input mt-1" name="notes" rows="3" placeholder="Non-sensitive work-order notes"></textarea></label>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-6">
                            @if($currentIndex > 0)
                                <button type="submit" class="secondary-button" form="previous-form">← Previous</button>
                            @else<span></span>@endif
                            <div class="flex flex-wrap gap-2"><a class="secondary-button" href="#support">Need help</a><button class="primary-button" id="verify-next">{{ $currentIndex === $steps->count()-1 ? 'Verify final gates & complete' : 'Verify & continue' }} →</button></div>
                        </div>
                    </form>
                    @if($currentIndex > 0)
                        <form id="previous-form" method="post" action="{{ route('setup.step',$setup) }}">@csrf<input type="hidden" name="step_key" value="{{ $step->step_key }}"><input type="hidden" name="direction" value="previous"></form>
                    @endif
                </div>
            </section>

            <section id="support" class="panel p-6">
                <h2 class="section-title">Search and support</h2>
                <p class="section-copy">Search this step’s visible instructions and errors, or return to the device page for server command diagnostics.</p>
                <div class="mt-4 flex flex-wrap gap-3"><input id="step-search" class="field-input max-w-lg" type="search" placeholder="Search this page…" oninput="window.find && window.find(this.value)"><a class="secondary-button" href="{{ route('devices.show',$setup->device) }}">Open device diagnostics</a>@if($setup->mode!=='setup_helper')<a class="secondary-button" href="{{ $helperUrl }}">Download authenticated {{ $setup->computer_os==='macos'?'macOS shell':'Windows PowerShell' }} helper</a>@endif<a class="secondary-button" href="{{ route('setup.index') }}">Save and exit</a></div>
            </section>
        </main>

        <details class="fixed inset-x-3 bottom-3 z-30 rounded-2xl border border-indigo-200 bg-white shadow-2xl xl:hidden">
            <summary class="flex cursor-pointer list-none items-center justify-between rounded-2xl bg-indigo-600 px-5 py-4 text-sm font-black text-white">
                <span>Setup progress · {{ $completed->count() }}/{{ $steps->count() }}</span><span>{{ $percent }}% · Open checklist ↑</span>
            </summary>
            <div class="max-h-[55vh] overflow-y-auto p-4">
                <ol class="space-y-2">
                    @foreach($steps as $mobileStep)
                        <li class="flex gap-3 rounded-xl p-2 {{ $loop->index===$currentIndex?'bg-indigo-50':'' }}"><span class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-black {{ $completed->has($mobileStep->step_key)?'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-500' }}">{{ $completed->has($mobileStep->step_key)?'✓':$loop->iteration }}</span><span class="text-xs font-semibold leading-5">{{ $mobileStep->title }}</span></li>
                    @endforeach
                </ol>
                <p class="mt-4 rounded-xl bg-amber-50 p-3 text-xs font-semibold text-amber-900">{{ collect($serverChecks)->where('ok',false)->count() }} live server checks still need attention.</p>
            </div>
        </details>

        <aside class="hidden space-y-5 xl:sticky xl:top-6 xl:block xl:self-start">
            <section class="panel p-5">
                <div class="flex items-center justify-between"><h2 class="font-black">All setup steps</h2><span class="text-xs font-bold text-slate-500">{{ $completed->count() }}/{{ $steps->count() }}</span></div>
                <ol class="mt-4 max-h-[420px] space-y-2 overflow-y-auto pr-1">
                    @foreach($steps as $listStep)
                        <li class="flex gap-3 rounded-xl p-3 {{ $loop->index===$currentIndex?'bg-indigo-50 ring-1 ring-indigo-200':'' }}">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-black {{ $completed->has($listStep->step_key)?'bg-emerald-100 text-emerald-700':'bg-slate-100 text-slate-500' }}">{{ $completed->has($listStep->step_key)?'✓':$loop->iteration }}</span>
                            <span class="text-xs font-semibold leading-5 {{ $loop->index===$currentIndex?'text-indigo-900':'text-slate-600' }}">{{ $listStep->title }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="panel p-5">
                <div class="flex items-center justify-between"><h2 class="font-black">Live server checks</h2><a class="text-xs font-bold text-indigo-600" href="{{ route('setup.show',$setup) }}">Refresh</a></div>
                <div class="mt-4 space-y-2">
                    @foreach($serverChecks as $check)
                        <div class="rounded-xl border p-3 {{ $check['ok']?'border-emerald-200 bg-emerald-50':'border-amber-200 bg-amber-50' }}">
                            <div class="flex items-center justify-between gap-2"><p class="text-xs font-black {{ $check['ok']?'text-emerald-900':'text-amber-950' }}">{{ $check['label'] }}</p><span class="text-xs">{{ $check['ok']?'✓ Confirmed':'● Needs attention' }}</span></div>
                            <p class="mt-1 text-[11px] {{ $check['ok']?'text-emerald-700':'text-amber-800' }}">{{ $check['detail'] }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-xs leading-5 text-slate-500">Setup cannot be completed while any required server check needs attention.</p>
            </section>
        </aside>
    </div>
</div>
</x-layouts.app>
