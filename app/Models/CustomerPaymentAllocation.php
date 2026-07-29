<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomerPaymentAllocation extends Model {
    protected $guarded=['id']; protected $casts=['amount'=>'decimal:2'];
    public function installment(){return $this->belongsTo(InstallmentSchedule::class,'installment_schedule_id');}
}
