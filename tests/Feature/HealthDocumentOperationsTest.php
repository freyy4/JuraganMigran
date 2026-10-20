<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\DocumentRequirementSet;
use App\Models\DocumentType;
use App\Models\HealthInterview;
use App\Models\HealthScreeningTemplate;
use App\Models\HealthTemplateVersion;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\DocumentRequirementEngine;
use App\Services\HealthScreeningEngine;
use App\Services\ScopedTemplateResolver;
use Database\Seeders\HealthDocumentOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthDocumentOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Storage::fake('documents');
        $this->seed(HealthDocumentOperationsSeeder::class);
    }

    private function roleUser(string $code): User
    {
        return User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', $code)->value('id')]);
    }

    public function test_scope_options_support_job_orders_without_soft_deletes(): void
    {
        $application = Application::factory()->create();
        $order = $application->vacancy->jobOrder;
        $options = app(ScopedTemplateResolver::class)->options();

        $this->assertSame($order->code, $options['JOB_ORDER']->firstWhere('id', $order->id)->name);
        $application->vacancy->delete();
        $order->company->delete();

        $options = app(ScopedTemplateResolver::class)->options();
        $this->assertFalse($options['VACANCY']->contains('id', $application->vacancy_id));
        $this->assertFalse($options['SPONSOR']->contains('id', $order->company_id));
        $this->assertTrue($options['JOB_ORDER']->contains('id', $order->id));
    }

    private function requirements(Application $application, array $rows): DocumentRequirementSet
    {
        $set = DocumentRequirementSet::create(['name' => 'Kebutuhan uji', 'scope_type' => 'VACANCY', 'scope_id' => $application->vacancy_id, 'status' => 'published', 'draft_schema' => ['rules' => $rows]]);
        $version = $set->versions()->create(['version' => 1, 'snapshot' => ['rules' => $rows], 'published_at' => now()]);
        foreach ($rows as $row) {
            DB::table('document_requirement_rules')->insert(['document_requirement_version_id' => $version->id, 'required_level' => 'REQUIRED', 'must_verify_before_exit' => true, 'original_required' => false, 'expiry_policy' => 'VALID', 'minimum_validity_days' => 0, ...$row]);
        }

        return $set;
    }

    private function document(Application $application, DocumentType $type, array $extra = []): CandidateDocument
    {
        return CandidateDocument::create(['candidate_id' => $application->candidate_id, 'application_id' => $application->id, 'document_type_id' => $type->id, 'file_path' => UploadedFile::fake()->create('file.pdf', 10, 'application/pdf')->store('', 'documents'), 'status' => 'verified', 'version' => 1, ...$extra]);
    }

    public function test_seed_preserves_legacy_and_document_types_and_restricted_questions_are_not_default(): void
    {
        $type = DocumentType::factory()->create(['name' => 'Jenis lama']);
        $legacy = HealthInterview::factory()->create();
        $this->seed(HealthDocumentOperationsSeeder::class);
        $this->assertDatabaseHas('document_types', ['id' => $type->id, 'name' => 'Jenis lama']);
        $this->assertDatabaseHas('health_interviews', ['id' => $legacy->id, 'source' => 'LEGACY_GENERIC']);
        $snapshot = HealthTemplateVersion::firstOrFail()->snapshot['questions'];
        $this->assertCount(8, $snapshot);
        $this->assertFalse(collect($snapshot)->contains('restricted', true));
        $this->assertDatabaseHas('health_questions', ['code' => 'HIV_KNOWN_STATUS', 'restricted' => true]);
        $this->assertDatabaseCount('health_screening_templates', 1);
    }

    public function test_structured_answers_are_encrypted_drafts_complete_and_never_reject_employment(): void
    {
        $application = Application::factory()->create();
        $this->get(route('health-screenings.show', $application))->assertOk()->assertDontSee('Tambah Pertanyaan');
        $this->post(route('health-screenings.start', $application))->assertSessionHasNoErrors();
        $session = HealthInterview::where('application_id', $application->id)->firstOrFail();
        $questions = app(HealthScreeningEngine::class)->questions($session, $application);
        $this->put(route('health-screenings.update', $application), ['session_id' => $session->id, 'intent' => 'complete'])->assertSessionHasErrors();
        $answers = $questions->mapWithKeys(fn ($q) => [$q['id'] => ['value' => 'TIDAK']])->all();
        $answers[$questions->first()['id']] = ['value' => 'YA', 'details' => ['period' => 'Pengobatan tahun tertentu', 'treatment_finished' => 'YA']];
        $this->put(route('health-screenings.update', $application), ['session_id' => $session->id, 'intent' => 'draft', 'answers' => $answers])->assertSessionHasNoErrors();
        if (getenv('JM_V88_VISUAL_QA')) {
            $html = $this->get(route('health-screenings.show', $application))->getContent();
            $html = preg_replace('#https?://[^/]+/(css|js)/#', 'file:///var/www/juraganmigran/public/$1/', $html);
            file_put_contents('/tmp/jm-v88-screening.html', $html);
        }
        $stored = DB::table('health_answers')->where('health_interview_id', $session->id)->first();
        $this->assertNull($stored->answer);
        $this->assertStringNotContainsString('pengobatan', $stored->structured_value);
        $this->put(route('health-screenings.update', $application), ['session_id' => $session->id, 'intent' => 'complete'])->assertSessionHasNoErrors();
        $this->assertSame('NEEDS_CLARIFICATION', $session->fresh()->outcome);
        $this->assertSame('active', $application->fresh()->status);
        $this->assertDatabaseHas('tasks', ['application_id' => $application->id, 'task_type' => 'health_follow_up']);
        $this->put(route('health-screenings.update', $application), ['session_id' => $session->id, 'intent' => 'draft', 'answers' => $answers])->assertSessionHasErrors('session_id');
        $this->get(route('health-screenings.show', $application))->assertOk()->assertSee('Perlu klarifikasi');
    }

    public function test_scope_resolution_prioritizes_job_order_and_old_sessions_pin_published_version(): void
    {
        $application = Application::factory()->create();
        $global = HealthTemplateVersion::firstOrFail();
        $template = HealthScreeningTemplate::create(['name' => 'Job Order khusus', 'scope_type' => 'JOB_ORDER', 'scope_id' => $application->vacancy->job_order_id, 'status' => 'published', 'draft_schema' => ['question_ids' => []]]);
        $v1 = $template->versions()->create(['version' => 1, 'snapshot' => $global->snapshot, 'published_at' => now()]);
        $this->post(route('health-screenings.start', $application))->assertSessionHasNoErrors();
        $session = HealthInterview::firstOrFail();
        $this->assertSame($v1->id, $session->health_template_version_id);
        $v2 = $template->versions()->create(['version' => 2, 'snapshot' => ['questions' => array_slice($global->snapshot['questions'], 0, 2)], 'published_at' => now()]);
        $this->assertSame($v2->id, app(HealthScreeningEngine::class)->resolved($application)->id);
        $this->assertCount(8, app(HealthScreeningEngine::class)->questions($session, $application));
        $this->get(route('health-screenings.show', [$application, 'session' => HealthInterview::factory()->create()->id]))->assertNotFound();
    }

    public function test_restricted_publication_requires_policy_and_cs_cannot_configure_or_reveal(): void
    {
        $id = DB::table('health_questions')->where('code', 'HIV_KNOWN_STATUS')->value('id');
        $template = HealthScreeningTemplate::create(['name' => 'Terbatas', 'scope_type' => 'GLOBAL', 'status' => 'review', 'draft_schema' => ['question_ids' => [$id]]]);
        $this->post(route('health-templates.publish', $template), [])->assertSessionHasErrors('policy_reference');
        $this->post(route('health-templates.publish', $template), ['policy_reference' => 'Kebijakan medis terbatas yang telah ditinjau'])->assertSessionHasNoErrors();
        $this->assertNotNull($template->versions()->first()->policy_approved_by);
        $cs = $this->roleUser('cs');
        $application = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $this->actingAs($cs)->post(route('health-screenings.start', $application))->assertForbidden();
        $this->post(route('health-templates.question'), ['question' => 'Sembarangan'])->assertForbidden();
        $this->post('/pemeriksaan-awal-kesehatan/'.$application->id.'/pertanyaan', [])->assertNotFound();
    }

    public function test_management_gets_aggregates_and_technical_admin_does_not_get_sensitive_content(): void
    {
        $application = Application::factory()->create();
        HealthInterview::factory()->create(['application_id' => $application->id, 'outcome' => 'REFER_TO_MCU', 'status' => 'completed']);
        $name = $application->candidate->person->full_name;
        $this->actingAs($this->roleUser('management'))->get(route('health-screenings.index'))->assertOk()->assertSee('Tampilan agregat')->assertDontSee($name)->assertViewHas('metrics', fn ($metrics) => $metrics['REFER_TO_MCU'] === 1);
        $this->get(route('health-screenings.show', $application))->assertNotFound();
        $this->actingAs($this->roleUser('super_admin'))->get(route('health-screenings.index'))->assertForbidden();
        $document = $this->document($application, DocumentType::factory()->create());
        $this->get(route('documents.download', $document))->assertNotFound();
        $this->get(route('document-operations.workspace', $document))->assertNotFound();
    }

    public function test_stage_requirements_do_not_count_future_documents_and_gate_is_atomic(): void
    {
        $early = PipelineStage::factory()->create(['sequence' => 1]);
        $later = PipelineStage::factory()->create(['sequence' => 10]);
        $application = Application::factory()->create(['current_stage_id' => $early->id]);
        $ktp = DocumentType::factory()->create(['has_expiry' => false]);
        $passport = DocumentType::factory()->create();
        $this->requirements($application, [['document_type_id' => $ktp->id, 'stage_id' => $early->id], ['document_type_id' => $passport->id, 'stage_id' => $later->id]]);
        $rows = app(DocumentRequirementEngine::class)->rows($application);
        $this->assertSame('not_required_yet', $rows->firstWhere('rule.document_type_id', $passport->id)['status']);
        $this->assertSame(1, app(DocumentRequirementEngine::class)->summary($application)['total']);
        $payload = ['from_stage_id' => $early->id, 'to_stage_id' => $later->id, 'reason' => 'Sudah diperiksa', 'confirmed' => 1];
        $this->patch(route('pipeline.move', $application), $payload)->assertSessionHasErrors('to_stage_id');
        $this->assertSame($early->id, $application->fresh()->current_stage_id);
        $this->assertDatabaseCount('application_stage_histories', 0);
        $this->document($application, $ktp);
        $this->patch(route('pipeline.move', $application), $payload)->assertSessionHasNoErrors();
        $this->assertSame($later->id, $application->fresh()->current_stage_id);
        $this->assertSame(2, app(DocumentRequirementEngine::class)->summary($application->fresh())['total']);
        $this->get(route('documents.show', $application))->assertOk()->assertSee('Kelengkapan Dokumen Tahap Ini');
    }

    public function test_minimum_validity_and_original_custody_are_independent_and_gate_exception_requires_other_approver(): void
    {
        $stage = PipelineStage::factory()->create(['sequence' => 2]);
        $next = PipelineStage::factory()->create(['sequence' => 3]);
        $application = Application::factory()->create(['current_stage_id' => $stage->id]);
        $type = DocumentType::factory()->create();
        $this->requirements($application, [['document_type_id' => $type->id, 'stage_id' => $stage->id, 'minimum_validity_days' => 30, 'original_required' => true]]);
        $document = $this->document($application, $type, ['expires_at' => now()->addDays(10)->toDateString()]);
        $row = app(DocumentRequirementEngine::class)->rows($application)->first();
        $this->assertTrue($row['minimumInvalid']);
        $document->update(['expires_at' => now()->addDays(90)->toDateString()]);
        $payload = ['from_stage_id' => $stage->id, 'to_stage_id' => $next->id, 'confirmed' => 1];
        $this->patch(route('pipeline.move', $application), $payload)->assertSessionHasErrors('to_stage_id');
        $this->post(route('document-operations.exception', $application), ['document_type_id' => $type->id, 'reason' => 'Dokumen asli sedang diproses pihak terkait', 'valid_until' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $exception = DB::table('document_exceptions')->first();
        $this->post(route('document-operations.approve-exception', [$application, $exception->id]), ['decision' => 'approved'])->assertStatus(422);
        $this->actingAs(User::factory()->create())->post(route('document-operations.approve-exception', [$application, $exception->id]), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->patch(route('pipeline.move', $application), $payload)->assertSessionHasNoErrors();
        $this->assertSame('verified', $document->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document-operations.approve-exception']);
    }

    public function test_correction_presets_private_preview_history_and_replacement_are_preserved(): void
    {
        $application = Application::factory()->create();
        $type = DocumentType::factory()->create(['has_expiry' => false]);
        $application->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);
        $document = $this->document($application, $type, ['status' => 'uploaded']);
        $this->patch(route('documents.review', $document), ['status' => 'needs_correction', 'correction_code' => 'OTHER'])->assertSessionHasErrors('rejection_reason');
        $this->patch(route('documents.review', $document), ['status' => 'needs_correction', 'correction_code' => 'PHOTO_BLURRY'])->assertSessionHasNoErrors();
        $this->assertSame('Foto buram', $document->fresh()->rejection_reason);
        $this->assertDatabaseHas('document_verification_events', ['candidate_document_id' => $document->id, 'to_status' => 'needs_correction', 'reason_code' => 'PHOTO_BLURRY']);
        $this->get(route('document-operations.workspace', $document))->assertOk()->assertSee('Foto buram');
        if (getenv('JM_V88_VISUAL_QA')) {
            $html = $this->get(route('document-operations.workspace', $document))->getContent();
            $html = preg_replace('#https?://[^/]+/(css|js)/#', 'file:///var/www/juraganmigran/public/$1/', $html);
            file_put_contents('/tmp/jm-v88-review.html', $html);
        }
        $this->get(route('document-operations.preview', $document))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post(route('documents.upload', $application), ['document_type_id' => $type->id, 'file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame('superseded', $document->fresh()->status);
        $this->assertDatabaseCount('document_verification_events', 1);
        Storage::disk('documents')->assertExists($document->file_path);
    }

    public function test_external_dependencies_are_not_cs_overdue_and_bulk_reminders_are_scoped_safe_and_idempotent(): void
    {
        $cs = $this->roleUser('cs');
        $application = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $type = DocumentType::factory()->create(['has_expiry' => false]);
        $application->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);
        $template = DB::table('whatsapp_templates')->insertGetId(['name' => 'Reminder aman', 'status' => 'active', 'body' => 'Halo {name}, lengkapi dokumen di {portal_url}.', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($cs)->post(route('document-operations.action', $application), ['document_type_id' => $type->id, 'owner' => 'EXTERNAL_DEPENDENCY', 'due_at' => now('Asia/Jakarta')->subDay()->format('Y-m-d\TH:i'), 'reason' => 'Menunggu penerbitan'])->assertSessionHasNoErrors();
        $row = app(DocumentRequirementEngine::class)->rows($application)->first();
        $this->assertFalse($row['overdue']);
        $payload = ['applications' => [$application->id], 'document_type_id' => $type->id, 'template_id' => $template, 'batch_key' => (string) Str::uuid()];
        $this->post(route('document-operations.reminder'), $payload)->assertSessionHasErrors('applications');
        $this->post(route('document-operations.action', $application), ['document_type_id' => $type->id, 'owner' => 'CPMI_ACTION', 'reason' => 'Lengkapi dokumen'])->assertSessionHasNoErrors();
        $this->post(route('document-operations.reminder'), $payload)->assertSessionHasNoErrors();
        $this->post(route('document-operations.reminder'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('document_followups', 1);
        $this->get(route('document-operations.followups'))->assertOk()->assertSee('Siap Dikirim');
        $payload['applications'] = [Application::factory()->create(['assigned_cs_id' => User::factory()->create()->id])->id];
        $this->post(route('document-operations.reminder'), $payload)->assertForbidden();
        DB::table('whatsapp_templates')->where('id', $template)->update(['body' => '{nik}']);
        $payload['applications'] = [$application->id];
        $payload['batch_key'] = (string) Str::uuid();
        $this->post(route('document-operations.reminder'), $payload)->assertSessionHasErrors('template_id');
    }

    public function test_conditional_text_is_required_only_when_the_parent_answer_triggers_it(): void
    {
        $parent = DB::table('health_questions')->where('code', 'TB_HISTORY')->first();
        $child = DB::table('health_questions')->insertGetId(['code' => 'CONDITIONAL_CONTEXT', 'question' => 'Penjelasan tambahan', 'category' => 'Klarifikasi', 'answer_type' => 'SHORT_TEXT_CONDITIONAL', 'applicability_rule' => json_encode(['question_code' => 'TB_HISTORY', 'values' => ['YA']]), 'is_required' => true, 'is_active' => true, 'sequence' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $question = collect(HealthTemplateVersion::first()->snapshot['questions'])->firstWhere('id', $parent->id);
        $question['clarification_rule'] = ['when' => []];
        $question['followup_rule'] = null;
        $template = HealthScreeningTemplate::create(['name' => 'Klarifikasi bersyarat', 'scope_type' => 'GLOBAL', 'status' => 'published', 'draft_schema' => ['question_ids' => [$parent->id, $child]]]);
        $template->versions()->create(['version' => 1, 'published_at' => now(), 'snapshot' => ['questions' => [$question, ['id' => $child, 'code' => 'CONDITIONAL_CONTEXT', 'question' => 'Penjelasan tambahan', 'category' => 'Klarifikasi', 'answer_type' => 'SHORT_TEXT_CONDITIONAL', 'is_required' => true, 'options' => null, 'helper_text' => null, 'clarification_rule' => null, 'followup_rule' => null, 'restricted' => false, 'applicability_rule' => ['question_code' => 'TB_HISTORY', 'values' => ['YA']]]]]]);
        $application = Application::factory()->create();
        $this->post(route('health-screenings.start', $application))->assertSessionHasNoErrors();
        $session = HealthInterview::where('application_id', $application->id)->first();
        $this->put(route('health-screenings.update', $application), ['session_id' => $session->id, 'intent' => 'complete', 'answers' => [$parent->id => ['value' => 'YA']]])->assertSessionHasErrors('answers.'.$child.'.value');
        $this->put(route('health-screenings.update', $application), ['session_id' => $session->id, 'intent' => 'complete', 'answers' => [$parent->id => ['value' => 'TIDAK']]])->assertSessionHasNoErrors();
        $this->assertSame(100, $session->fresh()->completeness_percentage);
    }

    public function test_return_approval_and_custody_history_do_not_change_digital_verification(): void
    {
        $application = Application::factory()->create();
        $type = DocumentType::factory()->create(['has_expiry' => false]);
        $document = $this->document($application, $type);
        $this->post(route('document-custodies.store'), ['candidate_document_id' => $document->id, 'holder' => 'Tim JM', 'location' => 'Brankas'])->assertSessionHasNoErrors();
        $custody = DB::table('original_document_custodies')->first();
        $payload = ['holder' => 'Tim JM', 'location' => 'Brankas', 'reason' => 'Pengembalian diminta CPMI'];
        $this->patch(route('document-custodies.update', $custody->id), [...$payload, 'action' => 'request_return'])->assertSessionHasNoErrors();
        $this->patch(route('document-custodies.update', $custody->id), [...$payload, 'action' => 'schedule_return'])->assertSessionHasErrors('action');
        $this->patch(route('document-custodies.update', $custody->id), [...$payload, 'action' => 'approve_return'])->assertSessionHasNoErrors();
        $this->patch(route('document-custodies.update', $custody->id), [...$payload, 'action' => 'schedule_return', 'expected_return_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('original_document_custodies', ['id' => $custody->id, 'custody_state' => 'RETURN_SCHEDULED']);
        $this->assertDatabaseCount('document_custody_histories', 4);
        $this->assertSame('verified', $document->fresh()->status);
    }

    public function test_configuration_pages_and_publication_use_existing_types_and_immutable_versions(): void
    {
        $this->get(route('health-templates.index'))->assertOk();
        $this->get(route('health-templates.create'))->assertOk();
        $this->get(route('health-templates.show', HealthScreeningTemplate::first()))->assertOk();
        $this->get(route('document-requirements.index'))->assertOk();
        $this->get(route('document-requirements.create'))->assertOk();
        $type = DocumentType::factory()->create(['has_expiry' => false]);
        $payload = ['name' => 'Dokumen wawancara', 'scope_type' => 'GLOBAL', 'intent' => 'review', 'rules' => [['document_type_id' => $type->id, 'stage_id' => null, 'required_level' => 'REQUIRED', 'must_verify_before_exit' => 1, 'original_required' => 0, 'expiry_policy' => 'NONE', 'minimum_validity_days' => 0, 'sort_order' => 1]]];
        $this->post(route('document-requirements.store'), $payload)->assertSessionHasNoErrors();
        $set = DocumentRequirementSet::firstOrFail();
        $this->post(route('document-requirements.publish', $set))->assertSessionHasNoErrors();
        $this->get(route('document-requirements.show', $set))->assertOk()->assertSee('v1');
        $this->assertDatabaseCount('document_types', 1);
        $this->assertDatabaseHas('document_requirement_rules', ['document_type_id' => $type->id]);
        if (getenv('JM_V88_VISUAL_QA')) {
            foreach (['health-master' => route('health-templates.show', HealthScreeningTemplate::first()), 'document-config' => route('document-requirements.show', $set)] as $name => $url) {
                $html = $this->get($url)->getContent();
                $html = preg_replace('#https?://[^/]+/(css|js)/#', 'file:///var/www/juraganmigran/public/$1/', $html);
                file_put_contents('/tmp/jm-v88-'.$name.'.html', $html);
            }
        }
    }
}
