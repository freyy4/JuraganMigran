<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return ['region' => ['nullable', 'string', 'max:100'], 'flag' => ['nullable', 'string', 'max:16'], 'code' => ['required', 'string', 'max:3', 'regex:/^[A-Z]{2,3}$/', Rule::unique('countries', 'code')->ignore($this->route('country'))], 'name' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.', 'regex' => 'Kode negara harus terdiri dari dua atau tiga huruf.', 'unique' => 'Kode negara sudah digunakan.', 'boolean' => 'Status negara tidak valid.'];
    }

    public function attributes(): array
    {
        return ['region' => ['nullable', 'string', 'max:100'], 'flag' => ['nullable', 'string', 'max:16'], 'code' => 'Kode negara', 'name' => 'Nama negara', 'is_active' => 'Status'];
    }
}
