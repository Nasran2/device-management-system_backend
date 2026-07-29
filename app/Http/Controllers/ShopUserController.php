<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShopUserController extends Controller
{
    private function owner(Request $request): void
    {
        abort_unless(
            $request->user()->shop
            && $request->user()->shop->staff_accounts_enabled
            && $request->user()->isShopOwner(),
            403
        );
    }

    public function index(Request $request)
    {
        $this->owner($request);

        return view('shops.users', [
            'shop' => $request->user()->shop,
            'users' => $request->user()->shop->users()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->owner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:10'],
            'staff_role' => ['required', 'in:shop_manager,sales_staff,payment_collector,device_setup_staff,read_only_staff'],
            'shop_permissions' => ['array'],
            'shop_permissions.*' => [Rule::in([
                'customers.manage',
                'devices.create',
                'devices.lock',
                'payments.create',
                'payments.reverse',
                'setup.manage',
                'reports.view',
                'shop.settings',
                'commission.view',
            ])],
        ]);

        User::create($data + [
            'shop_id' => $request->user()->shop_id,
            'role' => 'shop_staff',
            'is_active' => true,
        ]);

        return back()->with('success', 'Shop staff account created.');
    }

    public function update(Request $request, User $shopUser)
    {
        $this->owner($request);
        abort_unless($shopUser->shop_id === $request->user()->shop_id && $shopUser->role === 'shop_staff', 403);
        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'staff_role' => ['required', 'in:shop_manager,sales_staff,payment_collector,device_setup_staff,read_only_staff'],
            'shop_permissions' => ['array'],
            'shop_permissions.*' => [Rule::in([
                'customers.manage',
                'devices.create',
                'devices.lock',
                'payments.create',
                'payments.reverse',
                'setup.manage',
                'reports.view',
                'shop.settings',
                'commission.view',
            ])],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['shop_permissions'] = $data['shop_permissions'] ?? [];
        $shopUser->update($data);

        return back()->with('success', 'Staff permissions updated.');
    }
}
