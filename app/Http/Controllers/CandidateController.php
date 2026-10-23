<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCandidateRequest;
use App\Models\Candidate;
use App\Models\Country;
use App\Models\Person;
use App\Services\AccessPolicy;
use App\Services\CandidateWorkspace;
use App\Services\CrmWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CandidateController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $sessionKey = 'cpmi.registry.filters.'.$request->user()->id;
        if (! $request->expectsJson()) {
            if ($request->boolean('reset')) {
                $request->session()->forget($sessionKey);
            } elseif (! $request->hasAny(['search', 'q', 'status', 'completeness', 'created_from', 'created_to', 'createdFrom', 'createdTo', 'age', 'education', 'gender', 'sort', 'filter_applied', 'vacancy_id', 'vacancyId'])) {
                $request->merge(array_replace($request->session()->get($sessionKey, []), $request->query()));
            }
        }
        $workspace = app(CrmWorkspace::class);
        $filters = $workspace->filters($request, ['status' => 'nullable|string|max:255', 'completeness' => 'nullable|in:complete,incomplete', 'vacancy_id' => 'nullable|integer|exists:vacancies,id', 'age' => 'nullable|in:18-25,26-35,36-45,46+', 'education' => 'nullable|string|max:255', 'gender' => 'nullable|in:male,female']);
        $score = $workspace->completenessSql();
        $allIdentity = collect(app(AccessPolicy::class)->policies($request->user(), 'candidate.read.identity'))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL');
        $allContact = collect(app(AccessPolicy::class)->policies($request->user(), 'candidate.read.contact'))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL');
        $query = Candidate::with('person')->join('persons', 'persons.id', '=', 'candidates.person_id')->whereNull('persons.deleted_at')->select('candidates.*')->selectRaw($score.' as profile_fields');
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('candidate_code', 'like', '%'.$search.'%')->orWhere('persons.full_name', 'like', '%'.$search.'%')->when($request->user()->hasPermission('candidate.read.identity'), fn ($q) => $q->orWhere(fn ($identity) => $identity->when(! $allIdentity, fn ($scoped) => $scoped->whereIn('candidates.id', app(AccessPolicy::class)->candidateIds($request->user(), 'candidate.read.identity')))->where('persons.nik', 'like', '%'.$search.'%')))->when($request->user()->hasPermission('candidate.read.contact'), fn ($q) => $q->orWhere(fn ($contact) => $contact->when(! $allContact, fn ($scoped) => $scoped->whereIn('candidates.id', app(AccessPolicy::class)->candidateIds($request->user(), 'candidate.read.contact')))->where('persons.whatsapp', 'like', '%'.$search.'%'))));
        }
        if ($filters['status'] ?? '') {
            $query->where('candidates.status', $filters['status']);
        }
        if ($filters['completeness'] ?? '') {
            $query->whereRaw($score.(($filters['completeness'] === 'complete') ? ' = 7' : ' < 7'));
        }
        if ($filters['education'] ?? '') {
            $query->where('persons.education_level', $filters['education']);
        }
        if (! $allIdentity && (($filters['age'] ?? '') || ($filters['gender'] ?? ''))) {
            $query->whereIn('candidates.id', app(AccessPolicy::class)->candidateIds($request->user(), 'candidate.read.identity'));
        }
        if ($filters['gender'] ?? '') {
            $query->where('persons.gender', $filters['gender']);
        }
        if ($filters['age'] ?? '') {
            $ages = explode('-', str_replace('+', '', $filters['age']));
            $today = now('Asia/Jakarta')->startOfDay();
            $query->whereDate('persons.birth_date', '<=', $today->copy()->subYears((int) $ages[0])->toDateString());
            if (isset($ages[1])) {
                $query->whereDate('persons.birth_date', '>', $today->copy()->subYears((int) $ages[1] + 1)->toDateString());
            }
        }
        if (! $request->expectsJson()) {
            $request->session()->put($sessionKey, array_diff_key($filters, ['page' => true]));
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

        $workspace->visiblePermissions($request, $rows->items(), ['candidate.read.identity', 'candidate.read.contact', 'candidate.read.location', 'candidate.update.profile', 'candidate.close', 'candidate.assign']);

        return $workspace->response($request, 'candidates.index', ['candidates' => $rows, 'statuses' => Candidate::statusOptions(), 'workspace' => $workspace, 'candidateWorkspace' => app(CandidateWorkspace::class), 'educations' => Candidate::join('persons', 'persons.id', '=', 'candidates.person_id')->whereNotNull('persons.education_level')->distinct()->orderBy('persons.education_level')->limit(100)->pluck('persons.education_level')], $rows, $summary, $filters, fn ($candidate): array => ['id' => $candidate->id, 'code' => $candidate->candidate_code, 'name' => $candidate->person->full_name, 'nik' => app(AccessPolicy::class)->mask($candidate->person->nik), 'whatsapp' => $workspace->mask($request->user(), $candidate, 'whatsapp', 'candidate.read.contact'), 'status' => $candidate->status, 'completeness' => $workspace->completeness($candidate), 'registered_at' => $candidate->registered_at]);
    }

    public function create(): View
    {
        return view('candidates.form', ['candidate' => new Candidate(['status' => 'lead', 'registered_at' => now()]), 'statuses' => Candidate::statusOptions(), 'countries' => Country::orderBy('name')->get(['id', 'name'])]);
    }

    public function store(SaveCandidateRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['registered_at'] = Carbon::parse($data['registered_at'], 'Asia/Jakarta')->utc();
        $candidate = DB::transaction(function () use ($data, $request): Candidate {
            $person = Person::create($data['person']);
            $candidate = $person->candidates()->create([...collect($data)->only(['candidate_code', 'status', 'registered_at', 'preferences'])->all(), 'created_by' => $request->user()?->id]);
            $candidate->contacts()->createMany($data['contacts'] ?? []);

            return $candidate;
        });

        return to_route('candidates.show', $candidate)->with('success', 'Calon pekerja berhasil ditambahkan.');
    }

    public function show(Request $request, Candidate $candidate): View
    {
        $request->validate(['timeline' => 'nullable|in:all']);

        return view('candidates.show', app(CandidateWorkspace::class)->details($request, $candidate));
    }

    public function print(Request $request, Candidate $candidate): View
    {
        abort_unless(app(AccessPolicy::class)->canCandidate($request->user(), $candidate->id, 'report.export'), 403);
        app(AccessPolicy::class)->audit($request->user(), 'candidate.print', ['candidate_id' => $candidate->id]);

        return view('candidates.show', app(CandidateWorkspace::class)->details($request, $candidate));
    }

    public function archive(Request $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500', 'confirm' => 'accepted']);
        DB::transaction(function () use ($request, $candidate, $data): void {
            $locked = Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;
            $locked->update(['status' => 'inactive']);
            app(AccessPolicy::class)->audit($request->user(), 'candidate.archived', ['candidate_id' => $candidate->id, 'previous_status' => $previous, 'reason' => $data['reason']]);
        });

        return back()->with('success', 'Calon pekerja diarsipkan. Riwayat dan lamaran tetap tersimpan.');
    }

    public function edit(Candidate $candidate): View
    {
        return view('candidates.form', ['candidate' => $candidate->load(['person', 'contacts']), 'statuses' => Candidate::statusOptions(), 'countries' => Country::orderBy('name')->get(['id', 'name'])]);
    }

    public function update(SaveCandidateRequest $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validated();
        $data['registered_at'] = Carbon::parse($data['registered_at'], 'Asia/Jakarta')->utc();
        DB::transaction(function () use ($candidate, $data): void {
            $candidate->person()->firstOrFail()->update($data['person']);
            $candidate->update(collect($data)->only(['candidate_code', 'status', 'registered_at', 'preferences'])->all());
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
