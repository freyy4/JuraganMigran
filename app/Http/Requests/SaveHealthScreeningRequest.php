<?php

namespace App\Http\Requests;

use App\Models\HealthInterview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveHealthScreeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        if ($this->filled('session_id')) {
            return ['session_id' => ['required', 'integer'], 'intent' => ['required', Rule::in(['draft', 'complete'])], 'answers' => ['nullable', 'array']];
        }
        $questions = HealthInterview::questionsFor($this->route('application'))->get();
        $rules = ['status' => ['required', Rule::in(['pending', 'completed'])], 'answers' => ['sometimes', 'array', Rule::array($questions->pluck('id')->map(fn ($id) => (string) $id)->all())], 'answers.*' => ['array'], 'answers.*.answer' => ['nullable', 'string', 'max:10000'], 'answers.*.notes' => ['nullable', 'string', 'max:5000']];
        foreach ($questions as $question) {
            $rules['answers.'.$question->id.'.answer'] = [$this->input('status') === 'completed' && $question->is_required ? 'required' : 'nullable', 'string', 'max:10000'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['required' => ':attribute wajib diisi sebelum pemeriksaan diselesaikan.', 'array' => 'Daftar jawaban tidak valid. Muat ulang halaman.', 'in' => 'Status tidak valid.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.'];
    }

    public function attributes(): array
    {
        return ['answers.*.answer' => 'Jawaban', 'answers.*.notes' => 'Catatan', 'status' => 'Status'];
    }
}
