<x-layouts.app title="Shop Staff">
    <div class="mb-7">
        <p class="eyebrow">{{ $shop->name }}</p>
        <h1 class="page-title">Staff Accounts</h1>
        <p class="page-copy">Create separate logins and grant only the permissions each employee needs.</p>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="panel p-6">
            <h2 class="section-title">Add staff member</h2>
            <form class="mt-5 space-y-4" method="post" action="{{ route('shop-users.store') }}">
                @csrf
                <label class="field-label">Name <x-required/><input class="field-input" name="name" required></label>
                <label class="field-label">Email <x-required/><input class="field-input" type="email" name="email" required></label>
                <label class="field-label">Phone<input class="field-input" name="phone"></label>
                <label class="field-label">Temporary password <x-required/><input class="field-input" type="password" name="password" minlength="10" required></label>
                <label class="field-label">Staff role <x-required/>
                    <select class="field-input" name="staff_role" required>
                        @foreach(['shop_manager','sales_staff','payment_collector','device_setup_staff','read_only_staff'] as $role)
                            <option value="{{ $role }}">{{ ucwords(str_replace('_',' ',$role)) }}</option>
                        @endforeach
                    </select>
                </label>
                <fieldset>
                    <legend class="field-label">Permissions</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach(['customers.manage'=>'Manage customers','devices.create'=>'Register devices','devices.lock'=>'Lock or unlock','payments.create'=>'Receive payments','payments.reverse'=>'Reverse payments','setup.manage'=>'Run setup wizard','reports.view'=>'View reports','shop.settings'=>'Shop settings','commission.view'=>'Commission view'] as $permission=>$label)
                            <label class="rounded-xl bg-slate-50 p-3"><input type="checkbox" name="shop_permissions[]" value="{{ $permission }}"> {{ $label }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <button class="primary-button">Create staff login</button>
            </form>
        </section>

        <section class="space-y-4">
            @foreach($users->where('role','shop_staff') as $staff)
                <form class="panel p-5" method="post" action="{{ route('shop-users.update',$staff) }}">
                    @csrf @method('patch')
                    <div class="flex items-start justify-between gap-3">
                        <div><h2 class="font-black">{{ $staff->name }}</h2><p class="text-sm text-slate-500">{{ $staff->email }}</p></div>
                        <label><input type="checkbox" name="is_active" value="1" @checked($staff->is_active)> Active</label>
                    </div>
                    <select class="field-input mt-4" name="staff_role">
                        @foreach(['shop_manager','sales_staff','payment_collector','device_setup_staff','read_only_staff'] as $role)
                            <option value="{{ $role }}" @selected($staff->staff_role===$role)>{{ ucwords(str_replace('_',' ',$role)) }}</option>
                        @endforeach
                    </select>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach(['customers.manage'=>'Customers','devices.create'=>'Device registration','devices.lock'=>'Lock/unlock','payments.create'=>'Receive payments','payments.reverse'=>'Reverse payments','setup.manage'=>'Setup wizard','reports.view'=>'Reports','shop.settings'=>'Shop settings','commission.view'=>'Commission view'] as $permission=>$label)
                            <label class="text-sm"><input type="checkbox" name="shop_permissions[]" value="{{ $permission }}" @checked(in_array($permission,$staff->shop_permissions??[],true))> {{ $label }}</label>
                        @endforeach
                    </div>
                    <button class="secondary-button mt-4">Save permissions</button>
                </form>
            @endforeach
        </section>
    </div>
</x-layouts.app>
