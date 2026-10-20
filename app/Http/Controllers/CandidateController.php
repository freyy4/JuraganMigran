<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCandidateRequest;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Person;
use App\Services\AccessPolicy;
use App\Services\CrmWorkspace;
use App\Services\DocumentRequirementEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CandidateController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $workspace = app(CrmWorkspace::class);
        $filters = $workspace->filters($request, ['status' => 'nullable|string|max:255', 'completeness' => 'nullable|in:complete,incomplete', 'vacancy_id' => 'nullable|integer|exists:vacancies,id']);
        $score = $workspace->completenessSql();
        $query = Candidate::with('person')->join('persons', 'persons.id', '=', 'candidates.person_id')->whereNull('persons.deleted_at')->select('candidates.*')->selectRaw($score.' as profile_fields');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('candidate_code', 'like', '%'.$search.'%')->orWhere('persons.full_name', 'like', '%'.$search.'%')->when($request->user()->hasPermission('candidate.read.identity'), fn ($q) => $q->orWhere('persons.nik', 'like', '%'.$search.'%'))->when($request->user()->hasPermission('candidate.read.contact'), fn ($q) => $q->orWhere('persons.whatsapp', 'like', '%'.$search.'%')));
        }
        if ($filters['status'] ?? '') {
            $query->where('candidates.status', $filters['status']);
        }
        if ($filters['completeness'] ?? '') {
            $query->whereRaw($score.(($filters['completeness'] === 'complete') ? ' = 7' : ' < 7'));
        }
        $workspace->dates($query, $filters, 'candidates.registered_at');
        $summary = ['Total Calon Pekerja' => (clone $query)->count(), 'Baru Minggu Ini' => (clone $query)->where('candidates.registered_at', '>=', now('Asia/Jakarta')->startOfWeek()->utc())->count(), 'Data Belum Lengkap' => (clone $query)->whereRaw($score.' < 7')->count(), 'Siap Diproses' => (clone $query)->whereRaw($score.' = 7')->whereNotIn('candidates.status', ['closed', 'inactive', 'rejected'])->count()];
        $query->withCount(['applications as active_application_count' => fn ($q) => $q->whereNotIn('status', ['closed', 'cancelled', 'rejected', 'withdrawn', 'completed'])->when($filters['vacancy_id'] ?? null, fn ($q, $id) => $q->where('vacancy_id', $id))]);
        $sort = $filters['sort'] ?? 'newest';
        if ($sort === 'name') {
            $query->orderBy('persons.full_name');
        } else {
            $query->orderBy('candidates.registered_at', $sort === 'oldest' ? 'asc' : 'desc');
        }
        $rows = $query->orderByDesc('candidates.id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        $workspace->visiblePermissions($request, $rows->items());

        return $workspace->response($request, 'candidates.index', ['candidates' => $rows, 'statuses' => Candidate::statusOptions(), 'workspace' => $workspace], $rows, $summary, $filters, fn ($candidate): array => ['id' => $candidate->id, 'code' => $candidate->candidate_code, 'name' => $candidate->person->full_name, 'nik' => app(AccessPolicy::class)->mask($candidate->person->nik), 'whatsapp' => $workspace->mask($request->user(), $candidate, 'whatsapp', 'candidate.read.contact'), 'status' => $candidate->status, 'completeness' => $workspace->completeness($candidate), 'registered_at' => $candidate->registered_at]);
    }

    public function create(): View
    {
        return view('candidates.form', ['candidate' => new Candidate(['status' => 'lead', 'registered_at' => now()]), 'statuses' => Candidate::statusOptions()]);
    }

    public function store(SaveCandidateRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $candidate = DB::transaction(function () use ($data, $request): Candidate {
            $person = Person::create($data['person']);
            $candidate = $person->candidates()->create([...collect($data)->only(['candidate_code', 'status', 'registered_at'])->all(), 'created_by' => $request->user()?->id]);
            $candidate->contacts()->createMany($data['contacts'] ?? []);

            return $candidate;
        });

        return to_route('candidates.show', $candidate)->with('success', 'Calon pekerja berhasil ditambahkan.');
    }

    public function show(Candidate $candidate): View
    {
        $mobilityTimeline = auth()->user()->hasPermission('candidate.read.timeline') ? DB::table('mobility_cases')->whereIn('application_id', DB::table('applications')->where('candidate_id', $candidate->id)->whereNull('deleted_at')->whereIn('id', app(AccessPolicy::class)->applicationIds(auth()->user(), 'candidate.read.timeline'))->select('id'))->latest('id')->limit(20)->get(['id', 'code', 'purpose', 'status', 'created_at']) : collect();

        $healthSummary = collect();
        $documentSummary = collect();
        if (auth()->user()->hasPermission('health.prescreen.read')) {
            $healthSummary = Application::where('candidate_id', $candidate->id)->whereIn('id', app(AccessPolicy::class)->applicationIds(auth()->user(), 'health.prescreen.read'))->with('latestHealthInterview')->get();
        }
        if (auth()->user()->hasPermission('document.read.metadata')) {
            $documentSummary = Application::where('candidate_id', $candidate->id)->whereIn('id', app(AccessPolicy::class)->applicationIds(auth()->user(), 'document.read.metadata'))->get()->map(fn ($application) => ['application' => $application, 'summary' => app(DocumentRequirementEngine::class)->summary($application)]);
        }

        return view('candidates.show', ['healthSummary' => $healthSummary, 'documentSummary' => $documentSummary, 'mobilityTimeline' => $mobilityTimeline, 'candidate' => $candidate->load(['person', 'contacts']), 'followups' => auth()->user()->hasPermission('candidate.read.timeline') ? DB::table('followup_events')->whereIn('lead_id', app(AcquisitionController::class)->scopedLeads(request(), 'candidate.read.timeline')->where('candidate_id', $candidate->id)->select('id'))->latest('id')->get() : collect()]);
    }

    public function edit(Candidate $candidate): View
    {
        return view('candidates.form', ['candidate' => $candidate->load(['person', 'contacts']), 'statuses' => Candidate::statusOptions()]);
    }

    public function update(SaveCandidateRequest $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($candidate, $data): void {
            $candidate->person()->firstOrFail()->update($data['person']);
            $candidate->update(collect($data)->only(['candidate_code', 'status', 'registered_at'])->all());
            $candidate->contacts()->delete();
            $candidate->contacts()->createMany($data['contacts'] ?? []);
        });

        return to_route('candidates.show', $candidate)->with('success', 'Data calon pekerja berhasil diperbarui.');
    }

    public function destroy(Candidate $candidate): RedirectResponse
    {
        $candidate->delete();

        return to_route('candidates.index')->with('success', 'Calon pekerja berhasil dihapus.');
    }
}
