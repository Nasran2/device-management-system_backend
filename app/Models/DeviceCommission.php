<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeviceCommission extends Model {
    protected $guarded=['id']; protected $casts=['captured_percentage'=>'decimal:4','base_amount'=>'decimal:2','commission_amount'=>'decimal:2','paid_amount'=>'decimal:2','waived_amount'=>'decimal:2','adjustment_amount'=>'decimal:2','outstanding_amount'=>'decimal:2'];
    public function device(){return $this->belongsTo(Device::class);} public function shop(){return $this->belongsTo(Shop::class);}
}
