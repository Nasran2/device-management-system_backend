<?php

namespace App\Http\Controllers;

use App\Models\OfflineProtectionAudit;
use Illuminate\Http\Request;

class OfflineProtectionReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $query = OfflineProtectionAudit::with(['device.customer', 'device.admin', 'device.offlinePolicy', 'requester'])
            ->whereHas('device', fn ($q) => $q->visibleTo($request->user()))
            ->when($request->event_type, fn ($q, $v) => $q->where('event_type', $v))
            ->when($request->device_id, fn ($q, $v) => $q->where('device_id', $v))
            ->when($request->date_from, fn ($q, $v) => $q->whereDate('occurred_at', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->whereDate('occurred_at', '<=', $v))
            ->latest('occurred_at');
        if ($request->format === 'csv') {
            return response()->streamDownload(function () use ($query) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Device', 'Customer', 'Added by', 'Period hours', 'Last verified', 'Deadline', 'Event', 'Result', 'Requested by', 'Date/time']);
                $query->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $a) fputcsv($out, [$a->device?->brand.' '.$a->device?->model, $a->device?->customer?->name, $a->device?->admin?->name, ($a->device?->offlinePolicy?->max_offline_seconds ?? 0) / 3600, $a->device?->offlinePolicy?->last_verified_at, $a->device?->offlinePolicy?->offline_deadline_at, $a->event_type, data_get($a->metadata, 'result'), $a->requester?->name, $a->occurred_at]);
                });
                fclose($out);
            }, 'offline-protection-report.csv', ['Content-Type' => 'text/csv']);
        }
        return view('reports.offline-protection', ['audits' => $query->paginate(50)->withQueryString()]);
    }
}
