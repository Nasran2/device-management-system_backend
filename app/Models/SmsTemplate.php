<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SmsTemplate extends Model {protected $guarded=['id'];protected $casts=['enabled'=>'boolean','is_global'=>'boolean'];}
