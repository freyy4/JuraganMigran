<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveReadinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.code' => ['required', 'string', 'max:255', 'distinct'], 'items.*.name' => ['required', 'string', 'max:255'], 'items.*.is_required' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'array' => ':attribute tidak valid.', 'min' => 'Minimal satu item harus diisi.', 'max' => ':attribute melebihi batas :max.', 'distinct' => 'Kode item tidak boleh sama.', 'boolean' => 'Pilihan wajib tidak valid.', 'string' => ':attribute harus berupa teks.'];
    }

    public function attributes(): array
    {
        return ['items' => 'Checklist', 'items.*.code' => 'Kode item', 'items.*.name' => 'Nama item', 'items.*.is_required' => 'Item wajib'];
    }
}
