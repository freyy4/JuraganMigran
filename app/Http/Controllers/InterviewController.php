<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveInterviewAnswersRequest;
use App\Http\Requests\SaveInterviewRequest;
use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InterviewController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['tab' => ['nullable', Rule::in(['today', 'upcoming', 'all'])], 'search' => ['nullable', 'string', 'max:255'], 'interviewer_id' => ['nullable', 'integer', Rule::exists('users', 'id')], 'status' => ['nullable', Rule::in(['scheduled', 'in_progress', 'completed', 'cancelled'])]], ['in' => 'Tab tidak valid.', 'integer' => 'Pewawancara tidak valid.', 'exists' => 'Pewawancara tidak tersedia.', 'string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal :max karakter.']);
        $query = Interview::with(['application.candidate.person' => fn ($q) => $q->withTrashed(), 'application.vacancy', 'interviewer']);
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        if ($filters['interviewer_id'] ?? null) {
            $query->where('interviewer_id', $filters['interviewer_id']);
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->whereHas('application', function ($q) use ($search): void {
                $q->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate', fn ($candidate) => $candidate->withTrashed()->where('candidate_code', 'like', '%'.$search.'%'))->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%'));
            });
        }
        $tab = $filters['tab'] ?? 'today';
        $start = now(Interview::TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'));
        $end = now(Interview::TIMEZONE)->startOfDay()->addDay()->setTimezone(config('app.timezone'));
        if ($tab === 'today') {
            $query->where('scheduled_at', '>=', $start)->where('scheduled_at', '<', $end);
        }
        if ($tab === 'upcoming') {
            $query->where('scheduled_at', '>=', $end);
        }

        return view('interviews.index', ['interviews' => $query->orderBy('scheduled_at')->orderBy('id')->paginate(15)->withQueryString(), 'tab' => $tab, 'users' => User::orderBy('name')->get()]);
    }

    private function form(Interview $interview): View
    {
        return view('interviews.form', ['interview' => $interview, 'applications' => Application::withTrashed()->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('id', $interview->application_id))->with(['candidate.person' => fn ($q) => $q->withTrashed(), 'vacancy'])->orderByDesc('id')->get(), 'users' => User::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return $this->form(new Interview(['type' => 'general']));
    }

    public function edit(Interview $interview): View
    {
        return $this->form($interview);
    }

    private function data(SaveInterviewRequest $request): array
    {
        $data = $request->validated();
        $data['scheduled_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], Interview::TIMEZONE)->setTimezone(config('app.timezone'))->startOfMinute();

        return $data;
    }

    public function store(SaveInterviewRequest $request): RedirectResponse
    {
        $interview = Interview::create([...$this->data($request), 'status' => 'scheduled']);

        return to_route('interviews.show', $interview)->with('success', 'Wawancara berhasil dijadwalkan.');
    }

    public function update(SaveInterviewRequest $request, Interview $interview): RedirectResponse
    {
        $interview->update($this->data($request));

        return to_route('interviews.show', $interview)->with('success', 'Jadwal dan pewawancara berhasil diperbarui.');
    }

    public function show(Interview $interview): View
    {
        return view('interviews.show', ['interview' => $interview->load(['application.candidate.person' => fn ($q) => $q->withTrashed(), 'application.vacancy', 'interviewer']), 'questions' => $interview->questions()->get(), 'answers' => $interview->answers()->get()->keyBy('question_id')]);
    }

    public function addQuestion(Request $request, Interview $interview): RedirectResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:10000'], 'input_type' => ['required', Rule::in(['text', 'textarea', 'number', 'date', 'boolean'])], 'is_required' => ['required', 'boolean'], 'sequence' => ['required', 'integer', 'min:0', 'max:4294967295']], ['required' => ':attribute wajib diisi.', 'string' => 'Pertanyaan harus berupa teks.', 'max' => ':attribute melebihi batas.', 'in' => 'Jenis jawaban tidak valid.', 'boolean' => 'Pilihan wajib tidak valid.', 'integer' => 'Urutan harus berupa bilangan bulat.', 'min' => 'Urutan minimal nol.'], ['question' => 'Pertanyaan', 'input_type' => 'Jenis jawaban', 'is_required' => 'Sifat wajib', 'sequence' => 'Urutan']);
        $interview->application->vacancy->interviewQuestions()->create([...$data, 'is_active' => true]);

        return to_route('interviews.show', $interview)->with('success', 'Pertanyaan lowongan berhasil ditambahkan.');
    }

    public function saveAnswers(SaveInterviewAnswersRequest $request, Interview $interview): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $interview): void {
            $locked = Interview::whereKey($interview->id)->lockForUpdate()->firstOrFail();
            $questions = $locked->questions()->get()->keyBy('id');
            foreach ($data['answers'] ?? [] as $id => $row) {
                if (! $questions->has($id)) {
                    throw ValidationException::withMessages(['answers' => 'Pertanyaan telah berubah. Muat ulang halaman.']);
                }
                $value = $row['answer'] ?? null;
                $locked->answers()->updateOrCreate(['question_id' => $id], ['answer' => is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value), 'score' => $row['score'] ?? null, 'notes' => $row['notes'] ?? null]);
            }
            if ($data['outcome'] ?? null) {
                $saved = $locked->answers()->get()->keyBy('question_id');
                foreach ($questions as $question) {
                    if ($question->is_required && trim($saved->get($question->id)?->answer ?? '') === '') {
                        throw ValidationException::withMessages(['answers' => 'Jawaban wajib belum lengkap. Muat ulang pertanyaan dan lengkapi jawaban.']);
                    }
                }
            }
            $locked->update(['outcome' => $data['outcome'] ?? null, 'notes' => $data['notes'] ?? null, 'status' => empty($data['outcome']) ? 'in_progress' : 'completed']);
        });

        return to_route('interviews.show', $interview)->with('success', 'Jawaban dan hasil wawancara berhasil disimpan.');
    }
}
