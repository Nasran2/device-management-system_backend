<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class InstallmentSchedule extends Model {
    protected $guarded=['id']; protected $casts=['due_date'=>'date','paid_at'=>'datetime','expected_amount'=>'decimal:2','amount_paid'=>'decimal:2','remaining_amount'=>'decimal:2'];
    public function financing(){return $this->belongsTo(DeviceFinancing::class,'device_financing_id');}
    public function allocations(){return $this->hasMany(CustomerPaymentAllocation::class);}
}
