<?php

namespace App\Http\Requests;

use App\Models\RequirementDefinition;
use App\Models\Vacancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVacancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vacancy = $this->route('vacancy');
        $existing = $vacancy?->requirements()->pluck('requirement_definition_id')->all() ?? [];
        $rules = [
            'code' => ['required', 'string', 'max:255', Rule::unique('vacancies')->ignore($vacancy?->id)],
            'job_order_id' => ['required', 'integer', Rule::exists('job_orders', 'id')->where(function ($query) use ($vacancy): void {
                $query->where(function ($query) use ($vacancy): void {
                    $query->whereExists(function ($countries): void {
                        $countries->selectRaw('1')->from('countries')->whereColumn('countries.id', 'job_orders.country_id')->where('is_active', true);
                    });
                    if ($vacancy) {
                        $query->orWhere('id', $vacancy->job_order_id);
                    }
                });
            })],
            'title' => ['required', 'string', 'max:255'], 'position_name' => ['required', 'string', 'max:255'],
            'quota' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99', 'decimal:0,2', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'currency' => ['nullable', 'required_with:salary', 'string', 'regex:/^[A-Z]{3}$/'],
            'contract_duration' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:50000'],
            'status' => ['required', Rule::in(Vacancy::statusOptions())],
            'requirements' => ['sometimes', 'array', 'max:500'],
            'requirements.*' => ['array'],
            'requirements.*.definition_id' => ['required', 'integer', 'distinct', Rule::exists('requirement_definitions', 'id')->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $existing))],
            'requirements.*.selected' => ['required', 'boolean'],
        ];
        $definitions = RequirementDefinition::all()->keyBy('id');
        $rows = $this->input('requirements', []);
        if (is_array($rows)) {
            foreach ($rows as $index => $row) {
                if (! is_array($row) || ! in_array($row['selected'] ?? null, [1, '1', true], true)) {
                    continue;
                }
                $definitionId = $row['definition_id'] ?? null;
                $definition = is_scalar($definitionId) ? $definitions->get($definitionId) : null;
                if (! $definition) {
                    continue;
                }
                $prefix = 'requirements.'.$index.'.';
                $rules[$prefix.'operator'] = ['required', Rule::in(array_keys($definition->operators()))];
                $rules[$prefix.'is_required'] = ['required', 'boolean'];
                $rules[$prefix.'source_reference'] = ['nullable', 'string', 'max:5000'];
                $numeric = $definition->inputType() === 'number';
                if ($numeric && ($row['operator'] ?? '') === 'between') {
                    $numberRules = ['required', 'numeric', 'between:-99999999999.9999,99999999999.9999', 'decimal:0,4'];
                    if (in_array($definition->data_type, ['integer', 'int'])) {
                        $numberRules[] = 'integer';
                    }
                    $rules[$prefix.'value_min'] = $numberRules;
                    $rules[$prefix.'value_max'] = [...$numberRules, 'gte:'.$prefix.'value_min'];
                } else {
                    $rules[$prefix.'value'] = match ($definition->inputType()) {
                        'number' => ['required', 'numeric', ...(in_array($definition->data_type, ['integer', 'int']) ? ['integer'] : [])],
                        'boolean' => ['required', 'boolean'], 'date' => ['required', 'date_format:Y-m-d'], default => ['required', 'string', 'max:5000']
                    };
                }
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['salary.regex' => 'Format gaji tidak valid; gunakan maksimal 16 angka dan dua angka desimal.', 'required' => ':attribute wajib diisi.', 'required_with' => ':attribute wajib diisi saat gaji diisi.', 'string' => ':attribute harus berupa teks.', 'integer' => ':attribute harus berupa bilangan bulat.', 'numeric' => ':attribute harus berupa angka.', 'min' => ':attribute minimal :min.', 'max' => ':attribute maksimal :max.', 'unique' => ':attribute sudah digunakan.', 'exists' => ':attribute tidak tersedia.', 'in' => 'Pilihan :attribute tidak valid.', 'regex' => 'Mata uang harus berupa kode tiga huruf kapital.', 'boolean' => ':attribute tidak valid.', 'array' => ':attribute harus berupa daftar.', 'distinct' => 'Definisi persyaratan tidak boleh berulang.', 'date_format' => ':attribute harus berupa tanggal yang valid.', 'gte' => 'Batas maksimum harus sama atau lebih besar dari batas minimum.', 'between' => ':attribute harus antara :min dan :max.', 'decimal' => ':attribute memiliki jumlah angka desimal yang tidak valid.'];
    }

    public function attributes(): array
    {
        return ['code' => 'Kode', 'job_order_id' => 'Permintaan tenaga kerja', 'title' => 'Nama lowongan', 'position_name' => 'Posisi', 'quota' => 'Kuota', 'salary' => 'Gaji', 'currency' => 'Mata uang', 'contract_duration' => 'Durasi kontrak', 'description' => 'Deskripsi', 'status' => 'Status', 'requirements' => 'Persyaratan', 'requirements.*.definition_id' => 'Definisi persyaratan', 'requirements.*.selected' => 'Pilihan persyaratan', 'requirements.*.operator' => 'Operator', 'requirements.*.value' => 'Nilai persyaratan', 'requirements.*.value_min' => 'Batas minimum', 'requirements.*.value_max' => 'Batas maksimum', 'requirements.*.is_required' => 'Syarat wajib', 'requirements.*.source_reference' => 'Referensi sumber'];
    }
}
