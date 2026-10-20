<?php

namespace App\Http\Requests;

use App\Models\MedicalEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMedicalEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $event = $this->route('medical_event');

        return [
            'application_id' => ['required', 'integer', $event ? Rule::in([$event->application_id]) : Rule::exists('applications', 'id')->whereNull('deleted_at')],
            'clinic_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('type', 'clinic')->where(fn ($query) => $query->where(fn ($query) => $query->whereNull('deleted_at')->where('status', 'active'))->when($event?->clinic_id, fn ($query) => $query->orWhere('id', $event->clinic_id)))],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'completed_at' => ['nullable', 'required_if:status,completed', 'date_format:Y-m-d\TH:i', 'after_or_equal:scheduled_at'],
            'status' => ['required', Rule::in(array_keys(MedicalEvent::STATUSES))],
            'clearance_status' => ['nullable', 'required_if:status,completed', Rule::in(array_keys(MedicalEvent::CLEARANCES))],
            'expires_at' => ['nullable', 'date_format:Y-m-d', ...(is_string($this->input('completed_at')) && $this->filled('completed_at') ? ['after_or_equal:'.substr($this->input('completed_at'), 0, 10)] : [])],
        ];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'required_if' => ':attribute wajib diisi untuk MCU selesai.', 'exists' => ':attribute tidak tersedia.', 'in' => ':attribute tidak valid.', 'integer' => ':attribute tidak valid.', 'date_format' => ':attribute harus berupa tanggal yang valid.', 'after_or_equal' => ':attribute tidak boleh mendahului :date.'];
    }

    public function attributes(): array
    {
        return ['application_id' => 'Lamaran', 'clinic_id' => 'Klinik', 'scheduled_at' => 'Jadwal MCU', 'completed_at' => 'Waktu selesai', 'status' => 'Status', 'clearance_status' => 'Hasil klinik', 'expires_at' => 'Masa berlaku'];
    }
}
