<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\SitePage;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketingWebsiteWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_search_is_bounded_scoped_and_recent_ids_are_reauthorized(): void
    {
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $mine = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $other = Application::factory()->create();
        $mine->candidate->person->update(['full_name' => 'Peserta Berizin']);
        $other->candidate->person->update(['full_name' => 'Peserta Rahasia']);
        $this->actingAs($cs)->getJson(route('referrals.candidates', ['search' => 'Peserta']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->candidate_id)->assertDontSee('Peserta Rahasia');
        $this->getJson(route('referrals.candidates', ['ids' => [$other->candidate_id]]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('referrals.candidates', ['search' => 'P']))->assertOk()->assertJsonCount(0, 'data');
        $this->post(route('referrals.store'), ['candidate_id' => $other->candidate_id, 'source' => 'Mitra', 'referral_code' => 'ABC'])->assertForbidden();
        $this->actingAs(User::factory()->create());
        Candidate::factory()->count(20)->create()->each(fn ($candidate) => $candidate->person->update(['full_name' => 'Peserta Tambahan']));
        $this->getJson(route('referrals.candidates', ['search' => 'Peserta']))->assertOk()->assertJsonCount(15, 'data')->assertDontSee('whatsapp')->assertDontSee('nik');
    }

    public function test_duplicate_referral_requires_confirmation_and_keeps_original_attribution(): void
    {
        $this->actingAs(User::factory()->create());
        $candidate = Candidate::factory()->create();
        $data = ['candidate_id' => $candidate->id, 'source' => 'Mitra', 'referral_code' => 'REF-X'];
        $this->post(route('referrals.store'), $data)->assertSessionHasNoErrors();
        $first = DB::table('lead_sources')->first();
        $this->post(route('referrals.store'), $data)->assertSessionHasErrors('confirm_duplicate');
        $this->assertDatabaseCount('lead_sources', 1);
        $this->post(route('referrals.store'), $data + ['confirm_duplicate' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lead_sources', 2);
        $this->assertSame($first->captured_at, DB::table('lead_sources')->where('id', $first->id)->value('captured_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.source.created']);
    }

    public function test_source_explorer_paginates_ten_thousand_rows_and_filters_server_side(): void
    {
        $this->actingAs(User::factory()->create());
        $candidate = Candidate::factory()->create();
        $batch = array_fill(0, 500, ['candidate_id' => $candidate->id, 'source' => 'Partner', 'medium' => 'referral', 'captured_at' => now()]);
        for ($i = 0; $i < 20; $i++) {
            DB::table('lead_sources')->insert($batch);
        }
        $this->get(route('lead-sources.index'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 10000 && $rows->count() === 50)->assertViewHas('stats', fn ($stats) => $stats['candidates'] === 1);
        $this->get(route('lead-sources.index', ['source' => 'Missing']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(route('lead-sources.index', ['page' => 2, 'per_page' => 100]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->first()->id === 9900 && $rows->count() === 100);
        $this->get(route('lead-sources.index', ['per_page' => 10000]))->assertSessionHasErrors('per_page');
    }

    public function test_site_draft_token_prevents_same_second_overwrites(): void
    {
        $this->actingAs(User::factory()->create());
        $page = SitePage::where('slug', 'home')->firstOrFail();
        $token = hash('sha256', json_encode([$page->title, $page->draft_schema, $page->in_navigation, $page->sequence, $page->active_version_id, $page->status]));
        $data = ['title' => 'Judul Versi A', 'schema' => $page->draft_schema, 'updated_at' => $page->updated_at->toISOString(), 'draft_token' => $token];
        $saved = $this->postJson(route('site-builder.update', $page), $data)->assertOk();
        $this->postJson(route('site-builder.update', $page), array_replace($data, ['title' => 'Judul Versi B', 'updated_at' => $saved->json('updated_at')]))->assertConflict();
        $this->assertSame('Judul Versi A', $page->fresh()->title);
        $this->get(route('site-builder.edit', $page))->assertOk()->assertSee('site-palette')->assertSee('site-undo')->assertDontSee('id="site-block-type"', false);
    }

    public function test_duplicate_asset_warns_without_leaving_extra_files(): void
    {
        Storage::fake('documents');
        $this->actingAs(User::factory()->create());
        $file = UploadedFile::fake()->image('test.png', 800, 400);
        $this->postJson(route('site-assets.upload'), ['image' => $file, 'alt' => 'Lanskap'])->assertOk();
        $paths = Storage::disk('documents')->allFiles();
        $this->postJson(route('site-assets.upload'), ['image' => $file, 'alt' => 'Salinan'])->assertUnprocessable();
        $this->assertDatabaseCount('site_assets', 1);
        $this->assertSame($paths, Storage::disk('documents')->allFiles());
        $this->getJson(route('site-builder.asset-picker', ['orientation' => 'portrait']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('site-builder.asset-picker', ['orientation' => 'landscape']))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_website_metrics_use_unique_sessions_filters_and_do_not_invent_visitors(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['a', 'a', 'b'] as $session) {
            DB::table('site_attribution_touches')->insert(['anonymous_session_id' => $session, 'path' => '/lowongan', 'channel' => 'social', 'device_class' => 'mobile', 'context' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->get(route('site-analytics.index', ['device' => 'mobile']))->assertOk()->assertViewHas('sessions', 2)->assertSee('Identitas pengunjung belum diukur');
        $this->get(route('site-analytics.index', ['device' => 'desktop']))->assertOk()->assertViewHas('sessions', 0);
        $this->get(route('site-analytics.index', ['export' => 'csv']))->assertOk()->assertDownload('analitik-website.csv');
    }

    public function test_registration_funnel_link_is_validated_and_public_form_preserves_attribution(): void
    {
        $this->actingAs(User::factory()->create());
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $this->post(route('funnels.store'), ['name' => 'Form Publik', 'slug' => 'form-publik', 'vacancy_id' => $vacancy->id])->assertSessionHasNoErrors();
        $funnel = DB::table('application_funnels')->first();
        $data = ['title' => 'Registrasi', 'slug' => 'registrasi', 'vacancy_id' => $vacancy->id, 'funnel_id' => $funnel->id, 'status' => 'published'];
        $this->post(route('landing-pages.store'), $data)->assertSessionHasErrors('funnel_id');
        $this->post(route('funnels.publish', $funnel->id), ['acknowledge' => 1])->assertSessionHasNoErrors();
        $this->post(route('landing-pages.store'), $data)->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('registration.show', ['slug' => 'registrasi', 'utm_source' => 'mitra']))->assertOk()->assertViewIs('funnels.public')->assertDontSee('marketing-workspace.js');
        $attribution = session('funnel_attribution.'.$funnel->id);
        $this->assertSame('mitra', $attribution['utm_source']);
        $this->assertSame(DB::table('landing_pages')->value('id'), $attribution['landing_page_id']);
    }

    public function test_aggregate_only_analytics_does_not_expose_participant_rows_or_remote_search(): void
    {
        $candidate = Candidate::factory()->create();
        $candidate->person->update(['full_name' => 'Nama Privat Peserta']);
        DB::table('lead_sources')->insert(['candidate_id' => $candidate->id, 'source' => 'Mitra', 'captured_at' => now()]);
        $manager = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'management')->value('id')]);
        $this->actingAs($manager)->get(route('marketing-analytics.index'))->assertOk()->assertDontSee('Nama Privat Peserta')->assertViewHas('rows', fn ($rows) => $rows->total() === 0)->assertViewHas('stats', fn ($stats) => $stats['records'] === 1);
        $this->getJson(route('referrals.candidates', ['search' => 'Nama']))->assertForbidden();
        $this->get(route('lead-sources.index', ['export' => 'csv']))->assertForbidden();
    }

    public function test_source_export_is_scoped_and_protects_spreadsheet_cells(): void
    {
        $this->actingAs(User::factory()->create());
        $candidate = Candidate::factory()->create();
        DB::table('lead_sources')->insert(['candidate_id' => $candidate->id, 'source' => '=HYPERLINK(test)', 'captured_at' => now()]);
        $response = $this->get(route('lead-sources.index', ['export' => 'csv']))->assertOk()->assertDownload('sumber-pendaftar.csv');
        $this->assertStringContainsString("'=HYPERLINK(test)", $response->streamedContent());
        $this->assertStringNotContainsString('nik', $response->streamedContent());
    }

    public function test_workspace_screens_render_and_can_produce_synthetic_browser_fixtures(): void
    {
        $this->actingAs(User::factory()->create());
        $candidate = Candidate::factory()->create();
        DB::table('lead_sources')->insert(['candidate_id' => $candidate->id, 'source' => 'Mitra Lokal', 'referral_code' => 'REF-QA', 'captured_at' => now()]);
        $vacancy = Vacancy::factory()->create();
        $this->post(route('funnels.store'), ['name' => 'Funnel QA', 'slug' => 'funnel-qa', 'vacancy_id' => $vacancy->id]);
        $funnel = DB::table('application_funnels')->first();
        $page = SitePage::where('slug', 'home')->firstOrFail();
        if (getenv('MARKETING_QA_FIXTURE')) {
            $preview = $this->get(route('site-builder.preview', $page))->assertOk()->getContent();
            file_put_contents('/tmp/jm-v811-preview.html', preg_replace('~https?://[^/]+/(css|js)/~', 'file:///var/www/juraganmigran/public/$1/', $preview));
        }
        $screens = ['pages' => route('site-builder.index'), 'editor' => route('site-builder.edit', $page), 'assets' => route('site-assets.index'), 'website-analytics' => route('site-analytics.index'), 'funnels' => route('funnels.index'), 'funnel-editor' => route('funnels.builder', $funnel->id), 'campaigns' => route('campaigns.index'), 'registration' => route('landing-pages.create'), 'sources' => route('lead-sources.index'), 'referrals' => route('referrals.index'), 'marketing-analytics' => route('marketing-analytics.index')];
        foreach ($screens as $name => $url) {
            $response = $this->get($url)->assertOk()->assertSee(in_array($name, ['editor', 'funnel-editor']) ? 'site-builder.css' : 'marketing-workspace.css');
            if (getenv('MARKETING_QA_FIXTURE')) {
                $html = preg_replace('~https?://[^/]+/(css|js)/~', 'file:///var/www/juraganmigran/public/$1/', $response->getContent());
                $html = preg_replace('~(<iframe id="site-canvas"[^>]*src=")[^"]+~', '$1file:///tmp/jm-v811-preview.html', $html);
                file_put_contents('/tmp/jm-v811-'.$name.'.html', $html);
            }
        }
    }
}
