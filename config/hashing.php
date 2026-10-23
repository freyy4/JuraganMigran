<?php

return [
    'driver' => env('HASH_DRIVER', 'argon2id'),
    'bcrypt' => ['rounds' => env('BCRYPT_ROUNDS', 12), 'verify' => true, 'limit' => null],
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 19456),
        'threads' => 1,
        'time' => (int) env('ARGON_TIME', 2),
        // Accept existing bcrypt hashes during migration; successful login rehashes to Argon2id.
        'verify' => false,
    ],
    'rehash_on_login' => true,
];
