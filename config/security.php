<?php

return [
    'throttle' => ['account' => 5, 'ip' => 30, 'device' => 12, 'decay' => 300, 'backoff_after' => 3, 'backoff_max' => 60],
    'challenge_minutes' => 10,
    'challenge_attempts' => 5,
    'reset_minutes' => 20,
    'reauth_minutes' => 10,
    'staff' => ['idle_minutes' => 30, 'absolute_minutes' => 480],
    'super_admin' => ['idle_minutes' => 15, 'absolute_minutes' => 240],
    'cpmi' => ['idle_minutes' => 120, 'absolute_minutes' => 1440],
    'trusted_device_days' => 90,
    'audit_retention_days' => 180,
    'support_email' => env('AUTH_SUPPORT_EMAIL'),
    'preferred_mfa' => 'webauthn',
    'available_mfa' => ['totp', 'recovery_code'],
];
