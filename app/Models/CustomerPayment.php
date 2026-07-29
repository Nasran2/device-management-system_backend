<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomerPayment extends Model {
    protected $guarded=['id']; protected $casts=['payment_date'=>'date','next_payment_date'=>'date','amount'=>'decimal:2','previous_total_paid'=>'decimal:2','new_total_paid'=>'decimal:2','previous_remaining_balance'=>'decimal:2','new_remaining_balance'=>'decimal:2','next_payment_amount'=>'decimal:2','send_sms'=>'boolean'];
    public function allocations(){return $this->hasMany(CustomerPaymentAllocation::class);}
    public function reversal(){return $this->hasOne(PaymentReversal::class);}
    public function customer(){return $this->belongsTo(Customer::class);} public function device(){return $this->belongsTo(Device::class);}
}
