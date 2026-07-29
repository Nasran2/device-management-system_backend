<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfflineProtectionSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'warning_notification_enabled' => 'boolean',
        'allow_admin_override' => 'boolean',
        'require_password_confirmation' => 'boolean',
    ];

    public static function current(): self
    {
        return static::firstOrCreate([], [
            'enabled' => true, 'default_period_value' => 5, 'default_period_unit' => 'days',
            'default_period_seconds' => 432000, 'warning_notification_enabled' => true,
            'first_warning_seconds' => 86400, 'final_warning_seconds' => 21600,
            'allow_admin_override' => true, 'require_password_confirmation' => true,
        ]);
    }
}
