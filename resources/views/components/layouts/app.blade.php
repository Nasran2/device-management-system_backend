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
    <div id="sidebar-overlay" class="fixed inset-0 z-40 hidden bg-slate-950/60 lg:hidden"></div>
    <aside id="app-sidebar" class="sidebar">
        <div class="flex items-center gap-3 px-5 py-6">
            <div class="grid size-11 place-items-center rounded-2xl bg-indigo-600 text-xl font-black text-white shadow-lg shadow-indigo-950/30">D</div>
            <div><p class="font-bold text-white">DeviceGuard</p><p class="text-xs text-slate-400">Secure device operations</p></div>
        </div>
        <nav class="flex-1 space-y-2 overflow-y-auto px-3 pb-6">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Dashboard</a>
            @if(auth()->user()->isSuperAdmin())
                <details class="nav-group" open><summary>Shop Management</summary><div>
                    <a class="nav-link {{ request()->routeIs('shops.index') && !request('status') ? 'active' : '' }}" href="{{ route('shops.index') }}">All Shop Owners</a>
                    <a class="nav-link {{ request()->routeIs('shops.create') ? 'active' : '' }}" href="{{ route('shops.create') }}">Add Shop Owner</a>
                    <a class="nav-link {{ request('status')==='active' ? 'active' : '' }}" href="{{ route('shops.index',['status'=>'active']) }}">Active Shops</a>
                    <a class="nav-link {{ request('status')==='inactive' ? 'active' : '' }}" href="{{ route('shops.index',['status'=>'inactive']) }}">Inactive Shops</a>
                    <a class="nav-link {{ request('status')==='archived' ? 'active' : '' }}" href="{{ route('shops.index',['status'=>'archived']) }}">Archived Shops</a>
                </div></details>
                <details class="nav-group" open><summary>Devices</summary><div>
                    <a class="nav-link {{ request()->routeIs('devices.index','devices.show') ? 'active' : '' }}" href="{{ route('devices.index') }}">All Devices</a>
                    <a class="nav-link {{ request()->routeIs('devices.create') ? 'active' : '' }}" href="{{ route('devices.create') }}">Register Device</a>
                    <a class="nav-link {{ request()->routeIs('devices.archived') ? 'active' : '' }}" href="{{ route('devices.archived') }}">Archived Devices</a>
                    <a class="nav-link {{ request()->routeIs('setup.*') ? 'active' : '' }}" href="{{ route('setup.index') }}">Device Setup Wizard</a>
                </div></details>
                <details class="nav-group" open><summary>Finance</summary><div>
                    <a class="nav-link {{ request()->routeIs('commissions.index') ? 'active' : '' }}" href="{{ route('commissions.index') }}">Shop Commission</a>
                    <a class="nav-link" href="{{ route('commissions.index') }}#shop-balances">Shop Balances</a>
                    <a class="nav-link" href="{{ route('commissions.index') }}#record-settlement">Commission Payments</a>
                    <a class="nav-link {{ request()->routeIs('reports.business') && request()->route('type')==='commissions' ? 'active' : '' }}" href="{{ route('reports.business','commissions') }}">Commission Report</a>
                </div></details>
                <details class="nav-group" open><summary>Communication</summary><div>
                    <a class="nav-link {{ request()->routeIs('sms.logs') ? 'active' : '' }}" href="{{ route('sms.logs') }}">SMS Logs</a>
                    <a class="nav-link {{ request()->routeIs('settings.sms') ? 'active' : '' }}" href="{{ route('settings.sms') }}#sms-templates">SMS Templates</a>
                </div></details>
                <details class="nav-group" open><summary>Protection</summary><div>
                    <a class="nav-link {{ request()->routeIs('settings.offline-protection*') ? 'active' : '' }}" href="{{ route('settings.offline-protection') }}">Offline Protection</a>
                    <a class="nav-link {{ request()->routeIs('reports.offline-protection') ? 'active' : '' }}" href="{{ route('reports.offline-protection') }}">Offline Report</a>
                </div></details>
                <details class="nav-group" open><summary>Settings</summary><div>
                    <a class="nav-link {{ request()->routeIs('settings.system') && request()->route('section')==='general' ? 'active' : '' }}" href="{{ route('settings.system','general') }}">General Settings</a>
                    <a class="nav-link {{ request()->routeIs('settings.system') && request()->route('section')==='commission' ? 'active' : '' }}" href="{{ route('settings.system','commission') }}">Commission Settings</a>
                    <a class="nav-link {{ request()->routeIs('settings.sms*') ? 'active' : '' }}" href="{{ route('settings.sms') }}">SMS Gateway Settings</a>
                    <a class="nav-link {{ request()->routeIs('settings.system') && request()->route('section')==='device' ? 'active' : '' }}" href="{{ route('settings.system','device') }}">Device Settings</a>
                    <a class="nav-link {{ request()->routeIs('settings.qr-provisioning*') ? 'active' : '' }}" href="{{ route('settings.qr-provisioning') }}">APK & Provisioning</a>
                    <a class="nav-link {{ request()->routeIs('phone-brands.*') ? 'active' : '' }}" href="{{ route('phone-brands.index') }}">Phone Brands</a>
                    <a class="nav-link {{ request()->routeIs('setup-instructions.*') ? 'active' : '' }}" href="{{ route('setup-instructions.index') }}">Setup Instructions</a>
                    <a class="nav-link {{ request()->routeIs('settings.system') && request()->route('section')==='roles' ? 'active' : '' }}" href="{{ route('settings.system','roles') }}">User Roles & Permissions</a>
                    <a class="nav-link {{ request()->routeIs('settings.audit-logs') ? 'active' : '' }}" href="{{ route('settings.audit-logs') }}">Audit Logs</a>
                </div></details>
            @else
                <details class="nav-group" open><summary>Customers & Devices</summary><div>
                    <a class="nav-link {{ request()->routeIs('customers.index','customers.show','customers.edit') ? 'active' : '' }}" href="{{ route('customers.index') }}">My Customers</a>
                    @if(auth()->user()->canShop('customers.manage'))<a class="nav-link {{ request()->routeIs('customers.create') ? 'active' : '' }}" href="{{ route('customers.create') }}">Add Customer</a>@endif
                    <a class="nav-link {{ request()->routeIs('devices.index','devices.show') ? 'active' : '' }}" href="{{ route('devices.index') }}">My Devices</a>
                    @if(auth()->user()->canShop('devices'))<a class="nav-link {{ request()->routeIs('devices.create') ? 'active' : '' }}" href="{{ route('devices.create') }}">Register Device</a>@endif
                    @if(auth()->user()->canShop('setup.manage'))<a class="nav-link {{ request()->routeIs('setup.*') ? 'active' : '' }}" href="{{ route('setup.index') }}">Device Setup Wizard</a>@endif
                </div></details>
                <details class="nav-group" open><summary>Finance</summary><div>
                    <a class="nav-link {{ request()->routeIs('payments.index','payments.show') ? 'active' : '' }}" href="{{ route('payments.index') }}">Customer Payments</a>
                    @if(auth()->user()->canShop('payments.create'))<a class="nav-link {{ request()->routeIs('payments.create') ? 'active' : '' }}" href="{{ route('payments.create') }}">Receive Payment</a>@endif
                    @if(auth()->user()->canShop('commission.view'))<a class="nav-link {{ request()->routeIs('commissions.*') ? 'active' : '' }}" href="{{ route('commissions.index') }}">Platform Commission</a>@endif
                </div></details>
                @if(auth()->user()->canShop('reports.view'))<details class="nav-group" open><summary>Reports</summary><div><a class="nav-link {{ request()->routeIs('reports.business') ? 'active' : '' }}" href="{{ route('reports.business','payments') }}">Business Reports</a><a class="nav-link {{ request()->routeIs('reports.offline-protection') ? 'active' : '' }}" href="{{ route('reports.offline-protection') }}">Offline Report</a></div></details>@endif
                <details class="nav-group" open><summary>Communication</summary><div><a class="nav-link {{ request()->routeIs('sms.logs') ? 'active' : '' }}" href="{{ route('sms.logs') }}">My SMS History</a></div></details>
                @if(auth()->user()->canShop('shop.settings') || auth()->user()->isShopOwner())<details class="nav-group" open><summary>Shop Settings</summary><div><a class="nav-link {{ request()->routeIs('shop-profile.*') ? 'active' : '' }}" href="{{ route('shop-profile.edit') }}">My Shop Profile</a>@if(auth()->user()->isShopOwner() && auth()->user()->shop?->staff_accounts_enabled)<a class="nav-link {{ request()->routeIs('shop-users*') ? 'active' : '' }}" href="{{ route('shop-users.index') }}">Staff Accounts</a>@endif</div></details>@endif
            @endif
        </nav>
        <div class="mt-auto border-t border-slate-800 p-4">
            <p class="truncate text-sm font-semibold text-white">{{ auth()->user()->name }}</p>
            <p class="mb-3 text-xs capitalize text-slate-400">{{ str_replace('_', ' ', auth()->user()->role) }}</p>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="text-sm text-slate-300 hover:text-white">Sign out</button></form>
        </div>
    </aside>
    <div class="flex min-h-screen min-w-0 flex-1 flex-col">
        <header class="flex h-16 items-center justify-between border-b border-slate-200 bg-white px-5 lg:px-8">
            <div class="flex items-center gap-3"><button id="sidebar-toggle" class="secondary-button px-3 py-2 lg:hidden" type="button" aria-label="Open navigation">☰</button><p class="text-xs font-semibold uppercase tracking-widest text-indigo-600">Authorized management only</p></div>
            <span class="status-pill bg-emerald-50 text-emerald-700">System online</span>
        </header>
        <main class="flex-1 p-5 lg:p-8">
            @if(session('success'))<div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>@endif
            @if(session('warning'))<div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">{{ session('warning') }}</div>@endif
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
