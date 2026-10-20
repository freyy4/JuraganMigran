<?php

namespace App\Services;

use App\Models\Country;
use App\Models\SitePage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteContent
{
    public function schema(string $slug): array
    {
        $page = SitePage::with('activeVersion')->where('slug', $slug)->where('status', 'published')->first();

        return $page?->activeVersion?->schema ?? (config('site.templates.'.$slug.'.schema') ?? abort(404));
    }

    public function navigation(): Collection
    {
        $published = SitePage::with('activeVersion')->where('status', 'published')->where('type', '!=', 'global')->get();
        $entries = collect(config('site.templates'))->filter(fn ($t) => $t['navigation'] ?? false)->map(fn ($t, $slug) => ['title' => $t['title'], 'path' => $slug === 'home' ? '/' : '/'.$slug, 'sequence' => $t['sequence'] ?? 0]);
        foreach ($published as $page) {
            $meta = $page->activeVersion?->schema['_page'] ?? [];
            if ($meta['navigation'] ?? false) {
                $entries->put($page->slug, ['title' => $meta['title'] ?? $page->title, 'path' => $this->path($page), 'sequence' => $meta['sequence'] ?? 0]);
            } elseif ($page->active_version_id) {
                $entries->forget($page->slug);
            }
        }

        return $entries->sortBy('sequence')->values();
    }

    public function path(SitePage $page): string
    {
        return $page->slug === 'home' ? '/' : ($page->type === 'guide' ? '/panduan/'.$page->slug : ($page->type === 'country' ? '/negara/'.$page->slug : '/'.$page->slug));
    }

    public function safePath(?string $path): bool
    {
        if (! is_string($path) || ! preg_match('~^/(?!/)[a-zA-Z0-9/_\-.]*$~', $path)) {
            return false;
        }
        if (in_array($path, ['/', '/lowongan', '/negara', '/portal/login', '/privacy'], true)) {
            return true;
        }
        if (str_starts_with($path, '/apply/')) {
            return DB::table('application_funnels')->where('slug', substr($path, 7))->where('status', 'published')->whereNotNull('active_version_id')->exists();
        }
        if (str_starts_with($path, '/lowongan/')) {
            return app(PublicVacancies::class)->query(false)->where('slug', substr($path, 10))->exists();
        }
        if (str_starts_with($path, '/negara/')) {
            return Country::where('is_active', true)->get()->contains(fn ($c) => strtolower($c->code) === substr($path, 8) || Str::slug($c->name) === substr($path, 8));
        }
        if (array_key_exists(ltrim($path, '/'), config('site.templates'))) {
            return ! str_starts_with($path, '/_');
        }

        return SitePage::where('status', 'published')->get()->contains(fn ($page) => $this->path($page) === $path);
    }

    public function validate(array $schema, bool $publishing = false): array
    {
        if (is_array($schema['seo'] ?? null) && array_key_exists('description', $schema['seo']) && $schema['seo']['description'] === null) {
            $schema['seo']['description'] = '';
        }
        Validator::make($schema, [
            'seo' => 'required|array', 'seo.title' => 'required|string|max:500', 'seo.description' => 'present|nullable|string|max:3000', 'seo.noindex' => 'sometimes|boolean', 'seo.nofollow' => 'sometimes|boolean', 'listing' => 'sometimes|array', 'listing.show_tips' => 'sometimes|boolean', 'listing.show_countries' => 'sometimes|boolean', 'seo.author' => 'nullable|string|max:255', 'seo.og_asset_id' => 'nullable|integer|exists:site_assets,id', 'seo.canonical_path' => 'nullable|string|max:255',
            'blocks.*.image_width' => 'nullable|integer|min:25|max:100', 'blocks.*.image_height' => 'nullable|integer|min:80|max:1000', 'blocks.*.image_radius' => 'nullable|integer|min:0|max:64', 'blocks.*.image_fit' => 'nullable|in:cover,contain',
            'blocks' => 'present|array|max:60', 'blocks.*' => 'array', 'blocks.*.title' => 'nullable|string|max:500', 'blocks.*.text' => 'nullable|string|max:10000', 'blocks.*.label' => 'nullable|string|max:255', 'blocks.*.path' => 'nullable|string|max:500', 'blocks.*.asset_id' => 'nullable|integer|exists:site_assets,id', 'blocks.*.country_id' => 'nullable|integer|exists:countries,id', 'blocks.*.limit' => 'nullable|integer|min:1|max:12', 'blocks.*.items' => 'sometimes|array|max:30', 'blocks.*.items.*.source' => 'nullable|string|max:1000', 'blocks.*.items.*.approved' => 'sometimes|boolean', 'blocks.*.items.*.consent' => 'sometimes|boolean',
        ], config('site.validation_messages'))->validate();
        $errors = [];
        if (! is_array($schema['seo'] ?? null) || ! is_string($schema['seo']['title'] ?? null) || ! is_string($schema['seo']['description'] ?? null)) {
            $errors['schema'] = 'Judul dan deskripsi SEO wajib diisi.';
        }
        if (! is_array($schema['blocks'] ?? null) || count($schema['blocks']) > 60) {
            $errors['blocks'] = 'Daftar blok tidak valid, maksimal 60.';
        }
        foreach (is_array($schema['blocks'] ?? null) ? $schema['blocks'] : [] as $index => $block) {
            if (! is_array($block) || ! array_key_exists($block['type'] ?? '', config('site.blocks'))) {
                $errors['blocks.'.$index] = 'Jenis blok tidak tersedia.';

                continue;
            }
            foreach (['title', 'text', 'label', 'path', 'video_url'] as $key) {
                if (isset($block[$key]) && (! is_string($block[$key]) || mb_strlen($block[$key]) > 10000)) {
                    $errors['blocks.'.$index] = 'Isi blok tidak valid.';
                }
            }
            if (isset($block['path']) && $block['path'] !== '' && (! is_string($block['path']) || ! preg_match('~^/(?!/)[a-zA-Z0-9/_\-.]*$~', $block['path']) || ($publishing && ! $this->safePath($block['path'])))) {
                $errors['blocks.'.$index] = 'Tautan tidak tersedia: '.$block['path'];
            }
            if (in_array($block['type'], ['hero', 'cta']) && $publishing && empty($block['path'])) {
                $errors['blocks.'.$index] = 'Tombol ajakan memerlukan tujuan.';
            }
            if (isset($block['asset_id']) && ! DB::table('site_assets')->where('id', $block['asset_id'])->exists()) {
                $errors['blocks.'.$index] = 'Gambar tidak tersedia.';
            }
            if ($block['type'] === 'image' && $publishing && empty($block['asset_id'])) {
                $errors['blocks.'.$index] = 'Pilih gambar.';
            }
            if ($block['type'] === 'video' && ! preg_match('~^https://(www\.youtube\.com/embed/[\w-]+|player\.vimeo\.com/video/\d+)$~', $block['video_url'] ?? '')) {
                $errors['blocks.'.$index] = 'Gunakan URL embed YouTube atau Vimeo.';
            }
            if (isset($block['items']) && (! is_array($block['items']) || count($block['items']) > 30)) {
                $errors['blocks.'.$index] = 'Maksimal 30 item.';

                continue;
            }
            foreach ($block['items'] ?? [] as $item) {
                if (! is_array($item) || ! is_string($item['title'] ?? '') || ! is_string($item['text'] ?? '')) {
                    $errors['blocks.'.$index] = 'Item harus berisi judul dan teks.';

                    continue;
                }
                if (in_array($block['type'], ['trust', 'stats', 'testimonials', 'partners']) && $publishing && (! ($item['approved'] ?? false) || empty($item['source']) || ($block['type'] === 'testimonials' && ! ($item['consent'] ?? false)))) {
                    $errors['blocks.'.$index] = 'Informasi kepercayaan memerlukan sumber dan persetujuan; cerita memerlukan persetujuan pemilik.';
                }
            }
        }
        if ($publishing && (empty($schema['seo']['title']) || empty($schema['seo']['description']))) {
            $errors['seo'] = 'Lengkapi metadata SEO.';
        }
        if (isset($schema['seo']['canonical_path']) && $schema['seo']['canonical_path'] !== '' && ! $this->safePath($schema['seo']['canonical_path'])) {
            $errors['seo'] = 'URL kanonis harus menuju halaman internal yang tersedia.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        foreach (['brand', 'whatsapp', 'address', 'hours', 'footer'] as $key) {
            if (isset($schema[$key]) && ! is_string($schema[$key])) {
                throw ValidationException::withMessages(['schema' => 'Konten global harus berupa teks.']);
            }
        }
        $allowed = ['listing', 'seo', 'blocks', 'brand', 'whatsapp', 'address', 'hours', 'footer'];
        $schema = array_intersect_key($schema, array_flip($allowed));
        $schema['seo']['description'] ??= '';
        $schema['seo'] = array_intersect_key($schema['seo'], array_flip(['title', 'description', 'noindex', 'nofollow', 'og_asset_id', 'canonical_path', 'author']));
        foreach ($schema['blocks'] as &$block) {
            $block = array_intersect_key($block, array_flip(['type', 'title', 'text', 'label', 'path', 'asset_id', 'video_url', 'items', 'limit', 'country_id', 'alignment', 'background', 'spacing', 'image_width', 'image_height', 'image_radius', 'image_fit']));
            foreach (['title', 'text', 'label'] as $key) {
                if (isset($block[$key])) {
                    $block[$key] = strip_tags($block[$key]);
                }
            }
            $block['limit'] = max(1, min(12, (int) ($block['limit'] ?? 4)));
            $block['alignment'] = in_array($block['alignment'] ?? '', ['left', 'center']) ? $block['alignment'] : 'left';
            $block['background'] = in_array($block['background'] ?? '', ['white', 'soft', 'green']) ? $block['background'] : 'white';
            foreach ($block['items'] ?? [] as $key => $item) {
                $block['items'][$key] = array_intersect_key($item, array_flip(['title', 'text', 'source', 'approved', 'consent', 'asset_id']));
            }
        }
        unset($block);

        return $schema;
    }

    public function publicSchema(array $schema): array
    {
        unset($schema['_page']);
        foreach ($schema['blocks'] ?? [] as $index => $block) {
            foreach ($block['items'] ?? [] as $key => $item) {
                unset($item['source'],$item['approved'],$item['consent']);
                $schema['blocks'][$index]['items'][$key] = $item;
            }
        }

        return $schema;
    }

    public function warnings(array $schema): array
    {
        $warnings = [];
        foreach ($schema['blocks'] as $block) {
            if (! empty($block['asset_id']) && DB::table('site_assets')->where('id', $block['asset_id'])->where('bytes', '>', 300000)->exists()) {
                $warnings[] = 'Gambar pada '.$block['type'].' lebih dari 300 KB.';
            }
            if (in_array($block['type'], ['featured_vacancies', 'latest_vacancies']) && ! app(PublicVacancies::class)->query()->exists()) {
                $warnings[] = 'Belum ada lowongan publik yang tersedia.';
            }
        }

        return array_unique($warnings);
    }
}
