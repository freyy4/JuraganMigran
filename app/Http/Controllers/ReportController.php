<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public const MODULES = ['pipeline' => 'Alur Proses', 'progress' => 'Perkembangan CPMI', 'blockers' => 'Kendala', 'cs' => 'Kinerja CS', 'partners' => 'Kinerja Mitra', 'vacancies' => 'Kinerja Lowongan', 'finance' => 'Keuangan'];

    public function index(Request $request, string $module): View|StreamedResponse
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        $filters = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'search' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', Rule::in(Application::statusOptions())], 'export' => ['nullable', 'in:csv']]);
        $applications = Application::query()->with(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy.jobOrder.company', 'currentStage', 'assignedCs', 'latestStageHistory'])->withCount(['blockers as open_blockers_count' => fn ($query) => $query->whereNotIn('status', ApplicationBlocker::RESOLVED), 'tasks as completed_tasks_count' => fn ($query) => $query->whereIn('status', Task::COMPLETED_STATUSES)]);
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $date = Carbon::parse($filters[$key], 'Asia/Jakarta');
                $date = $key === 'date_from' ? $date->startOfDay() : $date->endOfDay();
                $applications->where('applied_at', $operator, $date->setTimezone(config('app.timezone')));
            }
        }
        if (! empty($filters['status'])) {
            $applications->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $applications->where(fn ($query) => $query->where('application_code', 'like', $search)->orWhereHas('candidate.person', fn ($query) => $query->withTrashed()->where('full_name', 'like', $search))->orWhereHas('vacancy', fn ($query) => $query->where('title', 'like', $search)));
        }
        $records = $applications->orderBy('id')->get();
        $rows = collect();
        $headers = [];
        if ($module === 'progress') {
            $headers = ['Lamaran', 'CPMI', 'Lowongan', 'Tahap', 'Durasi Tahap', 'Status', 'CS', 'Kendala Terbuka'];
            $rows = $records->map(fn ($application) => [$application->application_code, $application->candidate?->person?->full_name ?? '—', $application->vacancy?->title ?? '—', $application->currentStage?->name ?? 'Belum ada tahap', $application->stageDuration(), Application::statusLabel($application->status), $application->assignedCs?->name ?? 'Belum ditugaskan', $application->open_blockers_count]);
        } elseif ($module === 'blockers') {
            $headers = ['Kode', 'Lamaran', 'Kategori', 'Uraian', 'Prioritas', 'Penanggung Jawab', 'Status', 'Tenggat'];
            $rows = ApplicationBlocker::with(['application', 'owner'])->whereIn('application_id', $records->pluck('id'))->orderBy('id')->get()->map(fn ($blocker) => [$blocker->code, $blocker->application?->application_code ?? '—', $blocker->category, $blocker->description, ApplicationBlocker::label($blocker->priority), $blocker->owner?->name ?? '—', ApplicationBlocker::label($blocker->status), $blocker->due_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '—']);
        } elseif ($module === 'finance') {
            $headers = ['Mata Uang', 'Tagihan Mitra', 'Pembayaran Mitra', 'Sisa Tagihan', 'Hak CPMI', 'Pencairan Hak', 'Sisa Hak', 'Kewajiban CPMI', 'Pembayaran Kewajiban', 'Sisa Kewajiban'];
            $totals = [];
            foreach ([['sponsor_receivables', 'sponsor_payments', 'receivable_id', 'amount', 0, 1], ['candidate_benefit_plans', 'candidate_benefit_disbursements', 'benefit_plan_id', 'target_amount', 3, 4], ['financial_obligations', 'financial_obligation_payments', 'financial_obligation_id', 'amount', 6, 7]] as [$table, $payments, $foreignKey, $amount, $totalIndex, $paidIndex]) {
                foreach (DB::table($table)->whereIn('application_id', $records->pluck('id'))->get() as $record) {
                    $currency = $record->currency ?? 'IDR';
                    $totals[$currency] ??= array_fill(0, 9, 0);
                    $total = FinanceController::cents((string) $record->$amount);
                    $paid = DB::table($payments)->where($foreignKey, $record->id)->pluck('amount')->sum(fn ($value) => FinanceController::cents((string) $value));
                    $totals[$currency][$totalIndex] += $total;
                    $totals[$currency][$paidIndex] += $paid;
                    $totals[$currency][$paidIndex + 1] += $total - $paid;
                }
            }
            ksort($totals);
            $rows = collect($totals)->map(fn ($values, $currency) => array_merge([$currency], array_map(fn ($cents) => FinanceController::money($cents), $values)))->values();
        } else {
            $headers = [$module === 'pipeline' ? 'Tahap' : ($module === 'cs' ? 'CS' : ($module === 'partners' ? 'Mitra' : 'Lowongan')), 'Lamaran', 'Aktif', 'Ditutup', 'Dibatalkan', 'Kendala Terbuka', 'Tugas Selesai'];
            $rows = $records->groupBy(fn ($application) => match ($module) {
                'pipeline' => $application->current_stage_id ?? 'none',
                'cs' => $application->assigned_cs_id ?? 'none',
                'partners' => $application->vacancy?->jobOrder?->company_id ?? 'none',
                default => $application->vacancy_id,
            })->map(function ($group) use ($module) {
                $application = $group->first();
                $label = match ($module) {
                    'pipeline' => $application->currentStage?->name ?? 'Belum ada tahap',
                    'cs' => $application->assignedCs?->name ?? 'Belum ditugaskan',
                    'partners' => $application->vacancy?->jobOrder?->company?->name ?? 'Tanpa mitra',
                    default => ($application->vacancy?->code ?? '').' · '.($application->vacancy?->title ?? '—'),
                };

                return [$label, $group->count(), $group->where('status', 'active')->count(), $group->where('status', 'closed')->count(), $group->where('status', 'cancelled')->count(), $group->sum('open_blockers_count'), $group->sum('completed_tasks_count')];
            })->values();
        }
        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($headers, $rows): void {
                $output = fopen('php://output', 'w');
                fwrite($output, "\xEF\xBB\xBF");
                foreach (collect([$headers])->concat($rows) as $row) {
                    fputcsv($output, array_map(fn ($value) => preg_match('/^[=+\-@\t\r\n]/', (string) $value) ? "'".$value : $value, $row), ',', '"', '');
                }
                fclose($output);
            }, 'laporan-'.$module.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $page = max(1, $request->integer('page', 1));
        $rows = new LengthAwarePaginator($rows->forPage($page, 25)->values(), $rows->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('reports.index', ['module' => $module, 'title' => self::MODULES[$module], 'headers' => $headers, 'rows' => $rows, 'totalApplications' => $records->count(), 'statuses' => Application::statusOptions()]);
    }
}
