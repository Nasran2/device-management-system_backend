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
            'admins' => auth()->user()->isSuperAdmin() ? User::where('role', 'admin')->count() : null,
        ];

        return view('dashboard', ['stats' => $stats, 'devices' => (clone $query)->with(['customer', 'admin'])->latest()->limit(8)->get()]);
    }
}
