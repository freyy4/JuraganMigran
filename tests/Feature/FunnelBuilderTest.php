<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\FunnelSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FunnelBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function funnel(): array
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $this->post(route('funnels.store'), ['name' => 'Taiwan', 'slug' => 'operator-taiwan', 'vacancy_id' => $vacancy->id])->assertSessionHasNoErrors();
        $funnel = DB::table('application_funnels')->first();
        $this->post(route('funnels.publish', $funnel->id))->assertSessionHasNoErrors();

        return [$admin, $funnel, $vacancy];
    }

    public function test_standalone_editor_keeps_draft_media_and_inline_controls_separate_from_public_pages(): void
    {
        [$admin, $funnel, $vacancy] = $this->funnel();
        $schema = FunnelSchema::defaults();
        $schema['steps'][] = ['id' => 'details', 'title' => 'Data Tambahan'];
        $schema['fields'][2]['step'] = 'details';
        $schema['blocks'][0] += ['url' => '/funnel-media/'.$funnel->id.'/00000000-0000-0000-0000-000000000001.png', 'media_source' => 'upload', 'image_width' => 75, 'image_height' => 240, 'image_radius' => 12, 'image_fit' => 'contain'];
        $schema['blocks'][0]['title'] = 'Judul draft studio';
        $token = $this->get(route('funnels.builder', $funnel->id))->assertOk()->viewData('draftToken');
        $saved = $this->postJson(route('funnels.update', $funnel->id), ['name' => 'Taiwan', 'vacancy_id' => $vacancy->id, 'schema' => json_encode($schema), 'draft_token' => $token])->assertOk();
        $editor = $this->get(route('funnels.builder', $funnel->id))->assertOk()->assertSee('site-builder.css')->assertSee('funnel-studio.css')->assertSee('funnel-builder.js')->assertDontSee('class="crm-layout"', false)->assertDontSee('marketing-sidebar');
        $tracking = $this->get(route('funnels.builder', ['funnel' => $funnel->id, 'tab' => 'tracking']))->assertOk()->assertSee('data-open-on-load', false);
        $this->get(route('funnels.index'))->assertOk()->assertSee('href="'.route('funnels.builder', $funnel->id).'" target="_blank" rel="noopener"', false);
        foreach (['landing', 'form', 'thank_you'] as $page) {
            $preview = $this->post(route('funnels.live-preview', $funnel->id), ['vacancy_id' => $vacancy->id, 'schema' => json_encode($schema), 'page' => $page])->assertOk()->assertSee('data-site-field="title"', false);
            if ($page === 'landing') {
                $preview->assertSee('data-site-image', false)->assertSee('width:75%;height:240px;border-radius:12px;object-fit:contain', false);
            }
            if (getenv('MARKETING_QA_FIXTURE')) {
                file_put_contents('/tmp/jm-funnel-'.$page.'.html', $preview->getContent());
            }
        }
        if (getenv('MARKETING_QA_FIXTURE')) {
            file_put_contents('/tmp/jm-funnel-editor.html', $editor->getContent());
            file_put_contents('/tmp/jm-funnel-tracking.html', $tracking->getContent());
        }
        $this->get(route('apply.show', $funnel->slug))->assertOk()->assertDontSee('Judul draft studio')->assertDontSee('data-site-field', false);
        $invalid = $schema;
        $invalid['blocks'][0]['image_width'] = 200;
        $invalid['blocks'][0]['image_fit'] = 'url(unsafe)';
        $this->postJson(route('funnels.update', $funnel->id), ['name' => 'Taiwan', 'vacancy_id' => $vacancy->id, 'schema' => json_encode($invalid), 'draft_token' => $saved->json('draft_token')])->assertUnprocessable()->assertJsonValidationErrors(['blocks.0.image_width', 'blocks.0.image_fit']);
        $this->post(route('funnels.publish', $funnel->id))->assertSessionHasNoErrors();
        $this->get(route('apply.show', $funnel->slug))->assertOk()->assertSee('Judul draft studio')->assertSee('width:75%;height:240px;border-radius:12px;object-fit:contain', false)->assertDontSee('data-site-field', false)->assertDontSee('data-site-image', false);
    }

    private function submit(string $slug = 'operator-taiwan', array $answers = []): TestResponse
    {
        $this->get(route('apply.show', $slug))->assertOk();
        $funnel = DB::table('application_funnels')->where('slug', $slug)->first();
        $entry = session('funnel_token.'.$funnel->id);

        return $this->post(route('apply.submit', $slug), ['idempotency_key' => $entry['token'], 'version_id' => $entry['version'], 'answers' => $answers + ['name' => 'Siti', 'whatsapp' => '081234567890'], 'consent' => 1]);
    }

    public function test_published_versions_are_immutable_and_every_submission_keeps_version_and_attribution(): void
    {
        [$admin,$funnel,$vacancy] = $this->funnel();
        $first = DB::table('funnel_versions')->first();
        $schema = FunnelSchema::defaults();
        $schema['blocks'][0]['title'] = 'Judul draft baru';
        $this->post(route('funnels.update', $funnel->id), ['name' => 'Taiwan', 'vacancy_id' => $vacancy->id, 'schema' => json_encode($schema)])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('apply.show', ['slug' => 'operator-taiwan', 'utm_source' => 'instagram']))->assertOk()->assertDontSee('Judul draft baru');
        $entry = session('funnel_token.'.$funnel->id);
        $this->post(route('apply.submit', 'operator-taiwan'), ['idempotency_key' => $entry['token'], 'version_id' => $entry['version'], 'answers' => ['name' => 'Siti', 'whatsapp' => '081234567890'], 'consent' => 1])->assertSessionHasNoErrors();
        $submission = DB::table('lead_submissions')->first();
        $this->assertSame($first->id, $submission->version_id);
        $this->assertSame('instagram', json_decode($submission->attribution, true)['utm_source']);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('candidates', 0);
        $this->assertStringNotContainsString('081234567890', $submission->payload);
        $this->get(route('apply.thank-you', ['operator-taiwan', $submission->public_token]))->assertOk()->assertSee('Pendaftaran berhasil');
        $this->actingAs($admin)->post(route('funnels.publish', $funnel->id))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('funnel_versions', 2);
        $this->assertSame($first->schema_snapshot, DB::table('funnel_versions')->where('id', $first->id)->value('schema_snapshot'));
    }

    public function test_round_robin_idempotency_duplicate_matching_and_whatsapp_handoff(): void
    {
        [$admin,$funnel,$vacancy] = $this->funnel();
        $group = DB::table('cs_groups')->insertGetId(['name' => 'CS Taiwan', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['A' => '+628111111111', 'B' => '+628222222222'] as $name => $phone) {
            DB::table('cs_members')->insert(['group_id' => $group, 'user_id' => $admin->id, 'name' => $name, 'whatsapp' => $phone, 'active_number' => $phone, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('cs_vacancy_mappings')->insert(['vacancy_id' => $vacancy->id, 'group_id' => $group, 'created_at' => now(), 'updated_at' => now()]);
        auth()->logout();
        $this->submit()->assertSessionHasNoErrors();
        $submission = DB::table('lead_submissions')->first();
        $entry = session('funnel_token.'.$funnel->id);
        $this->post(route('apply.submit', 'operator-taiwan'), ['idempotency_key' => $entry['token'], 'version_id' => $entry['version'], 'answers' => ['name' => 'Siti', 'whatsapp' => '081234567890'], 'consent' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lead_submissions', 1);
        $this->post(route('apply.whatsapp', ['operator-taiwan', $submission->public_token]))->assertRedirectContains('https://wa.me/628111111111');
        session()->forget('funnel_token.'.$funnel->id);
        $this->submit('operator-taiwan', ['whatsapp' => '+6281234567890'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('cs_assignment_events', 1);
        session()->forget('funnel_token.'.$funnel->id);
        $this->submit('operator-taiwan', ['whatsapp' => '083333333333'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('acquisition_leads', 2);
        $this->assertSame(2, DB::table('cs_groups')->value('last_member_id'));
    }

    public function test_rules_are_enforced_server_side_and_invalid_fields_and_templates_are_rejected(): void
    {
        [$admin,$funnel,$vacancy] = $this->funnel();
        $schema = FunnelSchema::defaults();
        $schema['fields'][] = ['key' => 'age', 'label' => 'Usia', 'type' => 'number', 'step' => 'basic', 'required' => true, 'sensitivity' => 'GENERAL'];
        $schema['rules'][] = ['field' => 'age', 'operator' => 'lt', 'value' => 18, 'action' => 'BLOCK_SUBMISSION', 'message' => 'Usia belum memenuhi syarat.'];
        $this->post(route('funnels.update', $funnel->id), ['name' => 'Taiwan', 'vacancy_id' => $vacancy->id, 'schema' => json_encode($schema)])->assertSessionHasNoErrors();
        $this->post(route('funnels.publish', $funnel->id))->assertSessionHasNoErrors();
        auth()->logout();
        $this->submit('operator-taiwan', ['age' => 16])->assertSessionHasErrors('answers');
        $this->assertDatabaseCount('acquisition_leads', 0);
        $this->actingAs($admin)->post(route('acquisition.saveTemplate'), ['name' => 'Tidak valid', 'body' => 'Halo {{unknown}}', 'status' => 'active'])->assertSessionHasErrors('body');
    }

    public function test_conversion_followup_and_preview_do_not_corrupt_production_metrics(): void
    {
        [$admin,$funnel,$vacancy] = $this->funnel();
        auth()->logout();
        $this->submit()->assertSessionHasNoErrors();
        $lead = DB::table('acquisition_leads')->first();
        $submission = DB::table('lead_submissions')->first();
        $this->actingAs($admin)->post(route('acquisition.convert', $lead->id), ['submission_id' => $submission->id])->assertSessionHasErrors('status');
        $this->post(route('acquisition.qualify', $lead->id), ['status' => 'qualified'])->assertSessionHasNoErrors();
        $this->post(route('acquisition.convert', $lead->id), ['submission_id' => $submission->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('candidates', 1);
        $this->assertDatabaseCount('applications', 1);
        $this->post(route('acquisition.whatsapp', $lead->id))->assertRedirectContains('https://wa.me/');
        $this->assertDatabaseHas('acquisition_leads', ['id' => $lead->id, 'followup_status' => 'DRAFT_OPENED']);
        $this->post(route('acquisition.followup', $lead->id), ['status' => 'SENT_CONFIRMED'])->assertSessionHasNoErrors();
        $this->post(route('acquisition.followup', $lead->id), ['status' => 'REPLIED'])->assertSessionHasNoErrors();
        $this->get(route('funnels.preview', $funnel->id))->assertOk();
        $this->post(route('funnels.test', $funnel->id), ['idempotency_key' => session('funnel_preview.'.$funnel->id), 'answers' => ['name' => 'Test', 'whatsapp' => '085555555555'], 'consent' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->get(route('funnels.builder', $funnel->id))->assertOk();
        $this->get(route('funnels.responses', $funnel->id))->assertOk();
        $this->get(route('funnels.analytics', $funnel->id))->assertOk();
    }

    public function test_conversion_reuses_an_exact_normalized_legacy_phone_without_merging_by_name(): void
    {
        [$admin,$funnel,$vacancy] = $this->funnel();
        $existing = Application::factory()->create(['vacancy_id' => $vacancy->id]);
        $existing->candidate->person->update(['whatsapp' => '0812 3456-7890', 'full_name' => 'Nama Lama Terverifikasi']);
        auth()->logout();
        $this->submit()->assertSessionHasNoErrors();
        $lead = DB::table('acquisition_leads')->first();
        $submission = DB::table('lead_submissions')->first();
        $this->actingAs($admin)->post(route('acquisition.qualify', $lead->id), ['status' => 'qualified'])->assertSessionHasNoErrors();
        $this->post(route('acquisition.convert', $lead->id), ['submission_id' => $submission->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('candidates', 1);
        $this->assertDatabaseCount('persons', 1);
        $this->assertSame('Nama Lama Terverifikasi', $existing->candidate->person->fresh()->full_name);
        $this->assertDatabaseHas('lead_submissions', ['id' => $submission->id, 'application_id' => $existing->id]);
    }
}
