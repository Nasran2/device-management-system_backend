<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PlatformSettlementAllocation extends Model {protected $guarded=['id'];protected $casts=['amount'=>'decimal:2'];}
