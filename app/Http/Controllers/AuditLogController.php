<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $logs = AuditLog::with(['user', 'device.customer'])
            ->when($request->search, fn ($query, $term) => $query->where(fn ($inner) => $inner
                ->where('action', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$term}%"))))
            ->when($request->action, fn ($query, $action) => $query->where('action', $action))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('settings.audit-logs', compact('logs'));
    }
}
