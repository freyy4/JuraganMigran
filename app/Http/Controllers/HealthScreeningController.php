<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveHealthScreeningRequest;
use App\Models\Application;
use App\Models\HealthInterview;
use App\Services\AccessPolicy;
use App\Services\HealthScreeningEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HealthScreeningController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $query = Application::with(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy', 'latestHealthInterview']);
        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%')));
        }

        $policies = app(AccessPolicy::class)->policies($request->user(), 'health.prescreen.read');
        $aggregateOnly = collect($policies)->isNotEmpty() && collect($policies)->every(fn ($policy) => $policy['scope'] === 'AGGREGATE_ONLY');
        $metrics = DB::table('health_interviews as health')->whereIn('application_id', app(AccessPolicy::class)->applicationIds($request->user(), 'health.prescreen.read', true))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('health_interviews as newer')->whereColumn('newer.application_id', 'health.application_id')->whereColumn('newer.id', '>', 'health.id'))->selectRaw('outcome, COUNT(*) as total')->groupBy('outcome')->pluck('total', 'outcome');

        return view('health-screenings.index', ['aggregateOnly' => $aggregateOnly, 'metrics' => $metrics, 'applications' => $query->latest('id')->paginate(15)->withQueryString()]);
    }

    public function show(Request $request, Application $application): View
    {
        $application->load(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy.jobOrder.country']);
        $request->validate(['session' => ['nullable', 'integer']]);
        $history = HealthInterview::where('application_id', $application->id)->latest('id')->get();
        $interview = $request->filled('session') ? $history->firstWhere('id', $request->integer('session')) : $history->first();
        if ($request->filled('session') && ! $interview) {
            abort(404);
        }
        $engine = app(HealthScreeningEngine::class);
        if ($interview?->source === 'LEGACY_GENERIC') {
            abort_unless($request->user()->hasPermission('health.sensitive.reveal'), 403);
            $answers = DB::table('health_answers')->where('health_interview_id', $interview->id)->get()->keyBy('question_id');

            return view('health-screenings.legacy', ['application' => $application, 'interview' => $interview, 'questions' => HealthInterview::questionsFor($application)->get(), 'answers' => $answers]);
        }
        $questions = $interview ? $engine->questions($interview, $application) : collect();
        if ($questions->contains('restricted', true)) {
            abort_unless($request->user()->hasPermission('health.sensitive.reveal'), 403);
        }

        return view('health-screenings.show', ['application' => $application, 'interview' => $interview, 'questions' => $questions, 'answers' => $interview ? $engine->answers($interview) : collect(), 'resolved' => $engine->resolved($application), 'version' => $interview?->templateVersion, 'history' => $history]);
    }

    public function start(Request $request, Application $application): RedirectResponse
    {
        app(HealthScreeningEngine::class)->start($application, $request->user());

        return to_route('health-screenings.show', $application)->with('success', 'Screening terstruktur dimulai menggunakan versi template terbit.');
    }

    public function review(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate(['session_id' => ['required', 'integer'], 'outcome' => ['required', Rule::in(['NEEDS_CLARIFICATION', 'REFER_TO_MCU', 'MEDICAL_REVIEW_REQUIRED', 'CLOSED'])], 'reason' => ['required', 'string', 'max:2000']]);
        abort_unless($request->user()->hasPermission('health.prescreen.review'), 403);
        DB::transaction(function () use ($application, $data, $request): void {
            $session = HealthInterview::where('application_id', $application->id)->whereKey($data['session_id'])->lockForUpdate()->firstOrFail();
            if (! $session->completed_at) {
                throw ValidationException::withMessages(['session_id' => 'Selesaikan screening sebelum review.']);
            }
            $session->update(['outcome' => $data['outcome']]);
            app(AccessPolicy::class)->audit($request->user(), 'health.session.reviewed', ['session_id' => $session->id, 'outcome' => $data['outcome'], 'reason_recorded' => true]);
            DB::table('health_screening_review_events')->insert(['health_interview_id' => $session->id, 'outcome' => $data['outcome'], 'encrypted_reason' => Crypt::encryptString($data['reason']), 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        });

        return back()->with('success', 'Review screening tercatat. Status pekerjaan tidak diubah otomatis.');
    }

    public function update(SaveHealthScreeningRequest $request, Application $application): RedirectResponse
    {
        $current = HealthInterview::where('application_id', $application->id)->latest('id')->first();
        if ($request->filled('session_id')) {
            app(HealthScreeningEngine::class)->save($application, $request->user(), $request->validated());

            return to_route('health-screenings.show', $application)->with('success', 'Jawaban screening disimpan.');
        }
        abort_unless($current?->source === 'LEGACY_GENERIC' && $request->user()->hasPermission('health.prescreen.review') && $request->user()->hasPermission('health.sensitive.reveal'), 403);
        $data = $request->validated();
        DB::transaction(function () use ($data, $application, $request): void {
            $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $questions = HealthInterview::questionsFor($locked)->get()->keyBy('id');
            if ($data['status'] === 'completed' && $questions->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'Tambahkan pertanyaan sebelum menyelesaikan pemeriksaan.']);
            }
            $interview = HealthInterview::where('application_id', $locked->id)->latest('id')->lockForUpdate()->first() ?? new HealthInterview(['application_id' => $locked->id]);
            $interview->save();
            foreach ($data['answers'] ?? [] as $id => $row) {
                if (! $questions->has($id)) {
                    throw ValidationException::withMessages(['answers' => 'Pertanyaan berubah. Muat ulang halaman.']);
                }
                $existing = DB::table('health_answers')->where('health_interview_id', $interview->id)->where('question_id', $id)->first();
                DB::table('health_answers')->updateOrInsert(['health_interview_id' => $interview->id, 'question_id' => $id], ['answer' => $row['answer'] ?? null, 'notes' => $row['notes'] ?? null, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
            }
            $saved = DB::table('health_answers')->where('health_interview_id', $interview->id)->get()->keyBy('question_id');
            $answered = $questions->filter(fn ($question) => trim($saved->get($question->id)?->answer ?? '') !== '')->count();
            if ($data['status'] === 'completed' && $questions->contains(fn ($question) => $question->is_required && trim($saved->get($question->id)?->answer ?? '') === '')) {
                throw ValidationException::withMessages(['answers' => 'Lengkapi jawaban wajib sebelum menyelesaikan pemeriksaan.']);
            }
            $interview->fill(['status' => $data['status'], 'completeness_percentage' => $questions->count() ? (int) round($answered / $questions->count() * 100) : 0]);
            if ($data['status'] !== 'completed') {
                $interview->completed_by = null;
                $interview->completed_at = null;
            } elseif (! $interview->completed_at || $interview->isDirty()) {
                $interview->completed_by = $request->user()->id;
                $interview->completed_at = now();
            }
            $interview->save();
        });

        return to_route('health-screenings.show', $application)->with('success', 'Pemeriksaan awal kesehatan berhasil disimpan.');
    }
}
