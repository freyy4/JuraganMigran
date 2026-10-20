<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveFlightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $flight = $this->route('flight');

        return ['application_id' => ['required', 'integer', Rule::exists('applications', 'id')->where(function ($query) use ($flight): void {
            $query->whereNull('deleted_at');
            if ($flight) {
                $query->orWhere('id', $flight->application_id);
            }
        })], 'airline' => ['required', 'string', 'max:255'], 'flight_number' => ['required', 'string', 'max:255'], 'departure_airport' => ['required', 'string', 'max:255'], 'arrival_airport' => ['required', 'string', 'max:255'], 'departure_at' => ['required', 'date_format:Y-m-d\TH:i'], 'arrival_at' => ['required', 'date_format:Y-m-d\TH:i', 'after_or_equal:departure_at']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'integer' => ':attribute tidak valid.', 'exists' => 'Lamaran tidak tersedia.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.', 'date_format' => ':attribute harus berupa tanggal dan waktu yang valid.', 'after_or_equal' => 'Waktu tiba tidak boleh lebih awal dari waktu berangkat.'];
    }

    public function attributes(): array
    {
        return ['application_id' => 'Lamaran', 'airline' => 'Maskapai', 'flight_number' => 'Nomor penerbangan', 'departure_airport' => 'Bandara asal', 'arrival_airport' => 'Bandara tujuan', 'departure_at' => 'Waktu berangkat', 'arrival_at' => 'Waktu tiba'];
    }
}
