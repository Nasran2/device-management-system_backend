<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\User;

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
            'admins' => auth()->user()->isSuperAdmin() ? User::where('role', 'admin')->count() : null,
        ];

        return view('dashboard', ['stats' => $stats, 'devices' => (clone $query)->with(['customer', 'admin', 'offlinePolicy'])->latest()->limit(8)->get()]);
    }
}
