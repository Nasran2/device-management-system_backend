<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Shop extends Model {
    use SoftDeletes; protected $guarded=['id'];
    protected $casts=['sms_enabled'=>'boolean','device_registration_enabled'=>'boolean','lock_unlock_enabled'=>'boolean','staff_accounts_enabled'=>'boolean','reminders_enabled'=>'boolean','admin_override_permissions'=>'array','commission_percentage'=>'decimal:4'];
    public function users(){return $this->hasMany(User::class);}
    public function customers(){return $this->hasMany(Customer::class);}
    public function devices(){return $this->hasMany(Device::class);}
    public function commissions(){return $this->hasMany(DeviceCommission::class);}
    public function settlements(){return $this->hasMany(PlatformSettlement::class);}
}
