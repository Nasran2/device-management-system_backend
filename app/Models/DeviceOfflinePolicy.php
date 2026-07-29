<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceOfflinePolicy extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'uses_global_default' => 'boolean',
        'permanent_release' => 'boolean',
        'phone_local_locked' => 'boolean',
        'last_verified_at' => 'datetime',
        'offline_deadline_at' => 'datetime',
        'policy_issued_at' => 'datetime',
        'policy_expires_at' => 'datetime',
        'policy_acknowledged_at' => 'datetime',
        'phone_reported_deadline_at' => 'datetime',
        'last_warning_at' => 'datetime',
        'last_offline_lock_at' => 'datetime',
    ];

    public function device() { return $this->belongsTo(Device::class); }
    public function audits() { return $this->hasMany(OfflineProtectionAudit::class, 'device_id', 'device_id'); }
}
