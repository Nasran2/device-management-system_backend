<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeviceFinancing extends Model {
    protected $table='device_financing'; protected $guarded=['id'];
    protected $casts=['first_due_date'=>'date','selling_price'=>'decimal:2','first_payment'=>'decimal:2','financed_balance'=>'decimal:2','installment_amount'=>'decimal:2','suggested_installment_amount'=>'decimal:2','remaining_balance'=>'decimal:2','total_paid'=>'decimal:2'];
    public function device(){return $this->belongsTo(Device::class);} public function customer(){return $this->belongsTo(Customer::class);}
    public function installments(){return $this->hasMany(InstallmentSchedule::class);}
}
