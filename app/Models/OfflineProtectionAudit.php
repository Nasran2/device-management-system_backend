<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfflineProtectionAudit extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['old_value' => 'array', 'new_value' => 'array', 'metadata' => 'array', 'occurred_at' => 'datetime'];
    public function device() { return $this->belongsTo(Device::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
}
