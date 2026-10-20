<?php

namespace App\Services;

use App\Http\Controllers\AcquisitionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FinanceController;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CockpitMetrics
{
    public function __construct(private AccessPolicy $policy) {}

    private function metric(string $label, string $value, string $icon, ?string $url, string $hint): array
    {
        return compact('label', 'value', 'icon', 'url', 'hint');
    }

    public function cs(Request $request): array
    {
        $user = $request->user();
        $ids = $this->policy->applicationIds($user, 'process.read');
        $tasks = Task::whereIn('application_id', $ids)->whereNotIn('status', Task::COMPLETED_STATUSES);
        $leads = app(AcquisitionController::class)->scopedLeads($request);
        $stats = [
            $this->metric('Lead baru', (string) (clone $leads)->where('status', 'new')->count(), 'users', route('acquisition.leads', ['status' => 'new']), 'Lead dalam cakupan Anda'),
            $this->metric('Follow-up hari ini', (string) (clone $tasks)->forTab('today')->where('task_type', 'follow_up')->count(), 'calendar', route('follow-ups.index', ['tab' => 'today']), 'Tindak lanjut yang belum jatuh tempo hari ini'),
            $this->metric('Overdue', (string) (clone $tasks)->forTab('overdue')->count(), 'clock', route('tasks.index', ['tab' => 'overdue']), 'Tugas terbuka melewati waktu jatuh tempo'),
            $this->metric('Dokumen kurang', (string) app(DocumentController::class)->missingCount($this->policy->applicationIds($user, 'document.read.metadata')), 'file', route('missing-documents.index'), 'Jumlah dokumen wajib yang perlu dilengkapi'),
        ];
        foreach (['lead.read.basic', 'process.read', 'process.read', 'document.read.metadata'] as $index => $permission) {
            if (! $user->hasPermission($permission)) {
                $stats[$index]['value'] = 'Tidak diizinkan';
                $stats[$index]['url'] = null;
                $stats[$index]['hint'] = 'Memerlukan izin untuk modul ini';
            }
        }
        $queue = (clone $tasks)->with(['application' => fn ($query) => $query->whereIn('applications.id', $this->policy->applicationIds($user, 'candidate.read.basic'))->with(['candidate.person', 'vacancy'])])->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(8)->get();
        $attention = (clone $leads)->whereIn('followup_status', ['NOT_CONTACTED', 'NO_RESPONSE', 'ESCALATED'])->orderBy('created_at')->limit(5)->get(['id', 'name', 'status', 'followup_status']);
        $today = now('Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone'));
        $tomorrow = $today->copy()->addDay();
        $schedules = collect();
        if ($user->hasPermission('schedule.read')) {
            foreach (['interviews' => 'Wawancara', 'medical_events' => 'MCU'] as $table => $label) {
                $rows = DB::table($table)->whereIn('application_id', $this->policy->applicationIds($user, 'schedule.read'))->where('scheduled_at', '>=', $today)->where('scheduled_at', '<', $tomorrow)->whereNotIn('status', ['cancelled', 'completed'])->orderBy('scheduled_at')->limit(8)->get(['application_id', 'scheduled_at', 'status']);
                foreach ($rows as $row) {
                    $row->title = $label;
                    $schedules->push($row);
                }
            }
        }

        return compact('stats', 'queue', 'attention') + ['schedules' => $schedules->sortBy('scheduled_at')->take(8)->values()];
    }

    private function scopedRecords(Request $request, string $table, string $permission): Collection
    {
        if (! $request->user()->hasPermission($permission)) {
            return collect();
        }

        $columns = match ($table) {
            'sponsor_receivables' => ['id', 'application_id', 'agreement_milestone_id', 'amount', 'currency', 'due_date', 'invoice_number', 'status'],
            'candidate_benefit_plans' => ['id', 'application_id', 'target_amount', 'currency', 'status'],
            default => ['id', 'application_id'],
        };

        return DB::table($table)->whereIn('application_id', $this->policy->applicationIds($request->user(), $permission))->get($columns);
    }

    public function finance(Request $request): array
    {
        $request->validate(['currency' => ['nullable', 'regex:/^[A-Z]{3}$/'], 'due_sort' => ['nullable', 'in:due,amount'], 'due_status' => ['nullable', 'in:all,overdue']]);
        $receivables = $this->scopedRecords($request, 'sponsor_receivables', 'finance.sponsor_receivable.read');
        $benefits = $this->scopedRecords($request, 'candidate_benefit_plans', 'finance.cpmi_benefit.read');
        $obligations = $this->scopedRecords($request, 'financial_obligations', 'finance.sponsor_receivable.read');
        $currencies = $receivables->pluck('currency')->merge($benefits->pluck('currency'))->push('IDR')->unique()->sort()->values();
        $currency = $request->input('currency', 'IDR');
        abort_unless($currencies->contains($currency), 422, 'Mata uang tidak tersedia.');
        $sponsorPayments = $request->user()->hasPermission('finance.sponsor_receivable.read') ? DB::table('sponsor_payments')->whereIn('receivable_id', $receivables->pluck('id'))->get() : collect();
        $disbursements = DB::table('candidate_benefit_disbursements')->whereIn('benefit_plan_id', $benefits->pluck('id'))->get();
        $obligationPayments = DB::table('financial_obligation_payments')->whereIn('financial_obligation_id', $obligations->pluck('id'))->get();
        $sum = fn (Collection $items): int => $items->sum(fn ($item) => FinanceController::cents($item->amount));
        $receivableTotals = $sponsorPayments->groupBy('receivable_id')->map($sum);
        $benefitTotals = $disbursements->groupBy('benefit_plan_id')->map($sum);
        $receivablesById = $receivables->keyBy('id');
        $benefitsById = $benefits->keyBy('id');
        foreach ($receivables as $record) {
            $record->outstanding = max(0, FinanceController::cents($record->amount) - ($receivableTotals[$record->id] ?? 0));
        }
        foreach ($benefits as $record) {
            $record->outstanding = max(0, FinanceController::cents($record->target_amount) - ($benefitTotals[$record->id] ?? 0));
        }
        $selected = $receivables->where('status', '!=', 'cancelled')->where('currency', $currency)->filter(fn ($row) => $row->outstanding > 0);
        $pending = $benefits->where('currency', $currency)->where('status', '!=', 'completed')->filter(fn ($row) => $row->outstanding > 0);
        $transactions = collect();
        foreach ($sponsorPayments as $payment) {
            $parent = $receivablesById[$payment->receivable_id];
            if (! $payment->paid_at) {
                continue;
            }
            $transactions->push((object) ['at' => $payment->paid_at, 'amount' => FinanceController::cents($payment->amount), 'currency' => $parent->currency, 'direction' => 'in', 'title' => 'Pembayaran sponsor', 'url' => route('sponsor-payments.index', ['search' => (string) $payment->id])]);
        }
        foreach ($disbursements as $payment) {
            $parent = $benefitsById[$payment->benefit_plan_id];
            if (! $payment->disbursed_at) {
                continue;
            }
            $transactions->push((object) ['at' => $payment->disbursed_at, 'amount' => FinanceController::cents($payment->amount), 'currency' => $parent->currency, 'direction' => 'out', 'title' => 'Pencairan hak CPMI', 'url' => route('benefits.index', ['search' => (string) $parent->id])]);
        }
        foreach ($obligationPayments as $payment) {
            if (! $payment->paid_at) {
                continue;
            }
            $transactions->push((object) ['at' => $payment->paid_at, 'amount' => FinanceController::cents($payment->amount), 'currency' => 'IDR', 'direction' => 'in', 'title' => 'Pembayaran kewajiban', 'url' => route('obligations.index', ['search' => (string) $payment->financial_obligation_id])]);
        }
        $transactions = $transactions->where('currency', $currency)->sortByDesc('at')->values();
        $day = now('Asia/Jakarta')->toDateString();
        $onDay = fn (object $row, string $date): bool => Carbon::parse($row->at)->timezone('Asia/Jakarta')->toDateString() === $date;
        $cashIn = $transactions->where('direction', 'in')->filter(fn ($row) => $onDay($row, $day))->sum('amount');
        $yesterday = $transactions->where('direction', 'in')->filter(fn ($row) => $onDay($row, now('Asia/Jakarta')->subDay()->toDateString()))->sum('amount');
        $format = fn (int $cents): string => $currency.' '.number_format($cents / 100, 2, ',', '.');
        $stats = [
            $this->metric('Sponsor receivable', $format($selected->sum('outstanding')), 'money', route('receivables.index'), 'Saldo tagihan setelah pembayaran'),
            $this->metric('Settlement pending', $format($pending->sum('outstanding')), 'clock', route('settlements.index'), 'Hak CPMI aktif yang belum dicairkan'),
            $this->metric('Cash in hari ini', $format($cashIn), 'chart', route('sponsor-payments.index'), 'Selisih dari kemarin: '.$format($cashIn - $yesterday)),
            $this->metric('Exposure / klaim', 'Belum tersedia', 'shield', null, 'Sumber data klaim dan exposure belum tersedia'),
        ];
        if (! $request->user()->hasPermission('finance.cpmi_benefit.read')) {
            $stats[1]['value'] = 'Tidak diizinkan';
            $stats[1]['hint'] = 'Memerlukan izin pembacaan hak CPMI';
            $stats[1]['url'] = null;
        } elseif (! $request->user()->hasPermission('finance.settlement.read')) {
            $stats[1]['url'] = route('benefits.index');
        }
        $aging = collect(['Belum jatuh tempo' => 0, '1–30 hari' => 0, '31–60 hari' => 0, '61–90 hari' => 0, '>90 hari' => 0]);
        foreach ($selected as $row) {
            $days = $row->due_date ? (int) Carbon::parse($row->due_date, 'Asia/Jakarta')->startOfDay()->diffInDays(now('Asia/Jakarta')->startOfDay(), false) : 0;
            $bucket = $days <= 0 ? 'Belum jatuh tempo' : ($days <= 30 ? '1–30 hari' : ($days <= 60 ? '31–60 hari' : ($days <= 90 ? '61–90 hari' : '>90 hari')));
            $aging[$bucket] += $row->outstanding;
        }
        $cashflow = collect();
        for ($offset = 6; $offset >= 0; $offset--) {
            $date = now('Asia/Jakarta')->subDays($offset)->toDateString();
            $daily = $transactions->filter(fn ($row) => $onDay($row, $date));
            $cashflow->push(['date' => $date, 'in' => $daily->where('direction', 'in')->sum('amount'), 'out' => $daily->where('direction', 'out')->sum('amount')]);
        }
        $due = $selected;
        if ($request->input('due_status') === 'overdue') {
            $due = $due->filter(fn ($row) => $row->due_date && $row->due_date < $day);
        }
        $due = ($request->input('due_sort') === 'amount' ? $due->sortByDesc('outstanding') : $due->sortBy(fn ($row) => $row->due_date ?? '9999-12-31'))->take(8);
        $sponsors = DB::table('agreement_milestones')->join('commercial_agreements', 'commercial_agreements.id', '=', 'agreement_milestones.agreement_id')->join('companies', 'companies.id', '=', 'commercial_agreements.company_id')->whereIn('agreement_milestones.id', $due->pluck('agreement_milestone_id'))->pluck('companies.name', 'agreement_milestones.id');
        foreach ($due as $row) {
            $row->sponsor = $sponsors[$row->agreement_milestone_id] ?? 'Sponsor';
        }

        return compact('stats', 'currencies', 'currency', 'aging', 'cashflow', 'transactions', 'pending', 'format', 'due');
    }

    public function system(Request $request): array
    {
        $request->validate(['level' => ['nullable', 'in:INFO,WARN,ERROR,SECURITY,INTEGRATION']]);
        $user = $request->user();
        $started = hrtime(true);
        DB::select('select 1');
        $latency = round((hrtime(true) - $started) / 1_000_000, 1);
        $routingErrors = $user->hasPermission('admin.cs_rotator.manage') ? DB::table('cs_assignment_events')->whereIn('status', ['no_active_cs', 'unmapped_vacancy'])->where('created_at', '>=', now()->subDay())->count() : null;
        $activeUsers = $user->hasPermission('admin.user.manage') ? DB::table('users')->where('is_active', true)->count() : null;
        $failed = DB::table('failed_jobs')->count();
        $stats = [
            $this->metric('Pengguna aktif', $activeUsers === null ? 'Tidak diizinkan' : (string) $activeUsers, 'users', $activeUsers === null ? null : route('settings.users.index'), 'Akun aktif pada sistem'),
            $this->metric('Routing error', $routingErrors === null ? 'Tidak diizinkan' : (string) $routingErrors, 'shield', $routingErrors === null ? null : route('acquisition.groups'), 'CS tidak tersedia / lowongan belum dipetakan · 24 jam'),
            $this->metric('Failed jobs', (string) $failed, 'clock', route('cockpit.index', 'system').'#queue-errors', 'Job gagal yang masih tersimpan'),
            $this->metric('Kesehatan sistem', 'Database aktif', 'chart', null, $latency.' ms · uptime belum diukur'),
        ];
        $integrations = collect([
            ['name' => 'Database', 'status' => 'Merespons', 'detail' => $latency.' ms'],
            ['name' => 'Queue', 'status' => config('queue.default'), 'detail' => DB::table('jobs')->count().' job dalam antrean · worker belum terpantau'],
            ['name' => 'Email', 'status' => config('mail.default'), 'detail' => 'Konfigurasi mailer · pengiriman belum diuji'],
            ['name' => 'WhatsApp', 'status' => 'Handoff via tautan', 'detail' => 'Tautan WhatsApp ke CS, tanpa gateway API'],
        ]);
        if ($user->hasPermission('admin.integration.manage')) {
            foreach (DB::table('crm_integrations')->get(['name']) as $integration) {
                $integrations->push(['name' => $integration->name, 'status' => 'Dikonfigurasi', 'detail' => 'Koneksi belum diuji']);
            }
        }
        $levelExpression = "CASE WHEN audit_logs.action = 'access.denied' THEN 'WARN' WHEN audit_logs.action LIKE '%failed%' OR audit_logs.action LIKE '%error%' THEN 'ERROR' WHEN audit_logs.action LIKE '%role%' OR audit_logs.action LIKE '%permission%' OR audit_logs.action LIKE '%login%' OR audit_logs.action LIKE '%break_glass%' THEN 'SECURITY' WHEN audit_logs.action LIKE '%integration%' THEN 'INTEGRATION' ELSE 'INFO' END";
        $logQuery = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id');
        if ($request->filled('level')) {
            $logQuery->whereRaw($levelExpression.' = ?', [$request->input('level')]);
        }
        $logs = $user->hasPermission('audit.read') ? $logQuery->orderByDesc('audit_logs.id')->limit(10)->select(['audit_logs.action', 'audit_logs.created_at', 'users.name as actor'])->selectRaw($levelExpression.' as level')->get() : collect();
        $jobs = DB::table('failed_jobs')->orderByDesc('id')->limit(8)->get(['uuid', 'connection', 'queue', 'failed_at']);
        $routing = $routingErrors === null ? collect() : DB::table('cs_assignment_events')->whereIn('status', ['no_active_cs', 'unmapped_vacancy'])->where('created_at', '>=', now()->subDay())->orderByDesc('id')->limit(5)->get(['status', 'created_at']);
        $roles = $user->hasPermission('admin.permission.manage') ? DB::table('crm_roles')->leftJoin('users', 'users.crm_role_id', '=', 'crm_roles.id')->select('crm_roles.name')->selectRaw('COUNT(users.id) as total')->groupBy('crm_roles.id', 'crm_roles.name')->get() : collect();
        $alerts = $user->hasPermission('audit.read') ? DB::table('audit_logs')->where('action', 'access.denied')->where('created_at', '>=', now()->subDay())->count() : null;

        return compact('stats', 'integrations', 'logs', 'roles', 'alerts', 'jobs', 'routing');
    }
}
