<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PaymentReversal extends Model {protected $guarded=['id'];protected $casts=['reversed_at'=>'datetime','amount'=>'decimal:2'];}
