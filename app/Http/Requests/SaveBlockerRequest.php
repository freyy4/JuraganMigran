<?php

namespace App\Http\Requests;

use App\Models\ApplicationBlocker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBlockerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $blocker = $this->route('blocker');

        return ['application_id' => ['required', 'integer', Rule::exists('applications', 'id')->where(function ($query) use ($blocker): void {
            $query->whereNull('deleted_at');
            if ($blocker) {
                $query->orWhere('id', $blocker->application_id);
            }
        })], 'stage_id' => ['nullable', 'integer', Rule::exists('pipeline_stages', 'id')], 'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')], 'category' => ['required', 'string', 'max:255'], 'description' => ['required', 'string', 'max:10000'], 'priority' => ['required', Rule::in(ApplicationBlocker::priorities())], 'status' => ['required', Rule::in(ApplicationBlocker::statuses())], 'due_at' => ['nullable', 'date_format:Y-m-d\TH:i'], 'next_action' => ['nullable', 'string', 'max:10000']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'integer' => ':attribute tidak valid.', 'exists' => ':attribute tidak tersedia.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.', 'in' => 'Pilihan :attribute tidak valid.', 'date_format' => 'Deadline harus berupa tanggal dan waktu yang valid.'];
    }

    public function attributes(): array
    {
        return ['application_id' => 'Lamaran', 'stage_id' => 'Tahap', 'owner_id' => 'PIC', 'category' => 'Kategori', 'description' => 'Kendala', 'priority' => 'Prioritas', 'status' => 'Status', 'due_at' => 'Deadline', 'next_action' => 'Tindakan berikutnya'];
    }
}
