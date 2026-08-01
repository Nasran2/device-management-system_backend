<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeviceActivation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'code_fingerprint', 'encrypted_code'];

    protected $casts = [
        'encrypted_code' => 'encrypted',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'locked_until' => 'datetime',
        'expired_audited_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (DeviceActivation $activation) {
            $activation->uuid ??= (string) Str::uuid();
        });
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function setupSession()
    {
        return $this->belongsTo(DeviceSetupSession::class, 'setup_session_id');
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at?->isFuture()
            && ! $this->locked_until?->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->used_at !== null => 'used',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at?->isPast() => 'expired',
            $this->locked_until?->isFuture() => 'temporarily_locked',
            $this->expires_at?->lte(now()->addHours(2)) => 'expiring_soon',
            default => 'active',
        };
    }
}
