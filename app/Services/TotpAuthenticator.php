<?php

namespace App\Services;

class TotpAuthenticator
{
    public function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        foreach (str_split(random_bytes(32)) as $byte) {
            $secret .= $alphabet[ord($byte) & 31];
        }

        return $secret;
    }

    public function code(string $secret, int $timestamp, int $digits = 6): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $letter) {
            $value = strpos($alphabet, $letter);
            if ($value === false) {
                throw new \InvalidArgumentException('Invalid authenticator encoding.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $key .= chr(bindec(substr($bits, $i, 8)));
        }
        $counter = intdiv($timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter & 0xFFFFFFFF), $key, true);
        $offset = ord($hash[19]) & 15;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function counter(string $secret, string $code, ?int $lastCounter): ?int
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return null;
        }
        $current = intdiv(now()->timestamp, 30);
        foreach ([$current, $current - 1, $current + 1] as $counter) {
            if (($lastCounter === null || $counter > $lastCounter) && hash_equals($this->code($secret, $counter * 30), $code)) {
                return $counter;
            }
        }

        return null;
    }
}
