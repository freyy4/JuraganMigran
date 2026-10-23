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
        $this->actingAs($approver)->post($url, ['version' => 1, 'status' => 'APPROVED', 'reason' => 'Care approval'])->assertSessionHasNoErrors();
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
}
