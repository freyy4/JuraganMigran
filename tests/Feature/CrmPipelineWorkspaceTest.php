<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmPipelineWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): User
    {
        return User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id'), 'team_id' => 1]);
    }

    private function lead(User $user, array $attributes = []): int
    {
        return DB::table('acquisition_leads')->insertGetId($attributes + ['public_token' => (string) Str::uuid(), 'name' => 'Pipeline Lead', 'normalized_phone' => '+628'.fake()->unique()->numerify('##########'), 'assigned_cs_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_columns_paginate_independently_and_share_list_filters_and_scope(): void
    {
        $user = $this->agent();
        $other = $this->agent();
        for ($i = 0; $i < 30; $i++) {
            $this->lead($user, ['crm_stage' => 'NEW', 'crm_source' => 'Referral']);
        }
        $contacted = $this->lead($user, ['crm_stage' => 'CONTACTED', 'crm_source' => 'Referral']);
        $this->lead($user, ['crm_source' => 'Organic']);
        $this->lead($other, ['crm_source' => 'Referral']);
        $response = $this->actingAs($user)->getJson(route('crm.pipeline', ['source' => 'Referral']));
        $response->assertOk()->assertJsonPath('summary.total', 31)->assertJsonPath('stages.NEW.count', 30)->assertJsonCount(25, 'stages.NEW.items')->assertJsonPath('stages.CONTACTED.items.0.id', $contacted)->assertDontSee('normalized_phone')->assertDontSee('public_token');

        $this->getJson(route('crm.pipeline', ['source' => 'Referral', 'column' => 'NEW', 'cursor' => $response->json('stages.NEW.nextCursor')]))->assertOk()->assertJsonCount(5, 'stages.NEW.items');
        $this->getJson(route('crm.pipeline', ['source' => 'Referral', 'mode' => 'list']))->assertJsonPath('rows.total', 31)->assertJsonPath('summary.total', 31);
        $this->get(route('crm.pipeline', ['mode' => 'list']))->assertOk()->assertSee('cp-table', false);
        $this->get(route('crm.pipeline'))->assertOk()->assertSee('Pindah tahap')->assertSee('Filter lanjutan');
    }

    public function test_search_sort_presets_and_archive_boundary(): void
    {
        $user = $this->agent();
        $lead = $this->lead($user, ['name' => 'Zahra', 'email' => 'qa-pipeline@example.test', 'next_followup_at' => now()->subDay()]);
        $this->lead($user, ['name' => 'Andi']);
        $archived = $this->lead($user, ['archived_at' => now()]);
        $this->actingAs($user)->getJson(route('crm.pipeline', ['search' => 'qa-pipeline@example.test']))->assertOk()->assertJsonPath('rows.total', 1)->assertJsonPath('rows.data.0.id', $lead);
        $this->getJson(route('crm.pipeline', ['channel' => 999]))->assertOk()->assertJsonPath('rows.total', 0);
        $this->getJson(route('crm.pipeline', ['preset' => 'overdue']))->assertJsonPath('rows.total', 1);
        $this->getJson(route('crm.pipeline', ['mode' => 'list', 'sort' => 'name', 'per_page' => 25]))->assertJsonPath('rows.data.0.name', 'Andi')->assertJsonPath('rows.per_page', 25);
        $this->postJson(route('crm.move', $archived), ['stage' => 'CONTACTED', 'version' => 0])->assertNotFound();
        $this->postJson(route('crm.move', $lead), ['stage' => 'CONTACTED', 'version' => 0])->assertOk();
        $this->postJson(route('crm.move', $lead), ['stage' => 'DISCUSSION', 'version' => 0])->assertConflict();
        $this->assertDatabaseHas('acquisition_leads', ['id' => $lead, 'crm_stage' => 'CONTACTED', 'crm_version' => 1]);
        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_bulk_snapshot_is_scoped_idempotent_and_preserves_history(): void
    {
        $user = $this->agent();
        $other = $this->agent();
        $lead = $this->lead($user);
        $hidden = $this->lead($other);
        $data = ['action' => 'archive', 'selection' => 'filtered', 'filters' => [], 'idempotency_key' => (string) Str::uuid(), 'payload' => ['reason' => 'Data tidak valid']];
        $job = $this->actingAs($user)->postJson(route('crm.bulk'), $data)->assertAccepted()->json('id');
        $this->postJson(route('crm.bulk'), $data)->assertOk()->assertJsonPath('id', $job);
        $this->artisan('crm:process-workspace')->assertSuccessful();
        $this->getJson(route('crm.bulk-job', $job))->assertOk()->assertJsonPath('status', 'DONE')->assertJsonPath('success_count', 1);
        $this->assertDatabaseHas('acquisition_leads', ['id' => $hidden, 'archived_at' => null]);
        $this->assertNotNull(DB::table('acquisition_leads')->find($lead)->archived_at);
        $this->assertDatabaseHas('crm_activities', ['lead_id' => $lead, 'event_type' => 'archived']);
        $this->assertDatabaseCount('acquisition_leads', 2);
        $this->actingAs($other)->getJson(route('crm.bulk-job', $job))->assertNotFound();
    }

    public function test_bulk_stage_reports_partial_failure_and_never_converts_candidates(): void
    {
        $user = $this->agent();
        $lead = $this->lead($user);
        $invalid = $this->lead($user, ['crm_stage' => 'QUALIFIED']);
        $job = $this->actingAs($user)->postJson(route('crm.bulk'), ['action' => 'stage', 'selection' => 'ids', 'ids' => [$lead, $invalid], 'idempotency_key' => (string) Str::uuid(), 'payload' => ['stage' => 'CONTACTED']])->assertAccepted()->json('id');
        $this->artisan('crm:process-workspace')->assertSuccessful();
        $this->getJson(route('crm.bulk-job', $job))->assertJsonPath('status', 'PARTIAL')->assertJsonPath('success_count', 1)->assertJsonPath('failure_count', 1);
        $this->artisan('crm:process-workspace')->assertSuccessful();
        $this->assertDatabaseCount('crm_activities', 1);
        $this->assertDatabaseHas('acquisition_leads', ['id' => $invalid, 'crm_stage' => 'QUALIFIED']);
        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_card_preferences_are_private_versioned_and_cannot_change_team_defaults(): void
    {
        $user = $this->agent();
        $other = $this->agent();
        $this->actingAs($user)->putJson(route('crm.card-layout'), ['scope' => 'USER', 'version' => 0, 'fields' => ['owner', 'followup']])->assertOk()->assertJsonPath('version', 1)->assertJsonPath('fields', ['owner', 'followup']);
        $this->putJson(route('crm.card-layout'), ['scope' => 'USER', 'version' => 0, 'fields' => []])->assertConflict();
        $this->putJson(route('crm.card-layout'), ['scope' => 'TEAM', 'version' => 0, 'fields' => []])->assertForbidden();
        $this->actingAs($other)->getJson(route('crm.card-layout'))->assertOk()->assertJsonPath('version', 0);
    }

    public function test_exports_require_permission_protect_downloads_and_escape_spreadsheet_formulas(): void
    {
        Storage::fake('local');
        $user = $this->agent();
        $lead = $this->lead($user, ['name' => '=HYPERLINK("bad")']);
        $data = ['action' => 'export', 'selection' => 'ids', 'ids' => [$lead], 'idempotency_key' => (string) Str::uuid(), 'payload' => ['format' => 'csv']];
        $this->actingAs($user)->postJson(route('crm.bulk'), $data)->assertForbidden();
        $role = DB::table('crm_roles')->find($user->crm_role_id);
        DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_merge(json_decode($role->permissions, true), ['lead.export']))]);
        $user = $user->fresh();
        $job = $this->actingAs($user)->postJson(route('crm.bulk'), $data)->assertAccepted()->json('id');
        $this->artisan('crm:process-workspace')->assertSuccessful();
        $url = $this->getJson(route('crm.bulk-job', $job))->assertJsonPath('status', 'DONE')->json('download_url');
        $this->get($url)->assertOk()->assertDownload('pipeline-lead.csv');
        $this->get(route('crm.export-download', $job))->assertForbidden();
        $file = Storage::disk('local')->get('crm-exports/'.$job.'.csv');
        $this->assertStringContainsString("'=HYPERLINK", $file);
        $this->assertStringNotContainsString('WhatsApp', $file);
        $data['idempotency_key'] = (string) Str::uuid();
        $data['payload'] = ['format' => 'xlsx'];
        $xlsxJob = $this->postJson(route('crm.bulk'), $data)->assertAccepted()->json('id');
        $this->artisan('crm:process-workspace')->assertSuccessful();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path('crm-exports/'.$xlsxJob.'.xlsx')));
        $this->assertStringContainsString('inlineStr', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        $this->travel(25)->hours();
        $this->get(URL::temporarySignedRoute('crm.export-download', now()->addMinutes(5), ['job' => $job]))->assertStatus(410);
    }

    public function test_bulk_tasks_and_tags_reuse_existing_lead_records(): void
    {
        $user = $this->agent();
        $lead = $this->lead($user);
        $tag = DB::table('crm_tags')->insertGetId(['name' => 'Prioritas QA', 'color_token' => 'blue', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        foreach (['task' => ['due_at' => now()->addDay()->toIso8601String(), 'note' => 'Hubungi kembali'], 'tag' => ['tag_id' => $tag]] as $action => $payload) {
            $job = $this->postJson(route('crm.bulk'), ['action' => $action, 'selection' => 'ids', 'ids' => [$lead], 'idempotency_key' => (string) Str::uuid(), 'payload' => $payload])->assertAccepted()->json('id');
            $this->artisan('crm:process-workspace')->assertSuccessful();
            $this->getJson(route('crm.bulk-job', $job))->assertJsonPath('status', 'DONE');
        }
        $this->assertDatabaseHas('crm_follow_ups', ['lead_id' => $lead, 'status' => 'SCHEDULED', 'assigned_user_id' => $user->id]);
        $this->assertDatabaseHas('crm_lead_tags', ['lead_id' => $lead, 'tag_id' => $tag, 'added_by' => $user->id]);
        $this->assertNotNull(DB::table('acquisition_leads')->find($lead)->next_followup_at);
        $this->assertDatabaseCount('acquisition_leads', 1);
    }
}
