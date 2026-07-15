<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;

class AuditService
{
    public function record(string $action, string $description, ?User $user = null, ?Device $device = null, array $before = [], array $after = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user?->id,
            'device_id' => $device?->id,
            'action' => $action,
            'description' => $description,
            'previous_values' => $before ?: null,
            'new_values' => $after ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
