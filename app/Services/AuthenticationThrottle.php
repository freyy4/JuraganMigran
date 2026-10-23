<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthenticationThrottle
{
    public const MESSAGE = 'Terlalu banyak percobaan. Silakan coba kembali beberapa saat lagi.';

    public function keys(Request $request, string $identifier, string $purpose): array
    {
        return [
            'account' => 'auth:'.$purpose.':account:'.hash_hmac('sha256', mb_strtolower($identifier), config('app.key')),
            'ip' => 'auth:'.$purpose.':ip:'.hash('sha256', $request->ip() ?? ''),
            'device' => 'auth:'.$purpose.':device:'.hash('sha256', $request->session()->get('auth.device_nonce', $request->session()->getId())),
        ];
    }

    public function ensure(Request $request, string $identifier, string $purpose, string $field = 'identifier'): void
    {
        if (! $request->session()->has('auth.device_nonce')) {
            $request->session()->put('auth.device_nonce', bin2hex(random_bytes(24)));
        }
        foreach ($this->keys($request, $identifier, $purpose) as $type => $key) {
            $attempts = RateLimiter::attempts($key);
            if (RateLimiter::tooManyAttempts($key, config('security.throttle.'.$type)) || ($attempts >= config('security.throttle.backoff_after') && RateLimiter::tooManyAttempts($key.':delay', 1))) {
                app(AccessPolicy::class)->audit(null, 'auth.attempt.throttled', ['purpose' => $purpose]);
                throw ValidationException::withMessages([$field => self::MESSAGE]);
            }
        }
    }

    public function failure(Request $request, string $identifier, string $purpose): void
    {
        foreach ($this->keys($request, $identifier, $purpose) as $key) {
            $attempts = RateLimiter::hit($key, config('security.throttle.decay'));
            if ($attempts >= config('security.throttle.backoff_after')) {
                RateLimiter::hit($key.':delay', min(config('security.throttle.backoff_max'), 2 ** ($attempts - config('security.throttle.backoff_after'))));
            }
        }
    }

    public function success(Request $request, string $identifier, string $purpose): void
    {
        // IP/device limits remain shared across accounts; only clear this account's failures.
        $key = $this->keys($request, $identifier, $purpose)['account'];
        RateLimiter::clear($key);
        RateLimiter::clear($key.':delay');
    }
}
