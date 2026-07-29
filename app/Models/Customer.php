<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;
    protected $guarded = ['id'];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }
    public function shop(){return $this->belongsTo(Shop::class);}
    public function payments(){return $this->hasMany(CustomerPayment::class);}
    public function smsLogs(){return $this->hasMany(SmsLog::class);}
}
