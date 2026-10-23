<?php

namespace App\Http\Requests;

use App\Services\AuthenticationSecurity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    public function authenticate(): array
    {
        try {
            return app(AuthenticationSecurity::class)->primary($this, 'staff', $this->string('email')->toString(), $this->string('password')->toString());
        } catch (ValidationException $exception) {
            if ($this->expectsJson()) {
                $limited = str_contains($exception->getMessage(), 'Terlalu banyak');
                throw new HttpResponseException(response()->json(['ok' => false, 'code' => $limited ? 'RATE_LIMITED' : 'INVALID_CREDENTIALS', 'message' => $exception->getMessage()], $limited ? 429 : 401));
            }
            throw $exception;
        }
    }
}
