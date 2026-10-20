<?php

return [
    'redirect_uri' => env('META_REDIRECT_URI'),
    'session_minutes' => 60,
    'permissions' => ['business_management', 'whatsapp_business_management', 'whatsapp_business_messaging'],
    'states' => ['NOT_CONFIGURED', 'AUTH_PENDING', 'AUTH_SUCCESS', 'ASSET_SELECTION_REQUIRED', 'PHONE_VERIFICATION_REQUIRED', 'PHONE_REGISTRATION_REQUIRED', 'PERMISSION_REQUIRED', 'WEBHOOK_SETUP', 'TEST_REQUIRED', 'CONNECTED', 'DEGRADED', 'REAUTH_REQUIRED', 'DISCONNECTED', 'FAILED'],
];
