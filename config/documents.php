<?php

return [
    'scan_enabled' => env('DOCUMENT_SCAN_ENABLED', env('APP_ENV') === 'production'),
    'scanner_binary' => env('DOCUMENT_SCANNER_BINARY', 'clamscan'),
];
