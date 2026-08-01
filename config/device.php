<?php

return [
    'command_expiry_minutes' => env('DEVICE_COMMAND_EXPIRY_MINUTES', 15),
    'command_signing_key' => env('DEVICE_COMMAND_SIGNING_KEY', env('APP_KEY')),
    'offline_timeout_minutes' => env('DEVICE_OFFLINE_TIMEOUT_MINUTES', 30),
    'offline_policy_private_key' => env('OFFLINE_POLICY_PRIVATE_KEY_PATH', storage_path('app/private/offline-policy-private.pem')),
    'offline_policy_public_key' => env('OFFLINE_POLICY_PUBLIC_KEY_PATH', storage_path('app/private/offline-policy-public.pem')),
    'offline_policy_expected_public_key_sha256' => env('OFFLINE_POLICY_EXPECTED_PUBLIC_KEY_SHA256', '6aa94348f910185a5fb56fe5be62289565f995cb7cc2d08de97699849394b3ce'),
    'offline_policy_clock_tolerance_seconds' => env('OFFLINE_POLICY_CLOCK_TOLERANCE_SECONDS', 600),
    'offline_policy_diagnostics' => env('OFFLINE_POLICY_DIAGNOSTICS', false),
];
