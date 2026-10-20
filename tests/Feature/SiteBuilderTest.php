<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\SitePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_uses_standalone_layout_and_opens_in_new_tab(): void
    {
        $this->actingAs(User::factory()->create());
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $this->get(route('site-builder.edit', $page))->assertOk()
            ->assertSee('class="site-studio"', false)
            ->assertSee('id="site-artboard"', false)
            ->assertSee('id="studio-zoom"', false)
            ->assertDontSee('class="crm-sidebar"', false)
            ->assertDontSee('class="crm-topbar"', false)
            ->assertDontSee('marketing-workspace.css');
        $html = $this->get(route('site-builder.index'))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//a[@href="'.route('site-builder.edit', $page).'"]');
        $this->assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            $this->assertSame('_blank', $link->getAttribute('target'));
            $this->assertStringContainsString('noopener', $link->getAttribute('rel'));
        }
        $create = $xpath->query('//form[@action="'.route('site-builder.store').'"]')->item(0);
        $this->assertSame('_blank', $create->getAttribute('target'));
    }

    public function test_text_and_image_properties_are_saved_validated_and_rendered_without_editor_controls(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('documents');
        $this->post(route('site-assets.upload'), ['image' => UploadedFile::fake()->image('hero-edit.png', 800, 600), 'alt' => 'Gambar pilihan editor'])->assertSessionHasNoErrors();
        $asset = DB::table('site_assets')->first();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $schema = $page->draft_schema;
        $schema['blocks'][0] = [...$schema['blocks'][0], 'title' => 'Judul hasil edit langsung', 'asset_id' => $asset->id, 'image_width' => 75, 'image_height' => 240, 'image_radius' => 12, 'image_fit' => 'contain'];
        $this->postJson(route('site-builder.update', $page), ['title' => $page->title, 'schema' => $schema, 'updated_at' => $page->updated_at->toISOString()])->assertOk();
        $this->assertSame(75, $page->fresh()->draft_schema['blocks'][0]['image_width']);
        $this->get(route('site-builder.preview', $page))->assertOk()->assertSee('data-site-field="title"', false)->assertSee('width:75%;height:240px;border-radius:12px;object-fit:contain', false);
        $this->get('/')->assertDontSee('Judul hasil edit langsung');
        $invalid = $schema;
        $invalid['blocks'][0]['image_width'] = 200;
        $invalid['blocks'][0]['image_fit'] = 'url(javascript:alert(1))';
        $this->postJson(route('site-builder.update', $page), ['title' => $page->title, 'schema' => $invalid, 'updated_at' => $page->fresh()->updated_at->toISOString()])->assertUnprocessable();
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertSessionHasNoErrors();
        $this->get('/')->assertOk()->assertSee('Judul hasil edit langsung')->assertDontSee('data-site-field', false)->assertDontSee('data-site-image', false)->assertSee('width:75%', false);
    }

    public function test_draft_preview_publication_restore_and_permissions(): void
    {
        $owner = User::factory()->create();
        $page = SitePage::where('slug', 'home')->first();
        $this->actingAs($owner)->get(route('site-builder.index'))->assertOk();
        $schema = $page->draft_schema;
        $schema['blocks'][0]['title'] = 'Judul Baru Untuk Pengunjung';
        $this->postJson(route('site-builder.update', $page), ['title' => 'Beranda', 'schema' => $schema, 'updated_at' => $page->updated_at->toISOString()])->assertOk();
        $this->get('/')->assertDontSee('Judul Baru Untuk Pengunjung');
        $this->get(route('site-builder.preview', $page))->assertOk()->assertSee('Judul Baru Untuk Pengunjung')->assertSee('noindex');
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertSessionHasNoErrors();
        $this->get('/')->assertSee('Judul Baru Untuk Pengunjung');
        $this->assertDatabaseCount('site_page_versions', 1);
        $version = $page->versions()->first();
        $this->post(route('site-builder.restore', ['page' => $page, 'version' => $version->id]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('site_page_versions', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.published']);
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $this->actingAs($cs)->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertForbidden();
    }

    public function test_incomplete_seo_can_be_saved_in_draft_but_cannot_be_published(): void
    {
        $this->actingAs(User::factory()->create());
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $schema = $page->draft_schema;
        $schema['seo']['description'] = '';
        $this->postJson(route('site-builder.update', $page), ['title' => 'Beranda', 'schema' => $schema, 'updated_at' => $page->updated_at->toISOString()])->assertOk();
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertSessionHasErrors('seo');
        $this->assertDatabaseCount('site_page_versions', 0);
    }

    public function test_builder_live_preview_assets_and_publication_validation(): void
    {
        $user = User::factory()->create();
        $page = SitePage::where('slug', 'home')->first();
        $this->actingAs($user)->get(route('site-builder.edit', $page))->assertOk()->assertSee('site-builder.js');
        $this->get(route('site-health.index'))->assertOk();
        $this->get(route('site-analytics.index'))->assertOk();
        $schema = $page->draft_schema;
        $schema['blocks'][0]['title'] = 'Pratinjau Sementara';
        $this->post(route('site-builder.live-preview', $page), ['schema' => $schema])->assertOk()->assertSee('Pratinjau Sementara');
        $this->assertNotSame('Pratinjau Sementara', $page->fresh()->draft_schema['blocks'][0]['title']);
        $schema['blocks'][0]['path'] = 'javascript:alert(1)';
        $this->postJson(route('site-builder.update', $page), ['title' => 'Beranda', 'schema' => $schema, 'updated_at' => $page->updated_at->toISOString()])->assertUnprocessable();
        Storage::fake('documents');
        $this->post(route('site-assets.upload'), ['image' => UploadedFile::fake()->image('job.png', 1000, 600), 'alt' => 'Pekerjaan Uji'])->assertSessionHasNoErrors();
        $asset = DB::table('site_assets')->first();
        $this->assertCount(3, json_decode($asset->variants, true));
        $this->get(route('site.media', ['asset' => $asset->id, 'w' => 400]))->assertOk()->assertHeader('Content-Type', 'image/webp');
        auth()->logout();
        $this->get(route('site.media', $asset->id))->assertNotFound();
        $this->artisan('site:audit')->assertSuccessful();
        $this->assertDatabaseHas('website_health_snapshots', ['source' => 'content_audit']);
    }

    public function test_publishing_rejects_broken_cta_and_unapproved_claims(): void
    {
        $this->actingAs(User::factory()->create());
        $page = SitePage::where('slug', 'home')->first();
        $schema = $page->draft_schema;
        $schema['blocks'][0]['path'] = '/does-not-exist';
        $page->update(['draft_schema' => $schema]);
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertSessionHasErrors('blocks.0');
        $schema['blocks'] = [['type' => 'testimonials', 'items' => [['title' => 'Testimoni', 'text' => 'Cerita', 'approved' => true, 'source' => 'persetujuan', 'consent' => false]]]];
        $page->update(['draft_schema' => $schema]);
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertSessionHasErrors('blocks.0');
        $this->assertDatabaseCount('site_page_versions', 0);
    }

    public function test_approved_content_renders_and_internal_evidence_stays_private(): void
    {
        $this->actingAs(User::factory()->create());
        $page = SitePage::where('slug', 'testimoni')->first();
        $schema = $page->draft_schema;
        $schema['blocks'][0]['items'] = [['title' => 'Cerita Disetujui', 'text' => 'Proses saya didampingi tim.', 'approved' => true, 'consent' => true, 'source' => 'BUKTI-INTERNAL-RAHASIA']];
        $page->update(['draft_schema' => $schema]);
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertSessionHasNoErrors();
        $this->get('/testimoni')->assertOk()->assertSee('Cerita Disetujui')->assertDontSee('BUKTI-INTERNAL-RAHASIA');
        $this->getJson('/api/public/site/pages/testimoni')->assertOk()->assertDontSee('BUKTI-INTERNAL-RAHASIA')->assertJsonMissingPath('schema.blocks.0.items.0.source');
        $this->get('/api/public/site/pages/_globals')->assertNotFound();
        $this->get(route('site-builder.preview', SitePage::where('slug', 'lowongan')->first()))->assertOk()->assertSee('Lowongan Ditemukan');
    }

    public function test_country_content_requires_active_master_country_and_is_rendered_on_its_route(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('site-builder.store'), ['title' => 'Negara Uji', 'slug' => 'negara-tidak-ada', 'type' => 'country'])->assertSessionHasErrors('slug');
        $country = Country::factory()->create(['name' => 'Negara Uji', 'code' => 'ZU', 'is_active' => true]);
        $this->post(route('site-builder.store'), ['title' => 'Negara Uji', 'slug' => 'negara-uji', 'type' => 'country'])->assertSessionHasNoErrors();
        $page = SitePage::where('slug', 'negara-uji')->firstOrFail();
        $schema = $page->draft_schema;
        $schema['blocks'][0]['text'] = 'Informasi tujuan dari konten yang ditinjau.';
        $schema['seo']['description'] = 'Informasi dan lowongan untuk Negara Uji.';
        $page->update(['draft_schema' => $schema]);
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertSessionHasNoErrors();
        $this->get('/negara/negara-uji')->assertOk()->assertSee('Informasi tujuan dari konten yang ditinjau.');
        $this->get('/negara/zu')->assertRedirect(route('public.country', 'negara-uji'));
        $this->get('/negara/zu?utm_source=partner')->assertRedirect(route('public.country', 'negara-uji').'?utm_source=partner');
        $this->get('/sitemap-pages.xml')->assertOk()->assertSee('/negara/negara-uji');
    }

    public function test_marketing_cannot_edit_unowned_pages_or_publish_without_grant(): void
    {
        $marketing = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'marketing')->value('id')]);
        $page = SitePage::where('slug', 'home')->first();
        $this->actingAs($marketing)->get(route('site-builder.edit', $page))->assertForbidden();
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertForbidden();
    }

    public function test_slug_redirects_preserve_published_links_and_system_pages_cannot_be_archived(): void
    {
        $this->actingAs(User::factory()->create());
        $page = SitePage::create(['title' => 'Panduan A', 'slug' => 'panduan-a', 'owner_id' => auth()->id(), 'draft_schema' => ['seo' => ['title' => 'Panduan A', 'description' => 'Keterangan'], 'blocks' => [['type' => 'rich_text', 'text' => 'Konten yang ditinjau']]]]);
        $this->post(route('site-builder.publish', $page), ['acknowledge' => 1])->assertSessionHasNoErrors();
        $this->post(route('site-builder.slug', $page), ['slug' => 'panduan-b'])->assertSessionHasNoErrors();
        $this->get('/panduan-a?utm_source=google')->assertRedirect('/panduan-b?utm_source=google');
        $home = SitePage::where('slug', 'home')->first();
        $this->post(route('site-builder.archive', $home))->assertUnprocessable();
    }
}
