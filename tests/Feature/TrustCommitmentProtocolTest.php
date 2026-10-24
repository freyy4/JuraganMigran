<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationQualificationResult;
use App\Models\CandidateDocument;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\HealthInterview;
use App\Models\MedicalEvent;
use App\Models\PipelineStage;
use App\Models\TrainingBatch;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\CareJourney;
use App\Services\ProcessStageTransition;
use App\Services\TrustCommitmentProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TrustCommitmentProtocolTest extends TestCase
{
    use RefreshDatabase;

    private function setupApplication(string $mode = 'ENFORCED'): Application
    {
        $this->actingAs(User::factory()->create());
        $policy = app(TrustCommitmentProtocol::class)->policy();
        $policy['new_application_mode'] = $mode;
        DB::table('protocol_settings')->where('id', 1)->update(['policy' => json_encode($policy)]);

        return Application::factory()->create();
    }

    private function payload(Application $app, string $action, array $extra = []): array
    {
        $run = DB::table('protocol_runs')->where('application_id', $app->id)->firstOrFail();

        return ['request_key' => (string) Str::uuid(), 'version' => $run->version, 'stage' => $run->stage, 'action' => $action] + $extra;
    }

    private function act(Application $app, string $action, array $extra = []): void
    {
        $this->post(route('protocol.action', $app), $this->payload($app, $action, $extra))->assertRedirect()->assertSessionHasNoErrors();
    }

    private function stage(Application $app): string
    {
        return DB::table('protocol_runs')->where('application_id', $app->id)->value('stage');
    }

    private function moveFixture(Application $app, string $stage): void
    {
        $run = DB::table('protocol_runs')->where('application_id', $app->id)->firstOrFail();
        DB::table('protocol_stage_instances')->where('run_id', $run->id)->update(['stage' => $stage, 'queue' => config('protocol.stages.'.$stage.'.queue'), 'next_action' => config('protocol.stages.'.$stage.'.action')]);
        DB::table('protocol_runs')->where('id', $run->id)->update(['stage' => $stage]);
    }

    private function approvedContent(): int
    {
        return DB::table('protocol_content_versions')->insertGetId(['title' => 'Materi uji resmi', 'version' => 1, 'status' => 'Approved', 'owner_id' => auth()->id(), 'approved_by' => auth()->id(), 'source' => 'Sumber program uji', 'body' => 'Profil, hak, kewajiban, mitra dan kanal verifikasi.', 'effective_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_ordered_enforced_happy_path_uses_canonical_sources_and_automatic_handoffs(): void
    {
        $app = $this->setupApplication();
        $app->vacancy->update(['status' => 'published']);
        $this->act($app, 'outcome', ['interest' => 'PROGRAM', 'inbound_confirmed' => 1]);
        $this->act($app, 'advance');
        $this->act($app, 'outcome', ['content_id' => $this->approvedContent(), 'response' => 'UNDERSTOOD', 'checklist_complete' => 1]);
        $this->act($app, 'advance');
        $this->act($app, 'outcome', ['wants_continue' => 1]);
        $this->act($app, 'advance');
        $result = ApplicationQualificationResult::factory()->create(['application_id' => $app->id, 'result' => 'passed']);
        DB::table('vacancy_requirements')->where('id', $result->vacancy_requirement_id)->update(['is_required' => true]);
        $this->act($app, 'advance');
        HealthInterview::factory()->create(['application_id' => $app->id, 'status' => 'completed', 'completeness_percentage' => 100]);
        $this->act($app, 'advance');
        $this->act($app, 'outcome', ['reviewed' => 1]);
        $this->act($app, 'advance');
        $this->act($app, 'outcome', ['rubric' => array_fill_keys(array_keys(config('protocol.rubric')), 'READY'), 'interview_outcome' => 'continue']);
        $this->act($app, 'advance');
        $this->act($app, 'advance');
        $this->assertSame('appointment', $this->stage($app));
        $medical = MedicalEvent::factory()->create(['application_id' => $app->id]);
        $booking = ['pic_id' => auth()->id(), 'office_location' => 'Kantor uji', 'scheduled_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i'), 'transport' => 'Mandiri', 'medical_event_id' => $medical->id, 'appointment_status' => 'CONFIRMED'];
        $this->act($app, 'appointment', $booking);
        $this->act($app, 'advance');
        $this->act($app, 'appointment', [...$booking, 'appointment_status' => 'ATTENDED']);
        $this->act($app, 'advance');
        $this->assertSame('waiting', $this->stage($app));
        $medical->update(['status' => 'completed', 'completed_at' => now(), 'clearance_status' => 'fit']);
        $this->assertSame('golden', $this->stage($app));
        $enrollment = TrainingEnrollment::factory()->create(['application_id' => $app->id]);
        $this->act($app, 'outcome', ['enrollment_id' => $enrollment->id, 'transport' => 'Mandiri', 'handover' => 'PIC LPK sudah mengonfirmasi penerimaan', 'support_reviewed' => 1]);
        $this->act($app, 'advance');
        $enrollment->update(['status' => 'ongoing', 'joined_at' => now()]);
        $this->act($app, 'advance');
        $this->assertSame('country', $this->stage($app));
        $this->assertDatabaseCount('candidates', 1);
        $this->assertDatabaseCount('medical_events', 1);
        $this->assertDatabaseCount('training_enrollments', 1);
        $this->assertDatabaseHas('protocol_golden_windows', ['status' => 'CLOSED', 'enrollment_id' => $enrollment->id]);
        $this->assertSame(1, DB::table('protocol_stage_instances')->whereNull('exited_at')->count());
        $this->assertDatabaseHas('care_events', ['event_type' => 'LPK_ADMITTED', 'auto_generated' => true]);
        $this->assertGreaterThanOrEqual(70, DB::table('care_events')->where('auto_generated', true)->count() * 100 / DB::table('care_events')->count());
        $this->get(route('protocol.show', $app))->assertOk()->assertSee('Template negara');
    }

    public function test_missing_gates_order_violation_stale_state_and_retry_are_server_authoritative(): void
    {
        $app = $this->setupApplication();
        $this->post(route('protocol.action', $app), $this->payload($app, 'advance'))->assertSessionHasErrors('gate');
        $this->act($app, 'outcome', ['interest' => 'JOB', 'inbound_confirmed' => 1]);
        $this->post(route('protocol.action', $app), $this->payload($app, 'advance', ['target' => 'family']))->assertSessionHasErrors('target');
        $payload = $this->payload($app, 'advance');
        $this->post(route('protocol.action', $app), $payload)->assertSessionHasNoErrors();
        $count = DB::table('care_events')->count();
        $this->post(route('protocol.action', $app), $payload)->assertSessionHasNoErrors();
        $this->assertSame($count, DB::table('care_events')->count());
        $this->post(route('protocol.action', $app), [...$payload, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->post(route('protocol.action', $app), [...$payload, 'action' => 'outcome'])->assertConflict();
        $this->assertDatabaseCount('protocol_stage_instances', 2);
    }

    public function test_draft_expired_or_wrong_program_trust_content_cannot_be_used(): void
    {
        $app = $this->setupApplication();
        $this->moveFixture($app, 'trust');
        $id = $this->approvedContent();
        foreach ([['status' => 'Draft'], ['status' => 'Approved', 'expires_at' => now()->subMinute()], ['expires_at' => null, 'vacancy_id' => Application::factory()->create()->vacancy_id]] as $changes) {
            DB::table('protocol_content_versions')->where('id', $id)->update($changes);
            $this->post(route('protocol.action', $app), $this->payload($app, 'outcome', ['content_id' => $id, 'response' => 'UNDERSTOOD', 'checklist_complete' => 1]))->assertSessionHasErrors('content_id');
        }
        $this->assertDatabaseCount('protocol_actions', 0);
    }

    public function test_senior_interview_requires_seven_rubric_fields_and_reason_for_non_continue(): void
    {
        $app = $this->setupApplication();
        $this->moveFixture($app, 'interview');
        $this->post(route('protocol.action', $app), $this->payload($app, 'outcome', ['interview_outcome' => 'continue']))->assertSessionHasErrors('rubric');
        $rubric = array_fill_keys(array_keys(config('protocol.rubric')), 'CLARIFY');
        $this->post(route('protocol.action', $app), $this->payload($app, 'outcome', ['rubric' => $rubric, 'interview_outcome' => 'hold']))->assertSessionHasErrors('reason_code');
        $data = $this->payload($app, 'outcome', ['rubric' => $rubric, 'interview_outcome' => 'hold', 'reason_code' => 'FAMILY']);
        $this->post(route('protocol.action', $app), $data)->assertSessionHasNoErrors();
        $this->post(route('protocol.action', $app), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('interviews', 1);
        $this->post(route('protocol.action', $app), $this->payload($app, 'advance'))->assertSessionHasErrors('gate');
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $app->update(['assigned_cs_id' => $cs->id]);
        $this->actingAs($cs)->post(route('protocol.action', $app), $this->payload($app, 'outcome', ['rubric' => $rubric, 'interview_outcome' => 'continue']))->assertForbidden();
    }

    public function test_blockers_require_resolution_owner_action_and_due_date_and_survive_override_handoff(): void
    {
        $app = $this->setupApplication();
        $this->post(route('protocol.action', $app), $this->payload($app, 'block', ['reason_code' => 'FAMILY']))->assertSessionHasErrors('reason');
        $this->act($app, 'block', ['reason_code' => 'FAMILY', 'reason' => 'Perlu penjelasan keluarga', 'owner_id' => auth()->id(), 'next_action' => 'Hubungi wali', 'due_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i')]);
        $this->post(route('protocol.action', $app), $this->payload($app, 'advance'))->assertSessionHasErrors('gate');
        $this->act($app, 'override', ['target' => 'trust', 'reason_code' => 'FAMILY', 'reason' => 'Edukasi keluarga bersama Trust Pack', 'risk_acknowledged' => 1]);
        $this->assertDatabaseHas('protocol_stage_instances', ['stage' => 'trust', 'blocker_status' => 'BLOCKED']);
        $this->assertDatabaseHas('care_events', ['event_type' => 'PROTOCOL_OVERRIDE']);
        $this->act($app, 'unblock');
        $this->assertDatabaseHas('application_blockers', ['status' => 'resolved']);
    }

    public function test_family_commitment_references_existing_contact_and_verified_consent_document(): void
    {
        Storage::fake('documents');
        $app = $this->setupApplication();
        $type = DocumentType::factory()->create();
        DB::table('care_journey_templates')->where('id', app(CareJourney::class)->template($app)->id)->update(['policy' => json_encode(['consent_required' => true, 'consent_document_type_id' => $type->id])]);
        $this->moveFixture($app, 'family');
        $this->post(route('protocol.action', $app), $this->payload($app, 'advance'))->assertSessionHasErrors('gate');
        $contact = $app->candidate->contacts()->create(['name' => 'Wali uji', 'relationship' => 'Orang tua']);
        $this->act($app, 'consent', ['consent_status' => 'EXPLAINED', 'contact_id' => $contact->id]);
        $this->post(route('protocol.action', $app), $this->payload($app, 'consent', ['consent_status' => 'VERIFIED', 'contact_id' => $contact->id]))->assertSessionHasErrors('consent_status');
        $this->act($app, 'consent', ['consent_status' => 'AGREED', 'contact_id' => $contact->id]);
        $this->act($app, 'consent', ['consent_status' => 'SENT', 'contact_id' => $contact->id, 'template_version' => 'Surat uji v1']);
        Storage::disk('documents')->put('consent.pdf', 'synthetic consent evidence');
        $doc = CandidateDocument::create(['candidate_id' => $app->candidate_id, 'application_id' => $app->id, 'document_type_id' => $type->id, 'status' => 'uploaded', 'file_path' => 'consent.pdf']);
        $this->act($app, 'consent', ['consent_status' => 'SIGNED', 'document_id' => $doc->id]);
        $this->post(route('protocol.action', $app), $this->payload($app, 'consent', ['consent_status' => 'VERIFIED', 'verification_method' => 'Pemeriksaan bukti dan konfirmasi wali']))->assertSessionHasErrors('document_id');
        $doc->update(['status' => 'verified']);
        $this->act($app, 'consent', ['consent_status' => 'VERIFIED', 'verification_method' => 'Pemeriksaan bukti dan konfirmasi wali']);
        $this->act($app, 'consent', ['consent_status' => 'COMPLETE']);
        $this->act($app, 'advance');
        $this->assertDatabaseCount('candidate_documents', 1);
        $this->assertDatabaseCount('candidate_contacts', 1);
        $this->assertDatabaseHas('care_events', ['event_type' => 'FAMILY_CONSENT_VERIFIED']);
    }

    public function test_only_authorized_medical_role_can_write_final_result_and_retest_is_supported(): void
    {
        $app = $this->setupApplication('SHADOW');
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $app->update(['assigned_cs_id' => $cs->id]);
        $medical = MedicalEvent::factory()->create(['application_id' => $app->id, 'scheduled_at' => now()->subDay()]);
        $data = ['application_id' => $app->id, 'clinic_id' => $medical->clinic_id, 'scheduled_at' => now('Asia/Jakarta')->subDay()->format('Y-m-d\TH:i'), 'completed_at' => now('Asia/Jakarta')->format('Y-m-d\TH:i'), 'status' => 'completed', 'clearance_status' => 'fit'];
        $role = DB::table('crm_roles')->where('id', $cs->crm_role_id)->first();
        DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode([...json_decode($role->permissions, true), 'health.mcu.summary.read', 'health.mcu.schedule.write'])]);
        $this->actingAs($cs)->put(route('medical-events.update', $medical), $data)->assertForbidden();
        $medic = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'medical_coordinator')->value('id')]);
        $this->actingAs($medic)->put(route('medical-events.update', $medical), [...$data, 'clearance_status' => 'retest'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('medical_events', ['id' => $medical->id, 'clearance_status' => 'retest']);
        $this->assertDatabaseCount('protocol_golden_windows', 0);
    }

    public function test_fit_and_admission_hooks_are_idempotent_with_configured_escalation_and_checkins(): void
    {
        $app = $this->setupApplication();
        $this->moveFixture($app, 'waiting');
        $medical = MedicalEvent::factory()->create(['application_id' => $app->id, 'status' => 'completed', 'completed_at' => now(), 'clearance_status' => 'fit']);
        $medical->save();
        $this->assertDatabaseCount('protocol_golden_windows', 1);
        $this->assertDatabaseCount('tasks', 1);
        $this->travel(73)->hours();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertSame(3, DB::table('care_events')->where('event_type', 'GOLDEN_WINDOW_BREACHED')->count());
        $this->assertDatabaseHas('protocol_golden_windows', ['escalation_level' => 3]);
        $enrollment = TrainingEnrollment::factory()->create(['application_id' => $app->id, 'status' => 'ongoing', 'joined_at' => now()]);
        $enrollment->save();
        $this->assertDatabaseHas('protocol_golden_windows', ['status' => 'CLOSED']);
        $this->travel(3)->days();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertSame(1, DB::table('care_events')->where('event_type', 'LPK_ADMITTED')->count());
        $this->assertDatabaseHas('care_events', ['event_type' => 'welfare_due']);
    }

    public function test_shadow_guided_rollout_requires_review_and_preserves_legacy_pipeline(): void
    {
        $app = $this->setupApplication('SHADOW');
        $original = $app->current_stage_id;
        $this->act($app, 'advance');
        $this->post(route('protocol.action', $app), $this->payload($app, 'mode', ['mode' => 'ENFORCED', 'reason' => 'Review']))->assertSessionHasErrors('mode');
        $this->act($app, 'mode', ['mode' => 'GUIDED', 'reason' => 'Gate dan pemetaan ditinjau']);
        $this->act($app, 'mode', ['mode' => 'ENFORCED', 'reason' => 'Siap diterapkan']);
        $this->post(route('protocol.action', $app), $this->payload($app, 'advance'))->assertSessionHasErrors('gate');
        $this->assertSame($original, $app->fresh()->current_stage_id);
    }

    public function test_routing_reassigns_unavailable_owner_without_duplicate_task_or_lost_context(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setTime(10, 0));
        $app = $this->setupApplication('SHADOW');
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([$first, $second] as $user) {
            DB::table('protocol_routing_profiles')->insert(['user_id' => $user->id, 'available' => true, 'capacity' => 3, 'queues' => json_encode(['LEAD_CS']), 'country_codes' => '[]', 'start_hour' => 0, 'end_hour' => 24, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertDatabaseCount('tasks', 1);
        $stage = DB::table('protocol_stage_instances')->whereNull('exited_at')->first();
        $this->assertSame($first->id, $stage->owner_id);
        DB::table('protocol_routing_profiles')->where('user_id', $first->id)->update(['available' => false]);
        $this->artisan('care:monitor')->assertSuccessful();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('protocol_stage_instances', ['id' => $stage->id, 'owner_id' => $second->id, 'next_action' => $stage->next_action, 'due_at' => $stage->due_at]);
        $this->assertDatabaseHas('tasks', ['id' => $stage->task_id, 'assigned_to' => $second->id]);
    }

    public function test_queue_and_candidate_detail_respect_assignment_scope(): void
    {
        $app = $this->setupApplication();
        $other = Application::factory()->create();
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $app->update(['assigned_cs_id' => $cs->id]);
        $this->actingAs($cs)->get(route('protocol.index'))->assertOk()->assertSee($app->candidate->person->full_name)->assertDontSee($other->candidate->person->full_name);
        $this->get(route('protocol.show', $other))->assertNotFound();
        $this->post(route('protocol.action', $other), $this->payload($other, 'advance'))->assertNotFound();
        $this->post(route('protocol.action', $app), $this->payload($app, 'override', ['target' => 'family', 'reason_code' => 'FAMILY', 'reason' => 'Uji', 'risk_acknowledged' => 1]))->assertForbidden();
    }

    public function test_content_governance_and_settings_versions_are_auditable_and_immutable(): void
    {
        $this->setupApplication();
        $data = ['title' => 'Trust Pack Uji', 'version' => 1, 'source' => 'Program uji resmi', 'body' => 'Hak dan kewajiban program uji', 'effective_at' => now('Asia/Jakarta')->subHour()->format('Y-m-d\TH:i'), 'status' => 'Draft'];
        $this->post(route('protocol.content'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $id = DB::table('protocol_content_versions')->value('id');
        $this->post(route('protocol.content'), ['content_id' => $id, 'status' => 'Approved'])->assertSessionHasNoErrors();
        $this->post(route('protocol.content'), ['content_id' => $id, 'status' => 'Draft'])->assertUnprocessable();
        $this->assertDatabaseHas('protocol_content_versions', ['id' => $id, 'status' => 'Approved', 'approved_by' => auth()->id()]);
        $policy = app(TrustCommitmentProtocol::class)->policy();
        $settings = ['version' => 1, 'new_application_mode' => 'GUIDED', 'golden_hours' => [8, 16, 24], 'stages' => collect($policy['stages'])->map(fn ($item) => ['hours' => $item['hours'], 'action' => $item['action']])->all()];
        $this->post(route('protocol.settings'), $settings)->assertSessionHasNoErrors();
        $this->post(route('protocol.settings'), $settings)->assertConflict();
        $this->assertSame([8, 16, 24], app(TrustCommitmentProtocol::class)->policy()['golden_hours']);
        $new = Application::factory()->create();
        $this->assertDatabaseHas('protocol_runs', ['application_id' => $new->id, 'mode' => 'GUIDED']);
    }

    public function test_appointment_reminders_are_consolidated_and_do_not_duplicate_medical_or_tasks(): void
    {
        $app = $this->setupApplication();
        $this->moveFixture($app, 'appointment');
        $medical = MedicalEvent::factory()->create(['application_id' => $app->id]);
        $payload = $this->payload($app, 'appointment', ['pic_id' => auth()->id(), 'office_location' => 'Kantor uji', 'scheduled_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i'), 'transport' => 'Mandiri', 'medical_event_id' => $medical->id, 'appointment_status' => 'CONFIRMED']);
        $this->post(route('protocol.action', $app), $payload)->assertSessionHasNoErrors();
        $this->post(route('protocol.action', $app), $payload)->assertSessionHasNoErrors();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertSame(1, DB::table('care_events')->where('event_type', 'APPOINTMENT_REMINDER')->count());
        $this->travel(1)->days();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertSame(2, DB::table('care_events')->where('event_type', 'APPOINTMENT_REMINDER')->count());
        $this->assertDatabaseCount('medical_events', 1);
        $this->assertDatabaseCount('protocol_appointments', 1);
    }

    public function test_legacy_mapping_is_shadow_and_preserves_real_sources_and_pipeline(): void
    {
        $app = $this->setupApplication();
        $original = $app->current_stage_id;
        $runId = DB::table('protocol_runs')->where('application_id', $app->id)->value('id');
        DB::table('protocol_runs')->where('id', $runId)->delete();
        $medical = MedicalEvent::factory()->create(['application_id' => $app->id, 'status' => 'completed', 'completed_at' => now(), 'clearance_status' => 'fit']);
        $this->artisan('protocol:sync', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('protocol_runs', 0);
        $this->artisan('protocol:sync')->assertSuccessful();
        $this->artisan('protocol:sync')->assertSuccessful();
        $this->assertDatabaseHas('protocol_runs', ['application_id' => $app->id, 'mode' => 'SHADOW', 'stage' => 'golden']);
        $this->assertSame($original, $app->fresh()->current_stage_id);
        $this->assertDatabaseCount('medical_events', 1);
        $this->assertDatabaseCount('protocol_golden_windows', 1);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_enforced_protocol_blocks_early_mcu_and_pipeline_bypass(): void
    {
        $app = $this->setupApplication();
        $clinic = Company::factory()->create(['type' => 'clinic', 'status' => 'active']);
        $this->post(route('medical-events.store'), ['application_id' => $app->id, 'clinic_id' => $clinic->id, 'scheduled_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i'), 'status' => 'scheduled'])->assertSessionHasErrors('application_id');
        $target = PipelineStage::factory()->create(['sequence' => 900]);
        try {
            app(ProcessStageTransition::class)->move(auth()->user(), $app, ['from_stage_id' => $app->current_stage_id, 'to_stage_id' => $target->id]);
            $this->fail('Pipeline must respect enforced protocol.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('to_stage_id', $exception->errors());
        }
        $this->get(route('protocol.state', $app))->assertOk()->assertExactJson(['version' => 1, 'stage' => 'opt_in', 'gate_passed' => false])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_every_protocol_stage_renders_with_theme_and_canonical_profile_links(): void
    {
        $app = $this->setupApplication('SHADOW');
        $app->candidate->person->update(['full_name' => 'CPMI Uji Protokol']);
        $run = DB::table('protocol_runs')->where('application_id', $app->id)->first();
        DB::table('protocol_family_consents')->insert(['run_id' => $run->id, 'signatory_role' => 'PARENT_GUARDIAN', 'created_at' => now(), 'updated_at' => now()]);
        $app->candidate->contacts()->create(['name' => 'Kontak Uji Keluarga', 'relationship' => 'Orang tua']);
        $manifest = [];
        $directory = getenv('PROTOCOL_QA_FIXTURES');
        if ($directory && ! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        foreach (array_keys(config('protocol.stages')) as $stage) {
            $this->moveFixture($app, $stage);
            $response = $this->get(route('protocol.show', $app))->assertOk()->assertSee('protocol.css')->assertSee('data-theme-toggle', false)->assertDontSee('name="person[full_name]"', false);
            if ($directory) {
                $key = 'protocol-'.$stage;
                file_put_contents($directory.'/'.$key.'.html', $response->getContent());
                $manifest[] = ['key' => $key];
            }
        }
        $response = $this->get(route('protocol.index'))->assertOk()->assertSee('Bottleneck');
        if ($directory) {
            file_put_contents($directory.'/protocol-queue.html', $response->getContent());
            $manifest[] = ['key' => 'protocol-queue'];
            file_put_contents($directory.'/manifest.json', json_encode($manifest));
        }
    }

    public function test_corrected_medical_result_returns_accountability_and_reopens_fit_safely(): void
    {
        $app = $this->setupApplication();
        $this->moveFixture($app, 'waiting');
        $medical = MedicalEvent::factory()->create(['application_id' => $app->id, 'status' => 'completed', 'completed_at' => now(), 'clearance_status' => 'fit']);
        $this->assertSame('golden', $this->stage($app));
        $medical->update(['clearance_status' => 'retest']);
        $this->assertSame('result', $this->stage($app));
        $this->assertDatabaseHas('protocol_golden_windows', ['status' => 'MEDICAL_REVIEW']);
        $this->assertSame(0, DB::table('tasks')->whereNotIn('status', ['completed', 'cancelled'])->count());
        $medical->update(['clearance_status' => 'fit']);
        $this->assertSame('golden', $this->stage($app));
        $this->assertDatabaseCount('protocol_golden_windows', 1);
        $this->assertSame(1, DB::table('tasks')->where('status', 'open')->count());
        $this->assertSame(1, DB::table('protocol_stage_instances')->whereNull('exited_at')->count());
    }

    public function test_json_object_key_normalization_cannot_reorder_the_core_protocol(): void
    {
        $app = $this->setupApplication();
        $run = DB::table('protocol_runs')->where('application_id', $app->id)->first();
        $policy = app(TrustCommitmentProtocol::class)->policy();
        $policy['stages'] = array_reverse($policy['stages'], true);
        DB::table('protocol_settings')->where('id', 1)->update(['policy' => json_encode($policy)]);
        DB::table('protocol_runs')->where('id', $run->id)->update(['definition' => json_encode($policy)]);
        $this->assertSame(array_keys(config('protocol.stages')), array_keys(app(TrustCommitmentProtocol::class)->policy()['stages']));
        $this->get(route('protocol.show', $app))->assertOk()->assertViewHas('definition', fn ($definition) => array_keys($definition['stages']) === array_keys(config('protocol.stages')));
        $this->act($app, 'outcome', ['interest' => 'PROGRAM', 'inbound_confirmed' => 1]);
        $this->act($app, 'advance');
        $this->assertSame('trust', $this->stage($app));
    }

    public function test_early_admission_and_expired_fit_cannot_bypass_enforced_gates(): void
    {
        $app = $this->setupApplication();
        $batch = TrainingBatch::factory()->create();
        $this->post(route('training.store'), ['application_id' => $app->id, 'batch_id' => $batch->id, 'status' => 'ongoing', 'joined_at' => now('Asia/Jakarta')->toDateString()])->assertSessionHasErrors('application_id');
        $this->moveFixture($app, 'waiting');
        $medical = MedicalEvent::factory()->create(['application_id' => $app->id, 'status' => 'completed', 'completed_at' => now(), 'clearance_status' => 'fit', 'expires_at' => now('Asia/Jakarta')->toDateString()]);
        $this->assertSame('golden', $this->stage($app));
        $this->travel(2)->days();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertSame('result', $this->stage($app));
        $this->assertDatabaseHas('protocol_golden_windows', ['status' => 'MEDICAL_REVIEW']);
        $this->assertSame(0, DB::table('training_enrollments')->count());
    }

    public function test_closed_applications_leave_work_queues_without_losing_their_history(): void
    {
        $app = $this->setupApplication('SHADOW');
        $app->update(['status' => 'withdrawn']);
        $this->artisan('care:monitor')->assertSuccessful();
        $this->artisan('care:monitor')->assertSuccessful();
        $this->assertSame(0, DB::table('protocol_stage_instances')->whereNull('exited_at')->count());
        $this->assertSame(0, app(TrustCommitmentProtocol::class)->queue(auth()->user())->count());
        $this->get(route('protocol.show', $app))->assertOk()->assertSee('Proses sudah ditutup');
        $this->assertSame(1, DB::table('care_events')->where('event_type', 'PROTOCOL_CLOSED')->count());
    }
}
