<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vacancy;
use App\Services\FunnelSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    public function publishedVacancy(): Vacancy
    {
        $vacancy = Vacancy::factory()->create(['status' => 'published', 'title' => 'Pekerja Restoran', 'salary' => 180000, 'currency' => 'JPY', 'description' => 'Menyiapkan makanan sesuai prosedur.']);
        $asset = DB::table('site_assets')->insertGetId(['path' => 'site-assets/test.png', 'alt' => 'Pekerjaan restoran', 'mime' => 'image/png', 'bytes' => 1200, 'width' => 400, 'height' => 240, 'created_at' => now(), 'updated_at' => now()]);
        $funnel = DB::table('application_funnels')->insertGetId(['name' => 'Restoran', 'slug' => 'lamar-restoran', 'vacancy_id' => $vacancy->id, 'status' => 'published', 'draft_schema' => json_encode(FunnelSchema::defaults()), 'created_at' => now(), 'updated_at' => now()]);
        $version = DB::table('funnel_versions')->insertGetId(['funnel_id' => $funnel, 'vacancy_id' => $vacancy->id, 'version' => 1, 'schema_snapshot' => json_encode(FunnelSchema::defaults()), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('application_funnels')->where('id', $funnel)->update(['active_version_id' => $version]);
        $vacancy->forceFill(['site_asset_id' => $asset, 'funnel_id' => $funnel, 'public_enabled' => true, 'sector' => 'Restoran', 'employment_class' => 'FORMAL', 'minimum_education' => 'SMP', 'responsibilities' => 'Melayani pelanggan', 'featured' => true, 'hiring_organization' => 'Restoran Uji', 'hiring_organization_approved' => true])->save();

        return $vacancy;
    }

    private function visualArtifact(string $html, string $name): void
    {
        $html = preg_replace_callback('~https?://[^/" ]+/(css|js)/([^" ]+)~', fn ($m) => 'file://'.public_path($m[1].'/'.$m[2]), $html);
        $html = preg_replace('~https?://[^/" ]+/site-media/[^" ]+~', 'file://'.public_path('images/portal-icon-512.png'), $html);
        $html = preg_replace('~<script[^>]*src="[^" ]*site.js[^" ]*"[^>]*></script>~', '', $html);
        file_put_contents('/tmp/jm-v86-'.$name.'.html', $html);
    }

    public function test_public_content_is_server_rendered_safe_and_synchronized(): void
    {
        $vacancy = $this->publishedVacancy();
        $home = $this->get('/');
        if (getenv('JM_SITE_VISUAL_QA')) {
            $this->visualArtifact($home->getContent(), 'home');
            $this->visualArtifact($this->get('/lowongan')->getContent(), 'listing');
        }
        $home->assertOk()->assertSee('Pekerjaan Bagus')->assertSee('Pekerja Restoran')->assertDontSee('site-builder.js');
        $this->get('/lowongan')->assertOk()->assertSee('JPY')->assertSee('180.000');
        $this->getJson('/api/public/vacancies/'.$vacancy->slug)->assertOk()->assertJsonPath('title', 'Pekerja Restoran')->assertJsonMissingPath('job_order_id')->assertJsonMissingPath('company_id');
        $this->get('/lowongan/'.$vacancy->slug)->assertOk()->assertSee('JobPosting')->assertSee('Lamar Sekarang');
        $vacancy->update(['title' => 'Judul Diperbarui']);
        $this->get('/lowongan')->assertSee('Judul Diperbarui');
        $vacancy->update(['status' => 'closed']);
        $this->get('/lowongan')->assertDontSee('Judul Diperbarui');
        $this->get('/lowongan/'.$vacancy->slug)->assertOk()->assertSee('Lowongan telah ditutup')->assertDontSee('Lamar Sekarang')->assertDontSee('JobPosting');
        $this->get('/apply/lamar-restoran')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee($vacancy->slug);
    }

    public function test_filters_unpublished_funnel_expiry_and_future_publication(): void
    {
        $vacancy = $this->publishedVacancy();
        $this->get('/lowongan?search=Restoran&education=SMP')->assertSee('Pekerja Restoran');
        $this->get('/lowongan?search=tidak-cocok')->assertSee('Belum ada lowongan yang cocok');
        $vacancy->forceFill(['valid_through' => now()->subMinute()])->save();
        $this->getJson('/api/public/vacancies')->assertJsonPath('total', 0);
        $this->get('/apply/lamar-restoran')->assertNotFound();
        $vacancy->forceFill(['valid_through' => null, 'publish_at' => now()->addDay()])->save();
        $this->get('/lowongan/'.$vacancy->slug)->assertNotFound();
        $vacancy->forceFill(['publish_at' => null])->save();
        DB::table('application_funnels')->where('id', $vacancy->funnel_id)->update(['status' => 'draft']);
        $this->getJson('/api/public/vacancies')->assertJsonPath('total', 0);
    }

    public function test_vacancy_publication_deadline_is_validated_in_wib(): void
    {
        $vacancy = $this->publishedVacancy();
        $this->actingAs(User::factory()->create());
        $payload = ['sector' => 'Restoran', 'employment_class' => 'FORMAL', 'minimum_education' => 'SMP', 'salary_period' => 'MONTH', 'salary_min' => 180000, 'site_asset_id' => $vacancy->site_asset_id, 'funnel_id' => $vacancy->funnel_id, 'responsibilities' => 'Melayani pelanggan', 'status' => 'published', 'approved' => 1];
        $this->post(route('site-vacancies.publish', $vacancy), $payload + ['valid_through' => now('Asia/Jakarta')->subHour()->format('Y-m-d\TH:i')])->assertSessionHasErrors('valid_through');
        $deadline = now('Asia/Jakarta')->addDays(2)->startOfMinute();
        $this->post(route('site-vacancies.publish', $vacancy), $payload + ['valid_through' => $deadline->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame($deadline->utc()->toDateTimeString(), $vacancy->fresh()->valid_through->toDateTimeString());
    }

    public function test_first_last_touch_survives_funnel_submission_and_links_to_lead(): void
    {
        $vacancy = $this->publishedVacancy();
        $this->get('/?utm_source=google&utm_medium=cpc')->assertOk();
        $this->get('/lowongan?utm_source=instagram&utm_medium=paid_social')->assertOk();
        $this->withHeader('referer', url('/lowongan'))->get('/apply/lamar-restoran')->assertOk();
        $entry = session('funnel_token.'.$vacancy->funnel_id);
        $this->post('/apply/lamar-restoran', ['idempotency_key' => $entry['token'], 'version_id' => $entry['version'], 'answers' => ['name' => 'Siti', 'whatsapp' => '081234567890'], 'consent' => 1])->assertSessionHasNoErrors();
        $submission = DB::table('lead_submissions')->first();
        $attribution = json_decode($submission->attribution, true);
        $this->assertSame('PAID_SEARCH', $attribution['first_touch']['channel']);
        $this->assertSame('PAID_SOCIAL', $attribution['last_touch']['channel']);
        $this->assertDatabaseCount('site_attribution_touches', 2);
        $this->assertSame(2, DB::table('site_attribution_touches')->where('lead_id', $submission->lead_id)->count());
        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_analytics_rejects_sensitive_metrics_and_records_the_referring_page(): void
    {
        $this->postJson('/api/public/events', ['event' => 'web_vitals', 'metrics' => ['lcp' => 1200, 'health' => 'DATA-RAHASIA']])->assertUnprocessable();
        $this->assertDatabaseCount('website_health_snapshots', 0);
        $this->withHeader('referer', url('/lowongan?search=restoran'))->postJson('/api/public/events', ['event' => 'web_vitals', 'metrics' => ['lcp' => 1200, 'cls' => 0.01]])->assertOk();
        $this->assertDatabaseHas('site_events', ['event' => 'web_vitals', 'path' => '/lowongan']);
        $this->assertDatabaseHas('website_health_snapshots', ['path' => '/lowongan', 'source' => 'browser']);
    }

    public function test_public_contact_is_structured_idempotent_and_encrypted(): void
    {
        $this->get('/hubungi-kami')->assertOk();
        $payload = ['name' => 'Siti', 'whatsapp' => '081234567890', 'message' => 'Saya ingin dibantu memilih pekerjaan.', 'consent' => 1, 'idempotency_key' => session('site_contact_key')];
        $this->post('/hubungi-kami', $payload)->assertSessionHasNoErrors();
        $this->post('/hubungi-kami', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('site_contact_requests', 1);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertStringNotContainsString($payload['message'], DB::table('site_contact_requests')->value('message'));
    }
}
