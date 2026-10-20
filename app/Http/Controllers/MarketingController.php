<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\AccessPolicy;
use App\Services\MarketingWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketingController extends Controller
{
    public function index(Request $request): View|StreamedResponse
    {
        $mode = $request->route()->defaults['mode'];
        if ($mode === 'analytics' && ! $request->filled('date_from') && ! $request->filled('date_to')) {
            $request->merge(['date_from' => now('Asia/Jakarta')->subDays(29)->toDateString(), 'date_to' => now('Asia/Jakarta')->toDateString()]);
        }
        $filters = $request->validate(['compare' => 'nullable|boolean', 'export' => 'nullable|in:csv', 'search' => ['nullable', 'string', 'max:255'], 'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')], 'source' => ['nullable', 'string', 'max:255'], 'medium' => ['nullable', 'string', 'max:255'], 'vacancy_id' => ['nullable', 'integer', 'exists:vacancies,id'], 'per_page' => ['nullable', Rule::in([25, 50, 100])], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])]]);
        $query = DB::table('lead_sources as source')->join('candidates as candidate', 'candidate.id', '=', 'source.candidate_id')->join('persons as person', 'person.id', '=', 'candidate.person_id')->leftJoin('campaigns', 'campaigns.id', '=', 'source.campaign_id')->leftJoin('landing_pages', 'landing_pages.id', '=', 'source.landing_page_id')->whereNull('candidate.deleted_at')->whereNull('person.deleted_at')->select('source.*', 'person.full_name', 'candidate.candidate_code', 'campaigns.name as campaign_name', 'landing_pages.title as page_title');
        $query->selectSub(DB::table('lead_submissions as submission')->join('application_funnels as funnel', 'funnel.id', '=', 'submission.funnel_id')->leftJoin('acquisition_leads as lead', 'lead.id', '=', 'submission.lead_id')->leftJoin('applications as application', 'application.id', '=', 'submission.application_id')->where('submission.is_test', false)->where(fn ($q) => $q->whereColumn('lead.candidate_id', 'source.candidate_id')->orWhereColumn('application.candidate_id', 'source.candidate_id'))->orderByDesc('submission.id')->limit(1)->select('funnel.name'), 'funnel_name');
        $permission = $request->attributes->get('access_permission');
        if (! collect(app(AccessPolicy::class)->policies($request->user(), $permission))->contains(fn ($policy) => in_array($policy['scope'], ['OPERATIONAL_ALL', 'AGGREGATE_ONLY'], true))) {
            $query->whereIn('source.candidate_id', app(AccessPolicy::class)->candidateIds($request->user(), $permission));
        }
        if ($mode !== 'analytics' && ! collect(app(AccessPolicy::class)->policies($request->user(), $permission))->contains(fn ($policy) => $policy['scope'] !== 'AGGREGATE_ONLY')) {
            $query->whereRaw('1=0');
        }
        foreach (['source', 'medium'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where('source.'.$field, $filters[$field]);
            }
        }
        if ($filters['vacancy_id'] ?? null) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('applications')->whereColumn('applications.candidate_id', 'source.candidate_id')->where('applications.vacancy_id', $filters['vacancy_id'])->whereNull('applications.deleted_at'));
        }
        if ($mode === 'referrals') {
            $query->whereNotNull('source.referral_code')->where('source.referral_code', '!=', '');
        }
        if ($filters['campaign_id'] ?? null) {
            $query->where('source.campaign_id', $filters['campaign_id']);
        }
        if ($filters['date_from'] ?? null) {
            $query->where('source.captured_at', '>=', Carbon::parse($filters['date_from'], 'Asia/Jakarta')->startOfDay()->setTimezone(config('app.timezone')));
        }
        if ($filters['date_to'] ?? null) {
            $query->where('source.captured_at', '<', Carbon::parse($filters['date_to'], 'Asia/Jakarta')->startOfDay()->addDay()->setTimezone(config('app.timezone')));
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('person.full_name', 'like', '%'.$search.'%')->orWhere('candidate.candidate_code', 'like', '%'.$search.'%')->orWhere('person.email', 'like', '%'.$search.'%')->orWhere('person.phone', 'like', '%'.$search.'%')->orWhere('person.whatsapp', 'like', '%'.$search.'%')->orWhere('source.medium', 'like', '%'.$search.'%')->orWhere('source.source', 'like', '%'.$search.'%')->orWhere('source.referral_code', 'like', '%'.$search.'%'));
        }
        if ($mode !== 'analytics' && $request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($query): void {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['ID Peserta', 'Nama', 'Sumber', 'Medium', 'Kampanye', 'Halaman', 'Funnel', 'Referensi', 'Dicatat']);
                foreach ((clone $query)->reorder()->orderBy('source.id')->lazy(500) as $row) {
                    $values = [$row->candidate_code, $row->full_name, $row->source, $row->medium, $row->campaign_name, $row->page_title, $row->funnel_name, $row->referral_code, $row->captured_at];
                    fputcsv($output, array_map(fn ($value) => preg_match('/^[=+@-]/', (string) $value) ? "'".$value : $value, $values));
                }
                fclose($output);
            }, 'sumber-pendaftar.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $stats = ['records' => (clone $query)->count(), 'candidates' => (clone $query)->distinct()->count('source.candidate_id'), 'campaigns' => (clone $query)->reorder()->select('campaigns.name')->selectRaw('COUNT(DISTINCT source.candidate_id) as total')->groupBy('campaigns.name')->orderByDesc('total')->limit(10)->get(), 'channels' => (clone $query)->reorder()->select('source.medium')->selectRaw('COUNT(DISTINCT source.candidate_id) as total')->groupBy('source.medium')->orderByDesc('total')->limit(10)->get(), 'referrals' => (clone $query)->reorder()->whereNotNull('source.referral_code')->select('source.referral_code')->selectRaw('COUNT(DISTINCT source.candidate_id) as total')->groupBy('source.referral_code')->orderByDesc('total')->limit(10)->get(), 'groups' => (clone $query)->reorder()->select('source.source')->selectRaw('COUNT(*) as records_count, COUNT(DISTINCT source.candidate_id) as candidates_count')->groupBy('source.source')->orderByDesc('records_count')->limit(20)->get()];

        return view('marketing.index', ['acquisition' => $mode === 'analytics' ? app(MarketingWorkspace::class)->acquisition($request) : null, 'mode' => $mode, 'rows' => ($mode === 'analytics' ? (clone $query)->whereRaw('1=0') : $query)->orderByDesc('source.id')->paginate((int) ($filters['per_page'] ?? 50))->withQueryString(), 'stats' => $stats, 'campaigns' => DB::table('campaigns')->orderBy('name')->get(), 'pages' => DB::table('landing_pages')->orderBy('title')->get(), 'selectedCandidate' => $request->old('candidate_id') && app(AccessPolicy::class)->canCandidate($request->user(), (int) $request->old('candidate_id'), 'lead.update') ? Candidate::with('person')->find($request->old('candidate_id')) : null]);
    }

    public function candidates(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => 'nullable|string|max:100', 'ids' => 'nullable|array|max:10', 'ids.*' => 'integer']);
        $search = trim($data['search'] ?? '');
        $query = Candidate::withoutGlobalScopes()->whereNull('deleted_at')->whereHas('person');
        if (! collect(app(AccessPolicy::class)->policies($request->user(), 'lead.update'))->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL')) {
            $query->whereIn('id', app(AccessPolicy::class)->candidateIds($request->user(), 'lead.update'));
        }
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        } elseif (mb_strlen($search) >= 2) {
            $contact = $request->user()->hasPermission('candidate.read.contact') || $request->user()->hasPermission('lead.read.contact');
            $query->where(fn ($q) => $q->where('candidate_code', 'like', '%'.$search.'%')->orWhereHas('person', fn ($q) => $q->where(fn ($q) => $q->where('full_name', 'like', '%'.$search.'%')->when($contact, fn ($q) => $q->orWhere('phone', 'like', '%'.$search.'%')->orWhere('whatsapp', 'like', '%'.$search.'%')))));
        } else {
            return response()->json(['data' => []]);
        }
        $rows = $query->with('person')->orderBy('id')->limit(15)->get();
        $history = DB::table('lead_sources')->whereIn('candidate_id', $rows->pluck('id'))->select('candidate_id')->selectRaw('COUNT(*) as total')->groupBy('candidate_id')->pluck('total', 'candidate_id');

        return response()->json(['data' => $rows->map(fn (Candidate $candidate): array => ['id' => $candidate->id, 'label' => $candidate->person->full_name.' · '.$candidate->candidate_code, 'code' => $candidate->candidate_code, 'history_count' => $history[$candidate->id] ?? 0])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['candidate_id' => ['required', 'integer', Rule::exists('candidates', 'id')->whereNull('deleted_at')], 'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')], 'landing_page_id' => ['nullable', 'integer', Rule::exists('landing_pages', 'id')], 'source' => ['required', 'string', 'max:255'], 'medium' => ['nullable', 'string', 'max:255'], 'referral_code' => [$request->route()->defaults['mode'] === 'referrals' ? 'required' : 'nullable', 'string', 'max:255'], 'content' => ['nullable', 'string', 'max:255'], 'confirm_duplicate' => ['sometimes', 'accepted']]);
        if ($data['landing_page_id'] ?? null) {
            $page = DB::table('landing_pages')->find($data['landing_page_id']);
            if ((int) ($data['campaign_id'] ?? 0) !== (int) ($page->campaign_id ?? 0)) {
                throw ValidationException::withMessages(['campaign_id' => 'Kampanye harus sesuai halaman pendaftaran.']);
            }
        }
        abort_unless(app(AccessPolicy::class)->canCandidate($request->user(), (int) $data['candidate_id'], 'lead.update'), 403);
        DB::transaction(function () use ($data, $request): void {
            DB::table('candidates')->where('id', $data['candidate_id'])->lockForUpdate()->first();
            $duplicate = DB::table('lead_sources')->where('candidate_id', $data['candidate_id'])->where('source', $data['source'])->where('referral_code', $data['referral_code'] ?? null)->where('captured_at', '>=', now()->subDay())->exists();
            if ($duplicate && ! $request->boolean('confirm_duplicate')) {
                throw ValidationException::withMessages(['confirm_duplicate' => 'Peserta, sumber, dan referensi yang sama telah dicatat dalam 24 jam. Periksa riwayat dan konfirmasikan jika ingin menambah catatan.']);
            }
            unset($data['confirm_duplicate']);
            $id = DB::table('lead_sources')->insertGetId([...$data, 'captured_at' => now()]);
            app(AccessPolicy::class)->audit($request->user(), 'marketing.source.created', ['source_id' => $id, 'candidate_id' => $data['candidate_id'], 'duplicate_confirmed' => $duplicate]);
        });

        return to_route($request->route()->defaults['mode'] === 'referrals' ? 'referrals.index' : 'lead-sources.index')->with('success', 'Sumber pendaftar berhasil dicatat.');
    }
}
