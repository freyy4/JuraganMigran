<?php

namespace App\Http\Requests;

use App\Models\ApplicationQualificationResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $requirements = $this->route('application')->vacancy->currentRequirements()->get()->keyBy('id');
        $rules = ['results' => ['required', 'array', 'size:'.$requirements->count()], 'results.*' => ['array'], 'results.*.vacancy_requirement_id' => ['required', 'integer', 'distinct', Rule::in($requirements->keys()->all())], 'results.*.result' => ['required', Rule::in(array_keys(ApplicationQualificationResult::RESULTS))], 'results.*.value' => ['nullable'], 'results.*.reason' => ['nullable', 'string', 'max:10000']];
        $rows = $this->input('results', []);
        if (is_array($rows)) {
            $requirements->load('definition');
            foreach ($rows as $index => $row) {
                if (! is_array($row) || ! is_scalar($row['vacancy_requirement_id'] ?? null)) {
                    continue;
                }
                $requirement = $requirements->get($row['vacancy_requirement_id']);
                if (! $requirement) {
                    continue;
                }
                $rules['results.'.$index.'.value'] = match ($requirement->definition?->inputType()) {
                    'number' => ['nullable', 'numeric', ...(in_array($requirement->definition->data_type, ['integer', 'int']) ? ['integer'] : [])],
                    'boolean' => ['nullable', 'boolean'], 'date' => ['nullable', 'date_format:Y-m-d'], default => ['nullable', 'string', 'max:10000']
                };
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'array' => ':attribute harus berupa daftar.', 'size' => 'Daftar persyaratan berubah. Muat ulang halaman sebelum menyimpan.', 'in' => ':attribute tidak valid atau bukan persyaratan terbaru lowongan ini.', 'integer' => ':attribute harus berupa bilangan bulat.', 'distinct' => 'Persyaratan tidak boleh berulang.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.', 'numeric' => ':attribute harus berupa angka.', 'boolean' => ':attribute harus Ya atau Tidak.', 'date_format' => ':attribute harus berupa tanggal yang valid.'];
    }

    public function attributes(): array
    {
        return ['results' => 'Hasil kualifikasi', 'results.*.vacancy_requirement_id' => 'Persyaratan', 'results.*.result' => 'Hasil', 'results.*.value' => 'Nilai kandidat', 'results.*.reason' => 'Alasan'];
    }
}
