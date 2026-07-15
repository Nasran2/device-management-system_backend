<?php

return [
    'command_expiry_minutes' => env('DEVICE_COMMAND_EXPIRY_MINUTES', 15),
    'command_signing_key' => env('DEVICE_COMMAND_SIGNING_KEY', env('APP_KEY')),
    'offline_timeout_minutes' => env('DEVICE_OFFLINE_TIMEOUT_MINUTES', 30),
];
