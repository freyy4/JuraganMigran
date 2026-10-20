<?php

namespace App\Support;

class PhoneNumber
{
    public static function display(?string $value): string
    {
        if (! $value || ! preg_match('/^\+?\d+$/', $value)) {
            return $value ?? '';
        }

        if (str_starts_with($value, '+62')) {
            $number = substr($value, 3);
            $groups = ['+62', substr($number, 0, 3), ...str_split(substr($number, 3), 4)];
        } else {
            $groups = str_split($value, 4);
        }

        return implode('-', array_filter($groups, static fn (string $group): bool => $group !== ''));
    }
}
