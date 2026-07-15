<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
