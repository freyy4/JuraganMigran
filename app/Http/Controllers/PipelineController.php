<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveApplicationStageRequest;
use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\Company;
use App\Models\Country;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\AccessPolicy;
use App\Services\ProcessCockpit;
use App\Services\ProcessStageTransition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PipelineController extends Controller
{
    private function relations(): array
    {
        return ['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy.jobOrder.company', 'vacancy.jobOrder.country', 'assignedCs', 'currentStage', 'latestStageHistory'];
    }

    public function index(Request $request): View
    {
        $cockpit = app(ProcessCockpit::class);
        $savedViews = DB::table('process_user_views')->where('user_id', $request->user()->id)->orderBy('name')->get();
        if ($request->filled('saved_view')) {
            $request->validate(['saved_view' => ['integer']]);
            $saved = $savedViews->firstWhere('id', (int) $request->input('saved_view'));
            abort_unless($saved, 404);
            $request->merge(json_decode($saved->filters, true));
            app(AccessPolicy::class)->audit($request->user(), 'PROCESS_SAVED_VIEW_USED', ['view_id' => $saved->id]);
        }
        $filters = $cockpit->filters($request);
        $mode = $filters['mode'] ?? 'table';
        if ($mode === 'followups' && empty($filters['queue'])) {
            $filters['queue'] = 'today';
        }
        $query = $cockpit->rows($request->user(), $filters);
        $baseFilters = array_diff_key($filters, array_flip(['current_stage_id']));
        $base = $cockpit->rows($request->user(), $baseFilters);
        $stages = PipelineStage::orderBy('sequence')->orderBy('id')->get();
        $stageCounts = (clone $base)->select('row.current_stage_id')->selectRaw('COUNT(*) as total, SUM(row.overdue) as overdue, AVG(row.age_hours) as average_age')->groupBy('row.current_stage_id')->get()->keyBy('current_stage_id');
        $summary = $cockpit->summary($query);
        $stageTotal = (clone $base)->count();
        $exceptions = [];
        foreach ($cockpit::EXCEPTIONS as $key => $label) {
            $exceptions[$key] = $cockpit->exception(clone $base, $key)->count();
        }
        $queueCounts = [];
        if ($mode === 'followups') {
            $queueBase = $cockpit->rows($request->user(), array_diff_key($filters, ['queue' => true]));
            foreach ($cockpit::QUEUES as $key => $label) {
                $queueCounts[$key] = $cockpit->queue(clone $queueBase, $key)->count();
            }
        }
        $rows = $cockpit->ordered(clone $query, $filters['sort'] ?? 'priority')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $rows->getCollection()->transform(fn ($row) => $cockpit->decorate($row));
        $priorities = $cockpit->ordered((clone $query)->where(function ($q): void {
            $q->where('row.priority_rank', '>=', 3)->orWhere('row.blocker_count', '>', 0)->orWhereBetween('row.next_action_due_at', [now(), now(Task::TIMEZONE)->endOfDay()->setTimezone(config('app.timezone'))]);
        }))->limit(config('process.priority_limit'))->get()->map(fn ($row) => $cockpit->decorate($row));
        $columns = [];
        if ($mode === 'board') {
            foreach ($stages as $stage) {
                $cards = $cockpit->ordered((clone $query)->where('row.current_stage_id', $stage->id))->paginate(config('process.board_page_size'), ['*'], 'stage_'.$stage->id.'_page')->withQueryString();
                $cards->getCollection()->transform(fn ($row) => $cockpit->decorate($row));
                $columns[] = ['stage' => $stage, 'applications' => $cards];
            }
        }
        $policy = app(AccessPolicy::class);
        $vacancyIds = $policy->vacancyIds($request->user(), 'process.read');
        $vacancies = Vacancy::withTrashed()->whereIn('id', $vacancyIds)->orderBy('title')->get(['id', 'title', 'code']);
        $companies = Company::withTrashed()->whereIn('id', $policy->companyIds($request->user(), 'process.read'))->orderBy('name')->get(['id', 'name']);
        $countries = Country::whereIn('id', DB::table('job_orders')->join('vacancies', 'vacancies.job_order_id', '=', 'job_orders.id')->whereIn('vacancies.id', $vacancyIds)->select('job_orders.country_id'))->orderBy('name')->get(['id', 'name']);
        $users = User::where('is_active', true)->where(function ($q) use ($base, $request, $policy): void {
            $q->whereIn('id', (clone $base)->select('row.assigned_cs_id'));
            if (collect($policy->policies($request->user(), 'candidate.reassign'))->contains(fn ($p) => $p['scope'] === 'OPERATIONAL_ALL')) {
                $q->orWhereRaw('1=1');
            } elseif ($request->user()->team_id) {
                $q->orWhere('team_id', $request->user()->team_id);
            }
        })->orderBy('name')->get(['id', 'name']);
        $workload = $mode === 'summary' ? (clone $query)->select('row.assigned_cs_id', 'row.cs_name')->selectRaw('COUNT(*) as total, SUM(row.overdue) as overdue, SUM(row.blocker_count) as blockers')->groupBy('row.assigned_cs_id', 'row.cs_name')->orderByDesc('total')->limit(30)->get() : collect();
        $bottlenecks = $mode === 'summary' ? (clone $query)->select('row.vacancy_id', 'row.vacancy_label', 'row.company_label')->selectRaw('COUNT(*) as total, SUM(row.overdue) as overdue')->groupBy('row.vacancy_id', 'row.vacancy_label', 'row.company_label')->orderByDesc('overdue')->limit(10)->get() : collect();
        $trends = collect();
        $blockerDistribution = collect();
        if ($mode === 'summary') {
            $historyIds = $cockpit->rows($request->user(), $baseFilters, 'process.read', true)->select('row.id');
            $tasks = DB::table('tasks')->whereIn('application_id', $historyIds);
            for ($offset = 6; $offset >= 0; $offset--) {
                $start = now(Task::TIMEZONE)->startOfDay()->subDays($offset)->setTimezone(config('app.timezone'));
                $end = $offset === 0 ? now() : $start->copy()->addDay();
                $taskOverdue = (clone $tasks)->where('created_at', '<=', $end)->where('due_at', '<', $end)->where(fn ($q) => $q->whereNotIn('status', Task::COMPLETED_STATUSES)->orWhere('completed_at', '>', $end))->distinct()->count('application_id');
                $departures = DB::table('travel_events')->whereIn('application_id', $historyIds)->where('event_type', 'departure')->where('status', 'completed')->where('completed_at', '>=', $start)->where('completed_at', '<=', $end)->count();
                $trends->push(['date' => $start->copy()->timezone(Task::TIMEZONE)->format('d M'), 'overdue' => $taskOverdue, 'departures' => $departures]);
            }
            $blockerDistribution = DB::table('application_blockers')->whereIn('application_id', (clone $query)->select('row.id'))->whereNotIn('status', ApplicationBlocker::RESOLVED)->select('category')->selectRaw('COUNT(*) as total')->groupBy('category')->orderByDesc('total')->limit(10)->get();
        }
        $templates = $request->user()->hasPermission('process.update') && $request->user()->hasPermission('candidate.read.contact') ? DB::table('whatsapp_templates')->where('status', 'active')->orderBy('name')->get(['id', 'name']) : collect();
        app(AccessPolicy::class)->audit($request->user(), $request->query() ? 'PROCESS_FILTER_APPLIED' : 'PROCESS_VIEW_OPENED', ['mode' => $mode, 'filter_keys' => array_keys($filters)]);
        $drafts = DB::table('process_message_drafts')->where('created_by', $request->user()->id)->whereIn('application_id', $policy->applicationIds($request->user(), 'process.update'))->whereIn('application_id', $policy->applicationIds($request->user(), 'candidate.read.contact'))->where('status', 'manual_ready')->orderByDesc('id')->limit(10)->get(['id', 'application_id']);

        return view('pipeline.index', compact('trends', 'blockerDistribution', 'drafts', 'filters', 'mode', 'rows', 'stages', 'stageCounts', 'stageTotal', 'summary', 'exceptions', 'queueCounts', 'priorities', 'columns', 'savedViews', 'workload', 'bottlenecks', 'templates', 'companies', 'vacancies', 'countries', 'users'));
    }

    public function api(Request $request, string $widget): JsonResponse
    {
        $cockpit = app(ProcessCockpit::class);
        $filters = $cockpit->filters($request);
        $query = $cockpit->rows($request->user(), $filters);
        $data = match ($widget) {
            'summary' => $cockpit->summary($query),
            'stages' => (clone $query)->select('row.current_stage_id', 'row.stage_label')->selectRaw('COUNT(*) as total, SUM(row.overdue) as overdue')->groupBy('row.current_stage_id', 'row.stage_label')->get(),
            'priorities' => $cockpit->ordered((clone $query)->where(fn ($q) => $q->where('row.priority_rank', '>=', 3)->orWhere('row.blocker_count', '>', 0)))->limit(config('process.priority_limit'))->get()->map(fn ($row) => $cockpit->decorate($row)),
            'filters' => ['modes' => $cockpit::MODES, 'statuses' => $cockpit::STATUSES, 'priorities' => $cockpit::PRIORITIES, 'queues' => $cockpit::QUEUES, 'exceptions' => $cockpit::EXCEPTIONS],
            default => $cockpit->ordered($query, $filters['sort'] ?? 'priority')->paginate((int) ($filters['per_page'] ?? 25))->through(fn ($row) => $cockpit->decorate($row)),
        };

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, Application $application): View
    {
        $data = $request->validate(['to_stage_id' => ['required', 'integer', Rule::exists('pipeline_stages', 'id')->where('is_active', true)]], ['required' => 'Pilih tahap tujuan.', 'integer' => 'Tahap tujuan tidak valid.', 'exists' => 'Tahap tujuan tidak tersedia atau tidak aktif.']);
        if ((int) $application->current_stage_id === (int) $data['to_stage_id']) {
            throw ValidationException::withMessages(['to_stage_id' => 'Lamaran sudah berada di tahap yang dipilih.']);
        }

        return view('pipeline.confirm', ['application' => $application->load($this->relations()), 'destination' => PipelineStage::findOrFail($data['to_stage_id'])]);
    }

    public function move(MoveApplicationStageRequest $request, Application $application): RedirectResponse
    {
        $data = $request->validated();
        app(ProcessStageTransition::class)->move($request->user(), $application, $data);

        return to_route('pipeline.index')->with('success', 'Tahap lamaran berhasil dipindahkan dan riwayat perubahan tersimpan.');
    }
}
