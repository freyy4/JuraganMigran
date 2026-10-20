<?php

namespace App\Http\Requests;

use App\Models\DocumentType;
use App\Services\DocumentRequirementEngine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $ids = app(DocumentRequirementEngine::class)->requirements($this->route('application'))->pluck('document_type_id');

        $type = DocumentType::find($this->input('document_type_id'));
        $formats = implode(',', $type?->allowed_file_types ?: ['pdf', 'jpg', 'jpeg', 'png']);

        return ['document_type_id' => ['required', 'integer', Rule::in($ids->all())], 'file' => ['required', 'file', 'mimes:'.$formats, 'max:'.($type?->max_file_size_kb ?? 10240)], 'document_number' => ['nullable', 'string', 'max:255'], 'issued_at' => ['nullable', 'date_format:Y-m-d'], 'expires_at' => ['nullable', 'date_format:Y-m-d', ...($this->filled('issued_at') ? ['after_or_equal:issued_at'] : [])]];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'integer' => ':attribute tidak valid.', 'in' => 'Jenis dokumen bukan kebutuhan lowongan ini.', 'file' => 'File tidak valid.', 'mimes' => 'File harus berupa PDF, JPG, atau PNG.', 'max' => ':attribute melebihi batas :max.', 'string' => ':attribute harus berupa teks.', 'date_format' => ':attribute harus berupa tanggal yang valid.', 'after_or_equal' => 'Tanggal kedaluwarsa harus sama atau setelah tanggal terbit.'];
    }

    public function attributes(): array
    {
        $type = DocumentType::find($this->input('document_type_id'));
        $formats = implode(',', $type?->allowed_file_types ?: ['pdf', 'jpg', 'jpeg', 'png']);

        return ['document_type_id' => 'Jenis dokumen', 'file' => 'File (maksimal 10 MB)', 'document_number' => 'Nomor dokumen', 'issued_at' => 'Tanggal terbit', 'expires_at' => 'Tanggal kedaluwarsa'];
    }
}
