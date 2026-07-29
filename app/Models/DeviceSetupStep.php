<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeviceSetupStep extends Model {protected $guarded=['id'];protected $casts=['completed'=>'boolean','completed_at'=>'datetime','safe_metadata'=>'array'];}
