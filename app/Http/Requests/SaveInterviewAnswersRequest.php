<?php

namespace App\Http\Requests;

use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInterviewAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $questions = $this->route('interview')->questions()->get();
        $rules = ['answers' => ['sometimes', 'array', Rule::array($questions->pluck('id')->map(fn ($id) => (string) $id)->all())], 'answers.*' => ['array'], 'answers.*.answer' => ['nullable'], 'answers.*.score' => ['nullable', 'numeric', 'between:-999999.99,999999.99', 'decimal:0,2'], 'answers.*.notes' => ['nullable', 'string', 'max:5000'], 'outcome' => ['nullable', Rule::in(array_keys(Interview::OUTCOMES))], 'notes' => ['nullable', 'string', 'max:10000']];
        foreach ($questions as $question) {
            $required = $this->filled('outcome') && $question->is_required ? 'required' : 'nullable';
            $rules['answers.'.$question->id.'.answer'] = match ($question->input_type) {
                'number' => [$required, 'numeric'],'date' => [$required, 'date_format:Y-m-d'],'boolean' => [$required, 'boolean'],default => [$required, 'string', 'max:50000']
            };
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['required' => 'Jawaban pertanyaan wajib harus diisi sebelum menyimpan hasil.', 'array' => 'Daftar jawaban tidak valid atau pertanyaan telah berubah.', 'in' => 'Hasil wawancara tidak valid.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute terlalu panjang.', 'numeric' => ':attribute harus berupa angka.', 'between' => 'Skor di luar batas penyimpanan.', 'decimal' => 'Skor maksimal dua angka desimal.', 'date_format' => 'Jawaban harus berupa tanggal yang valid.', 'boolean' => 'Jawaban harus Ya atau Tidak.'];
    }

    public function attributes(): array
    {
        return ['answers' => 'Jawaban', 'answers.*.answer' => 'Jawaban', 'answers.*.score' => 'Skor', 'answers.*.notes' => 'Catatan jawaban', 'outcome' => 'Hasil', 'notes' => 'Catatan wawancara'];
    }
}
