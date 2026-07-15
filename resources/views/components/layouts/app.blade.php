@props(['title' => 'DeviceGuard'])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'DeviceGuard' }} · Device Management</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<div class="min-h-screen lg:flex">
    <aside class="sidebar">
        <div class="flex items-center gap-3 px-5 py-6">
            <div class="grid size-11 place-items-center rounded-2xl bg-indigo-600 text-xl font-black text-white shadow-lg shadow-indigo-950/30">D</div>
            <div><p class="font-bold text-white">DeviceGuard</p><p class="text-xs text-slate-400">Secure device operations</p></div>
        </div>
        <nav class="space-y-1 px-3">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Dashboard</a>
            <a class="nav-link {{ request()->routeIs('devices.index') || request()->routeIs('devices.show') ? 'active' : '' }}" href="{{ route('devices.index') }}">{{ auth()->user()->isSuperAdmin() ? 'All devices' : 'My devices' }}</a>
            <a class="nav-link {{ request()->routeIs('devices.create') ? 'active' : '' }}" href="{{ route('devices.create') }}">Register device</a>
            @if(auth()->user()->isSuperAdmin())<a class="nav-link {{ request()->routeIs('settings.qr-provisioning*') ? 'active' : '' }}" href="{{ route('settings.qr-provisioning') }}">QR Provisioning Settings</a>@endif
        </nav>
        <div class="mt-auto border-t border-slate-800 p-4">
            <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
            <p class="mb-3 text-xs capitalize text-slate-400">{{ str_replace('_', ' ', auth()->user()->role) }}</p>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="text-sm text-slate-300 hover:text-white">Sign out</button></form>
        </div>
    </aside>
    <div class="flex min-h-screen min-w-0 flex-1 flex-col">
        <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-5 lg:px-8">
            <div><p class="text-xs font-semibold uppercase tracking-widest text-indigo-600">Authorized management only</p></div>
            <span class="status-pill bg-emerald-50 text-emerald-700">System online</span>
        </header>
        <main class="flex-1 p-5 lg:p-8">
            @if(session('success'))<div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><p class="font-semibold">Please correct the following:</p><ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            {{ $slot }}
        </main>
        <footer class="border-t border-slate-200 bg-white px-6 py-4 text-center text-sm text-slate-500">
            Powered by <a href="https://twinsofte.com" target="_blank" rel="noopener noreferrer" class="font-semibold text-indigo-600 hover:text-indigo-700">twinsofte.com</a>
        </footer>
    </div>
</div>
</body>
</html>
