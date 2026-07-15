<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DeviceProvisioningToken extends Model { protected $guarded = ['id']; protected $hidden = ['token_hash']; protected $casts = ['expires_at'=>'datetime','used_at'=>'datetime','revoked_at'=>'datetime','provisioning_started_at'=>'datetime','provisioning_completed_at'=>'datetime']; public function device(){return $this->belongsTo(Device::class);} public function creator(){return $this->belongsTo(User::class,'created_by');} public function usable(): bool{return $this->status==='active'&&!$this->used_at&&!$this->revoked_at&&$this->expires_at->isFuture();} }
