<?php

namespace App\Http\Requests;

use App\Models\TrainingEnrollment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTrainingEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $enrollment = $this->route('enrollment');

        return ['application_id' => ['required', 'integer', Rule::exists('applications', 'id')->where(function ($query) use ($enrollment): void {
            $query->whereNull('deleted_at');
            if ($enrollment) {
                $query->orWhere('id', $enrollment->application_id);
            }
        })], 'batch_id' => ['required', 'integer', Rule::exists('training_batches', 'id')], 'status' => ['required', Rule::in(array_keys(TrainingEnrollment::STATUSES))], 'joined_at' => [Rule::requiredIf(in_array($this->input('status'), ['ongoing', 'completed'], true) || $this->filled('completed_at')), 'nullable', 'date_format:Y-m-d'], 'completed_at' => ['required_if:status,completed', 'nullable', 'date_format:Y-m-d', 'after_or_equal:joined_at']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'required_if' => 'Tanggal selesai wajib diisi untuk peserta yang selesai.', 'integer' => ':attribute tidak valid.', 'exists' => ':attribute tidak tersedia.', 'in' => 'Status peserta tidak valid.', 'date_format' => ':attribute harus berupa tanggal yang valid.', 'after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal masuk.'];
    }

    public function attributes(): array
    {
        return ['application_id' => 'Lamaran CPMI', 'batch_id' => 'Angkatan', 'status' => 'Status peserta', 'joined_at' => 'Tanggal masuk', 'completed_at' => 'Tanggal selesai'];
    }
}
