<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Task;
use App\Services\AccessPolicy;
use App\Services\CrmWorkspace;
use App\Services\ProcessCockpit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FollowUpWorkspaceController extends Controller
{
    public const VIEWS = ['all' => 'Semua', 'today' => 'Hari Ini', 'overdue' => 'Overdue', 'WAITING_CANDIDATE' => 'Waiting Candidate', 'WAITING_EXTERNAL' => 'Waiting Sponsor/PT', 'WAITING_LPK' => 'Waiting LPK', 'completed' => 'Selesai'];

    public function index(Request $request): View|JsonResponse
    {
        if ($request->has('tab')) {
            return app(TaskController::class)->index($request);
        }
        $workspace = app(CrmWorkspace::class);
        $filters = $workspace->filters($request, ['view' => 'nullable|in:'.implode(',', array_keys(self::VIEWS)), 'assigned_cs_id' => 'nullable|integer|exists:users,id', 'current_stage_id' => 'nullable|integer|exists:pipeline_stages,id']);
        $process = app(ProcessCockpit::class);
        $query = $process->rows($request->user(), array_intersect_key($filters, array_flip(['search', 'assigned_cs_id', 'current_stage_id'])), 'process.read')->where(fn ($query) => $query->whereNotNull('row.next_action')->orWhereNotNull('row.waiting_status'));
        $workspace->dates($query, $filters, 'row.next_action_due_at', 'due');
        $today = $workspace->todayStart();
        $history = DB::table('followup_events as e')->join('applications as a', 'a.id', '=', 'e.application_id')->join('candidates as c', 'c.id', '=', 'a.candidate_id')->join('persons as p', 'p.id', '=', 'c.person_id')->where('e.status', 'WORKFLOW_COMPLETED')->whereNull('a.deleted_at')->whereIn('a.id', app(AccessPolicy::class)->applicationIds($request->user(), 'process.read'));
        if ($filters['search'] ?? '') {
            $search = $filters['search'];
            $history->where(fn ($q) => $q->where('p.full_name', 'like', '%'.$search.'%')->orWhere('c.candidate_code', 'like', '%'.$search.'%')->orWhere('a.application_code', 'like', '%'.$search.'%'));
        }
        foreach (['assigned_cs_id', 'current_stage_id'] as $field) {
            if ($filters[$field] ?? '') {
                $history->where('a.'.$field, $filters[$field]);
            }
        }
        $workspace->dates($history, $filters, 'e.context->due_at', 'due');
        $summary = ['Perlu Follow-up Hari Ini' => (clone $query)->where('row.next_action_due_at', '>=', $today)->where('row.next_action_due_at', '<', $today->copy()->addDay())->count(), 'Overdue' => (clone $query)->where('row.overdue', 1)->count(), 'Menunggu Eksternal' => (clone $query)->where('row.waiting_status', 'WAITING_EXTERNAL')->count(), 'Selesai Hari Ini' => (clone $history)->where('e.created_at', '>=', $today)->where('e.created_at', '<', $today->copy()->addDay())->count()];
        $view = $filters['view'] ?? 'all';
        if ($view === 'completed') {
            $rows = $history->select('e.*', 'a.application_code', 'p.full_name as candidate_name')->orderByDesc('e.id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        } else {
            if ($view === 'today') {
                $query->where('row.next_action_due_at', '>=', $today)->where('row.next_action_due_at', '<', $today->copy()->addDay());
            } elseif ($view === 'overdue') {
                $query->where('row.overdue', 1);
            } elseif (str_starts_with($view, 'WAITING_')) {
                $query->where('row.waiting_status', $view === 'WAITING_LPK' ? 'WAITING_EXTERNAL' : $view);
                if ($view === 'WAITING_LPK') {
                    $query->where(fn ($q) => $q->where('row.stage_label', 'like', '%LPK%')->orWhere('row.next_action', 'like', '%LPK%'));
                }
            }
            $rows = $process->ordered($query, ($filters['sort'] ?? '') === 'newest' ? 'newest' : 'due')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()->through(fn ($row) => $process->decorate($row));
            $ids = $rows->pluck('id');
            $last = DB::table('followup_events')->whereIn('application_id', $ids)->select('application_id')->selectRaw('MAX(created_at) as last_contact')->groupBy('application_id')->pluck('last_contact', 'application_id');
            $actual = Application::whereIn('id', $ids)->get()->keyBy('id');
            $rows->getCollection()->each(function ($row) use ($last, $actual): void {
                $row->last_contact = $last[$row->id] ?? null;
                $row->workflow_token = $actual->has($row->id) ? $this->token($actual[$row->id]) : null;
                $row->is_manual = filled($actual->get($row->id)?->next_action ?? null) || filled($actual->get($row->id)?->waiting_status ?? null);
            });
        }

        return $workspace->response($request, 'follow-ups.index', ['rows' => $rows, 'view' => $view, 'views' => self::VIEWS], $rows, $summary, $filters, fn ($row): array => ['id' => $row->id, 'candidate' => $row->candidate_name, 'application' => $row->application_code, 'nextAction' => $row->next_action ?? json_decode($row->context ?? '{}', true)['next_action'] ?? null, 'due' => $row->next_action_due_at ?? null, 'status' => $view === 'completed' ? 'COMPLETED' : $row->operational_status]);
    }

    private function token(Application $application): string
    {
        return hash('sha256', json_encode([$application->id, $application->next_action, $application->next_action_due_at?->toISOString(), $application->waiting_status, $application->updated_at->toISOString()]));
    }

    public function action(Request $request, Application $application): RedirectResponse
    {
        abort_unless(app(AccessPolicy::class)->applicationIds($request->user(), 'process.update')->where('applications.id', $application->id)->exists(), 403);
        $data = $request->validate(['action' => 'required|in:complete,task', 'workflow_token' => 'required|string|size:64', 'confirmed' => 'required|accepted', 'due_at' => 'required_if:action,task|nullable|date_format:Y-m-d\\TH:i', 'assigned_to' => 'required_if:action,task|nullable|integer|exists:users,id']);
        $taskId = DB::transaction(function () use ($request, $application, $data): ?int {
            $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($this->token($locked), $data['workflow_token'])) {
                throw ValidationException::withMessages(['workflow_token' => 'Tindak lanjut telah berubah. Muat ulang sebelum memproses.']);
            }
            if (! filled($locked->next_action) && ! filled($locked->waiting_status)) {
                throw ValidationException::withMessages(['action' => 'Tindakan berasal dari tugas, jadwal, atau kendala. Selesaikan melalui sumber tersebut.']);
            }
            if ($data['action'] === 'task') {
                $all = collect(app(AccessPolicy::class)->policies($request->user(), 'process.update'))->contains(fn ($p) => $p['scope'] === 'OPERATIONAL_ALL');
                abort_unless($all || (int) $data['assigned_to'] === $request->user()->id || ($request->user()->team_id && DB::table('users')->where('id', $data['assigned_to'])->where('team_id', $request->user()->team_id)->exists()), 403);
                $key = 'application-'.$locked->id.'-'.$data['workflow_token'];
                $existing = Task::where('workflow_key', $key)->first();
                if ($existing) {
                    return $existing->id;
                }
                $task = Task::create(['application_id' => $locked->id, 'assigned_to' => $data['assigned_to'], 'task_type' => 'follow_up', 'title' => $locked->next_action ?: 'Tindak lanjut proses', 'priority' => $locked->process_priority === 'critical' ? 'urgent' : ($locked->process_priority ?: 'normal'), 'status' => 'open', 'due_at' => Carbon::parse($data['due_at'], 'Asia/Jakarta')->utc(), 'created_by' => $request->user()->id]);
                $task->forceFill(['workflow_key' => $key])->save();
                app(AccessPolicy::class)->audit($request->user(), 'crm.workflow.task_created', ['application_id' => $locked->id, 'task_id' => $task->id]);

                return $task->id;
            }
            DB::table('followup_events')->insert(['application_id' => $locked->id, 'user_id' => $request->user()->id, 'status' => 'WORKFLOW_COMPLETED', 'context' => json_encode(['next_action' => $locked->next_action, 'waiting_status' => $locked->waiting_status, 'due_at' => $locked->next_action_due_at?->toDateTimeString()]), 'created_at' => now(), 'updated_at' => now()]);
            Task::where('workflow_key', 'application-'.$locked->id.'-'.$data['workflow_token'])->whereNotIn('status', Task::COMPLETED_STATUSES)->update(['status' => 'completed', 'completed_at' => now()]);
            $locked->update(['next_action' => null, 'next_action_due_at' => null, 'waiting_status' => null]);
            app(AccessPolicy::class)->audit($request->user(), 'crm.workflow.completed', ['application_id' => $locked->id]);

            return null;
        });

        return $taskId ? to_route('tasks.edit', $taskId)->with('success', 'Tugas formal dibuat dari tindak lanjut.') : to_route('follow-ups.index', ['view' => 'completed'])->with('success', 'Tindak lanjut selesai. Riwayat tersimpan.');
    }
}
