<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\Country;
use App\Models\DocumentRequirementSet;
use App\Models\DocumentType;
use App\Models\JobOrder;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\User;
use App\Services\ProcessStageTransition;
use App\Services\ScopedTemplateResolver;
use App\Services\VisualConditionCompiler;
use Database\Seeders\HealthDocumentOperationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GuidedMasterConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_visual_conditions_support_groups_numeric_and_legacy_values(): void
    {
        $application = Application::factory()->create();
        $application->candidate->person->update(['gender' => 'female', 'marital_status' => 'married', 'birth_date' => now()->subYears(20)]);
        $compiler = app(VisualConditionCompiler::class);
        $rule = $compiler->compile(['match' => 'all', 'conditions' => [['field' => 'marital_status', 'operator' => 'equals', 'value' => 'married'], ['field' => 'age', 'operator' => 'less_than', 'value' => '21']]]);
        $this->assertTrue($compiler->matches($rule, $application));
        $this->assertTrue(app(ScopedTemplateResolver::class)->applies(['field' => 'gender', 'values' => ['female']], $application));
        $this->assertFalse($compiler->matches(['field' => 'not_a_field', 'values' => ['x']], $application));
        $rule['match'] = 'any';
        $rule['conditions'][0]['values'] = ['divorced'];
        $this->assertTrue($compiler->matches($rule, $application));
        $this->expectException(ValidationException::class);
        $compiler->compile(['field' => 'age', 'operator' => 'less_than', 'value' => 'abc']);
    }

    public function test_marital_status_rules_support_prd_codes_without_changing_person_values(): void
    {
        $application = Application::factory()->create();
        $compiler = app(VisualConditionCompiler::class);

        foreach (['MENIKAH' => 'married', 'CERAI_HIDUP' => 'divorced', 'CERAI_MATI' => 'widowed'] as $code => $storedValue) {
            $application->candidate->person->update(['marital_status' => $storedValue]);
            $rule = $compiler->compile(['field' => 'marital_status', 'operator' => 'equals', 'value' => $code]);

            $this->assertSame([$storedValue], $rule['values']);
            $this->assertTrue($compiler->matches($rule, $application));
            $this->assertSame($storedValue, $application->candidate->person->fresh()->marital_status);
            $application->candidate->person->update(['marital_status' => 'single']);
            $this->assertFalse($compiler->matches($rule, $application));
        }
    }

    public function test_visual_conditions_reject_malformed_and_incompatible_values(): void
    {
        $compiler = app(VisualConditionCompiler::class);
        $invalidRules = [
            ['field' => ['gender'], 'operator' => 'equals', 'value' => 'female'],
            ['field' => 'gender', 'operator' => ['equals'], 'value' => 'female'],
            ['field' => 'gender', 'operator' => 'equals', 'value' => ['female']],
            ['field' => 'gender', 'operator' => 'equals', 'value' => 'married'],
            ['field' => 'marital_status', 'operator' => 'equals', 'value' => 'female'],
            ['field' => 'marital_status', 'operator' => 'equals', 'value' => 'MENIKA'],
            ['field' => 'age', 'operator' => 'equals', 'value' => 'twenty'],
            ['field' => 'country_id', 'operator' => 'in', 'value' => 'ID'],
            ['field' => 'gender', 'operator' => 'less_than', 'value' => '2'],
            ['field' => 'marital_status', 'operator' => 'equals', 'values' => ['married', 'divorced']],
        ];

        foreach ($invalidRules as $rule) {
            try {
                $compiler->compile($rule);
                $this->fail('Invalid visual condition was accepted: '.json_encode($rule));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('conditions', $exception->errors());
            }
        }
    }

    public function test_document_rules_reject_scalar_legacy_json_without_creating_a_set(): void
    {
        $this->actingAs(User::factory()->create());
        $type = DocumentType::factory()->create();
        $payload = ['name' => 'Aturan tidak valid', 'scope_type' => 'GLOBAL', 'intent' => 'review', 'rules' => [['document_type_id' => $type->id, 'stage_id' => null, 'required_level' => 'CONDITIONAL', 'must_verify_before_exit' => 1, 'original_required' => 0, 'expiry_policy' => 'NONE', 'minimum_validity_days' => 0, 'sort_order' => 0]]];

        foreach (['true', '1', '"unexpected"'] as $condition) {
            $payload['rules'][0]['condition_json'] = $condition;
            $this->post(route('document-requirements.store'), $payload)->assertSessionHasErrors('conditions');
            $this->assertDatabaseCount('document_requirement_sets', 0);
        }
    }

    public function test_scope_search_excludes_inactive_choices_but_keeps_existing_selections(): void
    {
        $this->actingAs(User::factory()->create());
        $country = Country::factory()->create(['is_active' => false]);
        $company = Company::factory()->create(['status' => 'inactive']);

        foreach (['health-templates.references', 'document-requirements.references'] as $route) {
            foreach (['COUNTRY' => $country, 'SPONSOR' => $company] as $scope => $record) {
                $this->getJson(route($route, ['scope' => $scope, 'q' => $record->name]))
                    ->assertOk()->assertJsonCount(0, 'items');
                $this->getJson(route($route, ['scope' => $scope, 'q' => $record->name, 'selected' => [$record->id]]))
                    ->assertOk()->assertJsonFragment(['id' => $record->id, 'name' => $record->name]);
            }
        }
    }

    public function test_inactive_scopes_cannot_be_newly_selected_but_existing_configuration_remains_editable(): void
    {
        $this->actingAs(User::factory()->create());
        $this->seed(HealthDocumentOperationsSeeder::class);
        $questionId = DB::table('health_questions')->where('code', 'TB_HISTORY')->value('id');
        $type = DocumentType::factory()->create();
        $country = Country::factory()->create();
        $company = Company::factory()->create();
        $health = ['name' => 'Template cakupan uji', 'intent' => 'draft', 'question_ids' => [$questionId]];
        $documents = ['name' => 'Dokumen cakupan uji', 'intent' => 'draft', 'rules' => [['document_type_id' => $type->id, 'stage_id' => null, 'required_level' => 'REQUIRED', 'must_verify_before_exit' => 1, 'original_required' => 0, 'expiry_policy' => 'NONE', 'minimum_validity_days' => 0, 'sort_order' => 0]]];

        foreach (['COUNTRY' => $country, 'SPONSOR' => $company] as $scope => $record) {
            $scopeData = ['scope_type' => $scope, 'scope_id' => $record->id];
            $this->post(route('health-templates.store'), [...$health, ...$scopeData])->assertSessionHasNoErrors();
            $this->post(route('document-requirements.store'), [...$documents, ...$scopeData])->assertSessionHasNoErrors();
            $templateId = DB::table('health_screening_templates')->where('name', $health['name'])->latest('id')->value('id');
            $set = DocumentRequirementSet::where('name', $documents['name'])->latest('id')->firstOrFail();
            $record->update($scope === 'COUNTRY' ? ['is_active' => false] : ['status' => 'inactive']);

            $this->put(route('health-templates.update', $templateId), [...$health, ...$scopeData])->assertSessionHasNoErrors();
            $this->put(route('document-requirements.update', $set), [...$documents, ...$scopeData])->assertSessionHasNoErrors();
            $this->get(route('health-templates.show', $templateId))->assertOk()->assertSee($record->name);
            $this->get(route('document-requirements.show', $set))->assertOk()->assertSee($record->name);
            $this->post(route('health-templates.store'), [...$health, ...$scopeData])->assertSessionHasErrors('scope_id');
            $this->post(route('document-requirements.store'), [...$documents, ...$scopeData])->assertSessionHasErrors('scope_id');
            $this->assertSame(1, DB::table('health_screening_templates')->where('scope_type', $scope)->where('scope_id', $record->id)->count());
            $this->assertSame(1, DocumentRequirementSet::where('scope_type', $scope)->where('scope_id', $record->id)->count());
        }
    }

    public function test_used_country_code_is_immutable_and_status_is_audited(): void
    {
        $this->actingAs(User::factory()->create());
        $country = Country::factory()->create();
        JobOrder::factory()->create(['country_id' => $country->id]);
        $this->put(route('countries.update', $country), ['code' => 'XY', 'name' => $country->name, 'is_active' => 1])->assertSessionHasErrors('code');
        $this->assertNotSame('XY', $country->fresh()->code);
        $this->patch(route('countries.status', $country), ['is_active' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('countries', ['id' => $country->id, 'is_active' => 0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'master.country.status_changed']);
        $this->get(route('countries.index'))->assertOk()->assertSee('referensi operasional')->assertSee('Panduan');
    }

    public function test_used_partner_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::factory()->create(['status' => 'draft']);
        JobOrder::factory()->create(['company_id' => $company->id]);
        $this->delete(route('companies.destroy', $company))->assertSessionHasErrors('company');
        $this->assertNotSoftDeleted($company);
        $unused = Company::factory()->create(['status' => 'draft']);
        $this->delete(route('companies.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($unused);
    }

    public function test_visual_document_rules_publish_snapshot_without_mutating_previous_version(): void
    {
        $this->actingAs(User::factory()->create());
        $type = DocumentType::factory()->create();
        $data = ['name' => 'Aturan pernikahan', 'scope_type' => 'GLOBAL', 'intent' => 'review', 'rules' => [['document_type_id' => $type->id, 'stage_id' => null, 'required_level' => 'CONDITIONAL', 'must_verify_before_exit' => 1, 'original_required' => 0, 'expiry_policy' => 'NONE', 'minimum_validity_days' => 0, 'sort_order' => 0, 'condition' => ['match' => 'all', 'conditions' => [['field' => 'marital_status', 'operator' => 'equals', 'value' => 'married']]]]]];
        $this->post(route('document-requirements.store'), $data)->assertSessionHasNoErrors();
        $set = DocumentRequirementSet::firstOrFail();
        $this->post(route('document-requirements.publish', $set))->assertSessionHasNoErrors();
        $snapshot = $set->versions()->firstOrFail()->snapshot;
        $data['rules'][0]['condition']['conditions'][0]['value'] = 'divorced';
        $this->put(route('document-requirements.update', $set), $data)->assertSessionHasNoErrors();
        $this->assertSame($snapshot, $set->versions()->firstOrFail()->snapshot);
        $this->assertSame('divorced', $set->fresh()->draft_schema['rules'][0]['condition_rule']['conditions'][0]['values'][0]);
        $this->get(route('document-requirements.index', ['selected' => $set->id]))->assertOk()->assertSee('SEMUA kondisi')->assertDontSee('<textarea name="rules', false);
        if (getenv('MASTER_QA_FIXTURES')) {
            $html = $this->get(route('document-requirements.index', ['selected' => $set->id]))->getContent();
            file_put_contents('/tmp/jm-v812-document-detail.html', preg_replace('#https?://[^/]+/(css|js)/#', 'file:///var/www/juraganmigran/public/$1/', $html));
        }
        $data['rules'][0]['condition']['conditions'][0]['field'] = 'secret';
        $this->put(route('document-requirements.update', $set), $data)->assertSessionHasErrors('conditions');
    }

    public function test_workflow_versions_pin_new_applications_and_enforce_transitions(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);
        $first = PipelineStage::factory()->create(['sequence' => 1]);
        $second = PipelineStage::factory()->create(['sequence' => 2]);
        $last = PipelineStage::factory()->create(['sequence' => 3, 'is_terminal' => true]);
        $old = Application::factory()->create(['current_stage_id' => $first->id]);
        $payload = ['name' => 'Alur Terpandu', 'stages' => collect([$first, $second, $last])->map(fn ($stage) => ['id' => $stage->id, 'enabled' => 1, 'sla_hours' => 99, 'is_terminal' => $stage->id === $last->id, 'required_checkpoint' => 0])->all()];
        $this->post(route('master-workflows.save'), $payload)->assertSessionHasNoErrors();
        $workflow = DB::table('process_workflows')->first();
        $token = hash('sha256', $workflow->draft_schema);
        $this->post(route('master-workflows.publish'), ['id' => $workflow->id, 'draft_token' => $token, 'confirmed' => 1])->assertSessionHasNoErrors();
        $version = DB::table('process_workflow_versions')->first();
        $this->post(route('master-workflows.assign'), ['vacancy_id' => $old->vacancy_id, 'version_id' => $version->id, 'confirmed' => 1])->assertSessionHasNoErrors();
        $new = Application::factory()->create(['vacancy_id' => $old->vacancy_id, 'current_stage_id' => $first->id]);
        $this->assertNull($old->fresh()->workflow_version_id);
        $this->assertEquals($version->id, $new->workflow_version_id);
        try {
            app(ProcessStageTransition::class)->move($actor, $new, ['from_stage_id' => $first->id, 'to_stage_id' => $last->id]);
            $this->fail('Skipping a workflow transition must fail.');
        } catch (ValidationException) {
            $this->assertEquals($first->id, $new->fresh()->current_stage_id);
        }
        app(ProcessStageTransition::class)->move($actor, $new, ['from_stage_id' => $first->id, 'to_stage_id' => $second->id]);
        $this->assertEquals(99, $new->stageHistories()->latest('id')->first()->sla_hours_snapshot);
        $payload['id'] = $workflow->id;
        $payload['draft_token'] = $token;
        $payload['stages'][0]['sla_hours'] = 24;
        $this->post(route('master-workflows.save'), $payload)->assertSessionHasNoErrors();
        $this->assertSame($version->snapshot, DB::table('process_workflow_versions')->value('snapshot'));
        $this->post(route('master-workflows.save'), $payload)->assertSessionHasErrors('draft_token');
        $response = $this->get(route('master-workflows.index', ['selected' => $workflow->id]))->assertOk()->assertSee('Tinjau dan Terbitkan');
        if (getenv('MASTER_QA_FIXTURES')) {
            file_put_contents('/tmp/jm-v812-workflows.html', preg_replace('#https?://[^/]+/(css|js)/#', 'file:///var/www/juraganmigran/public/$1/', $response->getContent()));
        }
    }

    public function test_taxonomy_metadata_can_be_configured_and_inactive_choices_block_new_use(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = ['code' => 'VERIFY_DOC', 'name' => 'Verifikasi dokumen', 'category' => 'Dokumen', 'default_priority' => 'high', 'sla_hours' => 12, 'default_role' => 'Supervisor', 'is_active' => 1];
        $this->post(route('master-reference.save', 'jenis-tugas'), $payload)->assertSessionHasNoErrors();
        $entry = DB::table('master_reference_entries')->where('code', 'VERIFY_DOC')->first();
        $this->get(route('master-reference.index', 'jenis-tugas'))->assertOk()->assertSee('Verifikasi dokumen')->assertSee('12 jam');
        $task = Task::factory()->create(['task_type' => 'VERIFY_DOC']);
        $this->post(route('master-reference.save', 'jenis-tugas'), [...$payload, 'id' => $entry->id, 'code' => 'NEW_CODE'])->assertSessionHasErrors('code');
        $this->post(route('master-reference.save', 'jenis-tugas'), [...$payload, 'id' => $entry->id, 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'task_type' => 'VERIFY_DOC']);
        $this->expectException(ValidationException::class);
        Task::factory()->create(['task_type' => 'VERIFY_DOC']);
    }

    public function test_guided_question_editor_saves_without_json_and_keeps_canonical_code(): void
    {
        $this->actingAs(User::factory()->create());
        $data = ['code' => 'GUIDED_QUESTION', 'question' => 'Pertanyaan terpandu', 'category' => 'Riwayat penyakit', 'answer_type' => 'YES_NO_UNKNOWN', 'is_required' => 1, 'restricted' => 0, 'is_active' => 1, 'sequence' => 1, 'guided_options' => '', 'guided_clarification' => '', 'guided_followup' => '', 'guided_followup_when' => 'YA'];
        $this->post(route('health-templates.question'), $data)->assertSessionHasNoErrors();
        $question = DB::table('health_questions')->where('code', 'GUIDED_QUESTION')->first();
        $this->assertNotNull($question);
        $this->assertNull($question->clarification_rule);
        $this->post(route('health-templates.question'), [...$data, 'question_id' => $question->id, 'code' => 'RENAMED'])->assertSessionHasErrors('code');
        $this->post(route('health-templates.question'), [...$data, 'question_id' => $question->id, 'guided_clarification' => 'YA', 'guided_followup' => 'NEEDS_CLARIFICATION'])->assertSessionHasNoErrors();
        $this->assertSame(['YA'], json_decode(DB::table('health_questions')->where('id', $question->id)->value('clarification_rule'), true)['when']);
    }

    public function test_guided_views_render_without_configuration_for_cs(): void
    {
        $this->seed(HealthDocumentOperationsSeeder::class);
        $this->actingAs(User::factory()->create());
        Application::factory()->create();
        foreach (['countries.index', 'companies.index', 'health-templates.index', 'health-templates.create', 'document-requirements.index', 'document-requirements.create', 'countries.create'] as $route) {
            $response = $this->get(route($route))->assertOk()->assertSee('data-master-workspace', false)->assertSee('Panduan');
            if (getenv('MASTER_QA_FIXTURES')) {
                $html = preg_replace('#https?://[^/]+/(css|js)/#', 'file:///var/www/juraganmigran/public/$1/', $response->getContent());
                file_put_contents('/tmp/jm-v812-'.str_replace('.', '-', $route).'.html', $html);
            }
        }
        $role = DB::table('crm_roles')->where('code', 'cs')->value('id');
        $this->actingAs(User::factory()->create(['crm_role_id' => $role]));
        $this->post(route('health-templates.store'), [])->assertForbidden();
        $this->post(route('document-requirements.store'), [])->assertForbidden();
    }
}
