<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveApplicationStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['to_stage_id' => ['required', 'integer', Rule::exists('pipeline_stages', 'id')->where('is_active', true)], 'from_stage_id' => ['present', 'nullable', 'integer', Rule::exists('pipeline_stages', 'id')], 'confirmed' => ['required', 'accepted'], 'reason' => ['nullable', 'string', 'max:5000']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'present' => ':attribute harus dikirim.', 'integer' => ':attribute tidak valid.', 'exists' => ':attribute tidak tersedia atau tidak aktif.', 'accepted' => 'Perpindahan tahap harus dikonfirmasi.', 'string' => 'Alasan harus berupa teks.', 'max' => 'Alasan maksimal :max karakter.'];
    }

    public function attributes(): array
    {
        return ['to_stage_id' => 'Tahap tujuan', 'from_stage_id' => 'Tahap asal', 'confirmed' => 'Konfirmasi', 'reason' => 'Alasan'];
    }
}
