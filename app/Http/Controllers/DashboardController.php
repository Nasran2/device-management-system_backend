<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;
use App\Models\Shop;
use App\Models\DeviceCommission;
use App\Models\CustomerPayment;
use App\Models\DeviceFinancing;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $query = Device::visibleTo(auth()->user());
        $stats = [
            'devices' => (clone $query)->count(),
            'value' => (clone $query)->sum('selling_price'),
            'locked' => (clone $query)->where('lock_status', 'locked')->count(),
            'unlocked' => (clone $query)->where('lock_status', 'unlocked')->count(),
            'offline' => (clone $query)->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes(30)))->count(),
            'released' => (clone $query)->whereNotNull('released_at')->count(),
            'offline_protected' => (clone $query)->whereHas('offlinePolicy', fn ($q) => $q->where('enabled', true)->where('permanent_release', false))->count(),
            'deadline_24h' => (clone $query)->whereHas('offlinePolicy', fn ($q) => $q->whereBetween('offline_deadline_at', [now(), now()->addDay()]))->count(),
            'offline_24h' => (clone $query)->where('last_seen_at', '<', now()->subDay())->count(),
            'offline_locked' => (clone $query)->whereHas('offlinePolicy', fn ($q) => $q->where('phone_local_locked', true))->count(),
            'policy_pending' => (clone $query)->whereHas('offlinePolicy', fn ($q) => $q->whereNull('policy_acknowledged_at')->orWhereColumn('policy_acknowledged_at', '<', 'updated_at'))->count(),
            'admins' => auth()->user()->isSuperAdmin() ? User::whereIn('role', ['admin', 'shop_owner'])->count() : null,
            'shops' => auth()->user()->isSuperAdmin() ? Shop::count() : null,
            'active_shops' => auth()->user()->isSuperAdmin() ? Shop::where('status','active')->count() : null,
            'inactive_shops' => auth()->user()->isSuperAdmin() ? Shop::where('status','inactive')->count() : null,
            'active_devices' => (clone $query)->where('is_device_owner',true)->where('is_admin_active',true)->whereNotNull('last_seen_at')->where('last_seen_at','>=',now()->subMinutes(30))->count(),
            'new_devices_today' => (clone $query)->whereDate('created_at',today())->count(),
            'payments_today' => CustomerPayment::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->where('status','completed')->whereDate('payment_date',today())->sum('amount'),
            'sms_sent_today' => \App\Models\SmsLog::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->whereIn('sent_status',['sent','delivered'])->whereDate('created_at',today())->count(),
            'sms_failed_today' => \App\Models\SmsLog::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->where('sent_status','failed')->whereDate('created_at',today())->count(),
            'customers' => \App\Models\Customer::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->count(),
            'customer_paid' => CustomerPayment::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->where('status','completed')->sum('amount'),
            'customer_outstanding' => DeviceFinancing::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->sum('remaining_balance'),
            'commission_total' => DeviceCommission::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->sum('commission_amount'),
            'commission_paid' => DeviceCommission::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->sum('paid_amount'),
            'commission_outstanding' => DeviceCommission::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->sum('outstanding_amount'),
            'overdue_installments' => \App\Models\InstallmentSchedule::when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->where('status','overdue')->sum('remaining_amount'),
        ];

        return view('dashboard', [
            'stats' => $stats,
            'devices' => (clone $query)->with(['customer', 'admin', 'offlinePolicy'])->latest()->limit(8)->get(),
            'recentShops' => auth()->user()->isSuperAdmin() ? Shop::latest()->limit(5)->get() : collect(),
            'recentSettlements' => auth()->user()->isSuperAdmin() ? \App\Models\PlatformSettlement::with('shop')->latest()->limit(5)->get() : collect(),
            'topOutstandingShops' => auth()->user()->isSuperAdmin() ? Shop::withSum('commissions','outstanding_amount')->get()->sortByDesc('commissions_sum_outstanding_amount')->take(5) : collect(),
            'recentSmsFailures' => auth()->user()->isSuperAdmin() ? \App\Models\SmsLog::with('shop')->where('sent_status','failed')->latest()->limit(5)->get() : collect(),
            'recentPayments' => CustomerPayment::with(['customer','device'])->when(!auth()->user()->isSuperAdmin(),fn($q)=>$q->where('shop_id',auth()->user()->shop_id))->latest()->limit(5)->get(),
        ]);
    }
}
