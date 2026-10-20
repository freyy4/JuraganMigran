<?php

namespace App\Http\Requests;

use App\Services\AccessPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email', 'string', 'max:255'], 'password' => ['required', 'string', 'max:4096'], 'remember' => ['sometimes', 'boolean']];
    }

    public function messages(): array
    {
        return ['email.required' => 'Email wajib diisi.', 'email.email' => 'Format email tidak valid.', 'email.string' => 'Email harus berupa teks.', 'email.max' => 'Email maksimal 255 karakter.', 'password.required' => 'Kata sandi wajib diisi.', 'password.string' => 'Kata sandi harus berupa teks.', 'password.max' => 'Kata sandi terlalu panjang.', 'remember.boolean' => 'Pilihan ingat saya tidak valid.'];
    }

    public function authenticate(): void
    {
        $key = Str::transliterate(Str::lower($this->string('email')->toString()).'|'.$this->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Terlalu banyak percobaan masuk. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        if (! Auth::attempt($this->only('email', 'password') + ['is_active' => true], $this->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            app(AccessPolicy::class)->audit(null, 'staff.login.denied');
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai.']);
        }
        RateLimiter::clear($key);
    }
}
