<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTaskRequest;
use App\Models\Application;
use App\Models\Task;
use App\Models\User;
use App\Services\CrmWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TaskController extends Controller
{
    private function routePrefix(): string
    {
        return request()->routeIs('follow-ups.*') ? 'follow-ups' : 'tasks';
    }

    private function viewContext(): array
    {
        return ['routePrefix' => $this->routePrefix(), 'pageTitle' => $this->routePrefix() === 'follow-ups' ? 'Tindak Lanjut' : 'Tugas'];
    }

    private function checkTaskScope(Task $task): void
    {
        abort_if($this->routePrefix() === 'follow-ups' && $task->task_type !== 'follow_up', 404);
    }

    public function index(Request $request): View|JsonResponse
    {
        $workspace = app(CrmWorkspace::class);
        $filters = $workspace->filters($request, ['tab' => ['nullable', Rule::in(array_keys(Task::TABS))], 'priority' => 'nullable|string|max:255', 'status' => 'nullable|string|max:255', 'assigned_to' => 'nullable|integer|exists:users,id', 'task_type' => 'nullable|string|max:255']);
        $tab = $filters['tab'] ?? 'today';
        $query = Task::with(['assignee', 'application.candidate.person' => fn ($query) => $query->withTrashed(), 'application.vacancy']);
        if ($this->routePrefix() === 'follow-ups') {
            $query->where('task_type', 'follow_up');
        }
        $workspace->taskFilters($query, $filters);
        $counts = [];
        foreach (Task::TABS as $key => $label) {
            $counts[$key] = (clone $query)->forTab($key)->count();
        }
        $active = (clone $query)->whereNotIn('status', Task::COMPLETED_STATUSES);
        $today = $workspace->todayStart();
        $summary = ['Hari Ini' => (clone $active)->where('due_at', '>=', $today)->where('due_at', '<', $today->copy()->addDay())->count(), 'Terlambat' => (clone $active)->where('due_at', '<', now())->count(), 'Prioritas Tinggi' => (clone $active)->whereIn('priority', ['high', 'urgent', 'critical'])->count(), 'Selesai' => (clone $query)->whereIn('status', Task::COMPLETED_STATUSES)->count()];
        $query->forTab($tab);
        if (($filters['sort'] ?? '') === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END");
        }
        if (in_array($filters['sort'] ?? '', ['newest', 'oldest'], true)) {
            $query->orderBy('created_at', $filters['sort'] === 'oldest' ? 'asc' : 'desc');
        } else {
            $query->orderByRaw('due_at IS NULL')->orderBy('due_at');
        }
        $rows = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return $workspace->response($request, 'tasks.index', [...$this->viewContext(), 'tasks' => $rows, 'tab' => $tab, 'counts' => $counts, 'priorities' => Task::priorities(), 'statuses' => Task::statuses(), 'users' => User::orderBy('name')->get(), 'types' => DB::table('master_reference_entries')->where('resource', 'jenis-tugas')->where('is_active', true)->orderBy('name')->pluck('code')], $rows, $summary, $filters, fn ($task): array => ['id' => $task->id, 'title' => $task->title, 'candidate' => $task->application?->candidate?->person?->full_name, 'owner' => $task->assignee?->name, 'priority' => $task->priority, 'status' => $task->status, 'due' => $task->due_at]);
    }

    public function insights(Request $request): JsonResponse
    {
        return response()->json(app(CrmWorkspace::class)->taskInsights($request));
    }

    private function form(Task $task): View
    {
        $applications = Application::withTrashed()->where(function ($query) use ($task): void {
            $query->whereNull('deleted_at');
            if ($task->application_id) {
                $query->orWhere('id', $task->application_id);
            }
        })->with(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy'])->orderByDesc('id')->get();

        return view('tasks.form', [...$this->viewContext(), 'task' => $task, 'applications' => $applications, 'users' => User::orderBy('name')->get(), 'priorities' => Task::priorities(), 'statuses' => Task::statuses(), 'types' => DB::table('master_reference_entries')->where('resource', 'jenis-tugas')->where('is_active', true)->orderBy('name')->pluck('code')]);
    }

    public function create(): View
    {
        return $this->form(new Task(['priority' => 'normal', 'status' => 'open', 'task_type' => $this->routePrefix() === 'follow-ups' ? 'follow_up' : null]));
    }

    public function edit(Task $task): View
    {
        $this->checkTaskScope($task);

        return $this->form($task);
    }

    private function data(SaveTaskRequest $request, ?Task $task = null): array
    {
        $data = $request->validated();
        if ((int) ($data['assigned_to'] ?? 0) !== (int) ($task?->assigned_to ?? 0)) {
            app(CrmWorkspace::class)->authorizeOwner($request, isset($data['assigned_to']) ? (int) $data['assigned_to'] : null);
        }
        if ($this->routePrefix() === 'follow-ups') {
            $data['task_type'] = 'follow_up';
        }
        $data['due_at'] = empty($data['due_at']) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data['due_at'], Task::TIMEZONE)->setTimezone(config('app.timezone'))->startOfMinute();
        $data['completed_at'] = in_array($data['status'], Task::COMPLETED_STATUSES, true) ? ($task?->completed_at ?? now()) : null;

        return $data;
    }

    public function store(SaveTaskRequest $request): RedirectResponse
    {
        $task = Task::create([...$this->data($request), 'created_by' => $request->user()->id]);

        return to_route($this->routePrefix().'.index', ['tab' => $this->tabFor($task)])->with('success', $this->viewContext()['pageTitle'].' berhasil ditambahkan.');
    }

    public function update(SaveTaskRequest $request, Task $task): RedirectResponse
    {
        $this->checkTaskScope($task);
        $task->update($this->data($request, $task));

        return to_route($this->routePrefix().'.index', ['tab' => $this->tabFor($task)])->with('success', $this->viewContext()['pageTitle'].' berhasil diperbarui.');
    }

    public function complete(Task $task): RedirectResponse
    {
        $this->checkTaskScope($task);
        Task::whereKey($task->id)->whereNotIn('status', Task::COMPLETED_STATUSES)->update(['status' => 'completed', 'completed_at' => now()]);

        return to_route($this->routePrefix().'.index', ['tab' => 'completed'])->with('success', $this->viewContext()['pageTitle'].' berhasil ditandai selesai.');
    }

    private function tabFor(Task $task): string
    {
        if ($task->isCompleted()) {
            return 'completed';
        }
        if (! $task->due_at) {
            return 'upcoming';
        }
        if ($task->due_at->lt(now())) {
            return 'overdue';
        }

        return $task->due_at->copy()->setTimezone(Task::TIMEZONE)->isToday() ? 'today' : 'upcoming';
    }
}
