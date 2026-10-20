<?php

return [
    'enabled' => env('WHATSAPP_QR_ENABLED', false),
    'url' => env('WHATSAPP_QR_GATEWAY_URL'),
    'api_key' => env('WHATSAPP_QR_GATEWAY_KEY'),
    'webhook_secret' => env('WHATSAPP_QR_WEBHOOK_SECRET'),
    'encrypted_storage' => env('WHATSAPP_QR_ENCRYPTED_STORAGE', false),
    'multiple_sessions' => env('WHATSAPP_QR_MULTIPLE_SESSIONS', false),
    'pairing_seconds' => 20,
    'heartbeat_seconds' => 90,
    'permissions' => ['WHATSAPP_CHANNEL_VIEW', 'WHATSAPP_CHANNEL_CONNECT_QR', 'WHATSAPP_CHANNEL_DISCONNECT', 'WHATSAPP_CHANNEL_RESCAN', 'WHATSAPP_CHANNEL_MANAGE_TEAM', 'WHATSAPP_CHANNEL_MANAGE_ROUTING', 'WHATSAPP_CHANNEL_DIAGNOSTICS'],
];
