<?php

namespace App\Services;

use App\Models\SitePage;
use App\Models\Vacancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebsiteHealth
{
    public function __construct(private SiteContent $content) {}

    public function report(): array
    {
        $issues = [];
        foreach (SitePage::with('activeVersion')->where('status', 'published')->get() as $page) {
            $schema = $page->activeVersion?->schema ?? [];
            $path = $this->content->path($page);
            if (! $page->activeVersion?->reviewed_at || $page->activeVersion->reviewed_at->lt(now()->subDays(180))) {
                $issues[] = ['path' => $path, 'message' => 'Konten perlu ditinjau kembali.'];
            }
            if (empty($schema['seo']['title']) || empty($schema['seo']['description'])) {
                $issues[] = ['path' => $path, 'message' => 'Metadata SEO belum lengkap.'];
            }
            try {
                $this->content->validate($schema, true);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $errors) {
                    $issues[] = ['path' => $path, 'message' => implode(' ', $errors)];
                }
            }
            foreach ($this->content->warnings($schema) as $warning) {
                $issues[] = ['path' => $path, 'message' => $warning];
            }
        }
        foreach (Vacancy::where('status', 'published')->get() as $vacancy) {
            if (! app(PublicVacancies::class)->query()->whereKey($vacancy->id)->exists()) {
                $issues[] = ['path' => route('site-vacancies.edit', $vacancy), 'message' => 'Lowongan '.$vacancy->code.' belum memenuhi publikasi atau masa berlaku/funnel tidak tersedia.'];
            }
        }
        $titles = [];
        foreach (SitePage::with('activeVersion')->where('status', 'published')->where('type', '!=', 'global')->get() as $page) {
            $title = $page->activeVersion?->schema['seo']['title'] ?? '';
            if (isset($titles[$title])) {
                $issues[] = ['path' => $this->content->path($page), 'message' => 'Judul SEO sama dengan '.$titles[$title]];
            }
            $titles[$title] = $this->content->path($page);
            if (in_array($page->slug, ['home', 'lowongan']) && ($page->activeVersion?->schema['seo']['noindex'] ?? false)) {
                $issues[] = ['path' => $this->content->path($page), 'message' => 'Halaman utama disembunyikan dari indeks mesin pencari.'];
            }
        }
        foreach (DB::table('site_assets')->where('bytes', '>', 300000)->get() as $asset) {
            $issues[] = ['path' => route('site-assets.index'), 'message' => 'Aset #'.$asset->id.' berukuran lebih dari 300 KB. Gunakan varian WebP.'];
        }
        $metrics = DB::table('website_health_snapshots')->where('source', 'browser')->latest()->limit(50)->get();

        return ['issues' => $issues, 'metrics' => $metrics, 'published' => app(PublicVacancies::class)->query()->count(), 'drafts' => SitePage::where('status', 'draft')->count(), 'redirects' => DB::table('site_redirects')->get()];
    }
}
