<?php

namespace App\Http\Requests;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(Application::statusOptions())]];
    }

    public function messages(): array
    {
        return ['required' => 'Status wajib dipilih.', 'in' => 'Pilihan status tidak valid.'];
    }
}
