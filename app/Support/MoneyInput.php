<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class MoneyInput
{
    public const FIELDS = ['amount' => 2, 'target_amount' => 2, 'salary' => 2, 'salary_min' => 2, 'salary_max' => 2, 'budget' => 2, 'percentage' => 4];

    public static function normalize(mixed $value, string $field = 'nominal'): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        if (! preg_match('/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d+)?$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Gunakan titik untuk ribuan dan koma untuk desimal, misalnya 1.250.000,50.']);
        }

        return str_replace(',', '.', str_replace('.', '', $value));
    }

    public static function format(mixed $value, int $scale = 2): string
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return '';
        }
        $value = (string) $value;
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $parts)) {
            return $value;
        }
        $integer = ltrim($parts[2], '0') ?: '0';
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);
        $fraction = $parts[3] ?? '';

        return $parts[1].$integer.','.str_pad($fraction, $scale, '0');
    }
}
