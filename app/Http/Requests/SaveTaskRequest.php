<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $task = $this->route('task');

        return ['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'task_type' => ['nullable', 'string', 'max:255'], 'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')], 'application_id' => ['nullable', 'integer', Rule::exists('applications', 'id')->where(function ($query) use ($task): void {
            $query->whereNull('deleted_at');
            if ($task?->application_id) {
                $query->orWhere('id', $task->application_id);
            }
        })], 'priority' => ['required', Rule::in(Task::priorities())], 'status' => ['required', Rule::in(Task::statuses())], 'due_at' => ['nullable', 'date_format:Y-m-d\TH:i']];
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.', 'integer' => ':attribute tidak valid.', 'exists' => ':attribute tidak tersedia.', 'in' => 'Pilihan :attribute tidak valid.', 'date_format' => 'Deadline harus berupa tanggal dan waktu yang valid.'];
    }

    public function attributes(): array
    {
        return ['title' => 'Judul', 'description' => 'Deskripsi', 'task_type' => 'Jenis', 'assigned_to' => 'PIC', 'application_id' => 'Lamaran', 'priority' => 'Prioritas', 'status' => 'Status', 'due_at' => 'Deadline'];
    }
}
