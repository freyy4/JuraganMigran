<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\ApplicationStageHistory;
use App\Models\DocumentRequirementSet;
use App\Models\DocumentType;
use App\Models\FlightDetail;
use App\Models\PipelineStage;
use App\Models\ReadinessChecklist;
use App\Models\ReadinessItem;
use App\Models\Task;
use App\Models\User;
use App\Services\ProcessCockpit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessCockpitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->startOfSecond());
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create($attributes + ['crm_role_id' => DB::table('crm_roles')->where('code', $role)->value('id')]);
    }

    public function test_migration_can_resume_after_partial_mysql_application(): void
    {
        $migration = require database_path('migrations/2026_10_13_145230_add_process_cockpit_fields.php');
        $application = Application::factory()->create(['next_action' => 'Tetap dipertahankan']);
        Schema::table('process_message_drafts', fn (Blueprint $table) => $table->dropUnique('process_message_batch_unique'));
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('process_message_drafts', 'process_message_batch_unique'));
        $this->assertSame('Tetap dipertahankan', $application->fresh()->next_action);
    }

    public function test_default_is_paginated_table_and_missing_stage_is_an_exception(): void
    {
        $stage = PipelineStage::factory()->create(['name' => 'Seleksi Dokumen']);
        $first = Application::factory()->create(['current_stage_id' => $stage->id]);
        Application::factory()->count(30)->create(['candidate_id' => $first->candidate_id, 'vacancy_id' => $first->vacancy_id, 'current_stage_id' => $stage->id]);
        Application::factory()->create(['candidate_id' => $first->candidate_id, 'vacancy_id' => $first->vacancy_id]);
        $this->get(route('pipeline.index'))->assertOk()->assertViewHas('mode', 'table')
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 25 && $rows->total() === 32)
            ->assertViewHas('columns', [])
            ->assertViewHas('exceptions', fn ($exceptions) => $exceptions['no_stage'] === 1)
            ->assertSee('Panel Prioritas')->assertSee('Next Action');
        $this->get(route('pipeline.index', ['mode' => 'board']))->assertOk()->assertViewHas('columns', fn ($columns) => count($columns) === 1 && $columns[0]['applications']->count() === 10);
        $this->get(route('pipeline.index', ['exception' => 'no_stage']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get('/alur-proses')->assertOk();
        $this->get(route('pipeline.index', ['per_page' => 1000]))->assertSessionHasErrors('per_page');
    }

    public function test_search_period_stage_and_contact_filters_are_server_side_and_scoped(): void
    {
        $cs = $this->user('cs');
        $mine = Application::factory()->create(['application_code' => 'APP-SEARCH', 'assigned_cs_id' => $cs->id]);
        $mine->candidate->person->update(['full_name' => 'Nama Dalam Scope', 'whatsapp' => '+628123456789']);
        $outside = Application::factory()->create(['application_code' => 'APP-OUTSIDE']);
        $this->actingAs($cs);
        foreach (['APP-SEARCH', 'Nama Dalam Scope', $mine->candidate->candidate_code, '628123456789', (string) $mine->id] as $search) {
            $this->getJson(route('pipeline.api', ['widget' => 'rows', 'search' => $search]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine->id)->assertDontSee($outside->application_code);
        }
        $this->get(route('pipeline.index'))->assertOk()->assertDontSee($outside->application_code)->assertViewHas('summary', fn ($s) => $s['total'] === 1);
        $this->getJson(route('pipeline.api', ['widget' => 'rows', 'search' => 'APP-OUTSIDE']))->assertJsonPath('total', 0);
        $this->getJson(route('pipeline.api', ['widget' => 'rows', 'from' => now('Asia/Jakarta')->addDay()->toDateString()]))->assertJsonPath('total', 0);
        $this->getJson(route('pipeline.api', ['widget' => 'rows']))->assertDontSee('whatsapp')->assertDontSee('nik')->assertDontSee('file_path')->assertDontSee('health_answers');
        $this->actingAs($this->user('admin_operations'));
        $this->getJson(route('pipeline.api', ['widget' => 'rows', 'search' => '628123456789']))->assertJsonPath('total', 0);
    }

    public function test_priority_aging_sla_and_jakarta_followup_queues_use_domain_data(): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 10:00:00', 'Asia/Jakarta')->utc());
        $stage = PipelineStage::factory()->create(['sla_hours' => 48]);
        $late = Application::factory()->create(['current_stage_id' => $stage->id]);
        ApplicationStageHistory::factory()->create(['application_id' => $late->id, 'to_stage_id' => $stage->id, 'entered_at' => now()->subHours(60), 'sla_hours_snapshot' => 48]);
        $stage->update(['sla_hours' => 100]);
        $today = Application::factory()->create();
        Task::factory()->create(['application_id' => $today->id, 'title' => 'Hubungi kandidat', 'due_at' => now()->addHours(3)]);
        Task::factory()->create(['application_id' => $today->id, 'title' => 'Tugas selesai bukan next action', 'due_at' => now()->subDay(), 'status' => 'completed']);
        $critical = Application::factory()->create();
        ApplicationBlocker::factory()->create(['application_id' => $critical->id, 'description' => 'SENSITIVE BLOCKER DETAIL']);
        FlightDetail::factory()->create(['application_id' => $critical->id, 'departure_at' => now()->addDays(3)]);
        $rows = app(ProcessCockpit::class)->rows(auth()->user())->get()->keyBy('id');
        $lateRow = app(ProcessCockpit::class)->decorate($rows[$late->id]);
        $this->assertSame('high', $lateRow->priority);
        $this->assertSame('overdue', $lateRow->sla_state);
        $this->assertSame('2 hari', $lateRow->age_label);
        $this->assertSame('critical', app(ProcessCockpit::class)->decorate($rows[$critical->id])->priority);
        $this->assertSame('Hubungi kandidat', $rows[$today->id]->next_action);
        $this->getJson(route('pipeline.api', ['widget' => 'rows', 'queue' => 'today']))->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $today->id);
        $this->getJson(route('pipeline.api', ['widget' => 'rows', 'queue' => 'overdue']))->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $late->id);
        $this->getJson(route('pipeline.api', ['widget' => 'priorities']))->assertDontSee('SENSITIVE BLOCKER DETAIL');
        foreach (['summary', 'followups', 'board'] as $mode) {
            $this->get(route('pipeline.index', ['mode' => $mode]))->assertOk();
        }
    }

    public function test_readiness_uses_existing_checklists_not_a_stage_label(): void
    {
        $app = Application::factory()->create(['current_stage_id' => PipelineStage::factory()->create(['name' => 'MCU'])->id]);
        $this->getJson(route('pipeline.api', ['widget' => 'summary']))->assertJsonPath('mcu', 0);
        $checklist = ReadinessChecklist::factory()->create(['application_id' => $app->id, 'type' => 'mcu']);
        $item = ReadinessItem::factory()->create(['readiness_checklist_id' => $checklist->id]);
        $this->getJson(route('pipeline.api', ['widget' => 'summary']))->assertJsonPath('mcu', 0);
        $item->update(['status' => 'completed']);
        $this->getJson(route('pipeline.api', ['widget' => 'summary']))->assertJsonPath('mcu', 1);
        ApplicationBlocker::factory()->create(['application_id' => $app->id]);
        $this->getJson(route('pipeline.api', ['widget' => 'summary']))->assertJsonPath('mcu', 0);
    }

    public function test_bulk_stage_moves_apply_document_gate_and_report_partial_success(): void
    {
        $first = PipelineStage::factory()->create(['sequence' => 1]);
        $next = PipelineStage::factory()->create(['sequence' => 2, 'sla_hours' => 72]);
        $blocked = Application::factory()->create(['current_stage_id' => $first->id]);
        $ready = Application::factory()->create(['current_stage_id' => $first->id]);
        $type = DocumentType::factory()->create();
        $set = DocumentRequirementSet::create(['name' => 'Gate uji', 'scope_type' => 'VACANCY', 'scope_id' => $blocked->vacancy_id, 'status' => 'published', 'draft_schema' => ['rules' => []]]);
        $version = $set->versions()->create(['version' => 1, 'snapshot' => ['rules' => []], 'published_at' => now()]);
        DB::table('document_requirement_rules')->insert(['document_requirement_version_id' => $version->id, 'document_type_id' => $type->id, 'stage_id' => $first->id, 'required_level' => 'REQUIRED', 'must_verify_before_exit' => true, 'original_required' => false, 'expiry_policy' => 'VALID', 'minimum_validity_days' => 0]);
        $response = $this->postJson(route('pipeline.bulk'), ['action' => 'move', 'applications' => [$blocked->id, $ready->id], 'from_stages' => [$blocked->id => $first->id, $ready->id => $first->id], 'to_stage_id' => $next->id, 'reason' => 'Pemeriksaan selesai', 'confirmed' => true]);
        $response->assertOk()->assertJsonPath('results.0.success', false)->assertJsonPath('results.1.success', true)->assertDontSee($type->name);
        $this->assertSame($first->id, $blocked->fresh()->current_stage_id);
        $this->assertSame($next->id, $ready->fresh()->current_stage_id);
        $this->assertDatabaseHas('application_stage_histories', ['application_id' => $ready->id, 'sla_hours_snapshot' => 72, 'changed_by' => auth()->id()]);
        $this->postJson(route('pipeline.bulk'), ['action' => 'move', 'applications' => [$ready->id], 'from_stages' => [$ready->id => $first->id], 'to_stage_id' => $first->id, 'reason' => 'Kembali ke seleksi', 'confirmed' => true])->assertJsonPath('results.0.success', false);
    }

    public function test_bulk_permissions_scope_confirmation_tasks_and_assignment(): void
    {
        $cs = $this->user('cs', ['team_id' => 9]);
        $supervisor = $this->user('supervisor', ['team_id' => 9]);
        $mine = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $outside = Application::factory()->create();
        $this->actingAs($cs);
        $payload = ['action' => 'task', 'applications' => [$mine->id, $outside->id], 'title' => 'Hubungi CPMI', 'due_at' => '2026-10-14T09:00'];
        $this->postJson(route('pipeline.bulk'), $payload)->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson(route('pipeline.bulk'), $payload + ['confirmed' => true])->assertOk()->assertJsonPath('results.0.success', true)->assertJsonPath('results.1.success', false);
        $this->assertDatabaseHas('tasks', ['application_id' => $mine->id, 'assigned_to' => $cs->id, 'title' => 'Hubungi CPMI', 'due_at' => '2026-10-14 02:00:00']);
        $this->assertDatabaseMissing('tasks', ['application_id' => $outside->id]);
        $this->postJson(route('pipeline.bulk'), ['action' => 'assign', 'applications' => [$mine->id], 'assigned_cs_id' => $cs->id, 'reason' => 'Penyesuaian tim', 'confirmed' => true])->assertForbidden();
        $this->actingAs($supervisor)->postJson(route('pipeline.bulk'), ['action' => 'assign', 'applications' => [$mine->id], 'assigned_cs_id' => $supervisor->id, 'reason' => 'Penyesuaian tim', 'confirmed' => true])->assertOk()->assertJsonPath('results.0.success', true);
        $this->assertSame($supervisor->id, $mine->fresh()->assigned_cs_id);
        $this->postJson(route('pipeline.bulk'), ['action' => 'priority', 'applications' => [$mine->id], 'priority' => 'high', 'confirmed' => true])->assertOk();
        $this->assertSame('high', $mine->fresh()->process_priority);
        $this->postJson(route('pipeline.bulk'), ['action' => 'export', 'applications' => [$mine->id], 'confirmed' => true])->assertForbidden();
    }

    public function test_next_action_and_wait_state_can_be_set_without_duplicate_tasks(): void
    {
        $application = Application::factory()->create();
        $this->postJson(route('pipeline.bulk'), ['action' => 'next', 'applications' => [$application->id], 'confirmed' => true])->assertOk()->assertJsonPath('results.0.success', false);
        $this->postJson(route('pipeline.bulk'), ['action' => 'next', 'applications' => [$application->id], 'waiting_status' => 'WAITING_EXTERNAL', 'next_action' => 'Menunggu konfirmasi jadwal', 'next_action_due_at' => now()->addDay()->format('Y-m-d\TH:i'), 'confirmed' => true])->assertOk()->assertJsonPath('results.0.success', true);
        $this->getJson(route('pipeline.api', ['widget' => 'rows', 'queue' => 'waiting_external']))->assertJsonPath('total', 1)->assertJsonPath('data.0.operational_status', 'WAITING_EXTERNAL');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_saved_views_are_private_and_export_is_safe(): void
    {
        $owner = auth()->user();
        $application = Application::factory()->create();
        $application->candidate->person->update(['full_name' => '=FORMULA()']);
        $this->post(route('pipeline.save-view'), ['name' => 'Hari ini', 'queue' => 'today', 'mode' => 'followups'])->assertSessionHasNoErrors();
        $view = DB::table('process_user_views')->first();
        $this->get(route('pipeline.index', ['saved_view' => $view->id]))->assertOk()->assertViewHas('mode', 'followups');
        $this->actingAs(User::factory()->create())->get(route('pipeline.index', ['saved_view' => $view->id]))->assertNotFound();
        $this->actingAs($owner);
        $response = $this->post(route('pipeline.bulk'), ['action' => 'export', 'applications' => [$application->id], 'confirmed' => true]);
        $response->assertOk();
        $this->assertStringContainsString("'=FORMULA()", $response->streamedContent());
        $this->assertStringNotContainsString($application->candidate->person->whatsapp ?? 'NEVER_EXPORTED', $response->streamedContent());
    }

    public function test_message_drafts_require_consent_templates_and_explicit_manual_sending(): void
    {
        $app = Application::factory()->create();
        $template = DB::table('whatsapp_templates')->insertGetId(['name' => 'Tindak lanjut aman', 'body' => 'Halo {name}, silakan buka {portal_url}', 'status' => 'active']);
        $data = ['action' => 'message', 'applications' => [$app->id], 'template_id' => $template, 'batch_key' => (string) Str::uuid(), 'confirmed' => true];
        $this->postJson(route('pipeline.bulk'), $data)->assertOk()->assertJsonPath('results.0.success', false);
        DB::table('portal_accounts')->insert(['candidate_id' => $app->candidate_id, 'user_id' => User::factory()->create()->id, 'normalized_phone' => '+628123456789', 'consented_at' => now()]);
        $app->candidate->person->update(['whatsapp' => '+628123456789']);
        $this->postJson(route('pipeline.bulk'), $data)->assertOk()->assertJsonPath('results.0.success', true);
        $this->postJson(route('pipeline.bulk'), $data)->assertOk();
        $this->assertDatabaseCount('process_message_drafts', 1);
        $draft = DB::table('process_message_drafts')->first();
        $this->post(route('pipeline.message', $draft->id))->assertRedirectContains('https://wa.me/628123456789?text=');
        $this->assertDatabaseHas('process_message_drafts', ['id' => $draft->id, 'status' => 'whatsapp_opened']);
        DB::table('whatsapp_templates')->where('id', $template)->update(['body' => '{nik}']);
        $data['batch_key'] = (string) Str::uuid();
        $this->postJson(route('pipeline.bulk'), $data)->assertJsonPath('results.0.success', false);
    }

    public function test_cockpit_uses_safe_scheduled_events_and_configured_stages(): void
    {
        $names = ['Seleksi Dokumen', 'Wawancara', 'MCU', 'LPK', 'OPP', 'Terbang'];
        foreach ($names as $index => $name) {
            $stage = PipelineStage::factory()->create(['name' => $name, 'sequence' => $index + 1, 'sla_hours' => 72]);
            for ($number = 0; $number < 6; $number++) {
                $app = Application::factory()->create(['current_stage_id' => $stage->id, 'assigned_cs_id' => auth()->id()]);
                ApplicationStageHistory::factory()->create(['application_id' => $app->id, 'to_stage_id' => $stage->id, 'entered_at' => now()->subHours(12 + $index * 20)]);
                Task::factory()->create(['application_id' => $app->id, 'assigned_to' => auth()->id(), 'title' => ['Cek kelengkapan berkas', 'Hubungi kandidat', 'Konfirmasi jadwal MCU', 'Konfirmasi masuk LPK', 'Jadwalkan OPP', 'Lengkapi flight document'][$index], 'due_at' => now()->addHours($number * 8 - 4)]);
            }
        }
        $medical = Application::factory()->create();
        DB::table('medical_events')->insert(['application_id' => $medical->id, 'status' => 'scheduled', 'scheduled_at' => now()->addDays(2), 'restricted_document_path' => 'SENSITIVE-MEDICAL-FILE']);
        $this->getJson(route('pipeline.api', ['widget' => 'rows', 'search' => $medical->application_code]))->assertOk()->assertJsonPath('data.0.next_action', 'Konfirmasi jadwal MCU')->assertDontSee('SENSITIVE-MEDICAL-FILE');
        $response = $this->get(route('pipeline.index'));
        $response->assertOk()->assertSeeInOrder($names)->assertViewHas('summary', fn ($summary) => $summary['total'] === 37);
        if (getenv('PROCESS_QA_FIXTURE')) {
            file_put_contents('/tmp/jm-process-ui.html', $response->getContent());
        }
    }

    public function test_large_dataset_has_bounded_payload_and_query_count(): void
    {
        $application = Application::factory()->create();
        $stamp = now()->toDateTimeString();
        foreach (range(1, 10) as $batch) {
            $records = [];
            for ($index = 0; $index < 1000; $index++) {
                $records[] = ['candidate_id' => $application->candidate_id, 'vacancy_id' => $application->vacancy_id, 'application_code' => 'VOLUME-'.$batch.'-'.$index, 'status' => 'active', 'applied_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp];
            }
            DB::table('applications')->insert($records);
            if ($batch === 1 || $batch === 10) {
                DB::enableQueryLog();
                $started = microtime(true);
                $response = $this->getJson(route('pipeline.api', ['widget' => 'rows']));
                $elapsed = microtime(true) - $started;
                $queries = count(DB::getQueryLog());
                DB::disableQueryLog();
                DB::flushQueryLog();
                $response->assertOk()->assertJsonPath('total', $batch * 1000 + 1)->assertJsonCount(25, 'data');
                $this->assertLessThan(80, $queries);
                $this->assertLessThan(5, $elapsed, 'Local SQLite API regression limit');
                fwrite(STDERR, 'Process rows '.($batch * 1000 + 1).' records: '.round($elapsed * 1000)." ms, $queries queries\n");
            }
        }
        $this->get(route('pipeline.index'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 25 && $rows->total() === 10001);
    }
}
