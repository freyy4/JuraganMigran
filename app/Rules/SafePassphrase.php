<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafePassphrase implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $common = ['password', 'password123', '1234567890', '123456789012', 'qwerty123456', 'juraganmigran', 'juraganmigran123', 'admin12345678', 'letmein123456'];
        if (mb_strlen((string) $value) < 12 || mb_strlen((string) $value) > 255 || in_array(mb_strtolower(trim((string) $value)), $common, true)) {
            $fail('Gunakan frasa sandi unik sepanjang 12–255 karakter. Hindari kata sandi umum.');
        }
    }
}
