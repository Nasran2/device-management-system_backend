<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = ['admin_id', 'name', 'phone', 'address'];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }
}
