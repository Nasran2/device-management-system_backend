<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeviceSetupSession extends Model {
    protected $guarded=['id'];protected $casts=['checklist'=>'array','completed_at'=>'datetime'];
    public function device(){return $this->belongsTo(Device::class);} public function steps(){return $this->hasMany(DeviceSetupStep::class);}
}
