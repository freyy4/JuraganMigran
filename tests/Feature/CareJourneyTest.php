<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\MedicalEvent;
use App\Models\User;
use App\Services\CareJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CareJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_events_are_idempotent_encrypted_and_templates_are_country_specific(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $application = Application::factory()->create();
        $application->vacancy->jobOrder->country->update(['code' => 'TW']);
        $candidate = $application->candidate;
        $service = app(CareJourney::class);
        $service->sync($candidate);
        $count = DB::table('care_events')->count();
        $service->sync($candidate);
        $this->assertSame($count, DB::table('care_events')->count());
        $this->assertStringNotContainsString('CPMI terdaftar', DB::table('care_events')->first()->payload);
        $response = $this->get(route('care.show', $candidate))->assertOk()->assertSee('TETO')->assertViewHas('automaticRatio', 100);
        if ($directory = getenv('CARE_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/care.html', $response->getContent());
        }
        $this->assertLessThan(array_search('opp', array_keys(json_decode($service->template($application)->stages, true))), array_search('teto', array_keys(json_decode($service->template($application)->stages, true))));
    }

    public function test_one_tap_welfare_opens_exactly_one_case_and_auto_escalates_without_tasks(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $candidate = Application::factory()->create()->candidate;
        $payload = ['request_key' => (string) Str::uuid(), 'outcome' => 'WELFARE', 'channel' => 'CALL', 'stage' => 'lpk'];
        $this->post(route('care.touchpoint', $candidate), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('care.touchpoint', $candidate), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('care_issues', 1);
        $this->assertDatabaseHas('care_issues', ['category' => 'HEALTH', 'severity' => 'S2', 'status' => 'OPEN']);
        $this->travel(181)->minutes();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertDatabaseCount('care_escalations', 2);
        $this->assertDatabaseCount('tasks', 0);
        $this->actingAs($user)->get(route('care.show', $candidate))->assertOk()->assertSee('Eskalasi 2');
    }

    public function test_critical_closure_requires_actions_outcome_followup_and_acknowledgement(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $candidate = Application::factory()->create()->candidate;
        $service = app(CareJourney::class);
        $id = $service->openIssue($user, $candidate, ['request_key' => (string) Str::uuid(), 'category' => 'HEALTH', 'severity' => 'S4', 'description' => 'Keluhan urgent di LPK']);
        $this->assertDatabaseCount('care_escalations', 2);
        $url = route('care.issue-status', [$candidate, $id]);
        foreach (['ACKNOWLEDGED', 'IN_ACTION', 'MONITORING'] as $index => $status) {
            $this->post($url, ['version' => $index + 1, 'status' => $status])->assertSessionHasNoErrors();
        }
        $this->post($url, ['version' => 4, 'status' => 'RESOLVED'])->assertSessionHasErrors('medical_action');
        $this->assertDatabaseHas('care_issues', ['id' => $id, 'status' => 'MONITORING']);
        $this->post($url, ['version' => 4, 'status' => 'RESOLVED', 'medical_action' => 'Dirujuk ke klinik oleh PIC', 'outcome' => 'Tindakan medis telah diberikan', 'followup' => 'Konfirmasi kondisi esok hari', 'candidate_acknowledgement' => 'CPMI mengonfirmasi bantuan diterima'])->assertSessionHasNoErrors();
        $this->post($url, ['version' => 4, 'status' => 'CLOSED'])->assertSessionHasErrors('version');
    }

    public function test_support_drop_only_requests_and_emergency_reserve_is_separate(): void
    {
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $this->actingAs($requester);
        $candidate = Application::factory()->create()->candidate;
        $service = app(CareJourney::class);
        $issue = $service->openIssue($requester, $candidate, ['request_key' => (string) Str::uuid(), 'category' => 'HEALTH', 'severity' => 'S3', 'description' => 'Membutuhkan bantuan penanganan']);
        $data = ['request_key' => (string) Str::uuid(), 'type' => 'emergency_medical', 'stage' => 'lpk', 'amount_rupiah' => 250000, 'issue_id' => $issue];
        $this->post(route('care.support', $candidate), $data)->assertSessionHasNoErrors();
        $this->post(route('care.support', $candidate), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('care_support_requests', 1);
        $this->assertDatabaseCount('care_reserve_transactions', 0);
        $support = DB::table('care_support_requests')->first();
        $url = route('care.support-status', [$candidate, $support->id]);
        $this->post($url, ['version' => 1, 'status' => 'APPROVED', 'reason' => 'Perlu bantuan segera'])->assertForbidden();
        $this->actingAs($approver)->post($url, ['version' => 1, 'status' => 'APPROVED', 'reason' => 'Care approval'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('care_support_requests', ['id' => $support->id, 'status' => 'APPROVED', 'version' => 2]);
        $payment = ['version' => 2, 'status' => 'DISBURSED', 'reason' => 'Transport medis', 'reference' => 'SYNTHETIC-TEST-001'];
        $this->post($url, $payment)->assertUnprocessable();
        $this->assertDatabaseHas('care_issues', ['id' => $issue, 'status' => 'OPEN']);
        config(['care.reserve_limit_minor' => 50000000]);
        $this->post($url, $payment)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('care_reserve_transactions', ['support_id' => $support->id, 'amount_minor' => 25000000]);
        $this->post($url, $payment)->assertSessionHasErrors('version');
        $this->assertDatabaseCount('care_reserve_transactions', 1);
    }

    public function test_post_mcu_delay_is_explainable_and_single_unverified_report_does_not_rank_partner(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $application = Application::factory()->create();
        MedicalEvent::factory()->create(['application_id' => $application->id, 'status' => 'completed', 'clearance_status' => 'fit', 'completed_at' => now()->subDays(10)]);
        $service = app(CareJourney::class);
        $service->openIssue($user, $application->candidate, ['request_key' => (string) Str::uuid(), 'category' => 'PARTNER', 'severity' => 'S1', 'description' => 'Laporan yang belum diverifikasi', 'partner_id' => $application->vacancy->jobOrder->company_id]);
        $this->get(route('care.show', $application->candidate))->assertOk()->assertViewHas('risk', fn ($risk) => $risk['level'] === 2 && str_contains(json_encode($risk), 'onboarding'))->assertViewHas('partnerQuality', fn ($quality) => $quality['score'] === null && $quality['substantiated'] === 0);
        $this->assertGreaterThan(0, DB::table('care_snapshots')->where('type', 'like', 'risk:%')->count());
    }

    public function test_candidate_scope_and_sensitive_case_permissions_are_enforced(): void
    {
        $candidate = Application::factory()->create()->candidate;
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $this->actingAs($cs)->get(route('care.show', $candidate))->assertNotFound();
        $this->post(route('care.issue', $candidate), ['request_key' => (string) Str::uuid(), 'category' => 'HEALTH', 'severity' => 'S4', 'description' => 'Outside scope'])->assertNotFound();
        $this->assertDatabaseCount('care_issues', 0);
    }
    public function test_consent_marital_rules_and_teto_inspection_feed_existing_document_and_mcu_gates(): void
    {
        \Illuminate\Support\Facades\Storage::fake('documents');
        $this->actingAs(User::factory()->create());
        $application = Application::factory()->create();
        $application->candidate->person->update(['marital_status' => 'married']);
        $consent = \App\Models\DocumentType::factory()->create(['name' => 'Izin Keluarga']);
        $marriage = \App\Models\DocumentType::factory()->create(['name' => 'Buku Nikah']);
        DB::table('care_journey_templates')->where('country_code', 'DEFAULT')->update(['policy' => json_encode(['consent_required' => true, 'consent_document_type_id' => $consent->id, 'marital_documents' => ['married' => $marriage->id], 'teto_document_type_ids' => [$consent->id]])]);
        $engine = app(\App\Services\DocumentRequirementEngine::class);
        $this->assertCount(2, $engine->requirements($application));
        $clinic = \App\Models\Company::factory()->create(['type' => 'clinic', 'status' => 'active']);
        $data = ['application_id' => $application->id, 'clinic_id' => $clinic->id, 'scheduled_at' => '2027-01-20T09:00', 'status' => 'scheduled'];
        $this->post(route('medical-events.store'), $data)->assertSessionHasErrors('application_id');
        \Illuminate\Support\Facades\Storage::disk('documents')->put('consent.pdf', 'Synthetic consent');
        $document = \App\Models\CandidateDocument::create(['candidate_id' => $application->candidate_id, 'application_id' => $application->id, 'document_type_id' => $consent->id, 'file_path' => 'consent.pdf', 'version' => 1, 'status' => 'verified']);
        $this->assertSame(0, $engine->summary($application)['verified']);
        $checks = array_fill_keys(['readable', 'qr_visible', 'qr_inspected', 'identity_readable', 'profile_reconciled'], 1);
        $this->post(route('care.document-checks', [$application->candidate, $document]), ['version' => 0, 'checks' => $checks])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $engine->summary($application)['verified']);
        $this->post(route('medical-events.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('medical_events', 1);
        $this->get(route('documents.show', $application))->assertOk()->assertSee('Izin Keluarga')->assertSee('Buku Nikah');
    }

    public function test_portal_welfare_uses_only_own_candidate_and_opens_an_urgent_case_once(): void
    {
        $user = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cpmi')->value('id')]);
        $application = Application::factory()->create();
        DB::table('portal_accounts')->insert(['candidate_id' => $application->candidate_id, 'user_id' => $user->id, 'normalized_phone' => '+6281234567890', 'status' => 'active', 'phone_verified_at' => now(), 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->get(route('portal.welfare'))->assertOk();
        $data = ['request_key' => (string) Str::uuid(), 'status' => 'EMERGENCY', 'note' => 'Memerlukan bantuan segera'];
        $this->post(route('portal.welfare'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('portal.welfare'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('care_issues', ['candidate_id' => $application->candidate_id, 'severity' => 'S4', 'status' => 'OPEN']);
        $this->assertDatabaseCount('care_issues', 1);
        $this->assertDatabaseCount('care_escalations', 2);
        $this->get(route('care.show', $application->candidate_id))->assertForbidden();
    }

    public function test_route_fit_uses_real_dob_and_requirements_and_records_human_override(): void
    {
        $this->actingAs(User::factory()->create());
        $candidate = Candidate::factory()->create();
        $candidate->person->update(['birth_date' => now()->subYears(22)->toDateString(), 'height_cm' => 160]);
        $vacancy = \App\Models\Vacancy::factory()->create(['status' => 'active']);
        $age = \App\Models\RequirementDefinition::factory()->create(['code' => 'age_years', 'name' => 'Usia', 'category' => 'basic', 'data_type' => 'integer']);
        $height = \App\Models\RequirementDefinition::factory()->create(['code' => 'height_cm', 'name' => 'Tinggi badan', 'category' => 'basic', 'data_type' => 'number']);
        $vacancy->requirements()->create(['requirement_definition_id' => $age->id, 'operator' => 'between', 'value_min' => 20, 'value_max' => 30, 'is_required' => true]);
        $vacancy->requirements()->create(['requirement_definition_id' => $height->id, 'operator' => 'gte', 'value_min' => 155, 'is_required' => true]);
        $this->get(route('care.show', $candidate))->assertOk()->assertViewHas('routes', fn ($rows) => $rows->first()['match'] === 'MATCH' && $rows->first()['score'] === 100);
        $this->post(route('care.decision', $candidate), ['request_key' => (string) Str::uuid(), 'vacancy_id' => $vacancy->id, 'decision' => 'OVERRIDE', 'reason' => 'CPMI memilih negara lain setelah penjelasan'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('care_events', ['candidate_id' => $candidate->id, 'event_type' => 'route_decision']);
        $this->assertDatabaseCount('applications', 0);
    }

}
