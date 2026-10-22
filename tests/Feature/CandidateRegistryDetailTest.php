<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CandidateRegistryDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_advanced_filters_restore_reset_and_respect_wib_boundaries(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22));
        $this->actingAs(User::factory()->create());
        $candidate = Candidate::factory()->create(['registered_at' => '2026-10-21 17:00:00']);
        $candidate->person->update(['birth_date' => '2002-10-22', 'gender' => 'female', 'education_level' => 'SMA']);
        Candidate::factory()->create(['registered_at' => '2026-10-21 16:59:59']);
        $filters = ['age' => '18-25', 'gender' => 'female', 'education' => 'SMA', 'created_from' => '2026-10-22', 'created_to' => '2026-10-22'];
        $this->get(route('candidates.index', $filters))->assertOk()->assertViewHas('candidates', fn ($rows) => $rows->total() === 1);
        $this->get(route('candidates.index'))->assertOk()->assertViewHas('filters', fn ($state) => $state['gender'] === 'female')->assertViewHas('candidates', fn ($rows) => $rows->total() === 1);
        $this->get(route('candidates.index', ['reset' => 1]))->assertOk()->assertViewHas('candidates', fn ($rows) => $rows->total() === 2);
        $this->get(route('candidates.index'))->assertOk()->assertViewHas('candidates', fn ($rows) => $rows->total() === 2);
    }

    public function test_optional_profile_preferences_save_and_registration_uses_wib(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('candidates.store'), ['candidate_code' => 'CPMI-PRD-822', 'status' => 'lead', 'registered_at' => '2026-10-22T09:00', 'person' => ['full_name' => 'Siti Registry', 'nationality' => 'Indonesia', 'current_job' => 'Penjahit', 'languages' => 'Indonesia, Inggris', 'height_cm' => 160, 'weight_kg' => 52], 'preferences' => ['work_sector' => 'Domestic Worker', 'experience_summary' => 'Dua tahun', 'passport_status' => 'IN_PROGRESS', 'departure_readiness' => 'PREPARING']])->assertSessionHasNoErrors()->assertRedirect();
        $candidate = Candidate::where('candidate_code', 'CPMI-PRD-822')->firstOrFail();
        $this->assertSame('2026-10-22 02:00', $candidate->registered_at->format('Y-m-d H:i'));
        $this->assertSame('Indonesia', $candidate->person->nationality);
        $this->assertSame('Domestic Worker', $candidate->preferences['work_sector']);
        $this->get(route('candidates.show', $candidate))->assertOk()->assertSee('Penjahit')->assertSee('Dalam pengurusan')->assertSee('data-portal-form', false);
    }

    public function test_archive_requires_confirmation_preserves_applications_and_audits_reason(): void
    {
        $this->actingAs(User::factory()->create());
        $application = Application::factory()->create();
        $url = route('candidates.archive', $application->candidate);
        $this->post($url, ['reason' => 'Permintaan CPMI'])->assertSessionHasErrors('confirm');
        $this->assertNotSame('inactive', $application->candidate->fresh()->status);
        $this->post($url, ['reason' => 'Permintaan CPMI', 'confirm' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('inactive', $application->candidate->fresh()->status);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'candidate.archived']);
    }

    public function test_detail_summarizes_real_timeline_and_exports_browser_fixtures(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $application = Application::factory()->create(['assigned_cs_id' => $user->id]);
        $candidate = $application->candidate;
        $candidate->person->update(['full_name' => 'Siti Aminah', 'whatsapp' => '081234567890', 'nationality' => 'Indonesia']);
        $lead = DB::table('acquisition_leads')->insertGetId(['public_token' => (string) Str::uuid(), 'candidate_id' => $candidate->id, 'name' => 'Siti Aminah', 'normalized_phone' => '+6281234567890', 'assigned_cs_id' => $user->id, 'status' => 'converted', 'followup_status' => 'NOT_CONTACTED', 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 8) as $number) {
            DB::table('crm_activities')->insert(['lead_id' => $lead, 'actor_id' => $user->id, 'event_type' => 'stage_changed', 'summary' => 'Tahap terverifikasi '.$number, 'created_at' => now()->subMinutes($number)]);
        }
        DB::table('crm_activities')->insert(['lead_id' => $lead, 'event_type' => 'stage_changed', 'summary' => 'Aktivitas lama', 'created_at' => now()->subDays(40)]);
        $detail = $this->get(route('candidates.show', $candidate))->assertOk()->assertSee('Tahap terverifikasi 1')->assertDontSee('Tahap terverifikasi 8')->assertViewHas('timeline', fn ($events) => $events->count() === 6)->assertViewHas('activityCounts', fn ($counts) => $counts['stages'] === 8);
        $this->get(route('candidates.show', [$candidate, 'timeline' => 'all']))->assertOk()->assertSee('Aktivitas lama');
        $this->post(route('portal-admin.invite', $candidate), [])->assertSessionHasErrors('contact_verified');
        $this->assertDatabaseCount('portal_invitations', 0);
        if ($directory = getenv('CPMI_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/detail.html', $detail->getContent());
            file_put_contents($directory.'/registry.html', $this->get(route('candidates.index'))->assertOk()->getContent());
        }
    }

    public function test_sensitive_search_and_detail_use_the_permission_scope_of_each_candidate(): void
    {
        $basicRole = DB::table('crm_roles')->insertGetId(['name' => 'Registry reader', 'active' => true, 'permissions' => json_encode(['candidate.read.basic']), 'scope_type' => 'OPERATIONAL_ALL', 'created_at' => now(), 'updated_at' => now()]);
        $sensitiveRole = DB::table('crm_roles')->insertGetId(['name' => 'Assigned contact reader', 'active' => true, 'permissions' => json_encode(['candidate.read.identity', 'candidate.read.contact']), 'scope_type' => 'ASSIGNED', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['crm_role_id' => $basicRole]);
        DB::table('user_role_assignments')->insert(['user_id' => $user->id, 'role_id' => $sensitiveRole, 'active' => true, 'valid_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $outside = Application::factory()->create();
        $candidate = $outside->candidate;
        $candidate->person->update(['nik' => '3271000000000012', 'whatsapp' => '081234999888', 'birth_place' => 'Kota Rahasia', 'address' => 'Alamat rahasia']);
        $this->actingAs($user);
        foreach (['3271000000000012', '081234999888'] as $search) {
            $this->getJson(route('candidates.index', ['search' => $search]))->assertOk()->assertJsonPath('total', 0);
        }
        $this->get(route('candidates.show', $candidate))->assertOk()->assertDontSee('3271000000000012')->assertDontSee('081234999888')->assertDontSee('Kota Rahasia')->assertDontSee('Alamat rahasia')->assertDontSee('data-copy-contact', false);
    }

    public function test_unassigned_cs_cannot_read_or_archive_another_candidate(): void
    {
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $application = Application::factory()->create();
        $candidate = $application->candidate;
        $this->actingAs($cs)->get(route('candidates.show', $candidate))->assertNotFound();
        $this->post(route('candidates.archive', $candidate), ['reason' => 'Outside scope', 'confirm' => 1])->assertNotFound();
        $this->assertDatabaseHas('candidates', ['id' => $candidate->id, 'status' => $candidate->status]);
    }
}
