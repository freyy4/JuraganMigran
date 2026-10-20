<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\JobOrder;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecruitmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'Asia/Jakarta')->utc());
    }

    private function campaign(Vacancy $vacancy, array $extra = []): int
    {
        return DB::table('campaigns')->insertGetId(['vacancy_id' => $vacancy->id, 'code' => fake()->unique()->bothify('CMP-########'), 'name' => 'Kampanye Perekrutan', 'channel' => 'Instagram', 'budget' => 1000, 'status' => 'active', 'utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => 'perekrutan', 'created_at' => now(), 'updated_at' => now(), ...$extra]);
    }

    private function page(Vacancy $vacancy, array $extra = []): int
    {
        return DB::table('landing_pages')->insertGetId(['vacancy_id' => $vacancy->id, 'title' => 'Pendaftaran Lowongan', 'slug' => fake()->unique()->slug(), 'content' => 'Isi halaman pendaftaran', 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(), ...$extra]);
    }

    public function test_campaign_create_edit_search_and_validation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $vacancy = Vacancy::factory()->create();
        $this->get(route('campaigns.create'))->assertOk()->assertSee('Kanal');
        $data = ['code' => 'CMP-001', 'name' => 'Kampanye Khusus', 'channel' => 'Facebook', 'budget' => '1234.50', 'status' => 'draft', 'owner_id' => $user->id, 'vacancy_id' => $vacancy->id, 'start_date' => '2026-10-03', 'end_date' => '2026-10-31', 'utm_source' => 'facebook'];
        $this->post(route('campaigns.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('campaigns.index'));
        $campaign = DB::table('campaigns')->first();
        $this->get(route('campaigns.index', ['search' => 'CMP-001', 'status' => 'draft']))->assertOk()->assertViewHas('records', fn ($rows) => $rows->total() === 1)->assertSee('Website &amp; Funnel', false)->assertDontSee('aria-label="Bagian Pemasaran"', false);
        $this->get(route('campaigns.edit', $campaign->id))->assertOk()->assertSee('Kampanye Khusus');
        $this->put(route('campaigns.update', $campaign->id), array_replace($data, ['status' => 'active']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'status' => 'active']);
        $this->post(route('campaigns.store'), $data)->assertSessionHasErrors('code');
        $this->put(route('campaigns.update', $campaign->id), array_replace($data, ['budget' => '-1', 'end_date' => '2026-10-01']))->assertSessionHasErrors(['budget', 'end_date']);
        $this->get(route('campaigns.index', ['status' => 'invalid']))->assertSessionHasErrors('status');
        $this->get(route('campaigns.edit', 99999))->assertNotFound();
    }

    public function test_landing_page_management_requires_matching_campaign_and_published_vacancy(): void
    {
        $this->actingAs(User::factory()->create());
        $vacancy = Vacancy::factory()->create();
        $campaign = $this->campaign($vacancy);
        $data = ['vacancy_id' => $vacancy->id, 'campaign_id' => $campaign, 'title' => 'Halaman Baru', 'slug' => 'halaman-baru', 'content' => 'Isi halaman', 'status' => 'draft'];
        $this->get(route('landing-pages.create'))->assertOk();
        $this->post(route('landing-pages.store'), $data)->assertSessionHasNoErrors();
        $page = DB::table('landing_pages')->first();
        $this->assertNull($page->published_at);
        $this->get(route('landing-pages.edit', $page->id))->assertOk()->assertSee('Halaman Baru');
        $this->put(route('landing-pages.update', $page->id), array_replace($data, ['status' => 'published']))->assertSessionHasErrors('status');
        $vacancy->update(['status' => 'published']);
        $this->put(route('landing-pages.update', $page->id), array_replace($data, ['status' => 'published']))->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('landing_pages')->find($page->id)->published_at);
        $this->get(route('landing-pages.index', ['search' => 'halaman-baru']))->assertOk()->assertSee('Buka Pendaftaran');
        $otherCampaign = $this->campaign(Vacancy::factory()->create());
        $this->put(route('landing-pages.update', $page->id), array_replace($data, ['campaign_id' => $otherCampaign]))->assertSessionHasErrors('campaign_id');
        DB::table('campaigns')->where('id', $campaign)->update(['status' => 'paused']);
        $this->put(route('landing-pages.update', $page->id), array_replace($data, ['status' => 'published']))->assertSessionHasErrors('campaign_id');
        $this->post(route('landing-pages.store'), array_replace($data, ['slug' => '../bad']))->assertSessionHasErrors('slug');
        $this->post(route('landing-pages.store'), $data)->assertSessionHasErrors('slug');
    }

    public function test_public_registration_creates_versioned_lead_without_candidate_or_account(): void
    {
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $campaign = $this->campaign($vacancy);
        $id = $this->page($vacancy, ['campaign_id' => $campaign, 'slug' => 'daftar-sekarang', 'content' => '<script>alert(1)</script>']);
        $this->get(route('registration.show', 'daftar-sekarang'))->assertOk()->assertSee('Daftar Lowongan')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Menu utama');
        $token = session('registration_token.'.$id);
        $data = ['full_name' => 'Pendaftar Baru', 'whatsapp' => '+6281234567890', 'email' => 'pendaftar@example.test', 'agreement' => '1', 'registration_token' => $token, 'vacancy_id' => 99999, 'campaign_id' => 99999];
        $this->post(route('registration.store', 'daftar-sekarang'), $data)->assertSessionHasNoErrors()->assertRedirect(route('registration.show', 'daftar-sekarang'));
        $this->assertGuest();
        $this->assertDatabaseHas('acquisition_leads', ['name' => 'Pendaftar Baru', 'normalized_phone' => '+6281234567890']);
        $this->assertDatabaseCount('persons', 0);
        $this->assertDatabaseCount('candidates', 0);
        $this->assertDatabaseHas('lead_submissions', ['vacancy_id' => $vacancy->id]);
        $this->get(route('registration.show', 'daftar-sekarang'))->assertOk()->assertSee('Pendaftaran Berhasil')->assertDontSee('Pendaftar Baru');
        $this->post(route('registration.store', 'daftar-sekarang'), $data)->assertSessionHasErrors('registration_token');
        $this->assertDatabaseCount('acquisition_leads', 1);
    }

    public function test_public_registration_blocks_drafts_closed_vacancies_and_inactive_campaign_periods(): void
    {
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $campaign = $this->campaign($vacancy);
        $id = $this->page($vacancy, ['campaign_id' => $campaign, 'slug' => 'terbuka']);
        $this->get('/daftar/terbuka')->assertOk();
        $token = session('registration_token.'.$id);
        foreach (['draft', 'archived'] as $status) {
            DB::table('landing_pages')->where('id', $id)->update(['status' => $status]);
            $this->get('/daftar/terbuka')->assertNotFound();
        }
        DB::table('landing_pages')->where('id', $id)->update(['status' => 'published']);
        $vacancy->update(['status' => 'closed']);
        $this->get('/daftar/terbuka')->assertNotFound();
        $this->post('/daftar/terbuka', ['full_name' => 'X', 'whatsapp' => '081234567890', 'agreement' => 1, 'registration_token' => $token])->assertNotFound();
        $vacancy->update(['status' => 'published']);
        DB::table('campaigns')->where('id', $campaign)->update(['status' => 'paused']);
        $this->get('/daftar/terbuka')->assertNotFound();
        DB::table('campaigns')->where('id', $campaign)->update(['status' => 'active', 'start_date' => '2026-10-04']);
        $this->get('/daftar/terbuka')->assertNotFound();
        DB::table('campaigns')->where('id', $campaign)->update(['start_date' => '2026-10-03', 'end_date' => '2026-10-03']);
        $this->get('/daftar/terbuka')->assertOk();
        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_public_form_validates_contact_and_consent_and_cannot_merge_existing_people(): void
    {
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $id = $this->page($vacancy, ['slug' => 'validasi']);
        $this->get('/daftar/validasi')->assertOk();
        $token = session('registration_token.'.$id);
        $this->post('/daftar/validasi', ['full_name' => '', 'whatsapp' => 'abc', 'email' => 'bad', 'registration_token' => $token])->assertSessionHasErrors(['full_name', 'whatsapp', 'email', 'agreement']);
        $this->post('/daftar/validasi', ['full_name' => 'Nama', 'whatsapp' => '081234567890', 'agreement' => 1, 'registration_token' => 'wrong'])->assertSessionHasErrors('registration_token');
        $this->assertDatabaseCount('persons', 0);
        $existing = Application::factory()->create();
        $existing->candidate->person->update(['whatsapp' => '081234567890']);
        $this->post('/daftar/validasi', ['full_name' => 'Nama Baru', 'whatsapp' => '081234567890', 'agreement' => 1, 'registration_token' => $token])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('persons', 1);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertNotSame('Nama Baru', $existing->candidate->person->fresh()->full_name);
    }

    public function test_recruitment_admin_routes_require_auth_and_existing_flow_is_prefilled(): void
    {
        foreach (['campaigns.index', 'campaigns.create', 'landing-pages.index', 'landing-pages.create'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->post(route('campaigns.store'), [])->assertRedirect(route('login'));
        $this->post(route('landing-pages.store'), [])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $company = Company::factory()->create();
        $order = JobOrder::factory()->create(['company_id' => $company->id]);
        $this->get(route('job-orders.create', ['company_id' => $company->id]))->assertOk()->assertViewHas('order', fn ($value) => $value->company_id === $company->id);
        $this->get(route('vacancies.create', ['job_order_id' => $order->id]))->assertOk()->assertViewHas('vacancy', fn ($value) => $value->job_order_id === $order->id);
        $this->get('/modul/menu-2-3')->assertRedirect(route('campaigns.index', ['menu' => 'menu-2-3']));
        $this->get('/modul/menu-2-4')->assertRedirect(route('landing-pages.index', ['menu' => 'menu-2-4']));
        $this->get(route('companies.show', $company))->assertOk()->assertSee('Tambah Permintaan');
        $this->get(route('job-orders.show', $order))->assertOk()->assertSee('Tambah Lowongan');
    }
}
