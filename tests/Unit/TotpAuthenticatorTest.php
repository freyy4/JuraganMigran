<?php

namespace Tests\Unit;

use App\Services\TotpAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpAuthenticatorTest extends TestCase
{
    public static function rfcVectors(): array
    {
        return [[59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'], [1234567890, '89005924'], [2000000000, '69279037'], [20000000000, '65353130']];
    }

    #[DataProvider('rfcVectors')]
    public function test_rfc_6238_sha1_vectors(int $timestamp, string $expected): void
    {
        $this->assertSame($expected, (new TotpAuthenticator)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $timestamp, 8));
    }

    public function test_secrets_have_160_bits_of_entropy_and_valid_base32_encoding(): void
    {
        $authenticator = new TotpAuthenticator;
        $first = $authenticator->secret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $first);
        $this->assertNotSame($first, $authenticator->secret());
    }
}
