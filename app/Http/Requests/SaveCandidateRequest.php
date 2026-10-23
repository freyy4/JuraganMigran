<?php

namespace App\Http\Requests;

use App\Models\Person;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $candidate = $this->route('candidate');

        return [
            'candidate_code' => ['required', 'string', 'max:255', Rule::unique('candidates')->ignore($candidate?->id)],
            'status' => ['required', 'string', 'max:255'],
            'registered_at' => ['required', 'date'],
            'person.full_name' => ['required', 'string', 'max:255'],
            'person.nik' => ['nullable', 'digits:16', Rule::unique('persons', 'nik')->ignore($candidate?->person_id)],
            'person.birth_place' => ['nullable', 'string', 'max:255'],
            'person.birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'person.gender' => ['nullable', Rule::in(['male', 'female'])],
            'person.phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]+$/'],
            'person.whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]+$/'],
            'person.email' => ['nullable', 'email', 'max:255', Rule::unique('persons', 'email')->ignore($candidate?->person_id)],
            'person.address' => ['nullable', 'string', 'max:5000'],
            'person.marital_status' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'person.education_level' => ['nullable', 'string', 'max:255'],
            'person.nationality' => ['nullable', 'string', 'max:255'],
            'person.current_job' => ['nullable', 'string', 'max:255'],
            'person.languages' => ['nullable', 'string', 'max:255'],
            'person.height_cm' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'person.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'preferences' => ['sometimes', 'array:target_country_id,work_sector,experience_summary,passport_status,departure_readiness,salary_target,contract_duration,departure_estimate'],
            'preferences.target_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'preferences.salary_target' => ['nullable', 'string', 'max:120'],
            'preferences.contract_duration' => ['nullable', 'string', 'max:120'],
            'preferences.departure_estimate' => ['nullable', 'string', 'max:120'],
            'preferences.work_sector' => ['nullable', 'string', 'max:255'],
            'preferences.experience_summary' => ['nullable', 'string', 'max:2000'],
            'preferences.passport_status' => ['nullable', Rule::in(['NONE', 'VALID', 'EXPIRED', 'IN_PROGRESS'])],
            'preferences.departure_readiness' => ['nullable', Rule::in(['READY', 'PREPARING', 'UNDECIDED'])],
            'contacts' => ['sometimes', 'array', 'max:30'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.relationship' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]+$/'],
            'contacts.*.whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]+$/'],
            'contacts.*.address' => ['nullable', 'string', 'max:5000'],
            'contacts.*.contact_type' => ['required', Rule::in(['family', 'emergency'])],
            'contacts.*.is_emergency' => ['required', 'boolean'],
            'contacts.*.is_primary' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $person = $this->input('person', []);
        foreach (['phone', 'whatsapp'] as $field) {
            if (! empty($person[$field]) && preg_match('/^\+?[0-9 ()-]+$/', $person[$field])) {
                $phone = preg_replace('/[^0-9]/', '', $person[$field]);
                $person[$field] = '+'.(str_starts_with($phone, '0') ? '62'.substr($phone, 1) : $phone);
            }
        }
        if (! empty($person['email'])) {
            $person['email'] = strtolower(trim($person['email']));
        }
        $this->merge(['person' => $person]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $person = $this->input('person', []);
            foreach (['phone', 'whatsapp'] as $field) {
                $number = $person[$field] ?? null;
                if (! $number) {
                    continue;
                }
                $digits = preg_replace('/[^0-9]/', '', $number);
                $local = str_starts_with($digits, '62') ? '0'.substr($digits, 2) : $digits;
                $duplicate = Person::query()->where('id', '!=', $this->route('candidate')?->person_id ?? 0)
                    ->whereHas('candidates')->where(function ($query) use ($digits, $local): void {
                        $query->whereIn('phone', ['+'.$digits, $digits, $local])->orWhereIn('whatsapp', ['+'.$digits, $digits, $local]);
                    })->exists();
                if ($duplicate) {
                    $validator->errors()->add('person.'.$field, 'Nomor terhubung dengan CPMI lain. Periksa profil existing sebelum menyimpan.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute melebihi batas :max.', 'unique' => ':attribute sudah digunakan.', 'digits' => ':attribute harus terdiri dari :digits angka.', 'date' => ':attribute harus berupa tanggal yang valid.', 'before_or_equal' => ':attribute tidak boleh melewati hari ini.', 'email' => ':attribute harus berupa alamat email yang valid.', 'regex' => ':attribute hanya boleh berisi angka, spasi, tanda +, tanda kurung, atau tanda hubung.', 'in' => 'Pilihan :attribute tidak valid.', 'boolean' => 'Pilihan :attribute tidak valid.', 'array' => ':attribute harus berupa daftar.'];
    }

    public function attributes(): array
    {
        return ['candidate_code' => 'Kode CPMI', 'status' => 'Status', 'registered_at' => 'Tanggal daftar', 'person.full_name' => 'Nama', 'person.nik' => 'NIK', 'person.birth_date' => 'Tanggal lahir', 'person.birth_place' => 'Tempat lahir', 'person.gender' => 'Jenis kelamin', 'person.phone' => 'Telepon', 'person.whatsapp' => 'WhatsApp', 'person.email' => 'Email', 'person.address' => 'Alamat', 'person.education_level' => 'Pendidikan', 'contacts' => 'Kontak', 'contacts.*.name' => 'Nama kontak', 'contacts.*.relationship' => 'Hubungan', 'contacts.*.phone' => 'Telepon kontak', 'contacts.*.whatsapp' => 'WhatsApp kontak', 'contacts.*.address' => 'Alamat kontak', 'contacts.*.contact_type' => 'Jenis kontak', 'contacts.*.is_emergency' => 'Kontak darurat', 'contacts.*.is_primary' => 'Kontak utama'];
    }
}
