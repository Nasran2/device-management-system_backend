<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformSettlement extends Model {
    protected $guarded=['id']; protected $casts=['payment_date'=>'date','amount'=>'decimal:2','unallocated_credit'=>'decimal:2'];
    public function shop(){return $this->belongsTo(Shop::class);} public function allocations(){return $this->hasMany(PlatformSettlementAllocation::class);}
    public function reversal(){return $this->hasOne(PlatformSettlementReversal::class);}
}
