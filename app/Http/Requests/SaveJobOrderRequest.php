<?php

namespace App\Http\Requests;

use App\Models\JobOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveJobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $order = $this->route('job_order');

        return [
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where(function ($query) use ($order): void {
                $query->whereNull('deleted_at');
                if ($order) {
                    $query->orWhere('id', $order->company_id);
                }
            })],
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->where(function ($query) use ($order): void {
                $query->where('is_active', true);
                if ($order) {
                    $query->orWhere('id', $order->country_id);
                }
            })],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'quota' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', ...($this->filled('valid_from') ? ['after_or_equal:valid_from'] : [])],
            'status' => ['required', Rule::in(JobOrder::statusOptions())],
        ];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'integer' => ':attribute harus berupa bilangan bulat.', 'exists' => ':attribute tidak tersedia.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max.', 'min' => ':attribute minimal :min.', 'date_format' => ':attribute harus berupa tanggal yang valid.', 'after_or_equal' => 'Berlaku sampai harus sama atau setelah berlaku dari.', 'in' => 'Pilihan :attribute tidak valid.'];
    }

    public function attributes(): array
    {
        return ['company_id' => 'Mitra', 'country_id' => 'Negara', 'reference_number' => 'Nomor referensi', 'quota' => 'Kuota', 'valid_from' => 'Berlaku dari', 'valid_until' => 'Berlaku sampai', 'status' => 'Status'];
    }
}
