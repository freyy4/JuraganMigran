<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $interview = $this->route('interview');

        return ['application_id' => ['required', 'integer', $interview ? Rule::in([$interview->application_id]) : Rule::exists('applications', 'id')->whereNull('deleted_at')], 'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'], 'interviewer_id' => ['nullable', 'integer', Rule::exists('users', 'id')], 'meeting_link' => ['nullable', 'url:http,https', 'max:5000'], 'type' => ['required', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'integer' => ':attribute tidak valid.', 'exists' => ':attribute tidak tersedia.', 'in' => 'Lamaran wawancara tidak dapat diganti.', 'date_format' => 'Jadwal harus berupa tanggal dan waktu yang valid.', 'url' => 'Tautan pertemuan harus berupa URL HTTP atau HTTPS.', 'max' => ':attribute maksimal :max karakter.', 'string' => ':attribute harus berupa teks.'];
    }

    public function attributes(): array
    {
        return ['application_id' => 'Lamaran', 'scheduled_at' => 'Jadwal', 'interviewer_id' => 'Pewawancara', 'meeting_link' => 'Tautan pertemuan', 'type' => 'Jenis wawancara'];
    }
}
