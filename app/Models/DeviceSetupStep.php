<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeviceSetupStep extends Model {
    protected $guarded=['id'];
    protected $casts=['completed'=>'boolean','started_at'=>'datetime','completed_at'=>'datetime','safe_metadata'=>'array'];
    public function instruction(){return $this->belongsTo(DeviceSetupInstruction::class,'device_setup_instruction_id');}
}
