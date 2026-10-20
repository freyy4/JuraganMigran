<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
use App\Services\AccessPolicy;
use App\Services\CsRouter;
use App\Services\FunnelSchema;
use App\Services\TrackingConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FunnelController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['search' => 'nullable|string|max:255', 'status' => 'nullable|in:draft,published,archived', 'vacancy_id' => 'nullable|integer|exists:vacancies,id', 'per_page' => 'nullable|in:25,50,100']);
        $query = $this->query();
        if ($data['search'] ?? '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%')->orWhere('slug', 'like', '%'.$data['search'].'%'));
        }
        foreach (['status', 'vacancy_id'] as $field) {
            if ($data[$field] ?? '') {
                $query->where($field, $data[$field]);
            }
        }
        $stats = ['draft' => (clone $query)->where('status', 'draft')->count(), 'published' => (clone $query)->where('status', 'published')->count()];
        $submissions = DB::table('lead_submissions')->where('is_test', false)->whereIn('funnel_id', (clone $query)->select('id'));
        $stats['leads'] = (clone $submissions)->distinct()->count('lead_id');
        $views = DB::table('funnel_analytics_events')->where('is_test', false)->where('event', 'view')->whereIn('funnel_id', (clone $query)->select('id'))->count();
        $stats['conversion'] = $views ? round((clone $submissions)->count() / $views * 100, 1) : null;
        $rows = $query->latest('id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString();
        $ids = $rows->pluck('id');
        $traffic = DB::table('funnel_analytics_events')->whereIn('funnel_id', $ids)->where('is_test', false)->where('event', 'view')->select('funnel_id')->selectRaw('COUNT(*) as total')->groupBy('funnel_id')->pluck('total', 'funnel_id');
        $submits = DB::table('lead_submissions')->whereIn('funnel_id', $ids)->where('is_test', false)->select('funnel_id')->selectRaw('COUNT(*) as total')->groupBy('funnel_id')->pluck('total', 'funnel_id');
        $vacancies = Vacancy::with('jobOrder.country')->whereIn('id', $rows->pluck('vacancy_id'))->get()->keyBy('id');
        $versions = DB::table('funnel_versions')->whereIn('id', $rows->pluck('active_version_id')->filter())->pluck('version', 'id');

        return view('funnels.index', ['funnels' => $rows, 'stats' => $stats, 'traffic' => $traffic, 'submits' => $submits, 'vacancies' => $vacancies, 'versions' => $versions]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100', 'unique:application_funnels'], 'vacancy_id' => ['required', 'integer', 'exists:vacancies,id']]);
        abort_unless(app(AccessPolicy::class)->vacancyIds($request->user(), 'funnel.manage_draft')->where('vacancies.id', $data['vacancy_id'])->exists(), 403);
        $id = DB::table('application_funnels')->insertGetId($data + ['draft_schema' => json_encode(FunnelSchema::defaults()), 'owner_id' => $request->user()->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        return to_route('funnels.builder', $id)->with('success', 'Funnel draft dibuat.');
    }

    private function query(): object
    {
        $permission = request()->attributes->get('access_permission') ?? 'funnel.read';

        return DB::table('application_funnels')->whereIn('vacancy_id', app(AccessPolicy::class)->vacancyIds(auth()->user(), $permission, request()->routeIs('funnels.analytics')));
    }

    private function funnel(int $funnel): object
    {
        return $this->query()->find($funnel) ?? abort(404);
    }

    public function builder(int $funnel): View
    {
        $row = $this->funnel($funnel);

        return view('funnels.builder', ['funnel' => $row, 'schema' => json_decode($row->draft_schema, true), 'draftToken' => $this->draftToken($row), 'types' => FunnelSchema::TYPES, 'blocks' => FunnelSchema::BLOCKS, 'actions' => FunnelSchema::ACTIONS, 'vacancies' => Vacancy::withTrashed()->whereIn('id', app(AccessPolicy::class)->vacancyIds(auth()->user(), 'funnel.read')->select('vacancies.id'))->where('id', $row->vacancy_id)->get(), 'templates' => DB::table('whatsapp_templates')->where('status', 'active')->where(fn ($query) => $query->whereNull('vacancy_id')->orWhereIn('vacancy_id', app(AccessPolicy::class)->vacancyIds(auth()->user(), 'funnel.read')))->get(), 'versions' => DB::table('funnel_versions')->where('funnel_id', $funnel)->latest('version')->get()]);
    }

    private function draftToken(object $row): string
    {
        return hash('sha256', json_encode([$row->name, (int) $row->vacancy_id, json_decode($row->draft_schema, true), $row->status]));
    }

    private function submittedSchema(Request $request): array
    {
        $request->validate(['schema' => ['required', 'json', 'max:2000000']]);
        $schema = json_decode($request->input('schema'), true);
        if (! is_array($schema)) {
            throw ValidationException::withMessages(['schema' => 'Schema harus berupa objek formulir.']);
        }

        return app(FunnelSchema::class)->validate($schema);
    }

    public function update(Request $request, int $funnel): RedirectResponse|JsonResponse
    {
        $this->funnel($funnel);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'vacancy_id' => ['required', 'integer', 'exists:vacancies,id'], 'draft_token' => [$request->expectsJson() ? 'required' : 'nullable', 'string', 'size:64'], 'status' => ['nullable', 'in:draft,published,archived']]);
        abort_unless(app(AccessPolicy::class)->vacancyIds($request->user(), 'funnel.manage_draft')->where('vacancies.id', $data['vacancy_id'])->exists(), 403);
        $schema = $this->submittedSchema($request);
        $row = DB::transaction(function () use ($request, $data, $schema, $funnel): object {
            $row = DB::table('application_funnels')->where('id', $funnel)->lockForUpdate()->first() ?? abort(404);
            if ($request->filled('draft_token') && ! hash_equals($this->draftToken($row), $data['draft_token'])) {
                abort(409, 'Draft sudah diubah di tab atau oleh pengguna lain. Muat ulang halaman sebelum menyimpan.');
            }
            $previous = json_decode($row->draft_schema, true);
            app(TrackingConfiguration::class)->assertNewAssignmentsActive($previous['tracking'] ?? [], $schema['tracking'] ?? []);
            app(TrackingConfiguration::class)->assertLegacyMappingPermission($previous['tracking'] ?? [], $schema['tracking'] ?? [], $request->user()->hasPermission('tracking.event_map.manage') || $request->user()->hasPermission('tracking.eventmap.manage'));
            if (($previous['tracking'] ?? []) !== ($schema['tracking'] ?? [])) {
                abort_unless($request->user()->hasPermission('funnel.tracking.update') || $request->user()->hasPermission('tracking.eventmap.manage'), 403);
            }
            if (($previous['tracking'] ?? []) !== ($schema['tracking'] ?? [])) {
                app(AccessPolicy::class)->audit($request->user(), 'tracking.funnel_binding_changed', ['funnel_id' => $funnel, 'before' => $previous['tracking'] ?? [], 'after' => $schema['tracking'] ?? []]);
            }
            $changes = ['name' => $data['name'], 'vacancy_id' => $data['vacancy_id'], 'draft_schema' => json_encode($schema), 'updated_at' => now()];
            if (($data['status'] ?? '') === 'archived') {
                $changes['status'] = 'archived';
            }
            DB::table('application_funnels')->where('id', $funnel)->update($changes);

            return DB::table('application_funnels')->find($funnel);
        });
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Draft tersimpan. Versi publik tidak berubah.', 'draft_token' => $this->draftToken($row), 'schema' => $schema]);
        }

        return to_route('funnels.builder', $funnel)->with('success', 'Draft tersimpan. Versi publik tidak berubah.');
    }

    public function uploadMedia(Request $request, int $funnel): JsonResponse
    {
        $this->funnel($funnel);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000']]);
        $file = $request->file('image');
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => abort(422),
        };
        $name = Str::uuid().'.'.$extension;
        $path = $file->storeAs('funnel-media/'.$funnel, $name, 'public');
        abort_unless($path, 500, 'Gambar gagal disimpan. Coba lagi.');

        return response()->json(['url' => route('funnel-media.show', [$funnel, $name], false), 'message' => 'Gambar berhasil diunggah.']);
    }

    public function media(int $funnel, string $file): BinaryFileResponse
    {
        $path = 'funnel-media/'.$funnel.'/'.$file;
        abort_unless(Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    public function livePreview(Request $request, int $funnel): View
    {
        $row = $this->funnel($funnel);
        $data = $request->validate(['vacancy_id' => ['required', 'integer', 'exists:vacancies,id'], 'page' => ['nullable', 'in:landing,form,thank_you'], 'canvas' => ['sometimes', 'boolean']]);
        abort_unless(app(AccessPolicy::class)->vacancyIds($request->user(), 'funnel.read')->where('vacancies.id', $data['vacancy_id'])->exists(), 403);
        $schema = $this->submittedSchema($request);
        if ($request->boolean('canvas') && ($data['page'] ?? '') !== 'thank_you') {
            return view('funnels.canvas', ['schema' => $schema, 'vacancy' => Vacancy::withTrashed()->with('jobOrder.country')->findOrFail($data['vacancy_id']), 'token' => '', 'version' => 0, 'isTest' => true, 'submitUrl' => '#']);
        }
        if (($data['page'] ?? '') === 'thank_you') {
            return view('funnels.thank-you', ['slug' => $row->slug, 'token' => 'preview', 'reference' => 'PRATINJAU', 'schema' => $schema, 'builderPreview' => true, 'whatsAppAvailable' => true, 'vacancy' => Vacancy::withTrashed()->findOrFail($data['vacancy_id'])]);
        }

        return view('funnels.public', ['previewPage' => $data['page'] ?? 'landing', 'funnel' => $row, 'schema' => $schema, 'vacancy' => Vacancy::withTrashed()->with(['jobOrder.country', 'jobOrder.company'])->findOrFail($data['vacancy_id']), 'token' => '', 'version' => 0, 'isTest' => true, 'builderPreview' => true, 'submitUrl' => '#']);
    }

    public function readiness(Request $request, int $funnel): JsonResponse
    {
        $this->funnel($funnel);
        $data = $request->validate(['vacancy_id' => ['required', 'integer', 'exists:vacancies,id']]);
        abort_unless(app(AccessPolicy::class)->vacancyIds($request->user(), 'funnel.read')->where('vacancies.id', $data['vacancy_id'])->exists(), 403);

        return response()->json($this->publishReadiness($this->submittedSchema($request), (int) $data['vacancy_id']));
    }

    private function publishReadiness(array $schema, int $vacancyId): array
    {
        $issues = [];
        $vacancy = Vacancy::withTrashed()->find($vacancyId);
        $published = $vacancy && ! $vacancy->trashed() && $vacancy->status === 'published';
        $issues[] = ['severity' => $published ? 'success' : 'blocker', 'message' => $published ? 'Lowongan terikat dan sudah dipublikasikan.' : ($vacancy?->trashed() ? 'Lowongan terhubung sudah dihapus. Pilih lowongan aktif sebelum menerbitkan funnel.' : 'Lowongan harus dipublikasikan sebelum funnel.')];
        $issues[] = ['severity' => 'success', 'message' => 'Nama, WhatsApp, persetujuan, aturan, dan halaman konfirmasi valid.'];
        $issues[] = ['severity' => 'info', 'message' => 'Lead dan atribusi tersimpan sebelum handoff. Publikasi membuat versi baru.'];
        if (in_array($schema['post_submit'], ['THANK_YOU_WITH_WHATSAPP_CTA', 'THANK_YOU_THEN_WHATSAPP'])) {
            $available = app(CsRouter::class)->availableForVacancy($vacancyId);
            $issues[] = ['severity' => $available ? 'success' : ($schema['post_submit'] === 'THANK_YOU_THEN_WHATSAPP' ? 'blocker' : 'warning'), 'message' => $available ? 'CS Rotator memiliki anggota aktif.' : 'CS Rotator belum tersedia; pendaftar tetap tersimpan dan handoff menunggu CS.'];
            $templateMatches = empty($schema['whatsapp_template_id']) || DB::table('whatsapp_templates')->where('id', $schema['whatsapp_template_id'])->where('status', 'active')->where(fn ($query) => $query->whereNull('vacancy_id')->orWhere('vacancy_id', $vacancyId))->exists();
            $issues[] = ['severity' => $templateMatches ? 'info' : 'blocker', 'message' => $templateMatches ? 'WhatsApp memakai template aktif yang sesuai, atau template standar sistem.' : 'Template WhatsApp tidak aktif atau terikat ke lowongan lain.'];
        }
        foreach ($schema['blocks'] as $block) {
            if (! ($block['hidden'] ?? false) && in_array($block['type'], ['image', 'hero']) && ! empty($block['url']) && ! ($block['decorative'] ?? false) && empty($block['alt'])) {
                $issues[] = ['severity' => 'warning', 'message' => 'Gambar pada '.($block['title'] ?? $block['id']).' belum memiliki teks alternatif.'];
            }
        }

        $tracking = app(TrackingConfiguration::class)->summary($schema);
        $issues = array_merge($issues, $tracking['issues']);

        return ['ready' => ! in_array('blocker', array_column($issues, 'severity'), true), 'issues' => $issues, 'tracking' => $tracking];
    }

    public function publish(Request $request, int $funnel): RedirectResponse
    {
        $this->funnel($funnel);
        $request->validate(['change_summary' => ['nullable', 'string', 'max:255'], 'draft_token' => ['nullable', 'string', 'size:64']]);
        DB::transaction(function () use ($request, $funnel): void {
            $row = DB::table('application_funnels')->where('id', $funnel)->lockForUpdate()->first() ?? abort(404);
            if ($request->filled('draft_token') && ! hash_equals($this->draftToken($row), $request->input('draft_token'))) {
                abort(409, 'Draft berubah. Simpan ulang sebelum publikasi.');
            }
            $schema = app(FunnelSchema::class)->validate(json_decode($row->draft_schema, true));
            $readiness = $this->publishReadiness($schema, (int) $row->vacancy_id);
            foreach ($readiness['issues'] as $issue) {
                if ($issue['severity'] === 'blocker') {
                    throw ValidationException::withMessages(['publish' => $issue['message']]);
                }
            }
            app(AccessPolicy::class)->audit($request->user(), 'tracking.publish_review', ['funnel_id' => $funnel, 'tracking' => $readiness['tracking'], 'warnings' => array_values(array_filter($readiness['issues'], fn ($issue) => $issue['severity'] === 'warning'))]);
            $schema = app(TrackingConfiguration::class)->freeze($schema);
            $version = (DB::table('funnel_versions')->where('funnel_id', $funnel)->max('version') ?? 0) + 1;
            $id = DB::table('funnel_versions')->insertGetId(['funnel_id' => $funnel, 'vacancy_id' => $row->vacancy_id, 'version' => $version, 'schema_snapshot' => json_encode($schema), 'created_by' => $request->user()->id, 'published_by' => $request->user()->id, 'published_at' => now(), 'change_summary' => $request->input('change_summary'), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('application_funnels')->where('id', $funnel)->update(['active_version_id' => $id, 'status' => 'published', 'published_at' => now(), 'updated_at' => now()]);
        });

        return to_route('funnels.builder', $funnel)->with('success', 'Versi baru berhasil dipublikasikan.');
    }

    public function clone(int $funnel): RedirectResponse
    {
        $row = $this->funnel($funnel);
        $id = DB::table('application_funnels')->insertGetId(['name' => $row->name.' (salinan)', 'slug' => $row->slug.'-'.Str::lower(Str::random(6)), 'vacancy_id' => $row->vacancy_id, 'draft_schema' => $row->draft_schema, 'status' => 'draft', 'owner_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);

        return to_route('funnels.builder', $id)->with('success', 'Salinan draft dibuat.');
    }

    public function rollback(int $funnel, int $version): RedirectResponse
    {
        $this->funnel($funnel);
        $row = DB::table('funnel_versions')->where('id', $version)->where('funnel_id', $funnel)->first() ?? abort(404);
        DB::table('application_funnels')->where('id', $funnel)->update(['draft_schema' => $row->schema_snapshot, 'vacancy_id' => $row->vacancy_id, 'updated_at' => now()]);

        return to_route('funnels.builder', $funnel)->with('success', 'Snapshot versi '.$row->version.' disalin ke draft. Publikasikan untuk membuat versi baru.');
    }

    public function preview(Request $request, int $funnel): View
    {
        $row = $this->funnel($funnel);
        $token = (string) Str::uuid();
        $request->session()->put('funnel_preview.'.$funnel, $token);

        return view('funnels.public', ['funnel' => $row, 'schema' => json_decode($row->draft_schema, true), 'vacancy' => Vacancy::with(['jobOrder.country', 'jobOrder.company'])->findOrFail($row->vacancy_id), 'token' => $token, 'version' => 0, 'isTest' => true, 'submitUrl' => route('funnels.test', $funnel)]);
    }

    public function test(Request $request, int $funnel): RedirectResponse
    {
        $row = $this->funnel($funnel);
        abort_unless(hash_equals($request->session()->get('funnel_preview.'.$funnel, 'invalid'), $request->input('idempotency_key', '')), 419);
        $schema = app(FunnelSchema::class)->validate(json_decode($row->draft_schema, true));
        app(FunnelSchema::class)->answers($request, $schema);
        $request->validate(['consent' => ['accepted']]);

        return to_route('funnels.builder', $funnel)->with('success', 'Formulir uji valid. Tidak membuat lead, tugas, atau mengubah penghitung CS.');
    }

    public function responses(Request $request, int $funnel): View|StreamedResponse
    {
        $row = $this->funnel($funnel);
        $query = DB::table('lead_submissions as submission')->leftJoin('acquisition_leads as lead', 'lead.id', '=', 'submission.lead_id')->leftJoin('users', 'users.id', '=', 'lead.assigned_cs_id')->where('submission.funnel_id', $funnel)->where('submission.is_test', false)->select('submission.*', 'lead.name', 'lead.normalized_phone', 'lead.followup_status', 'lead.status as lead_status', 'users.name as cs_name');
        $policy = app(AccessPolicy::class);
        $permission = $request->routeIs('funnels.export') ? 'funnel.responses.export' : 'funnel.responses.read';
        $all = collect($policy->policies($request->user(), $permission))->contains(fn ($grant) => $grant['scope'] === 'OPERATIONAL_ALL');
        if (! $all) {
            $query->where('lead.assigned_cs_id', $request->user()->id);
        }
        if ($request->routeIs('funnels.export')) {
            $rows = $query->orderBy('submission.id')->get();

            return response()->streamDownload(function () use ($rows, $policy): void {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Tanggal', 'Nama', 'Telepon', 'Status Duplikasi', 'CS', 'Follow Up'], ',', '"', '');
                foreach ($rows as $row) {
                    fputcsv($file, array_map(fn ($value) => preg_match('/^[=+\-@\t\r\n]/', (string) $value) ? "'".$value : $value, [$row->created_at, $row->name, $policy->mask($row->normalized_phone), $row->duplicate_state, $row->cs_name, $row->followup_status]), ',', '"', '');
                }fclose($file);
            }, 'respons-funnel.csv');
        }

        return view('funnels.responses', ['funnel' => $row, 'responses' => $query->latest('submission.id')->paginate(25)]);
    }

    public function analytics(int $funnel): View
    {
        $row = $this->funnel($funnel);
        $events = DB::table('funnel_analytics_events')->where('funnel_id', $funnel)->where('is_test', false)->select('event')->selectRaw('COUNT(*) as total')->groupBy('event')->pluck('total', 'event');
        $submissions = DB::table('lead_submissions')->where('funnel_id', $funnel)->where('is_test', false);
        $stats = ['Landing View' => $events['view'] ?? 0, 'Unique Visitors' => DB::table('funnel_analytics_events')->where('funnel_id', $funnel)->where('is_test', false)->distinct()->count('visitor_hash'), 'Form Start' => $events['start'] ?? 0, 'Step Selesai' => $events['step_completed'] ?? 0, 'Submit' => $submissions->count(), 'WhatsApp Dibuka' => $events['whatsapp_opened'] ?? 0, 'Lead Qualified' => DB::table('acquisition_leads')->whereIn('id', (clone $submissions)->select('lead_id'))->whereIn('status', ['qualified', 'converted'])->count(), 'Konversi CPMI' => (clone $submissions)->whereNotNull('application_id')->count()];
        $sources = (clone $submissions)->get()->groupBy(fn ($submission) => json_decode($submission->attribution, true)['utm_source'] ?? 'langsung')->map->count();

        return view('funnels.analytics', ['funnel' => $row, 'stats' => $stats, 'sources' => $sources]);
    }
}
