<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignApplicationCsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:255'], 'assigned_cs_id' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)]];
    }

    public function messages(): array
    {
        return ['present' => 'Pilihan CS harus dikirim.', 'integer' => 'Pilihan CS tidak valid.', 'exists' => 'Pengguna CS tidak tersedia.'];
    }
}
