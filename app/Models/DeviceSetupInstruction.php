<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceSetupInstruction extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'numbered_instructions' => 'array',
        'possible_errors' => 'array',
        'troubleshooting' => 'array',
        'verification_items' => 'array',
        'confirmation_items' => 'array',
        'required' => 'boolean',
        'auto_verifiable' => 'boolean',
        'active' => 'boolean',
    ];
}
