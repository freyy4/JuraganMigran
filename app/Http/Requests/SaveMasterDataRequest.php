<?php

namespace App\Http\Requests;

use App\Models\RequirementDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMasterDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $definition = config('master-data.'.$this->route('master'));
        abort_unless(is_array($definition) && isset($definition['model']), 404);
        $model = new $definition['model'];
        $rules = [];
        foreach ($definition['fields'] as $field => $label) {
            $rules[$field] = match ($field) {
                'is_active','has_expiry','is_terminal' => ['required', 'boolean'],
                'sequence' => ['required', 'integer', 'min:0', 'max:4294967295'],
                'max_file_size_kb' => ['nullable', 'integer', 'min:1', 'max:10240'],
                'allowed_file_types' => ['nullable', 'array'],
                'verification_help_text' => ['nullable', 'string', 'max:5000'],
                'sla_hours' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
                'data_type' => ['required', Rule::in(array_keys(RequirementDefinition::DATA_TYPES))],
                'name' => ['required', 'string', 'max:255'],
                'code' => [($definition['unique_code'] ?? false) ? 'required' : 'nullable', 'string', 'max:255'],
                'category' => [$this->route('master') === 'jenis-persyaratan' ? 'required' : 'nullable', 'string', 'max:255'],
                'postal_code' => ['nullable', 'string', 'max:10'],
                default => ['nullable', 'string', 'max:255']
            };
        }
        if ($this->route('master') === 'jenis-dokumen') {
            $rules['allowed_file_types.*'] = [Rule::in(['pdf', 'jpg', 'jpeg', 'png'])];
        }
        if ($definition['unique_code'] ?? false) {
            $rules['code'][] = Rule::unique($model->getTable(), 'code')->ignore($this->route('record'));
        }
        if (isset($definition['parent_field'])) {
            $parent = new $definition['parent_model'];
            $parentRule = Rule::exists($parent->getTable(), 'id');
            if ($this->route('master') === 'provinsi') {
                $current = $this->route('record') ? $model->newQuery()->findOrFail($this->route('record'))->country_id : null;
                $parentRule->where(function ($query) use ($current): void {
                    $query->where('is_active', true);
                    if ($current !== null) {
                        $query->orWhere('id', $current);
                    }
                });
            }
            $rules[$definition['parent_field']] = ['required', 'integer', $parentRule];
        }

        return $rules;
    }

    public function attributes(): array
    {
        $definition = config('master-data.'.$this->route('master'));

        return [...($definition['fields'] ?? []), ...(isset($definition['parent_field']) ? [$definition['parent_field'] => $definition['parent_label']] : [])];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.', 'integer' => ':attribute harus berupa bilangan bulat.', 'boolean' => ':attribute tidak valid.', 'exists' => ':attribute tidak tersedia.', 'unique' => ':attribute sudah digunakan.', 'in' => ':attribute tidak valid.', 'min' => ':attribute minimal :min.', 'max' => ':attribute maksimal :max.'];
    }
}
