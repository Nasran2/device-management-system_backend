<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCommand extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'expires_at' => 'datetime', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime', 'executing_at' => 'datetime', 'executed_at' => 'datetime', 'firebase_attempted_at' => 'datetime'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
