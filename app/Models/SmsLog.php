<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SmsLog extends Model {protected $guarded=['id'];protected $casts=['sent_at'=>'datetime','delivered_at'=>'datetime','attempts'=>'integer','safe_metadata'=>'array'];public function shop(){return $this->belongsTo(Shop::class);}}
