<?php

namespace App\Services;

use App\Models\ApplicationBlocker;
use App\Models\Candidate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class CrmWorkspace
{
    public const PROFILE_FIELDS = ['full_name', 'nik', 'whatsapp', 'birth_date', 'gender', 'address', 'education_level'];

    public const BLOCKER_CATEGORIES = ['DOCUMENT', 'HEALTH', 'ADMINISTRATION', 'SPONSOR_PT', 'LPK', 'TRANSPORT', 'FINANCE', 'PLACEMENT', 'OTHER'];

    /** @return array<string, mixed> */
    public function filters(Request $request, array $rules = []): array
    {
        $assignedField = $request->routeIs('applications.*', 'acquisition.*') || ($request->routeIs('follow-ups.*') && ! $request->has('tab')) ? 'assigned_cs_id' : ($request->routeIs('blockers.*') ? 'owner_id' : 'assigned_to');
        $stageField = $request->routeIs('blockers.*') ? 'stage_id' : 'current_stage_id';
        foreach (['partnerId' => 'company_id', 'countryId' => 'country_id', 'stage' => $stageField, 'q' => 'search', 'pageSize' => 'per_page', 'assignedTo' => $assignedField, 'vacancyId' => 'vacancy_id', 'createdFrom' => 'created_from', 'createdTo' => 'created_to', 'dueFrom' => 'due_from', 'dueTo' => 'due_to'] as $alias => $field) {
            if ($request->has($alias) && ! $request->has($field)) {
                $request->merge([$field => $request->input($alias)]);
            }
        }

        return $request->validate($rules + ['search' => 'nullable|string|max:255', 'per_page' => 'nullable|in:10,25,50,100', 'page' => 'nullable|integer|min:1|max:1000000', 'sort' => 'nullable|in:newest,oldest,name,due,priority', 'created_from' => 'nullable|date_format:Y-m-d', 'created_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('created_from') ? ['after_or_equal:created_from'] : [])], 'due_from' => 'nullable|date_format:Y-m-d', 'due_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('due_from') ? ['after_or_equal:due_from'] : [])]]);
    }

    public function authorizeOwner(Request $request, ?int $owner): void
    {
        if (! $owner || $owner === $request->user()->id) {
            return;
        }
        $policies = collect(app(AccessPolicy::class)->policies($request->user(), 'process.update'));
        if ($policies->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL')) {
            return;
        }
        abort_unless($policies->contains(fn ($policy) => $policy['scope'] === 'TEAM') && $request->user()->team_id && User::whereKey($owner)->where('team_id', $request->user()->team_id)->where('is_active', true)->exists(), 403);
    }

    public function dates(object $query, array $filters, string $column, string $prefix = 'created'): void
    {
        foreach (['from' => '>=', 'to' => '<'] as $suffix => $operator) {
            if (! empty($filters[$prefix.'_'.$suffix])) {
                $date = Carbon::parse($filters[$prefix.'_'.$suffix], 'Asia/Jakarta')->startOfDay();
                if ($suffix === 'to') {
                    $date->addDay();
                }
                $query->where($column, $operator, $date->utc());
            }
        }
    }

    public function todayStart(): Carbon
    {
        return now('Asia/Jakarta')->startOfDay()->utc();
    }

    public function completenessSql(string $alias = 'persons'): string
    {
        return '('.implode(' + ', array_map(fn (string $field): string => "CASE WHEN COALESCE(TRIM($alias.$field), '') <> '' THEN 1 ELSE 0 END", self::PROFILE_FIELDS)).')';
    }

    public function completeness(Candidate $candidate): int
    {
        return (int) round(collect(self::PROFILE_FIELDS)->filter(fn ($field) => filled($candidate->person?->{$field}))->count() / count(self::PROFILE_FIELDS) * 100);
    }

    /** @return array<int|string, string> */
    public function labels(iterable $values, string $label = 'name'): array
    {
        return collect($values)->mapWithKeys(fn ($value) => [$value->id => $value->{$label}])->all();
    }

    /** @return array<int, array{label:string,value:int,tone:string,meta:string}> */
    public function kpis(array $values): array
    {
        return collect($values)->map(fn ($value, $label) => ['label' => $label, 'value' => (int) $value, 'tone' => match ($label) {
            'Terlambat', 'Kritikal', 'Overdue Follow-up' => 'red', 'Selesai', 'Siap Diproses', 'Selesai Hari Ini' => 'green', default => 'blue'
        }, 'meta' => 'Sesuai filter dan cakupan akses'])->values()->all();
    }

    public function response(Request $request, string $view, array $data, LengthAwarePaginator $rows, array $summary, array $filters, callable $present): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['items' => $rows->getCollection()->map($present), 'page' => $rows->currentPage(), 'pageSize' => $rows->perPage(), 'total' => $rows->total(), 'summary' => $summary, 'appliedFilters' => $filters]);
        }

        return view($view, [...$data, 'kpis' => $this->kpis($summary), 'filters' => $filters]);
    }

    public function visiblePermissions(Request $request, iterable $candidates, array $names = ['candidate.read.contact', 'candidate.read.identity']): void
    {
        $ids = collect($candidates)->pluck('id')->all();
        $permissions = [];
        foreach ($names as $permission) {
            $policies = app(AccessPolicy::class)->policies($request->user(), $permission);
            if (collect($policies)->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL')) {
                $permissions[$permission] = $ids;
            } else {
                $permissions[$permission] = Candidate::withoutGlobalScope('access')->whereIn('id', $ids)->whereIn('id', app(AccessPolicy::class)->candidateIds($request->user(), $permission))->pluck('id')->all();
            }
        }
        $request->attributes->set('crm_field_permissions', $permissions);
    }

    public function mask(User $user, Candidate $candidate, string $field, string $permission): string
    {
        $value = $candidate->person?->{$field};
        $cached = request()->attributes->get('crm_field_permissions', []);
        $allowed = array_key_exists($permission, $cached) ? in_array($candidate->id, $cached[$permission], true) : app(AccessPolicy::class)->canCandidate($user, $candidate->id, $permission);
        if (! $allowed) {
            return app(AccessPolicy::class)->mask($value) ?: '—';
        }

        return (string) ($value ?: '—');
    }

    /** @return array<string, mixed> */
    public function taskInsights(Request $request): array
    {
        $filters = $this->filters($request, ['priority' => 'nullable|string|max:255', 'status' => 'nullable|string|max:255', 'assigned_to' => 'nullable|integer|exists:users,id', 'task_type' => 'nullable|string|max:255', 'tab' => 'nullable|in:today,overdue,upcoming,completed']);
        $query = Task::query();
        $this->taskFilters($query, $filters);
        $today = $this->todayStart();

        return ['focus' => (clone $query)->whereNotIn('status', Task::COMPLETED_STATUSES)->where('due_at', '<', $today->copy()->addDay())->orderBy('due_at')->limit(5)->get(['id', 'title', 'due_at']), 'distribution' => (clone $query)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->get(), 'priorities' => (clone $query)->select('priority')->selectRaw('COUNT(*) as total')->groupBy('priority')->get()];
    }

    public function taskFilters(object $query, array $filters): void
    {
        foreach (['priority', 'status', 'assigned_to', 'task_type'] as $field) {
            if ($filters[$field] ?? '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhereHas('application.candidate.person', fn ($q) => $q->withTrashed()->where('full_name', 'like', '%'.$search.'%')));
        }
        $this->dates($query, $filters, 'due_at', 'due');
        $this->dates($query, $filters, 'created_at');
    }

    /** @return array<string, mixed> */
    public function blockerInsights(Request $request): array
    {
        $filters = $this->filters($request, ['category' => 'nullable|string|max:255', 'priority' => 'nullable|string|max:255', 'status' => 'nullable|string|max:255', 'owner_id' => 'nullable|integer|exists:users,id', 'stage_id' => 'nullable|integer|exists:pipeline_stages,id']);
        $query = ApplicationBlocker::query();
        $this->blockerFilters($query, $filters);

        return ['categories' => (clone $query)->select('category')->selectRaw('COUNT(*) as total')->groupBy('category')->orderByDesc('total')->limit(9)->get(), 'recurring' => (clone $query)->select('category', 'stage_id')->selectRaw('COUNT(*) as total')->groupBy('category', 'stage_id')->orderByDesc('total')->limit(3)->get(), 'aging' => ['0–7 hari' => (clone $query)->whereNotIn('status', ApplicationBlocker::RESOLVED)->where('created_at', '>=', now()->subDays(7))->count(), '8–30 hari' => (clone $query)->whereNotIn('status', ApplicationBlocker::RESOLVED)->where('created_at', '<', now()->subDays(7))->where('created_at', '>=', now()->subDays(30))->count(), '>30 hari' => (clone $query)->whereNotIn('status', ApplicationBlocker::RESOLVED)->where('created_at', '<', now()->subDays(30))->count()]];
    }

    public function blockerFilters(object $query, array $filters): void
    {
        foreach (['category', 'priority', 'status', 'owner_id', 'stage_id'] as $field) {
            if ($filters[$field] ?? '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('code', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%')->orWhereHas('application.candidate.person', fn ($q) => $q->withTrashed()->where('full_name', 'like', '%'.$search.'%')));
        }
        $this->dates($query, $filters, 'due_at', 'due');
        $this->dates($query, $filters, 'created_at');
    }
}
