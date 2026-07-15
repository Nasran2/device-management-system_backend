<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceActivation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
