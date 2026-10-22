<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\Candidate;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 03:00:00', 'UTC'));
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function requestFor(Candidate $candidate, string $type = 'callback'): int
    {
        return DB::table('cpmi_requests')->insertGetId(['candidate_id' => $candidate->id, 'type' => $type, 'status' => 'SUBMITTED', 'payload' => json_encode(['description' => 'Mohon diperiksa oleh petugas']), 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_candidate_registry_completeness_filter_and_api_mask_identity(): void
    {
        $this->owner();
        $complete = Candidate::factory()->create();
        $complete->person->update(['full_name' => 'Profil Lengkap', 'nik' => '1234567890123456', 'whatsapp' => '081234567890', 'birth_date' => '1995-01-01', 'gender' => 'female', 'address' => 'Alamat', 'education_level' => 'SMA']);
        $incomplete = Candidate::factory()->create();
        $incomplete->person->update(['nik' => null, 'whatsapp' => null, 'address' => null]);
        $this->getJson(route('candidates.api-index', ['completeness' => 'complete', 'q' => 'Profil Lengkap', 'pageSize' => 10]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('pageSize', 10)->assertJsonPath('items.0.completeness', 100)->assertDontSee('1234567890123456')->assertJsonPath('summary.Total Calon Pekerja', 1);
        $this->get(route('candidates.index', ['completeness' => 'incomplete']))->assertOk()->assertSee($incomplete->candidate_code)->assertDontSee($complete->candidate_code);
        $this->getJson(route('candidates.api-index', ['per_page' => 1000]))->assertUnprocessable();
    }

    public function test_cs_lists_insights_and_bulk_actions_are_scoped_and_partial_results_are_explicit(): void
    {
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $mine = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $other = Application::factory()->create();
        $task = Task::factory()->create(['application_id' => $mine->id, 'due_at' => now()->addHour()]);
        $hidden = Task::factory()->create(['application_id' => $other->id, 'due_at' => now()->addHour()]);
        $this->actingAs($cs)->getJson(route('tasks.api-index'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $task->id)->assertDontSee($hidden->title);
        $this->getJson(route('tasks.insights'))->assertOk()->assertDontSee($hidden->title);
        $this->postJson(route('tasks.bulk'), ['ids' => [$task->id, $hidden->id], 'action' => 'complete', 'confirmed' => 1])->assertOk()->assertJsonPath('succeeded', 1)->assertJsonPath('failed', 1);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame('open', $hidden->fresh()->status);
        $this->postJson(route('tasks.bulk'), ['ids' => [$hidden->id], 'action' => 'complete'])->assertUnprocessable();
    }

    public function test_workflow_followup_creates_one_formal_task_and_preserves_original_action(): void
    {
        $user = $this->owner();
        $application = Application::factory()->create(['next_action' => 'Konfirmasi dokumen', 'next_action_due_at' => now()->addHour(), 'assigned_cs_id' => $user->id]);
        $response = $this->get(route('follow-ups.index'))->assertOk()->assertSee('Konfirmasi dokumen')->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $token = $response->viewData('rows')->first()->workflow_token;
        $data = ['action' => 'task', 'workflow_token' => $token, 'confirmed' => 1, 'assigned_to' => $user->id, 'due_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i')];
        $this->post(route('follow-ups.action', $application), $data)->assertSessionHasNoErrors();
        $this->post(route('follow-ups.action', $application), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('Konfirmasi dokumen', $application->fresh()->next_action);
        $this->post(route('follow-ups.action', $application), ['action' => 'complete', 'workflow_token' => $token, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertNull($application->fresh()->next_action);
        $this->assertSame('completed', Task::first()->status);
        $this->assertDatabaseHas('followup_events', ['application_id' => $application->id, 'status' => 'WORKFLOW_COMPLETED']);
        $this->get(route('follow-ups.index', ['view' => 'completed']))->assertOk()->assertSee('Konfirmasi dokumen');
        $this->post(route('follow-ups.action', $application), $data)->assertSessionHasErrors('workflow_token');
    }

    public function test_profile_correction_requires_verification_and_audits_before_after(): void
    {
        $this->owner();
        $candidate = Candidate::factory()->create();
        $id = $this->requestFor($candidate, 'profile_correction');
        $before = $candidate->person->full_name;
        $data = ['status' => 'APPROVED', 'public_note' => 'Koreksi telah ditinjau', 'correction_field' => 'full_name', 'correction_value' => 'Nama Terverifikasi'];
        $this->post(route('portal-admin.review', $id), $data)->assertSessionHasErrors(['verified', 'evidence_reference']);
        $this->assertSame($before, $candidate->person->fresh()->full_name);
        $this->post(route('portal-admin.review', $id), $data + ['verified' => 1, 'evidence_reference' => 'Pemeriksaan identitas asli'])->assertSessionHasNoErrors();
        $this->assertSame('Nama Terverifikasi', $candidate->person->fresh()->full_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cpmi.profile.correction_applied']);
        $this->get(route('portal-admin.request-detail', $id))->assertOk()->assertSee('Nama Terverifikasi')->assertSee($before);
    }

    public function test_request_detail_and_review_reject_other_cs_scope(): void
    {
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $mine = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $other = Application::factory()->create();
        $mineId = $this->requestFor($mine->candidate);
        $otherId = $this->requestFor($other->candidate);
        $this->actingAs($cs)->get(route('portal-admin.requests'))->assertOk()->assertSee($mine->candidate->person->full_name)->assertDontSee($other->candidate->person->full_name);
        $this->get(route('portal-admin.request-detail', $otherId))->assertNotFound();
        $this->post(route('portal-admin.review', $otherId), ['status' => 'DONE', 'public_note' => 'Selesai'])->assertForbidden();
        $this->get(route('portal-admin.request-detail', $mineId))->assertOk();
    }

    public function test_lead_cockpit_excludes_converted_and_scheduling_does_not_claim_message_sent(): void
    {
        $user = $this->owner();
        $data = ['public_token' => (string) Str::uuid(), 'name' => 'Lead Baru QA', 'normalized_phone' => '+628123450001', 'status' => 'new', 'followup_status' => 'NOT_CONTACTED', 'assigned_cs_id' => $user->id, 'created_at' => now(), 'updated_at' => now()];
        $lead = DB::table('acquisition_leads')->insertGetId($data);
        DB::table('acquisition_leads')->insert([...$data, 'public_token' => (string) Str::uuid(), 'name' => 'Lead Converted QA', 'normalized_phone' => '+628123450002', 'status' => 'converted']);
        $this->getJson(route('acquisition.api-leads'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.Belum Dihubungi', 1)->assertDontSee('Lead Converted QA');
        $this->post(route('acquisition.schedule', $lead), ['next_followup_at' => now('Asia/Jakarta')->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('acquisition_leads', ['id' => $lead, 'followup_status' => 'NOT_CONTACTED']);
        $this->getJson(route('acquisition.api-leads', ['status' => 'converted']))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.name', 'Lead Converted QA');
    }

    public function test_create_application_rejects_duplicate_active_application(): void
    {
        $this->owner();
        $application = Application::factory()->create();
        $this->post(route('candidates.store-application', $application->candidate_id), ['vacancy_id' => $application->vacancy_id])->assertSessionHasErrors('vacancy_id');
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_large_work_queue_is_bounded_and_filters_summary_on_the_server(): void
    {
        $this->owner();
        $application = Application::factory()->create();
        $row = ['application_id' => $application->id, 'title' => 'Tugas Volume QA', 'status' => 'open', 'priority' => 'normal', 'due_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()];
        for ($i = 0; $i < 20; $i++) {
            DB::table('tasks')->insert(array_fill(0, 500, $row));
        }
        $this->getJson(route('tasks.api-index', ['pageSize' => 25]))->assertOk()->assertJsonPath('total', 10000)->assertJsonCount(25, 'items')->assertJsonPath('summary.Hari Ini', 10000);
        $this->getJson(route('tasks.api-index', ['priority' => 'high']))->assertOk()->assertJsonPath('total', 0)->assertJsonPath('summary.Hari Ini', 0);
    }

    public function test_request_decisions_reject_a_stale_review_token(): void
    {
        $this->owner();
        $candidate = Candidate::factory()->create();
        $id = $this->requestFor($candidate);
        $row = DB::table('cpmi_requests')->find($id);
        $token = hash('sha256', json_encode([$row->id, $row->status, $row->payload, $row->public_note, $row->internal_note, $row->priority, $row->updated_at]));
        $this->post(route('portal-admin.review', $id), ['status' => 'REVIEWING', 'public_note' => 'Sedang diperiksa', 'review_token' => $token])->assertSessionHasNoErrors();
        $this->post(route('portal-admin.review', $id), ['status' => 'DONE', 'public_note' => 'Keputusan dari tab lama', 'review_token' => $token])->assertSessionHasErrors('review_token');
        $this->assertDatabaseHas('cpmi_requests', ['id' => $id, 'status' => 'REVIEWING']);
    }

    public function test_date_ranges_accept_an_end_date_alone_and_reject_reversed_dates(): void
    {
        $this->owner();
        Task::factory()->create(['due_at' => now()->subDay()]);
        Task::factory()->create(['due_at' => now()->addDay()]);
        $this->getJson(route('tasks.api-index', ['tab' => 'overdue', 'dueTo' => now('Asia/Jakarta')->subDay()->toDateString()]))->assertOk()->assertJsonPath('total', 1);
        $this->getJson(route('tasks.api-index', ['dueFrom' => '2026-10-16', 'dueTo' => '2026-10-15']))->assertUnprocessable();
    }

    public function test_assigned_cs_cannot_create_a_task_owned_by_another_team(): void
    {
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id'), 'team_id' => 4]);
        $application = Application::factory()->create(['assigned_cs_id' => $cs->id]);
        $other = User::factory()->create(['team_id' => 9]);
        $this->actingAs($cs)->post(route('tasks.store'), ['application_id' => $application->id, 'assigned_to' => $other->id, 'title' => 'Penugasan di luar scope', 'priority' => 'normal', 'status' => 'open'])->assertForbidden();
        $this->assertDatabaseMissing('tasks', ['title' => 'Penugasan di luar scope']);
    }

    public function test_all_seven_workspaces_render_and_can_produce_synthetic_qa_fixtures(): void
    {
        $user = $this->owner();
        $application = Application::factory()->create(['assigned_cs_id' => $user->id, 'next_action' => 'Konfirmasi kelengkapan dokumen', 'next_action_due_at' => now()->addHour()]);
        $application->candidate->person->update(['full_name' => 'Cantika QA']);
        Task::factory()->create(['application_id' => $application->id, 'assigned_to' => $user->id, 'title' => 'Verifikasi dokumen QA', 'due_at' => now()->addHour()]);
        ApplicationBlocker::factory()->create(['application_id' => $application->id, 'description' => 'Dokumen masih perlu dilengkapi', 'owner_id' => $user->id, 'priority' => 'high', 'due_at' => now()->subHour()]);
        $request = $this->requestFor($application->candidate);
        DB::table('acquisition_leads')->insert(['public_token' => (string) Str::uuid(), 'name' => 'Lead Baru QA', 'normalized_phone' => '+628123450003', 'status' => 'new', 'followup_status' => 'NOT_CONTACTED', 'assigned_cs_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $routes = ['leads' => 'acquisition.leads', 'requests' => 'portal-admin.requests', 'candidates' => 'candidates.index', 'applications' => 'applications.index', 'tasks' => 'tasks.index', 'follow-ups' => 'follow-ups.index', 'blockers' => 'blockers.index'];
        foreach ($routes as $name => $route) {
            $response = $this->get(route($route))->assertOk()->assertSee('data-filter-toolbar', false)->assertSee($name === 'candidates' ? 'cpmi-workspace.css' : 'crm-workspace.css');
            if (getenv('CRM_QA_FIXTURES')) {
                $html = preg_replace('~https?://[^/]+/(css|js)/~', 'file:///var/www/juraganmigran/public/$1/', $response->getContent());
                file_put_contents('/tmp/jm-v810-'.$name.'.html', $html);
            }
        }
        if (getenv('CRM_QA_FIXTURES')) {
            file_put_contents('/tmp/jm-v810-request-detail.html', $this->get(route('portal-admin.request-detail', $request))->getContent());
            file_put_contents('/tmp/jm-v810-task-insights.json', $this->getJson(route('tasks.insights'))->getContent());
            file_put_contents('/tmp/jm-v810-blocker-insights.json', $this->getJson(route('blockers.insights'))->getContent());
        }
    }
}
