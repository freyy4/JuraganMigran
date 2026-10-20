<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignApplicationCsRequest;
use App\Http\Requests\UpdateApplicationStatusRequest;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Country;
use App\Models\PipelineStage;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\AccessPolicy;
use App\Services\CrmWorkspace;
use App\Services\ProcessCockpit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    private function relations(): array
    {
        return ['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy.jobOrder.company', 'vacancy.jobOrder.country', 'currentStage', 'assignedCs'];
    }

    public function index(Request $request): View|JsonResponse
    {
        $rules = ['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'string', 'max:255']];
        foreach (['current_stage_id' => 'pipeline_stages', 'vacancy_id' => 'vacancies', 'company_id' => 'companies', 'country_id' => 'countries', 'assigned_cs_id' => 'users'] as $field => $table) {
            $rules[$field] = ['nullable', 'integer', Rule::exists($table, 'id')];
        }
        $filters = app(CrmWorkspace::class)->filters($request, $rules);
        $query = Application::with($this->relations());
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($query) use ($search, $request): void {
                $query->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate', function ($candidate) use ($search, $request): void {
                    $candidate->where('candidate_code', 'like', '%'.$search.'%')->orWhereHas('person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%')->when($request->user()->hasPermission('candidate.read.identity'), fn ($q) => $q->orWhere('nik', 'like', '%'.$search.'%')));
                });
            });
        }
        foreach (['current_stage_id', 'vacancy_id', 'assigned_cs_id', 'status'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }
        foreach (['company_id', 'country_id'] as $field) {
            if ($value = $filters[$field] ?? null) {
                $query->whereHas('vacancy.jobOrder', fn ($order) => $order->where($field, $value));
            }
        }

        $workspace = app(CrmWorkspace::class);
        $workspace->dates($query, $filters, 'applications.applied_at');
        $context = app(ProcessCockpit::class)->rows($request->user(), [], 'candidate.read.basic', true)->whereIn('row.id', (clone $query)->select('applications.id'));
        $summary = ['Total Lamaran' => (clone $query)->count(), 'Aktif Diproses' => (clone $query)->whereNotIn('applications.status', ['closed', 'completed', 'cancelled', 'withdrawn', 'rejected', 'departed'])->count(), 'Perlu Tindakan' => (clone $context)->whereIn('row.operational_status', ['ACTION_REQUIRED', 'OVERDUE', 'BLOCKED'])->count(), 'Stagnan' => (clone $query)->whereNotIn('applications.status', ['closed', 'completed', 'cancelled', 'withdrawn', 'rejected', 'departed'])->where('applications.updated_at', '<', now()->subDays(7))->count()];
        if (($filters['sort'] ?? '') === 'name') {
            $query->orderBy(Candidate::withoutGlobalScopes()->join('persons', 'persons.id', '=', 'candidates.person_id')->whereColumn('candidates.id', 'applications.candidate_id')->select('persons.full_name')->limit(1));
        } else {
            $query->orderBy('applications.applied_at', ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc');
        }
        $rows = $query->orderByDesc('applications.id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $process = (clone $context)->whereIn('row.id', $rows->pluck('id'))->get()->map(fn ($row) => app(ProcessCockpit::class)->decorate($row))->keyBy('id');
        $stages = PipelineStage::orderBy('sequence')->orderBy('id')->get();
        $stageCounts = (clone $query)->reorder()->select('current_stage_id')->selectRaw('COUNT(*) as total')->groupBy('current_stage_id')->pluck('total', 'current_stage_id');
        $options = ['stages' => $stages, 'vacancies' => Vacancy::withTrashed()->whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), 'candidate.read.basic'))->orderBy('title')->limit(200)->get(), 'companies' => Company::withTrashed()->whereIn('id', app(AccessPolicy::class)->companyIds($request->user(), 'candidate.read.basic'))->orderBy('name')->get(), 'countries' => Country::orderBy('name')->get(), 'users' => User::where('is_active', true)->orderBy('name')->get(), 'statuses' => Application::statusOptions()];

        return $workspace->response($request, 'applications.index', ['applications' => $rows, 'process' => $process, 'stageCounts' => $stageCounts, ...$options], $rows, $summary, $filters, fn ($application): array => ['id' => $application->id, 'code' => $application->application_code, 'candidate' => $application->candidate?->person?->full_name, 'vacancy' => $application->vacancy?->title, 'stage' => $application->currentStage?->name, 'status' => $application->status, 'owner' => $application->assignedCs?->name, 'nextAction' => $process[$application->id]?->next_action ?? null, 'due' => $process[$application->id]?->next_action_due_at ?? null, 'sla' => $process[$application->id]?->sla_state ?? 'unknown']);
    }

    public function show(Application $application): View
    {
        return view('applications.show', ['application' => $application->load($this->relations()), 'users' => User::where('is_active', true)->when(! collect(app(AccessPolicy::class)->policies(auth()->user(), 'candidate.reassign'))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL'), fn ($query) => $query->where('team_id', auth()->user()->team_id))->orderBy('name')->get(), 'statuses' => Application::statusOptions()]);
    }

    public function assignCs(AssignApplicationCsRequest $request, Application $application): RedirectResponse
    {
        $data = $request->validated();
        $all = collect(app(AccessPolicy::class)->policies($request->user(), 'candidate.reassign'))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL');
        if (! $all && $data['assigned_cs_id']) {
            abort_unless($request->user()->team_id && User::whereKey($data['assigned_cs_id'])->where('team_id', $request->user()->team_id)->exists(), 403);
        }
        DB::transaction(function () use ($application, $data): void {
            $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $locked->update(['assigned_cs_id' => $data['assigned_cs_id']]);
            $member = $data['assigned_cs_id'] ? DB::table('cs_members')->where('user_id', $data['assigned_cs_id'])->where('is_active', true)->value('id') : null;
            DB::table('cs_assignment_events')->insert(['application_id' => $application->id, 'member_id' => $member, 'status' => 'reassigned', 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
        });

        return to_route('applications.show', $application)->with('success', 'Penugasan CS berhasil diperbarui.');
    }

    public function updateStatus(UpdateApplicationStatusRequest $request, Application $application): RedirectResponse
    {
        DB::transaction(function () use ($request, $application): void {
            $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $locked->update($request->validated());
        });

        return to_route('applications.show', $application)->with('success', 'Status lamaran berhasil diperbarui.');
    }
}
