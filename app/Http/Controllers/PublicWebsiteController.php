<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\SitePage;
use App\Services\CsRouter;
use App\Services\PublicVacancies;
use App\Services\SiteAttribution;
use App\Services\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicWebsiteController extends Controller
{
    public function __construct(private PublicVacancies $vacancies, private SiteContent $content, private SiteAttribution $attribution) {}

    private function base(string $slug): array
    {
        return ['schema' => $this->content->schema($slug), 'globals' => $this->content->schema('_globals'), 'navigation' => $this->content->navigation(), 'preview' => false];
    }

    public function home(Request $request): View
    {
        $this->attribution->capture($request);
        $this->attribution->event($request, 'page_view');

        return view('site.page', $this->base('home') + ['slug' => 'home']);
    }

    public function listing(Request $request): View|JsonResponse
    {
        $data = $request->validate(['search' => 'nullable|string|max:100', 'country' => 'nullable|string|max:20', 'sector' => 'nullable|string|max:255', 'education' => 'nullable|string|max:100', 'class' => 'nullable|in:FORMAL,NON_FORMAL', 'sort' => 'nullable|in:newest', 'page' => 'nullable|integer|min:1'], config('site.validation_messages'));
        $query = $this->vacancies->query();
        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('sector', 'like', '%'.$search.'%')->orWhere('position_name', 'like', '%'.$search.'%')->orWhereHas('jobOrder.country', fn ($c) => $c->where('name', 'like', '%'.$search.'%')));
        }
        if ($data['country'] ?? null) {
            $query->whereHas('jobOrder.country', fn ($q) => $q->where('code', $data['country']));
        }
        foreach (['sector' => 'sector', 'education' => 'minimum_education', 'class' => 'employment_class'] as $key => $column) {
            if ($data[$key] ?? null) {
                $query->where($column, $data[$key]);
            }
        }
        $rows = $query->orderByDesc('published_at')->paginate(12)->withQueryString();
        $cards = $rows->through(fn ($v) => $this->vacancies->display($v));
        if ($request->routeIs('public-api.*')) {
            return response()->json($cards);
        }
        $this->attribution->capture($request);
        $this->attribution->event($request, $search ? 'vacancy_search' : 'page_view', ['results' => $cards->total()]);

        return view('site.listing', $this->base('lowongan') + ['cards' => $cards, 'countries' => Country::where('is_active', true)->orderBy('name')->get(), 'sectors' => $this->vacancies->query()->whereNotNull('sector')->distinct()->pluck('sector'), 'educations' => $this->vacancies->query()->whereNotNull('minimum_education')->distinct()->pluck('minimum_education')]);
    }

    public function detail(Request $request, string $slug): View|JsonResponse
    {
        $vacancy = $this->vacancies->query(false)->where('slug', $slug)->firstOrFail();
        $card = $this->vacancies->display($vacancy);
        if ($request->routeIs('public-api.*')) {
            return response()->json($card);
        }
        $this->attribution->capture($request, $vacancy->id);
        $this->attribution->event($request, 'job_detail_view', [], $vacancy->id);
        $schema = ['seo' => ['title' => $vacancy->seo_title ?: $vacancy->title.' · Juragan Migran', 'description' => $vacancy->seo_description ?: Str::limit(strip_tags($vacancy->description), 160), 'noindex' => ! $card['application_path'], 'og_asset_id' => $vacancy->site_asset_id], 'blocks' => []];
        $structured = null;
        if ($card['application_path'] && $vacancy->hiring_organization && $vacancy->hiring_organization_approved) {
            $structured = ['@context' => 'https://schema.org', '@type' => 'JobPosting', 'title' => $card['title'], 'description' => $card['description'], 'datePosted' => $card['published_at'], 'hiringOrganization' => ['@type' => 'Organization', 'name' => $vacancy->hiring_organization], 'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressCountry' => $card['country']['code']]], 'baseSalary' => ['@type' => 'MonetaryAmount', 'currency' => $card['salary']['currency'], 'value' => ['@type' => 'QuantitativeValue', 'value' => (float) $card['salary']['min'], 'unitText' => $card['salary']['period']]], 'url' => route('public.vacancy', $slug)];
            if ($card['salary']['max']) {
                unset($structured['baseSalary']['value']['value']);
                $structured['baseSalary']['value']['minValue'] = (float) $card['salary']['min'];
                $structured['baseSalary']['value']['maxValue'] = (float) $card['salary']['max'];
            }
            if ($card['valid_through']) {
                $structured['validThrough'] = $card['valid_through'];
            }
            if ($card['salary']['period'] === 'CONTRACT') {
                unset($structured['baseSalary']);
            }
        }

        return view('site.detail', $this->base('home') + ['card' => $card, 'detailSchema' => $schema, 'structured' => $structured, 'similar' => $this->vacancies->query()->whereKeyNot($vacancy->id)->where('sector', $vacancy->sector)->limit(3)->get()->map(fn ($v) => $this->vacancies->display($v))]);
    }

    public function country(Request $request, string $slug): View|JsonResponse|RedirectResponse
    {
        $country = Country::whereRaw('LOWER(code) = ?', [$slug])->where('is_active', true)->first();
        if (! $country) {
            $country = Country::where('is_active', true)->get()->first(fn ($c) => Str::slug($c->name) === $slug) ?? abort(404);
        }
        if (! $request->routeIs('public-api.*') && $slug !== Str::slug($country->name)) {
            return redirect(route('public.country', ['slug' => Str::slug($country->name)]).($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }
        $cards = $this->vacancies->query()->whereHas('jobOrder', fn ($q) => $q->where('country_id', $country->id))->paginate(12)->withQueryString()->through(fn ($v) => $this->vacancies->display($v));
        if ($request->routeIs('public-api.*')) {
            return response()->json(['country' => ['code' => $country->code, 'name' => $country->name], 'vacancies' => $cards]);
        }

        $page = SitePage::with('activeVersion')->where('type', 'country')->where('slug', Str::slug($country->name))->where('status', 'published')->first();
        $schema = $page?->activeVersion?->schema ?? ['seo' => ['title' => 'Kerja di '.$country->name.' · Juragan Migran', 'description' => 'Lowongan aktif untuk tujuan '.$country->name], 'blocks' => []];
        $base = $this->base('negara');
        $base['schema'] = $schema;

        return view('site.country', $base + ['country' => $country, 'cards' => $cards]);
    }

    public function page(Request $request, string $slug): View|JsonResponse|RedirectResponse
    {
        $path = '/'.$request->path();
        $redirect = DB::table('site_redirects')->where('source', $path)->first();
        if ($redirect) {
            return redirect($redirect->target.($request->getQueryString() ? '?'.$request->getQueryString() : ''), $redirect->status);
        }
        $page = SitePage::with('activeVersion')->where('slug', $slug)->where('status', 'published')->where('type', '!=', 'global')->first();
        abort_unless($page || (array_key_exists($slug, config('site.templates')) && $slug !== '_globals'), 404);
        if ($request->routeIs('public.guide', 'public-api.guide')) {
            abort_unless($page && $page->type === 'guide', 404);
        }
        if ($request->routeIs('public-api.*')) {
            return response()->json(['slug' => $slug, 'schema' => $this->content->publicSchema($this->content->schema($slug))]);
        }
        $this->attribution->capture($request);
        $this->attribution->event($request, 'page_view');

        $base = $this->base($slug);
        $article = null;
        if ($page?->type === 'guide' && $page->activeVersion) {
            $article = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $base['schema']['seo']['title'], 'description' => $base['schema']['seo']['description'], 'dateModified' => $page->activeVersion->reviewed_at?->toIso8601String(), 'datePublished' => $page->activeVersion->published_at?->toIso8601String()];
            if (! empty($base['schema']['seo']['author'])) {
                $article['author'] = ['@type' => 'Person', 'name' => $base['schema']['seo']['author']];
            }
        }

        return view('site.page', $base + ['slug' => $slug, 'reviewed' => $page?->activeVersion?->reviewed_at, 'structured' => $article]);
    }

    public function countries(): JsonResponse
    {
        return response()->json(Country::where('is_active', true)->get(['code', 'name']));
    }

    public function guides(): JsonResponse
    {
        return response()->json(SitePage::with('activeVersion')->where('type', 'guide')->where('status', 'published')->get()->map(fn ($p) => ['slug' => $p->slug, 'title' => $p->activeVersion?->schema['seo']['title'], 'description' => $p->activeVersion?->schema['seo']['description'], 'reviewed_at' => $p->activeVersion?->reviewed_at]));
    }

    public function navigation(): JsonResponse
    {
        return response()->json($this->content->navigation());
    }

    public function event(Request $request): JsonResponse
    {
        $data = $request->validate(['event' => 'required|in:vacancy_impression,vacancy_click,filter_apply,apply_click,web_vitals', 'vacancy_slug' => 'nullable|string|max:255', 'metrics' => 'nullable|array:lcp,cls,inp,ttfb', 'metrics.lcp' => 'nullable|numeric|min:0|max:120000', 'metrics.cls' => 'nullable|numeric|min:0|max:100', 'metrics.inp' => 'nullable|numeric|min:0|max:120000', 'metrics.ttfb' => 'nullable|numeric|min:0|max:120000'], config('site.validation_messages'));
        $vacancy = ! empty($data['vacancy_slug']) ? $this->vacancies->query(false)->where('slug', $data['vacancy_slug'])->value('id') : null;
        $metrics = $data['metrics'] ?? [];
        if ($data['event'] === 'web_vitals' && count($metrics) === 0) {
            return response()->json(['ok' => true]);
        }
        $this->attribution->event($request, $data['event'], $metrics, $vacancy);
        if ($data['event'] === 'web_vitals') {
            DB::table('website_health_snapshots')->insert(['path' => substr(parse_url($request->headers->get('referer', ''), PHP_URL_PATH) ?: '/', 0, 500), 'source' => 'browser', 'metrics' => json_encode($data['metrics'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function contact(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'whatsapp' => 'required|string|regex:/^\+?[0-9][0-9 ()-]{7,28}$/|max:30', 'message' => 'required|string|max:3000', 'consent' => 'accepted', 'idempotency_key' => 'required|uuid', 'website' => 'prohibited'], config('site.validation_messages'));
        abort_unless($request->session()->get('site_contact_key') === $data['idempotency_key'], 419);
        DB::transaction(function () use ($request, $data): void {
            $phone = CsRouter::phone($data['whatsapp']);
            DB::table('acquisition_leads')->insertOrIgnore(['public_token' => (string) Str::uuid(), 'name' => $data['name'], 'normalized_phone' => $phone, 'status' => 'new', 'created_at' => now(), 'updated_at' => now()]);
            $lead = DB::table('acquisition_leads')->where('normalized_phone', $phone)->lockForUpdate()->first();
            DB::table('site_contact_requests')->insertOrIgnore(['lead_id' => $lead->id, 'idempotency_key' => $data['idempotency_key'], 'message' => Crypt::encryptString($data['message']), 'consent_version' => 'JM-PRIVACY-1', 'created_at' => now(), 'updated_at' => now()]);
            $this->attribution->link($request, $lead->id);
        });

        $request->session()->put('site_contact_saved', true);

        return back()->with('success', 'Permintaan Anda tersimpan. Tim akan meninjaunya.');
    }

    public function contactWhatsapp(Request $request): RedirectResponse
    {
        abort_unless($request->session()->get('site_contact_saved'), 403);
        $phone = preg_replace('/\D/', '', $this->content->schema('_globals')['whatsapp'] ?? '');
        abort_unless(preg_match('/^[1-9][0-9]{8,14}$/', $phone), 404);
        $this->attribution->event($request, 'whatsapp_click');

        return redirect()->away('https://wa.me/'.$phone);
    }

    public function media(Request $request, int $asset): BinaryFileResponse
    {
        $row = DB::table('site_assets')->find($asset) ?? abort(404);
        $allowed = $request->user()?->hasPermission('site.assets') || $request->user()?->hasPermission('site.edit') || $this->vacancies->query(false)->where('site_asset_id', $asset)->exists();
        if (! $allowed) {
            $allowed = SitePage::with('activeVersion')->where('status', 'published')->get()->contains(fn ($page) => in_array($asset, array_map('intval', array_column($page->activeVersion?->schema['blocks'] ?? [], 'asset_id')), true) || (int) ($page->activeVersion?->schema['seo']['og_asset_id'] ?? 0) === $asset);
        }
        abort_unless($allowed, 404);
        $variants = json_decode($row->variants ?? '{}', true);
        $variant = $variants[(string) $request->integer('w')] ?? null;

        return response()->file(Storage::disk('documents')->path($variant ?: $row->path), ['Content-Type' => $variant ? 'image/webp' : $row->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }

    public function sitemap(Request $request): Response
    {
        $kind = $request->route()->defaults['kind'] ?? 'index';
        if ($kind === 'index') {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.collect(['sitemap-pages.xml', 'sitemap-lowongan.xml'])->map(fn ($path) => '<sitemap><loc>'.htmlspecialchars(url($path), ENT_XML1).'</loc></sitemap>')->implode('').'</sitemapindex>';
        } else {
            $urls = $kind === 'vacancies' ? $this->vacancies->query()->get()->map(fn ($v) => route('public.vacancy', $v->slug)) : SitePage::with('activeVersion')->where('status', 'published')->where('type', '!=', 'global')->get()->filter(fn ($p) => ! ($p->activeVersion?->schema['seo']['noindex'] ?? false))->map(fn ($p) => url($this->content->path($p)));
            if ($kind === 'pages') {
                $urls = $urls->merge(Country::where('is_active', true)->get()->map(fn ($country) => route('public.country', Str::slug($country->name))));
                $urls = $urls->merge(collect(config('site.templates'))->keys()->reject(fn ($slug) => $slug === '_globals')->map(fn ($slug) => url($slug === 'home' ? '/' : '/'.$slug)));
            }
            $blocked = SitePage::with('activeVersion')->where('status', 'published')->get()->filter(fn ($p) => $p->activeVersion?->schema['seo']['noindex'] ?? false)->map(fn ($p) => url($this->content->path($p)));
            $urls = $urls->reject(fn ($url) => $blocked->contains($url));
            $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls->unique()->map(fn ($url) => '<url><loc>'.htmlspecialchars($url, ENT_XML1).'</loc></url>')->implode('').'</urlset>';
        }

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow: /admin/\nDisallow: /portal\nDisallow: /api/\nDisallow: /apply/\nSitemap: ".url('/sitemap.xml')."\n", 200, ['Content-Type' => 'text/plain']);
    }
}
