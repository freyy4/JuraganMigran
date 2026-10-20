<?php

namespace App\Services;

use App\Models\ApplicationBlocker;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProcessCockpit
{
    public const MODES = ['summary' => 'Ringkasan', 'board' => 'Board', 'table' => 'Tabel', 'followups' => 'Tindak Lanjut'];

    public const STATUSES = ['PROCESSING' => 'Proses', 'ACTION_REQUIRED' => 'Perlu tindakan', 'SCHEDULED' => 'Terjadwal', 'WAITING_CANDIDATE' => 'Menunggu kandidat', 'WAITING_INTERNAL' => 'Menunggu internal', 'WAITING_EXTERNAL' => 'Menunggu pihak luar', 'OVERDUE' => 'Terlambat', 'BLOCKED' => 'Terkendala', 'COMPLETED' => 'Selesai', 'WITHDRAWN' => 'Ditarik', 'REJECTED' => 'Ditolak'];

    public const QUEUES = ['overdue' => 'Overdue', 'today' => 'Hari Ini', 'tomorrow' => 'Besok', 'week' => '7 Hari ke Depan', 'waiting_candidate' => 'Menunggu Kandidat', 'waiting_external' => 'Menunggu Pihak Luar', 'no_action' => 'Tanpa Next Action'];

    public const EXCEPTIONS = ['no_stage' => 'Belum ada tahap', 'no_owner' => 'Tanpa CS/PIC', 'no_action' => 'Tanpa next action / wait state', 'invalid_stage' => 'Tahap tidak valid', 'overdue_no_owner' => 'Overdue tanpa pemilik tugas', 'closed_candidate' => 'Kandidat ditutup dalam proses aktif'];

    public const PRIORITIES = ['critical' => 'Kritis', 'high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];

    public function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'vacancy_id' => ['nullable', 'integer', 'exists:vacancies,id'],
            'assigned_cs_id' => ['nullable', 'integer', 'exists:users,id'],
            'current_stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'priority' => ['nullable', Rule::in(array_keys(self::PRIORITIES))],
            'queue' => ['nullable', Rule::in(array_keys(self::QUEUES))],
            'exception' => ['nullable', Rule::in(array_keys(self::EXCEPTIONS))],
            'kpi' => ['nullable', Rule::in(['today', 'overdue', 'mcu', 'departure', 'blocked'])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'departure_from' => ['nullable', 'date_format:Y-m-d'], 'departure_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:departure_from'],
            'mode' => ['nullable', Rule::in(array_keys(self::MODES))],
            'per_page' => ['nullable', Rule::in([10, 25, 50, 100])],
            'sort' => ['nullable', Rule::in(['priority', 'due', 'age', 'name', 'newest'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
    }

    public function rows(User $user, array $filters = [], string $permission = 'process.read', bool $includeClosed = false): Builder
    {
        $nextTask = DB::table('tasks as t')->select('t.id')->whereColumn('t.application_id', 'applications.id')->whereNotIn('t.status', Task::COMPLETED_STATUSES)->orderByRaw('t.due_at IS NULL')->orderBy('t.due_at')->orderBy('t.id')->limit(1);
        $visit = DB::table('application_stage_histories as h')->select('h.id')->whereColumn('h.application_id', 'applications.id')->orderByDesc('h.id')->limit(1);
        $blockers = DB::table('application_blockers')->whereNotIn('status', ApplicationBlocker::RESOLVED)->groupBy('application_id')->select('application_id')->selectRaw('COUNT(*) as blocker_count, MIN(due_at) as blocker_due');
        $flights = DB::table('flight_details')->groupBy('application_id')->select('application_id')->selectRaw('MIN(departure_at) as departure_at');
        $scheduleIds = app(AccessPolicy::class)->applicationIds($user, 'schedule.read');
        $events = DB::table('interviews')->where('status', 'scheduled')->whereNotNull('scheduled_at')->whereIn('application_id', $scheduleIds)->select('application_id', 'scheduled_at')->selectRaw("'Hadiri wawancara' as action_label")
            ->unionAll(DB::table('medical_events')->where('status', 'scheduled')->whereNotNull('scheduled_at')->whereIn('application_id', $scheduleIds)->select('application_id', 'scheduled_at')->selectRaw("'Konfirmasi jadwal MCU' as action_label"))
            ->unionAll(DB::table('travel_events')->where('status', 'scheduled')->whereNotNull('scheduled_at')->whereIn('application_id', $scheduleIds)->select('application_id', 'scheduled_at')->selectRaw("CASE WHEN event_type = 'OPP' THEN 'Hadiri OPP' WHEN event_type = 'departure' THEN 'Konfirmasi keberangkatan' ELSE 'Konfirmasi jadwal perjalanan' END as action_label"))
            ->unionAll(DB::table('training_enrollments')->join('training_batches', 'training_batches.id', '=', 'training_enrollments.batch_id')->where('training_enrollments.status', 'scheduled')->whereNotNull('training_batches.start_date')->whereIn('training_enrollments.application_id', $scheduleIds)->select('training_enrollments.application_id', 'training_batches.start_date as scheduled_at')->selectRaw("'Konfirmasi masuk LPK' as action_label"));
        $appointments = DB::query()->fromSub($events, 'events')->groupBy('application_id')->select('application_id')->selectRaw('MIN(scheduled_at) as scheduled_at');
        $eventAction = DB::query()->fromSub(clone $events, 'events')->whereColumn('events.application_id', 'applications.id')->orderBy('scheduled_at')->orderBy('action_label')->limit(1)->select('action_label');
        $query = DB::table('applications')
            ->join('candidates', 'candidates.id', '=', 'applications.candidate_id')
            ->join('persons', 'persons.id', '=', 'candidates.person_id')
            ->leftJoin('vacancies', 'vacancies.id', '=', 'applications.vacancy_id')
            ->leftJoin('job_orders', 'job_orders.id', '=', 'vacancies.job_order_id')
            ->leftJoin('companies', 'companies.id', '=', 'job_orders.company_id')
            ->leftJoin('countries', 'countries.id', '=', 'job_orders.country_id')
            ->leftJoin('users as cs', 'cs.id', '=', 'applications.assigned_cs_id')
            ->leftJoin('pipeline_stages as stage', 'stage.id', '=', 'applications.current_stage_id')
            ->leftJoin('application_stage_histories as visit', fn ($join) => $join->on('visit.id', '=', DB::raw('('.$visit->toSql().')')))
            ->leftJoin('tasks as next_task', function ($join) use ($nextTask): void {
                $join->on('next_task.id', '=', DB::raw('('.$nextTask->toSql().')'))->addBinding($nextTask->getBindings());
            })
            ->leftJoinSub($blockers, 'blockers', 'blockers.application_id', '=', 'applications.id')
            ->leftJoinSub($flights, 'flight', 'flight.application_id', '=', 'applications.id')
            ->leftJoinSub($appointments, 'interview', 'interview.application_id', '=', 'applications.id')
            ->whereNull('applications.deleted_at');
        app(AccessPolicy::class)->scopeApplications($query, $user, $permission);
        $query->select([
            'applications.id', 'applications.application_code', 'applications.candidate_id', 'applications.vacancy_id', 'applications.assigned_cs_id', 'applications.current_stage_id', 'applications.status as application_status', 'applications.applied_at',
            'persons.full_name as candidate_name', 'candidates.candidate_code', 'candidates.status as candidate_status', 'candidates.deleted_at as candidate_deleted_at',
            'vacancies.title as vacancy_label', 'companies.name as company_label', 'job_orders.company_id', 'job_orders.country_id', 'countries.name as country_label', 'cs.name as cs_name',
            'stage.name as stage_label', 'stage.sequence as stage_sequence', 'stage.is_active as stage_active', 'stage.is_terminal', 'next_task.assigned_to as task_owner', 'next_task.id as task_id', 'next_task.priority as task_priority', 'flight.departure_at',
        ])->selectRaw('CASE WHEN visit.to_stage_id = applications.current_stage_id THEN visit.entered_at ELSE NULL END as stage_entered_at')
            ->selectRaw('CASE WHEN visit.to_stage_id = applications.current_stage_id THEN COALESCE(visit.sla_hours_snapshot, stage.sla_hours) ELSE stage.sla_hours END as sla_hours')
            ->selectRaw('COALESCE(blockers.blocker_count, 0) as blocker_count, blockers.blocker_due')
            ->selectRaw("COALESCE(NULLIF(applications.next_action, ''), next_task.title, CASE WHEN blockers.blocker_count > 0 THEN 'Tindak lanjuti kendala' WHEN interview.scheduled_at IS NOT NULL THEN 'Konfirmasi jadwal kegiatan' WHEN flight.departure_at IS NOT NULL THEN 'Konfirmasi keberangkatan' END) as next_action")
            ->selectRaw("CASE WHEN NULLIF(applications.next_action, '') IS NOT NULL OR applications.waiting_status IS NOT NULL THEN applications.next_action_due_at WHEN next_task.id IS NOT NULL THEN next_task.due_at WHEN blockers.blocker_count > 0 THEN blockers.blocker_due ELSE COALESCE(interview.scheduled_at, flight.departure_at) END as next_action_due_at")
            ->selectRaw('applications.waiting_status, applications.process_priority, interview.scheduled_at')
            ->selectSub($eventAction, 'scheduled_action');
        $now = now()->toDateTimeString();
        $hours = match (DB::getDriverName()) {
            'sqlite' => '(julianday(?) - julianday(row.stage_entered_at)) * 24',
            'pgsql' => 'EXTRACT(EPOCH FROM (?::timestamp - row.stage_entered_at)) / 3600',
            default => 'TIMESTAMPDIFF(SECOND, row.stage_entered_at, ?) / 3600',
        };
        $aged = DB::query()->fromSub($query, 'row')->select('row.*')->selectRaw("CASE WHEN row.stage_entered_at IS NOT NULL THEN $hours ELSE NULL END as age_hours", [$now])
            ->selectRaw('CASE WHEN (row.next_action_due_at < ? OR row.blocker_due < ?) THEN 1 ELSE 0 END as action_overdue', [$now, $now]);
        $derived = DB::query()->fromSub($aged, 'row')->select('row.*')
            ->selectRaw('CASE WHEN row.action_overdue = 1 OR (row.sla_hours > 0 AND row.age_hours > row.sla_hours) THEN 1 ELSE 0 END as overdue')
            ->selectRaw("CASE WHEN row.application_status = 'rejected' THEN 'REJECTED' WHEN row.application_status IN ('withdrawn', 'cancelled') THEN 'WITHDRAWN' WHEN row.application_status IN ('closed', 'completed', 'departed') THEN 'COMPLETED' WHEN row.blocker_count > 0 THEN 'BLOCKED' WHEN row.action_overdue = 1 OR (row.sla_hours > 0 AND row.age_hours > row.sla_hours) THEN 'OVERDUE' WHEN row.waiting_status IS NOT NULL THEN row.waiting_status WHEN row.task_id IS NULL AND (row.scheduled_at IS NOT NULL OR row.departure_at IS NOT NULL) THEN 'SCHEDULED' WHEN row.next_action IS NOT NULL THEN 'ACTION_REQUIRED' ELSE 'PROCESSING' END as operational_status")
            ->selectRaw("CASE WHEN row.blocker_count > 0 AND row.departure_at >= ? AND row.departure_at <= ? THEN 4 WHEN row.process_priority = 'critical' THEN 4 WHEN row.action_overdue = 1 OR (row.sla_hours > 0 AND row.age_hours > row.sla_hours) OR row.process_priority = 'high' OR row.task_priority IN ('high', 'urgent') THEN 3 WHEN row.process_priority = 'low' THEN 1 ELSE 2 END as priority_rank", [$now, now()->addDays(config('process.departure_critical_days'))->toDateTimeString()]);
        $rows = DB::query()->fromSub($derived, 'row')->select('row.*');
        $this->applyFilters($rows, $user, $filters, $includeClosed);

        return $rows;
    }

    private function applyFilters(Builder $query, User $user, array $filters, bool $includeClosed): void
    {
        if (! $includeClosed && ! in_array($filters['status'] ?? '', ['COMPLETED', 'WITHDRAWN', 'REJECTED'], true)) {
            $query->whereNotIn('row.application_status', ['closed', 'completed', 'departed', 'cancelled', 'withdrawn', 'rejected']);
        }
        foreach (['company_id', 'vacancy_id', 'country_id', 'assigned_cs_id', 'current_stage_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('row.'.$field, $filters[$field]);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function (Builder $q) use ($search, $user): void {
                foreach (['candidate_name', 'candidate_code', 'application_code'] as $field) {
                    $q->orWhere('row.'.$field, 'like', '%'.$search.'%');
                }
                $q->orWhere('row.id', ctype_digit($search) ? (int) $search : -1);
                if ($user->hasPermission('candidate.read.contact')) {
                    $contactScope = app(AccessPolicy::class)->candidateIds($user, 'candidate.read.contact');
                    $q->orWhereIn('row.candidate_id', DB::table('candidates')->join('persons', 'persons.id', '=', 'candidates.person_id')->whereIn('candidates.id', $contactScope)->where('persons.whatsapp', 'like', '%'.$search.'%')->select('candidates.id'));
                }
            });
        }
        foreach (['from' => ['applied_at', true], 'until' => ['applied_at', false], 'departure_from' => ['departure_at', true], 'departure_until' => ['departure_at', false]] as $key => [$column, $start]) {
            if (! empty($filters[$key])) {
                $date = Carbon::parse($filters[$key], Task::TIMEZONE)->startOfDay();
                if (! $start) {
                    $date->addDay();
                }
                $query->where('row.'.$column, $start ? '>=' : '<', $date->setTimezone(config('app.timezone'))->toDateTimeString());
            }
        }
        if (! empty($filters['status'])) {
            $query->where('row.operational_status', $filters['status']);
        }
        if (! empty($filters['priority'])) {
            $query->where('row.priority_rank', ['critical' => 4, 'high' => 3, 'normal' => 2, 'low' => 1][$filters['priority']]);
        }
        if (! empty($filters['exception'])) {
            $this->exception($query, $filters['exception']);
        }
        if (! empty($filters['queue'])) {
            $this->queue($query, $filters['queue']);
        }
        if (! empty($filters['kpi'])) {
            match ($filters['kpi']) {
                'today' => $this->queue($query, 'today'), 'overdue' => $query->where('row.overdue', 1), 'blocked' => $query->where('row.blocker_count', '>', 0),
                'departure' => $query->whereIn('row.application_status', ['ready', 'ready_for_departure', 'departure_ready'])->where('row.blocker_count', 0),
                'mcu' => $this->mcu($query),
            };
        }
    }

    private function mcu(Builder $query): Builder
    {
        // Readiness comes from the existing checklist domain; stage names never imply readiness.
        return $query->where('row.blocker_count', 0)->whereIn('row.id', DB::table('readiness_checklists as checklist')->whereIn('checklist.type', ['mcu', 'medical'])->whereExists(fn ($q) => $q->selectRaw('1')->from('readiness_items')->whereColumn('readiness_items.readiness_checklist_id', 'checklist.id')->where('is_required', true))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('readiness_items')->whereColumn('readiness_items.readiness_checklist_id', 'checklist.id')->where('is_required', true)->where('status', '!=', 'completed'))->select('checklist.application_id'));
    }

    public function queue(Builder $query, string $queue): Builder
    {
        $today = now(Task::TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'));
        $tomorrow = $today->copy()->addDay();

        return match ($queue) {
            'overdue' => $query->where('row.overdue', 1),
            'today' => $query->where('row.next_action_due_at', '>=', $today)->where('row.next_action_due_at', '<', $tomorrow),
            'tomorrow' => $query->where('row.next_action_due_at', '>=', $tomorrow)->where('row.next_action_due_at', '<', $tomorrow->copy()->addDay()),
            'week' => $query->where('row.next_action_due_at', '>=', $tomorrow->copy()->addDay())->where('row.next_action_due_at', '<', $today->copy()->addDays(8)),
            'waiting_candidate' => $query->where('row.waiting_status', 'WAITING_CANDIDATE'),
            'waiting_external' => $query->where('row.waiting_status', 'WAITING_EXTERNAL'),
            'no_action' => $query->whereNull('row.next_action')->whereNull('row.waiting_status'),
        };
    }

    public function exception(Builder $query, string $exception): Builder
    {
        return match ($exception) {
            'no_stage' => $query->whereNull('row.current_stage_id'), 'no_owner' => $query->whereNull('row.assigned_cs_id'),
            'no_action' => $query->whereNull('row.next_action')->whereNull('row.waiting_status'),
            'invalid_stage' => $query->whereNotNull('row.current_stage_id')->where(fn ($q) => $q->whereNull('row.stage_label')->orWhere('row.stage_active', false)),
            'overdue_no_owner' => $query->where('row.overdue', 1)->where(fn ($q) => $q->whereNull('row.task_owner')->whereNull('row.assigned_cs_id')),
            'closed_candidate' => $query->where(fn ($q) => $q->whereNotNull('row.candidate_deleted_at')->orWhereIn('row.candidate_status', ['closed', 'withdrawn', 'rejected', 'cancelled'])),
        };
    }

    public function ordered(Builder $query, string $sort = 'priority'): Builder
    {
        match ($sort) {
            'name' => $query->orderBy('row.candidate_name'), 'age' => $query->orderByDesc('row.age_hours'), 'newest' => $query->orderByDesc('row.applied_at'),
            'due' => $query->orderByRaw('row.next_action_due_at IS NULL')->orderBy('row.next_action_due_at'),
            default => $query->orderByDesc('row.priority_rank')->orderByDesc('row.overdue')->orderByRaw('row.next_action_due_at IS NULL')->orderBy('row.next_action_due_at')->orderByDesc('row.age_hours'),
        };

        return $query->orderByDesc('row.id');
    }

    public function summary(Builder $query): array
    {
        return [
            'total' => (clone $query)->count(), 'today' => $this->queue(clone $query, 'today')->count(), 'overdue' => (clone $query)->where('overdue', 1)->count(),
            'mcu' => $this->mcu(clone $query)->count(), 'departure' => (clone $query)->whereIn('application_status', ['ready', 'ready_for_departure', 'departure_ready'])->where('blocker_count', 0)->count(),
            'blocked' => (clone $query)->where('blocker_count', '>', 0)->count(),
        ];
    }

    public function decorate(object $row): object
    {
        if ($row->next_action === 'Konfirmasi jadwal kegiatan') {
            $row->next_action = $row->scheduled_action;
        }
        $row->priority = [4 => 'critical', 3 => 'high', 2 => 'normal', 1 => 'low'][(int) $row->priority_rank];
        $row->age_label = $row->age_hours === null ? 'Belum tercatat' : ((float) $row->age_hours < 1 ? max(0, (int) ($row->age_hours * 60)).' menit' : ((float) $row->age_hours < 24 ? (int) $row->age_hours.' jam' : (int) ($row->age_hours / 24).' hari'));
        $row->sla_state = $row->age_hours !== null && $row->sla_hours > 0 ? ($row->age_hours > $row->sla_hours ? 'overdue' : ($row->age_hours >= $row->sla_hours * config('process.warning_ratio') ? 'warning' : 'normal')) : 'unknown';
        $row->due_label = $row->next_action_due_at ? Carbon::parse($row->next_action_due_at)->timezone(Task::TIMEZONE)->format('d M H:i').' WIB' : 'Belum dijadwalkan';
        $row->priority_reason = match (true) {
            $row->blocker_count > 0 && $row->departure_at && Carbon::parse($row->departure_at)->between(now(), now()->addDays(config('process.departure_critical_days'))) => 'Keberangkatan ≤7 hari dengan kendala aktif',
            (bool) $row->action_overdue => 'Deadline tindakan telah lewat', $row->sla_state === 'overdue' => 'Durasi tahap melewati SLA '.$row->sla_hours.' jam',
            $row->task_priority === 'high' || $row->task_priority === 'urgent' => 'Tugas berikutnya berprioritas tinggi',
            $row->process_priority !== null => 'Prioritas ditetapkan petugas: '.self::PRIORITIES[$row->process_priority],
            $row->next_action_due_at && Carbon::parse($row->next_action_due_at)->timezone(Task::TIMEZONE)->isToday() => 'Tindakan jatuh tempo hari ini',
            $row->blocker_count > 0 => 'Kendala aktif perlu ditindaklanjuti', default => 'Tindakan berikutnya dalam antrean',
        };

        return $row;
    }
}
