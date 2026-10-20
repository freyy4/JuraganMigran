<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\SitePage;
use App\Models\Vacancy;
use App\Services\AccessPolicy;
use App\Services\MarketingWorkspace;
use App\Services\PublicVacancies;
use App\Services\SiteContent;
use App\Services\WebsiteHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteBuilderController extends Controller
{
    public function __construct(private SiteContent $content) {}

    private function scoped(Request $request): Builder
    {
        $query = SitePage::query();
        if ($request->user()->crmRole()?->code === 'marketing') {
            $query->where('owner_id', $request->user()->id);
        }

        return $query;
    }

    private function access(Request $request, SitePage $page): void
    {
        abort_unless($this->scoped($request)->whereKey($page->id)->exists(), 403);
        if ($page->type === 'global') {
            abort_unless($request->user()->hasPermission('site.globals'), 403);
        }
    }

    private function validateCountrySlug(string $type, string $slug): void
    {
        if ($type === 'country' && ! Country::where('is_active', true)->get()->contains(fn (Country $country): bool => Str::slug($country->name) === $slug)) {
            throw ValidationException::withMessages(['slug' => 'Slug konten negara harus sesuai nama negara aktif pada Data Master.']);
        }
    }

    public function index(Request $request): View
    {
        $data = $request->validate(['search' => 'nullable|string|max:255', 'status' => 'nullable|in:draft,published,archived', 'type' => 'nullable|in:page,guide,country', 'per_page' => 'nullable|in:25,50,100'], config('site.validation_messages'));
        $query = $this->scoped($request)->with(['activeVersion', 'owner'])->where('type', '!=', 'global');
        if ($data['search'] ?? '') {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$data['search'].'%')->orWhere('slug', 'like', '%'.$data['search'].'%'));
        }
        if ($data['status'] ?? '') {
            $query->where('status', $data['status']);
        }

        if ($data['type'] ?? '') {
            $query->where('type', $data['type']);
        }
        $stats = (clone $query)->reorder()->selectRaw("COUNT(*) as total, SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) as published, SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) as draft")->first();

        return view('site-admin.index', ['stats' => $stats, 'pages' => $query->orderBy('sequence')->orderBy('id')->paginate((int) ($data['per_page'] ?? 25))->withQueryString(), 'global' => $request->user()->hasPermission('site.globals') ? SitePage::where('slug', '_globals')->first() : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:255', 'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:200', Rule::unique('site_pages'), Rule::notIn([...array_keys(config('site.templates')), ...config('site.reserved')])], 'type' => 'required|in:page,guide,country', 'description' => 'nullable|string|max:1000'], config('site.validation_messages'));
        $this->validateCountrySlug($data['type'], $data['slug']);
        $description = $data['description'] ?? '';
        unset($data['description']);
        $page = SitePage::create($data + ['owner_id' => $request->user()->id, 'draft_schema' => ['seo' => ['title' => $data['title'], 'description' => $description, 'noindex' => false], 'blocks' => [['type' => 'rich_text', 'title' => $data['title'], 'text' => '']]]]);

        return to_route('site-builder.edit', $page);
    }

    public function edit(Request $request, SitePage $page): View
    {
        $this->access($request, $page);

        return view('site-admin.builder', ['page' => $page, 'versions' => $page->versions()->latest('version')->get(), 'assets' => DB::table('site_assets')->whereIn('id', array_filter([...array_column($page->draft_schema['blocks'] ?? [], 'asset_id'), $page->draft_schema['seo']['og_asset_id'] ?? null]))->get(), 'warnings' => $this->content->warnings($page->draft_schema)]);
    }

    public function update(Request $request, SitePage $page): RedirectResponse|JsonResponse
    {
        $this->access($request, $page);
        $data = $request->validate(['title' => 'required|string|max:255', 'schema' => 'required|array', 'in_navigation' => 'sometimes|boolean', 'sequence' => 'nullable|integer|min:0|max:1000', 'updated_at' => 'required|string', 'draft_token' => 'nullable|string|size:64'], config('site.validation_messages'));
        $schema = $this->content->validate($data['schema']);
        DB::transaction(function () use ($page, $data, $schema): void {
            $locked = SitePage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $token = hash('sha256', json_encode([$locked->title, $locked->draft_schema, $locked->in_navigation, $locked->sequence, $locked->active_version_id, $locked->status]));
            if (isset($data['draft_token']) && ! hash_equals($token, $data['draft_token'])) {
                abort(409, 'Draft berubah di tab atau pengguna lain. Muat ulang sebelum menyimpan.');
            }
            if ($locked->updated_at->toISOString() !== $data['updated_at']) {
                throw ValidationException::withMessages(['updated_at' => 'Halaman diubah pengguna lain. Muat ulang sebelum menyimpan.']);
            }
            $locked->update(['title' => $data['title'], 'draft_schema' => $schema, 'in_navigation' => $data['in_navigation'] ?? false, 'sequence' => $data['sequence'] ?? 0]);
        });
        if ($request->expectsJson()) {
            $fresh = $page->fresh();

            return response()->json(['ok' => true, 'updated_at' => $fresh->updated_at->toISOString(), 'draft_token' => hash('sha256', json_encode([$fresh->title, $fresh->draft_schema, $fresh->in_navigation, $fresh->sequence, $fresh->active_version_id, $fresh->status]))]);
        }

        return back()->with('success', 'Draft disimpan. Website publik belum berubah.');
    }

    public function preview(Request $request, SitePage $page): View
    {
        $this->access($request, $page);

        $schema = $request->isMethod('post') ? $this->content->validate($request->validate(['schema' => 'required|array'], config('site.validation_messages'))['schema']) : $page->draft_schema;
        $base = ['schema' => $schema, 'globals' => $page->type === 'global' ? $schema : $this->content->schema('_globals'), 'navigation' => $this->content->navigation(), 'preview' => true, 'slug' => $page->slug];
        if ($page->slug === 'lowongan') {
            $service = app(PublicVacancies::class);

            return view('site.listing', $base + ['cards' => $service->query()->paginate(12)->through(fn ($v) => $service->display($v)), 'countries' => Country::where('is_active', true)->get(), 'sectors' => $service->query()->distinct()->pluck('sector'), 'educations' => $service->query()->distinct()->pluck('minimum_education')]);
        }
        if ($page->type === 'country') {
            $country = Country::where('is_active', true)->get()->first(fn ($c) => Str::slug($c->name) === $page->slug) ?? abort(404);
            $service = app(PublicVacancies::class);

            return view('site.country', $base + ['country' => $country, 'cards' => $service->query()->whereHas('jobOrder', fn ($q) => $q->where('country_id', $country->id))->paginate(12)->through(fn ($v) => $service->display($v))]);
        }

        return view('site.page', $base);
    }

    public function publish(Request $request, SitePage $page): RedirectResponse
    {
        $this->access($request, $page);
        $request->validate(['acknowledge' => 'accepted'], config('site.validation_messages'));
        DB::transaction(function () use ($request, $page): void {
            $locked = SitePage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $this->validateCountrySlug($locked->type, $locked->slug);
            $schema = $this->content->validate($locked->draft_schema, true);
            $schema['_page'] = ['title' => $locked->title, 'navigation' => $locked->in_navigation, 'sequence' => $locked->sequence];
            $version = $locked->versions()->create(['version' => ($locked->versions()->max('version') ?? 0) + 1, 'schema' => $schema, 'created_by' => $request->user()->id, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'published_at' => now()]);
            $locked->update(['active_version_id' => $version->id, 'status' => 'published']);
            app(AccessPolicy::class)->audit($request->user(), 'site.published', ['page_id' => $locked->id, 'version' => $version->version]);
        });

        return back()->with('success', 'Versi baru diterbitkan. Website langsung diperbarui.');
    }

    public function restore(Request $request, SitePage $page, int $version): RedirectResponse
    {
        $this->access($request, $page);
        $record = $page->versions()->findOrFail($version);
        $page->update(['draft_schema' => $record->schema]);

        return back()->with('success', 'Versi dipulihkan ke draft. Terbitkan untuk mengaktifkannya. Riwayat tetap disimpan.');
    }

    public function clone(Request $request, SitePage $page): RedirectResponse
    {
        $this->access($request, $page);
        abort_if($page->type === 'global', 422);
        $copy = SitePage::create(['slug' => $page->slug.'-'.Str::lower(Str::random(8)), 'title' => $page->title.' (Salinan)', 'type' => $page->type === 'country' ? 'page' : $page->type, 'owner_id' => $request->user()->id, 'draft_schema' => $page->draft_schema]);

        return to_route('site-builder.edit', $copy);
    }

    public function archive(Request $request, SitePage $page): RedirectResponse
    {
        $this->access($request, $page);
        abort_if($page->system, 422, 'Halaman sistem tidak dapat diarsipkan.');
        $page->update(['status' => 'archived']);

        return back()->with('success', 'Halaman diarsipkan.');
    }

    public function slug(Request $request, SitePage $page): RedirectResponse
    {
        $this->access($request, $page);
        abort_if($page->system, 422);
        $data = $request->validate(['slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:200', Rule::unique('site_pages')->ignore($page->id), Rule::notIn([...array_keys(config('site.templates')), ...config('site.reserved')])]], config('site.validation_messages'));
        $this->validateCountrySlug($page->type, $data['slug']);
        DB::transaction(function () use ($request, $page, $data): void {
            $old = $this->content->path($page);
            $page->slug = $data['slug'];
            $target = $this->content->path($page);
            if ($old === $target) {
                return;
            }
            abort_if(DB::table('site_redirects')->where('source', $target)->exists(), 422, 'Slug merupakan redirect yang sudah ada.');
            if ($page->active_version_id) {
                DB::table('site_redirects')->where('target', $old)->update(['target' => $target]);
                DB::table('site_redirects')->updateOrInsert(['source' => $old], ['target' => $target, 'status' => 301, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $page->save();
        });

        return back()->with('success', 'Slug diperbarui. Redirect dipertahankan untuk halaman yang pernah terbit.');
    }

    public function assets(Request $request): View|JsonResponse
    {
        $data = $request->validate(['search' => 'nullable|string|max:255', 'orientation' => 'nullable|in:landscape,portrait,square', 'mime' => 'nullable|in:image/jpeg,image/png,image/webp', 'usage' => 'nullable|in:used,unused', 'category' => 'nullable|string|max:50', 'status' => 'nullable|in:active,archived', 'sort' => 'nullable|in:newest,oldest,size', 'per_page' => 'nullable|in:25,50,100']);
        $query = DB::table('site_assets');
        if ($data['search'] ?? '') {
            $query->where(fn ($q) => $q->where('alt', 'like', '%'.$data['search'].'%')->orWhere('display_name', 'like', '%'.$data['search'].'%')->orWhere('tags', 'like', '%'.$data['search'].'%'));
        }
        foreach (['category', 'status'] as $field) {
            if ($data[$field] ?? '') {
                $query->where($field, $data[$field]);
            }
        }
        $usage = ! $request->expectsJson() || ($data['usage'] ?? '') ? app(MarketingWorkspace::class)->assetUsage() : [];
        if ($data['usage'] ?? '') {
            $data['usage'] === 'used' ? $query->whereIn('id', array_keys($usage)) : $query->whereNotIn('id', array_keys($usage));
        }
        if ($data['mime'] ?? '') {
            $query->where('mime', $data['mime']);
        }
        if ($data['orientation'] ?? '') {
            $query->whereColumn('width', ['landscape' => '>', 'portrait' => '<', 'square' => '='][$data['orientation']], 'height');
        }
        $query->orderBy(($data['sort'] ?? '') === 'size' ? 'bytes' : 'id', ($data['sort'] ?? '') === 'oldest' ? 'asc' : 'desc')->orderByDesc('id');
        if ($request->expectsJson()) {
            return response()->json(['data' => $query->limit(20)->get()->map(fn ($asset): array => ['id' => $asset->id, 'label' => $asset->alt, 'url' => route('site.media', ['asset' => $asset->id, 'w' => 400]), 'width' => $asset->width, 'height' => $asset->height])]);
        }

        return view('site-admin.assets', ['usage' => $usage, 'assets' => $query->paginate((int) ($data['per_page'] ?? 25))->withQueryString()]);
    }

    public function upload(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048', 'alt' => 'required|string|max:255'], config('site.validation_messages'));
        $file = $request->file('image');
        [$width,$height] = getimagesize($file->path());
        abort_if($width * $height > 20000000, 422, 'Dimensi gambar terlalu besar. Gunakan maksimal 20 juta piksel.');
        $hash = hash_file('sha256', $file->path());
        if (DB::table('site_assets')->where('sha256', $hash)->exists() && ! $request->boolean('confirm_duplicate')) {
            throw ValidationException::withMessages(['image' => 'Gambar yang sama sudah ada. Gunakan aset tersebut atau centang konfirmasi unggah duplikat.']);
        }
        $path = $file->store('site-assets', 'documents');
        $variants = [];
        $image = imagecreatefromstring(file_get_contents($file->path()));
        if ($image && function_exists('imagewebp')) {
            foreach ([400, 800, 1400] as $size) {
                $targetWidth = min($size, $width);
                $targetHeight = (int) round($height * $targetWidth / $width);
                $resized = imagescale($image, $targetWidth, $targetHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                ob_start();
                imagewebp($resized, null, 82);
                $bytes = ob_get_clean();
                imagedestroy($resized);
                $variant = $path.'.'.$size.'.webp';
                Storage::disk('documents')->put($variant, $bytes);
                $variants[(string) $size] = $variant;
            }
            imagedestroy($image);
        }
        DB::table('site_assets')->insert(['display_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'sha256' => $hash, 'path' => $path, 'alt' => $data['alt'], 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'width' => $width, 'height' => $height, 'variants' => json_encode($variants), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Gambar disimpan. Gunakan gambar WebP yang ringkas untuk tampilan cepat.');
    }

    public function updateAsset(Request $request, int $asset): RedirectResponse
    {
        $data = $request->validate(['alt' => 'required|string|max:255', 'display_name' => 'nullable|string|max:255', 'tags' => 'nullable|string|max:255', 'category' => 'nullable|string|max:50', 'status' => 'nullable|in:active,archived', 'focal_x' => 'required|numeric|between:0,100', 'focal_y' => 'required|numeric|between:0,100'], config('site.validation_messages'));
        $row = DB::table('site_assets')->find($asset) ?? abort(404);
        $variants = json_decode($row->variants ?? '{}', true);
        $variants['_focal'] = ['x' => (float) $data['focal_x'], 'y' => (float) $data['focal_y']];
        DB::table('site_assets')->where('id', $asset)->update([...array_intersect_key($data, array_flip(['display_name', 'tags', 'category', 'status'])), 'alt' => $data['alt'], 'variants' => json_encode($variants), 'updated_at' => now()]);

        return back()->with('success', 'Teks alternatif dan titik fokus gambar disimpan.');
    }

    public function deleteAsset(Request $request, int $asset): RedirectResponse
    {
        $row = DB::table('site_assets')->find($asset) ?? abort(404);
        $used = Vacancy::withTrashed()->where('site_asset_id', $asset)->exists() || SitePage::cursor()->contains(fn ($p) => in_array($asset, array_map('intval', array_column($p->draft_schema['blocks'] ?? [], 'asset_id')), true) || (int) ($p->draft_schema['seo']['og_asset_id'] ?? 0) === $asset) || DB::table('site_page_versions')->lazyById(200)->contains(fn ($v) => in_array($asset, array_map('intval', array_column(json_decode($v->schema, true)['blocks'] ?? [], 'asset_id')), true) || (int) (json_decode($v->schema, true)['seo']['og_asset_id'] ?? 0) === $asset);
        abort_if($used, 422, 'Gambar masih digunakan oleh halaman atau versi sebelumnya.');
        Storage::disk('documents')->delete([$row->path, ...array_values(array_filter(json_decode($row->variants ?? '{}', true), fn ($value, $key) => is_string($value) && ! str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_BOTH))]);
        DB::table('site_assets')->where('id', $asset)->delete();

        return back()->with('success', 'Gambar dihapus.');
    }

    public function vacancy(Request $request, Vacancy $vacancy): View
    {
        abort_unless(app(AccessPolicy::class)->vacancyIds($request->user(), 'job_order.manage')->where('vacancies.id', $vacancy->id)->exists(), 403);

        return view('site-admin.vacancy', ['vacancy' => $vacancy, 'assets' => DB::table('site_assets')->get(), 'funnels' => DB::table('application_funnels')->where('status', 'published')->where('vacancy_id', $vacancy->id)->get()]);
    }

    public function publishVacancy(Request $request, Vacancy $vacancy): RedirectResponse
    {
        abort_unless(app(AccessPolicy::class)->vacancyIds($request->user(), 'job_order.manage')->where('vacancies.id', $vacancy->id)->exists(), 403);
        $data = $request->validate(['sector' => 'required|string|max:255', 'employment_class' => 'required|in:FORMAL,NON_FORMAL', 'minimum_education' => 'required|string|max:100', 'salary_period' => 'required|in:HOUR,DAY,MONTH,CONTRACT', 'salary_max' => 'nullable|numeric|min:0|gte:salary_min', 'salary_min' => 'required|numeric|min:0', 'site_asset_id' => 'required|integer|exists:site_assets,id', 'funnel_id' => 'required|integer|exists:application_funnels,id', 'featured' => 'sometimes|boolean', 'responsibilities' => 'required|string|max:10000', 'public_notes' => 'nullable|string|max:5000', 'publish_at' => 'nullable|date', 'valid_through' => 'nullable|date', 'seo_title' => 'nullable|string|max:255', 'seo_description' => 'nullable|string|max:1000', 'status' => 'required|in:review,published,paused,closed,archived', 'hiring_organization' => 'nullable|string|max:255', 'hiring_organization_approved' => 'sometimes|boolean', 'approved' => 'accepted'], config('site.validation_messages'));
        $funnel = DB::table('application_funnels as f')->join('funnel_versions as v', 'v.id', '=', 'f.active_version_id')->where('f.id', $data['funnel_id'])->where('f.status', 'published')->whereColumn('v.funnel_id', 'f.id')->where('v.vacancy_id', $vacancy->id)->exists();
        if (! $funnel || ! $vacancy->currency || ! $vacancy->description || ! $vacancy->jobOrder?->country?->is_active || ! $vacancy->jobOrder?->company) {
            throw ValidationException::withMessages(['status' => 'Lengkapi gaji/mata uang, deskripsi, negara, mitra, dan funnel yang diterbitkan untuk lowongan ini.']);
        }
        foreach (['publish_at', 'valid_through'] as $dateField) {
            $data[$dateField] = ! empty($data[$dateField]) ? Carbon::parse($data[$dateField], 'Asia/Jakarta')->utc() : null;
        }
        if ($data['valid_through'] && ($data['valid_through']->isPast() || ($data['publish_at'] && $data['valid_through']->lte($data['publish_at'])))) {
            throw ValidationException::withMessages(['valid_through' => 'Batas lamaran harus setelah waktu sekarang dan jadwal publikasi (WIB).']);
        }
        unset($data['approved']);
        $data['salary'] = $data['salary_min'];
        unset($data['salary_min']);
        $vacancy->forceFill($data + ['featured' => $request->boolean('featured'), 'public_enabled' => true, 'hiring_organization_approved' => $request->boolean('hiring_organization_approved')])->save();

        return back()->with('success', 'Pengaturan publikasi disimpan. Data publik mengikuti lowongan ini langsung.');
    }

    public function health(Request $request): View|JsonResponse
    {
        $payload = app(WebsiteHealth::class)->report();
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return view('site-admin.health', $payload);
    }

    public function analytics(Request $request): View|StreamedResponse
    {
        $data = app(MarketingWorkspace::class)->website($request);
        if ($request->input('export') === 'csv') {
            return response()->streamDownload(function () use ($data): void {
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Halaman', 'Sesi', 'Periode mulai', 'Periode akhir']);
                foreach ($data['touches'] as $row) {
                    fputcsv($output, [preg_match('/^[=+@-]/', $row->path) ? "'".$row->path : $row->path, $row->total, $data['from'], $data['to']]);
                }
                fclose($output);
            }, 'analitik-website.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('site-admin.analytics', $data);
    }
}
