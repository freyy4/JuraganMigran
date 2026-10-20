<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Vacancy;
use App\Services\AccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CandidateApplicationController extends Controller
{
    public function create(Request $request, Candidate $candidate): View
    {
        abort_unless(app(AccessPolicy::class)->canCandidate($request->user(), $candidate->id, 'candidate.assign'), 403);
        $request->validate(['vacancy_id' => 'nullable|integer|exists:vacancies,id', 'search' => 'nullable|string|max:100']);
        $vacancies = Vacancy::whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), 'candidate.assign'))->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.$request->search.'%'))->orderBy('title')->limit(100)->get();

        return view('applications.create', ['candidate' => $candidate->load('person'), 'vacancies' => $vacancies]);
    }

    public function store(Request $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validate(['vacancy_id' => 'required|integer|exists:vacancies,id']);
        $policy = app(AccessPolicy::class);
        abort_unless($policy->canCandidate($request->user(), $candidate->id, 'candidate.assign') && $policy->vacancyIds($request->user(), 'candidate.assign')->where('vacancies.id', $data['vacancy_id'])->exists(), 403);
        $application = DB::transaction(function () use ($request, $candidate, $data): Application {
            $locked = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['closed', 'inactive', 'rejected'], true)) {
                throw ValidationException::withMessages(['candidate' => 'Kandidat telah ditutup. Tinjau profil sebelum membuat lamaran.']);
            }
            $vacancy = Vacancy::whereKey($data['vacancy_id'])->whereNotIn('status', ['closed', 'archived'])->lockForUpdate()->first();
            if (! $vacancy) {
                throw ValidationException::withMessages(['vacancy_id' => 'Lowongan tidak tersedia atau telah ditutup.']);
            }
            $exists = Application::withoutGlobalScope('access')->where('candidate_id', $candidate->id)->where('vacancy_id', $vacancy->id)->whereNotIn('status', ['closed', 'completed', 'cancelled', 'rejected', 'withdrawn'])->exists();
            if ($exists) {
                throw ValidationException::withMessages(['vacancy_id' => 'Kandidat sudah memiliki lamaran aktif untuk lowongan ini.']);
            }
            $application = Application::create(['candidate_id' => $candidate->id, 'vacancy_id' => $vacancy->id, 'application_code' => 'APP-'.Str::ulid(), 'status' => 'active', 'applied_at' => now()]);
            app(AccessPolicy::class)->audit($request->user(), 'crm.application.created', ['candidate_id' => $candidate->id, 'application_id' => $application->id]);

            return $application;
        });

        return to_route('applications.show', $application)->with('success', 'Lamaran berhasil dibuat.');
    }
}
