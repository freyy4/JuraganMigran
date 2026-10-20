<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\AccessPolicy;
use App\Services\CockpitMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CockpitController extends Controller
{
    public function index(Request $request, ?string $role = null): View
    {
        $primaryRole = $request->user()->crmRole();
        $code = $primaryRole?->code ?? (($primaryRole?->is_admin && $request->user()->hasPermission('admin.system_config.manage')) ? 'super_admin' : 'cs');
        if ($role === null && ($primaryRole?->is_admin || in_array($code, ['super_admin', 'migration_owner'], true))) {
            abort_unless($request->user()->hasPermission('dashboard.read'), 403);
            $policy = app(AccessPolicy::class);
            $groups = collect(config('navigation'))->map(function (array $group) use ($request, $policy): array {
                $group['items'] = array_values(array_filter($group['items'], function (array $item) use ($request, $policy): bool {
                    $routePermission = $policy->routePermission($item['route']);

                    return $routePermission && $request->user()->hasPermission($routePermission) && $request->user()->hasPermission($item['permission'] ?? $routePermission);
                }));

                return $group;
            })->filter(fn (array $group): bool => count($group['items']) > 0)->values()->all();

            return view('cockpit.menu', ['groups' => $groups]);
        }
        $role ??= match ($code) {
            'super_admin' => 'admin','migration_owner' => 'system','admin_operations' => 'admin',default => $code
        };
        $role = $role === 'super-admin' ? 'system' : $role;
        $required = match ($role) {
            'management' => 'report.read','finance' => 'finance.sponsor_receivable.read','marketing' => 'marketing.analytics.read','admin' => 'admin.master_data.manage','system' => 'admin.system_config.manage','compliance' => 'incident.read',default => 'process.read'
        };
        abort_unless(in_array($role, ['management', 'finance', 'marketing', 'admin', 'system', 'compliance', 'cs', 'supervisor'], true) && $request->user()->hasPermission($required), 403);
        if ($code === 'super_admin' && $role === 'admin') {
            return view('cockpit.menu', ['groups' => config('navigation')]);
        }
        $metrics = app(CockpitMetrics::class);
        if (in_array($role, ['cs', 'supervisor'], true)) {
            return view('cockpit.cs', $metrics->cs($request));
        }
        if ($role === 'finance') {
            return view('cockpit.finance', $metrics->finance($request));
        }
        if ($role === 'system') {
            return view('cockpit.system', $metrics->system($request));
        }
        $policy = app(AccessPolicy::class);
        $ids = $policy->applicationIds($request->user(), 'dashboard.read', true);
        $applications = DB::table('applications')->whereIn('applications.id', $ids);
        $stats = [];
        $queue = collect();
        $pipeline = (clone $applications)->leftJoin('pipeline_stages', 'pipeline_stages.id', '=', 'applications.current_stage_id')->selectRaw('COALESCE(pipeline_stages.name, ?) as name, COUNT(*) as total', ['Verifikasi Awal'])->groupBy('pipeline_stages.name')->get();
        $countries = (clone $applications)->join('vacancies', 'vacancies.id', '=', 'applications.vacancy_id')->join('job_orders', 'job_orders.id', '=', 'vacancies.job_order_id')->join('countries', 'countries.id', '=', 'job_orders.country_id')->select('countries.name')->selectRaw('COUNT(*) as total')->groupBy('countries.name')->get();
        if ($role === 'system' || $role === 'admin') {
            $stats = [['Pengguna aktif', DB::table('users')->where('is_active', true)->count(), 'settings.users.index'], ['Grup CS aktif', DB::table('cs_groups')->where('is_active', true)->count(), 'acquisition.groups'], ['Funnel dipublikasikan', DB::table('application_funnels')->where('status', 'published')->count(), 'funnels.index'], ['Akses ditolak (24 jam)', DB::table('audit_logs')->where('action', 'access.denied')->where('created_at', '>=', now()->subDay())->count(), 'settings.activity.index']];
            $pipeline = collect();
            $countries = collect();
        } elseif ($role === 'finance') {
            $receivables = DB::table('sponsor_receivables')->whereIn('application_id', $ids);
            $stats = [['Tagihan mitra', (clone $receivables)->count(), 'receivables.index'], ['Tagihan jatuh tempo', (clone $receivables)->where('due_date', '<', now('Asia/Jakarta')->toDateString())->whereNotIn('status', ['paid', 'cancelled'])->count(), 'receivables.index'], ['Hak CPMI belum selesai', DB::table('candidate_benefit_plans')->whereIn('application_id', $ids)->where('status', '!=', 'completed')->count(), 'benefits.index'], ['Kewajiban belum selesai', DB::table('financial_obligations')->whereIn('application_id', $ids)->where('status', '!=', 'settled')->count(), 'obligations.index']];
            $pipeline = collect();
            $countries = collect();
        } elseif ($role === 'marketing') {
            $vacancyIds = $policy->vacancyIds($request->user(), 'marketing.analytics.read', true);
            $submissions = DB::table('lead_submissions')->whereIn('vacancy_id', $vacancyIds)->where('is_test', false);
            $leadIds = (clone $submissions)->select('lead_id');
            $stats = [['Funnel publik', DB::table('application_funnels')->whereIn('vacancy_id', $vacancyIds)->where('status', 'published')->count(), 'funnels.index'], ['Pendaftaran', (clone $submissions)->count(), 'funnels.index'], ['Lead qualified', DB::table('acquisition_leads')->whereIn('id', $leadIds)->whereIn('status', ['qualified', 'converted'])->count(), 'funnels.index'], ['Konversi CPMI', DB::table('acquisition_leads')->whereIn('id', $leadIds)->where('status', 'converted')->count(), 'funnels.index']];
            $pipeline = collect();
            $countries = collect();
        } elseif (in_array($role, ['cs', 'supervisor'])) {
            $leads = app(AcquisitionController::class)->scopedLeads($request);
            $tasks = Task::whereNotIn('status', Task::COMPLETED_STATUSES);
            $stats = [['Lead baru', (clone $leads)->where('status', 'new')->count(), 'acquisition.leads'], ['Perlu follow up', (clone $leads)->whereIn('followup_status', ['NOT_CONTACTED', 'NO_RESPONSE'])->count(), 'acquisition.leads'], ['Tugas terlambat', (clone $tasks)->where('due_at', '<', now())->count(), 'tasks.index'], ['Lamaran aktif', (clone $applications)->where('status', 'active')->count(), 'applications.index']];
            $queue = (clone $tasks)->with(['application.candidate.person', 'application.vacancy'])->orderByRaw('due_at IS NULL')->orderBy('due_at')->limit(8)->get();
        } else {
            $stats = [['Lamaran aktif', (clone $applications)->where('status', 'active')->count(), 'reports.pipeline.index'], ['Proses penempatan', (clone $applications)->count(), 'reports.pipeline.index'], ['Berangkat (30 hari)', DB::table('travel_events')->whereIn('application_id', $ids)->where('event_type', 'departure')->where('status', 'completed')->where('completed_at', '>=', now()->subDays(30))->count(), 'reports.pipeline.index'], ['Kendala terbuka', DB::table('application_blockers')->whereIn('application_id', $ids)->whereNotIn('status', ['resolved', 'completed', 'done'])->count(), 'reports.blockers.index']];
        }

        return view('cockpit.index', ['role' => $role, 'titles' => ['management' => 'Dashboard Management', 'cs' => 'Aktivitas Saya', 'supervisor' => 'Dashboard Tim', 'finance' => 'Dashboard Finance', 'marketing' => 'Dashboard Marketing', 'compliance' => 'Compliance & Risiko', 'admin' => $code === 'super_admin' ? 'Dashboard Superadmin' : 'Dashboard Admin', 'system' => 'System Cockpit'], 'stats' => $stats, 'queue' => $queue, 'pipeline' => $pipeline, 'countries' => $countries]);
    }
}
