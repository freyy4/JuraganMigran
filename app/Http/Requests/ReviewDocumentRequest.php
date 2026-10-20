<?php

namespace App\Http\Requests;

use App\Services\DocumentRequirementEngine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['correction_code' => [Rule::requiredIf($this->input('status') === 'needs_correction'), 'nullable', Rule::in(array_keys(DocumentRequirementEngine::REASONS))], 'status' => ['required', Rule::in(['reviewed', 'verified', 'rejected', 'needs_correction'])], 'rejection_reason' => [Rule::requiredIf(($this->input('status') === 'rejected' && ! $this->filled('correction_code')) || $this->input('correction_code') === 'OTHER'), 'nullable', 'string', 'max:5000']];
    }

    public function messages(): array
    {
        return ['required' => 'Status wajib dipilih.', 'in' => 'Status tidak valid.', 'required_if' => 'Alasan penolakan wajib diisi.', 'string' => 'Alasan harus berupa teks.', 'max' => 'Alasan maksimal :max karakter.'];
    }
}
