<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vacancy;
use App\Services\AccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RecruitmentController extends Controller
{
    private function campaigns(Request $request): bool
    {
        return $request->route()->defaults['module'] === 'campaigns';
    }

    public function vacancies(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => 'nullable|string|max:100']);
        $search = trim($data['search'] ?? '');
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }
        $permission = $request->routeIs('funnels.*') ? 'funnel.manage_draft' : 'marketing.campaign.manage';
        $rows = Vacancy::whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), $permission))->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'))->orderBy('title')->orderBy('id')->limit(15)->get(['id', 'title', 'code']);

        return response()->json(['data' => $rows->map(fn (Vacancy $vacancy): array => ['id' => $vacancy->id, 'label' => $vacancy->title.' · '.$vacancy->code])]);
    }

    private function scope(Request $request, object $query): object
    {
        $policies = app(AccessPolicy::class)->policies($request->user(), 'marketing.campaign.manage');
        if (! collect($policies)->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL')) {
            $query->whereIn('record.vacancy_id', app(AccessPolicy::class)->vacancyIds($request->user(), 'marketing.campaign.manage'));
        }

        return $query;
    }

    public function index(Request $request): View
    {
        $campaigns = $this->campaigns($request);
        $statuses = $campaigns ? ['draft' => 'Draf', 'active' => 'Aktif', 'paused' => 'Dijeda', 'completed' => 'Selesai'] : ['draft' => 'Draf', 'published' => 'Dipublikasikan', 'archived' => 'Diarsipkan'];
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(array_keys($statuses))], 'channel' => 'nullable|string|max:255', 'owner_id' => 'nullable|integer|exists:users,id', 'vacancy_id' => 'nullable|integer|exists:vacancies,id', 'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => 'nullable|date_format:Y-m-d|after_or_equal:date_from', 'per_page' => 'nullable|in:25,50,100']);
        $table = $campaigns ? 'campaigns' : 'landing_pages';
        $query = DB::table($table.' as record')->leftJoin('vacancies', 'vacancies.id', '=', 'record.vacancy_id')->select('record.*', 'vacancies.title as vacancy_title');
        $query->selectSub(DB::table('lead_sources')->selectRaw('count(DISTINCT candidate_id)')->whereColumn($campaigns ? 'lead_sources.campaign_id' : 'lead_sources.landing_page_id', 'record.id'), 'leads_count');
        $query->selectSub(DB::table('lead_submissions')->where('is_test', false)->selectRaw('COUNT(DISTINCT lead_id)')->whereColumn('attribution->'.($campaigns ? 'campaign_id' : 'landing_page_id'), 'record.id'), 'canonical_leads_count');
        if ($campaigns) {
            $query->leftJoin('users', 'users.id', '=', 'record.owner_id')->addSelect('users.name as owner_name');
        }
        $this->scope($request, $query);
        foreach (['vacancy_id', ...($campaigns ? ['channel', 'owner_id'] : [])] as $field) {
            if ($filters[$field] ?? null) {
                $query->where('record.'.$field, $filters[$field]);
            }
        }
        if ($filters['date_from'] ?? null) {
            $query->where('record.created_at', '>=', Carbon::parse($filters['date_from'], 'Asia/Jakarta')->startOfDay()->utc());
        }
        if ($filters['date_to'] ?? null) {
            $query->where('record.created_at', '<', Carbon::parse($filters['date_to'], 'Asia/Jakarta')->startOfDay()->addDay()->utc());
        }
        if ($filters['status'] ?? null) {
            $query->where('record.status', $filters['status']);
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('record.'.($campaigns ? 'name' : 'title'), 'like', '%'.$search.'%')->orWhere('record.'.($campaigns ? 'code' : 'slug'), 'like', '%'.$search.'%')->orWhere('vacancies.title', 'like', '%'.$search.'%'));
        }

        $stats = ['total' => (clone $query)->count(), 'active' => (clone $query)->where('record.status', $campaigns ? 'active' : 'published')->count(), 'budget' => $campaigns ? (clone $query)->sum('record.budget') : null];

        return view('recruitment.index', ['stats' => $stats, 'owners' => User::orderBy('name')->get(['id', 'name']), 'campaigns' => $campaigns, 'statuses' => $statuses, 'records' => $query->orderByDesc('record.id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()]);
    }

    private function form(Request $request, ?object $record): View
    {
        $campaigns = $this->campaigns($request);
        $vacancies = Vacancy::withTrashed()->whereKey($record?->vacancy_id)->get();

        return view('recruitment.form', ['campaigns' => $campaigns, 'record' => $record, 'vacancies' => $vacancies, 'owners' => User::orderBy('name')->get(), 'availableCampaigns' => $this->scope($request, DB::table('campaigns as record'))->orderBy('record.name')->get(), 'availableFunnels' => DB::table('application_funnels')->whereIn('vacancy_id', app(AccessPolicy::class)->vacancyIds($request->user(), 'marketing.campaign.manage'))->where('status', 'published')->orderBy('name')->limit(100)->get(), 'workspace' => json_decode($record?->workspace_content ?? '{}', true)]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, null);
    }

    public function edit(Request $request, int $record): View
    {
        $item = $this->scope($request, DB::table(($this->campaigns($request) ? 'campaigns' : 'landing_pages').' as record'))->where('record.id', $record)->first();
        abort_unless($item, 404);

        return $this->form($request, $item);
    }

    private function data(Request $request, ?int $record): array
    {
        $campaigns = $this->campaigns($request);
        $table = $campaigns ? 'campaigns' : 'landing_pages';
        $current = $record ? $this->scope($request, DB::table($table.' as record'))->where('record.id', $record)->first() : null;
        abort_if($record && ! $current, 404);
        $rules = ['vacancy_id' => [$campaigns ? 'nullable' : 'required', 'integer', Rule::exists('vacancies', 'id')->where(fn ($query) => $query->whereNull('deleted_at')->when($current?->vacancy_id, fn ($query) => $query->orWhere('id', $current->vacancy_id)))]];
        if ($campaigns) {
            $rules += [
                'code' => ['required', 'string', 'max:255', Rule::unique('campaigns', 'code')->ignore($record)],
                'name' => ['required', 'string', 'max:255'], 'channel' => ['required', 'string', 'max:255'],
                'budget' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
                'start_date' => ['nullable', 'required_with:end_date', 'date_format:Y-m-d'],
                'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
                'status' => ['required', Rule::in(['draft', 'active', 'paused', 'completed'])],
                'utm_source' => ['nullable', 'string', 'max:255'], 'utm_medium' => ['nullable', 'string', 'max:255'], 'utm_campaign' => ['nullable', 'string', 'max:255'],
            ];
        } else {
            $rules += ['title' => ['required', 'string', 'max:255'], 'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('landing_pages', 'slug')->ignore($record)], 'funnel_id' => 'nullable|integer|exists:application_funnels,id', 'workspace' => 'nullable|array', 'workspace.description' => 'nullable|string|max:300', 'workspace.hero_title' => 'nullable|string|max:80', 'workspace.subtitle' => 'nullable|string|max:120', 'workspace.seo_title' => 'nullable|string|max:60', 'workspace.seo_description' => 'nullable|string|max:160', 'content' => ['nullable', 'string', 'max:50000'], 'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')], 'status' => ['required', Rule::in(['draft', 'published', 'archived'])]];
        }
        $data = $request->validate($rules, ['required' => ':attribute wajib diisi.', 'required_with' => ':attribute wajib diisi saat tanggal akhir diisi.', 'string' => ':attribute harus berupa teks.', 'max' => ':attribute terlalu panjang.', 'exists' => ':attribute tidak tersedia.', 'unique' => ':attribute sudah digunakan.', 'in' => ':attribute tidak valid.', 'numeric' => 'Anggaran harus berupa angka.', 'min' => 'Anggaran minimal nol.', 'regex' => ':attribute memiliki format tidak valid.', 'date_format' => ':attribute harus berupa tanggal yang valid.', 'after_or_equal' => 'Tanggal akhir tidak boleh mendahului tanggal mulai.', 'integer' => ':attribute tidak valid.'], ['code' => 'Kode', 'name' => 'Nama kampanye', 'channel' => 'Kanal', 'budget' => 'Anggaran', 'vacancy_id' => 'Lowongan', 'title' => 'Judul halaman', 'slug' => 'Alamat halaman', 'owner_id' => 'Penanggung jawab', 'campaign_id' => 'Kampanye', 'status' => 'Status', 'start_date' => 'Tanggal mulai', 'end_date' => 'Tanggal akhir']);
        if (! empty($data['vacancy_id'])) {
            abort_unless(app(AccessPolicy::class)->vacancyIds($request->user(), 'marketing.campaign.manage')->where('vacancies.id', $data['vacancy_id'])->exists(), 403);
        }
        if ($campaigns && $record && ($data['vacancy_id'] ?? null) && DB::table('landing_pages')->where('campaign_id', $record)->where('vacancy_id', '!=', $data['vacancy_id'])->exists()) {
            throw ValidationException::withMessages(['vacancy_id' => 'Kampanye sudah terhubung ke halaman dengan lowongan berbeda. Sesuaikan halaman pendaftaran terlebih dahulu.']);
        }
        if (! $campaigns) {
            if (! empty($data['funnel_id'])) {
                $funnel = DB::table('application_funnels as f')->join('funnel_versions as v', 'v.id', '=', 'f.active_version_id')->where('f.id', $data['funnel_id'])->where('f.status', 'published')->whereColumn('v.funnel_id', 'f.id')->where('v.vacancy_id', $data['vacancy_id'])->first();
                if (! $funnel) {
                    throw ValidationException::withMessages(['funnel_id' => 'Pilih funnel terbit dengan versi aktif untuk lowongan yang sama.']);
                }
            }
            $data['workspace_content'] = json_encode($data['workspace'] ?? json_decode($current?->workspace_content ?? '{}', true));
            unset($data['workspace']);
            $vacancy = Vacancy::withTrashed()->findOrFail($data['vacancy_id']);
            $campaign = empty($data['campaign_id']) ? null : DB::table('campaigns')->find($data['campaign_id']);
            if ($campaign?->vacancy_id && (int) $campaign->vacancy_id !== (int) $vacancy->id) {
                throw ValidationException::withMessages(['campaign_id' => 'Kampanye harus sesuai dengan lowongan halaman pendaftaran.']);
            }
            if ($data['status'] === 'published' && ($vacancy->trashed() || $vacancy->status !== 'published')) {
                throw ValidationException::withMessages(['status' => 'Publikasikan lowongan terlebih dahulu sebelum halaman pendaftaran.']);
            }
            if ($data['status'] === 'published' && $campaign && $campaign->status !== 'active') {
                throw ValidationException::withMessages(['campaign_id' => 'Aktifkan kampanye sebelum mempublikasikan halaman yang terhubung.']);
            }
            $data['published_at'] = $data['status'] === 'published' ? ($current?->published_at ?? now()) : null;
        }

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->data($request, null);
        if (! $this->campaigns($request)) {
            $data['created_by'] = $request->user()->id;
        }
        DB::table($this->campaigns($request) ? 'campaigns' : 'landing_pages')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return to_route($this->campaigns($request) ? 'campaigns.index' : 'landing-pages.index', $request->only('menu'))->with('success', 'Data perekrutan berhasil ditambahkan.');
    }

    public function update(Request $request, int $record): RedirectResponse
    {
        $data = $this->data($request, $record);
        DB::table($this->campaigns($request) ? 'campaigns' : 'landing_pages')->where('id', $record)->update([...$data, 'updated_at' => now()]);

        return to_route($this->campaigns($request) ? 'campaigns.index' : 'landing-pages.index', $request->only('menu'))->with('success', 'Data perekrutan berhasil diperbarui.');
    }
}
