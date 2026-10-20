<?php

namespace App\Http\Requests;

use App\Models\TrainingBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTrainingBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $batch = $this->route('batch');

        return ['company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where(function ($query) use ($batch): void {
            $query->where(function ($q) use ($batch): void {
                $q->where('type', 'lpk')->whereNull('deleted_at');
                if ($batch) {
                    $q->orWhere('id', $batch->company_id);
                }
            });
        })], 'code' => ['required', 'string', 'max:255', Rule::unique('training_batches', 'code')->ignore($batch)], 'name' => ['required', 'string', 'max:255'], 'start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', ...($this->filled('start_date') ? ['after_or_equal:start_date'] : [])], 'capacity' => ['nullable', 'integer', 'min:1', 'max:4294967295'], 'status' => ['required', Rule::in(array_keys(TrainingBatch::STATUSES))]];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'integer' => ':attribute harus berupa angka bulat.', 'exists' => 'LPK tidak tersedia atau perusahaan bukan bertipe LPK.', 'unique' => 'Kode angkatan sudah digunakan.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max.', 'min' => ':attribute minimal :min.', 'in' => 'Status angkatan tidak valid.', 'date_format' => ':attribute harus berupa tanggal yang valid.', 'after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
    }

    public function attributes(): array
    {
        return ['company_id' => 'LPK', 'code' => 'Kode angkatan', 'name' => 'Nama angkatan', 'start_date' => 'Tanggal mulai', 'end_date' => 'Tanggal selesai', 'capacity' => 'Kapasitas', 'status' => 'Status'];
    }
}
