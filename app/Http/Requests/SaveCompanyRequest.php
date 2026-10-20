<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('country_ids')) {
            if ($this->exists('country_id')) {
                $country = $this->input('country_id');
                $this->merge(['country_ids' => is_array($country) ? $country : ($country !== null && $country !== '' ? [$country] : [])]);
            } elseif ($this->input('countries_present') === '1') {
                $this->merge(['country_ids' => []]);
            } else {
                $this->merge(['country_ids' => $this->route('company')?->selectedCountryIds() ?? []]);
            }
        }
    }

    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'code' => ['required', 'string', 'max:255', Rule::unique('companies')->ignore($company?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Company::TYPES))],
            'country_ids' => ['present', 'array', 'max:500'],
            'country_ids.*' => ['required', 'integer', 'distinct', Rule::exists('countries', 'id')->where(function ($query) use ($company): void {
                $query->where(function ($query) use ($company): void {
                    $query->where('is_active', true);
                    if ($company) {
                        $query->orWhereIn('id', $company->selectedCountryIds());
                    }
                });
            })],
            'status' => ['required', Rule::in(Company::statusOptions())],
            'address' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'contacts' => ['sometimes', 'array', 'max:30'],
            'contacts.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('company_contacts', 'id')->where('company_id', $company?->id ?? 0)],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.position' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]+$/'],
            'contacts.*.whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]+$/'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.is_primary' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $contacts = $this->input('contacts', []);
            if (is_array($contacts) && collect($contacts)->filter(fn ($contact) => is_array($contact) && ($contact['is_primary'] ?? false) == 1)->count() > 1) {
                $validator->errors()->add('contacts', 'Pilih paling banyak satu PIC utama.');
            }
        }];
    }

    public function messages(): array
    {
        return ['present' => 'Daftar negara wajib dikirim.', 'required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute melebihi batas :max.', 'unique' => ':attribute sudah digunakan.', 'exists' => ':attribute tidak tersedia.', 'integer' => ':attribute tidak valid.', 'in' => 'Pilihan :attribute tidak valid.', 'email' => ':attribute harus berupa alamat email yang valid.', 'regex' => ':attribute harus berupa nomor telepon yang valid.', 'boolean' => ':attribute tidak valid.', 'array' => ':attribute harus berupa daftar.', 'distinct' => ':attribute tidak boleh berulang.'];
    }

    public function attributes(): array
    {
        return ['code' => 'Kode perusahaan', 'name' => 'Nama perusahaan', 'type' => 'Jenis perusahaan', 'country_ids' => 'Daftar negara', 'country_ids.*' => 'Negara', 'status' => 'Status', 'address' => 'Alamat', 'phone' => 'Telepon', 'email' => 'Email', 'contacts' => 'Daftar PIC', 'contacts.*.id' => 'PIC', 'contacts.*.name' => 'Nama PIC', 'contacts.*.position' => 'Jabatan PIC', 'contacts.*.phone' => 'Telepon PIC', 'contacts.*.whatsapp' => 'WhatsApp PIC', 'contacts.*.email' => 'Email PIC', 'contacts.*.is_primary' => 'PIC utama'];
    }
}
