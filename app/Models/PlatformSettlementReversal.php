<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformSettlementReversal extends Model {protected $guarded=['id'];protected $casts=['amount'=>'decimal:2','reversed_at'=>'datetime'];}
