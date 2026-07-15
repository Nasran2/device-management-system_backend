<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Device extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['management_pin_hash', 'management_pin_encrypted'];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'is_device_owner' => 'boolean',
        'is_admin_active' => 'boolean',
        'can_block_uninstall' => 'boolean',
        'can_block_reset' => 'boolean',
        'can_full_lock' => 'boolean',
        'can_use_lock_task' => 'boolean',
        'is_lock_task_permitted' => 'boolean',
        'location_tracking_enabled' => 'boolean',
        'last_location_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'registered_at' => 'datetime',
        'released_at' => 'datetime',
        'management_pin_changed_at' => 'datetime',
        'management_pin_locked_until' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->uuid ??= (string) Str::uuid();
        $this->registered_at ??= now();
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function managementPinChangedBy()
    {
        return $this->belongsTo(User::class, 'management_pin_changed_by');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function activations()
    {
        return $this->hasMany(DeviceActivation::class);
    }

    public function commands()
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function locations()
    {
        return $this->hasMany(DeviceLocation::class);
    }

    public function accessCodes()
    {
        return $this->hasMany(DeviceAccessCode::class);
    }

    public function tokens()
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function provisioningTokens()
    {
        return $this->hasMany(DeviceProvisioningToken::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isSuperAdmin() ? $query : $query->where('admin_id', $user->id);
    }

    public function getMaskedImeiAttribute(): string
    {
        return str_repeat('•', max(0, strlen($this->imei) - 4)).substr($this->imei, -4);
    }

    public function isReleased(): bool
    {
        return $this->status === 'permanently_released' || $this->released_at !== null;
    }
}
