<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vacancy;
use App\Services\FunnelSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FormBuilderDynamicTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $this->actingAs(User::factory()->create());
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $this->post(route('funnels.store'), ['name' => 'Funnel Dinamis', 'slug' => 'funnel-dinamis', 'vacancy_id' => $vacancy->id])->assertSessionHasNoErrors();
        $funnel = DB::table('application_funnels')->first();
        $response = $this->get(route('funnels.builder', $funnel->id))->assertOk();

        return [$funnel, $vacancy, $response->viewData('draftToken')];
    }

    public function test_deleted_linked_vacancy_keeps_draft_preview_available_and_can_be_replaced(): void
    {
        [$funnel, $vacancy, $token] = $this->context();
        $vacancy->delete();
        $this->get(route('funnels.builder', $funnel->id))->assertOk()
            ->assertSee('name="vacancy_id" id="vacancy_id" value="'.$vacancy->id.'"', false)
            ->assertSee('Lowongan terhubung sudah dihapus');
        $payload = ['name' => 'Funnel Dinamis', 'vacancy_id' => $vacancy->id, 'schema' => json_encode(FunnelSchema::defaults()), 'draft_token' => $token];
        foreach (['landing', 'form', 'thank_you'] as $page) {
            $this->postJson(route('funnels.live-preview', $funnel->id), $payload + ['page' => $page])->assertOk();
        }
        $draft = $this->postJson(route('funnels.update', $funnel->id), $payload)->assertOk();
        $payload['draft_token'] = $draft->json('draft_token');
        $this->postJson(route('funnels.readiness', $funnel->id), $payload)->assertOk()->assertJsonPath('ready', false);
        $this->post(route('funnels.publish', $funnel->id), ['draft_token' => $payload['draft_token']])->assertSessionHasErrors('publish');
        $replacement = Vacancy::factory()->create(['status' => 'published']);
        $saved = $this->postJson(route('funnels.update', $funnel->id), array_replace($payload, ['vacancy_id' => $replacement->id]))->assertOk();
        $this->get(route('funnels.builder', $funnel->id))->assertOk()->assertDontSee('Lowongan terhubung sudah dihapus')
            ->assertSee('name="vacancy_id" id="vacancy_id" value="'.$replacement->id.'"', false);
        $this->postJson(route('funnels.live-preview', $funnel->id), array_replace($payload, ['vacancy_id' => $replacement->id, 'draft_token' => $saved->json('draft_token')]))->assertOk();
        $this->assertDatabaseHas('application_funnels', ['id' => $funnel->id, 'vacancy_id' => $replacement->id]);
        $this->assertDatabaseCount('funnel_versions', 0);
        $this->assertDatabaseCount('acquisition_leads', 0);
    }

    public function test_dynamic_draft_save_preserves_all_three_pages_and_does_not_modify_published_version(): void
    {
        [$funnel,$vacancy,$token] = $this->context();
        $this->post(route('funnels.publish', $funnel->id))->assertSessionHasNoErrors();
        $snapshot = DB::table('funnel_versions')->first();
        $token = $this->get(route('funnels.builder', $funnel->id))->viewData('draftToken');
        $schema = FunnelSchema::defaults();
        $schema['blocks'][0]['title'] = 'Landing Page Baru';
        $schema['blocks'][0]['align'] = 'center';
        $schema['blocks'][0]['padding'] = 32;
        $schema['blocks'][0]['button_text'] = 'Mulai sekarang';
        $schema['steps'][] = ['id' => 'experience', 'title' => 'Pengalaman'];
        $schema['fields'][] = ['key' => 'experience', 'label' => 'Pengalaman kerja', 'type' => 'select', 'step' => 'experience', 'required' => true, 'sensitivity' => 'GENERAL', 'options' => ['Belum pernah', 'Sudah pernah']];
        $schema['thank_you']['headline'] = 'Terima kasih sudah mendaftar';
        $schema['design']['primary_color'] = '#089181';
        $response = $this->postJson(route('funnels.update', $funnel->id), ['name' => 'Funnel Dinamis', 'vacancy_id' => $vacancy->id, 'draft_token' => $token, 'schema' => json_encode($schema)])->assertOk();
        $this->assertNotSame($token, $response->json('draft_token'));
        $saved = json_decode(DB::table('application_funnels')->value('draft_schema'), true);
        $this->assertSame($schema['thank_you']['headline'], $saved['thank_you']['headline']);
        $this->assertSame('#089181', $saved['design']['primary_color']);
        $this->assertSame('experience', $saved['fields'][3]['step']);
        $this->assertSame($snapshot->schema_snapshot, DB::table('funnel_versions')->value('schema_snapshot'));
    }

    public function test_stale_tab_cannot_overwrite_newer_draft_or_publish_it(): void
    {
        [$funnel,$vacancy,$token] = $this->context();
        $schema = FunnelSchema::defaults();
        $schema['thank_you']['headline'] = 'Tersimpan dari tab satu';
        $payload = ['name' => 'Funnel Dinamis', 'vacancy_id' => $vacancy->id, 'draft_token' => $token, 'schema' => json_encode($schema)];
        $this->postJson(route('funnels.update', $funnel->id), $payload)->assertOk();
        $schema['thank_you']['headline'] = 'Perubahan tab lama';
        $payload['schema'] = json_encode($schema);
        $this->postJson(route('funnels.update', $funnel->id), $payload)->assertConflict();
        $this->postJson(route('funnels.publish', $funnel->id), ['draft_token' => $token])->assertConflict();
        $this->assertDatabaseCount('funnel_versions', 0);
        $this->assertSame('Tersimpan dari tab satu', json_decode(DB::table('application_funnels')->value('draft_schema'), true)['thank_you']['headline']);
    }

    public function test_interactive_preview_of_each_page_has_no_lead_or_analytics_side_effects(): void
    {
        [$funnel,$vacancy] = $this->context();
        $schema = FunnelSchema::defaults();
        $original = $funnel->draft_schema;
        $schema['blocks'][0]['title'] = 'Preview saja';
        $schema['thank_you']['headline'] = 'Konfirmasi preview';
        foreach (['landing' => 'Preview saja', 'form' => 'Data Dasar', 'thank_you' => 'Konfirmasi preview'] as $page => $text) {
            $this->postJson(route('funnels.live-preview', $funnel->id), ['schema' => json_encode($schema), 'vacancy_id' => $vacancy->id, 'page' => $page])->assertOk()->assertSee($text);
        }
        $this->assertSame($original, DB::table('application_funnels')->value('draft_schema'));
        foreach (['acquisition_leads', 'lead_submissions', 'funnel_analytics_events', 'cs_assignment_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_preview_is_available_to_read_only_editor_but_saving_is_forbidden(): void
    {
        [$funnel,$vacancy,$token] = $this->context();
        $role = DB::table('crm_roles')->insertGetId(['name' => 'Pembaca Funnel', 'permissions' => json_encode(['funnel.read']), 'scope_type' => 'OPERATIONAL_ALL', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create(['crm_role_id' => $role]));
        $this->get(route('funnels.builder', $funnel->id))->assertOk()->assertSee('data-editable="0"', false);
        $payload = ['name' => 'Funnel Dinamis', 'vacancy_id' => $vacancy->id, 'draft_token' => $token, 'schema' => json_encode(FunnelSchema::defaults())];
        $this->postJson(route('funnels.live-preview', $funnel->id), $payload)->assertOk();
        $this->postJson(route('funnels.update', $funnel->id), $payload)->assertForbidden();
    }

    public function test_invalid_styles_schema_and_skip_targets_are_rejected(): void
    {
        [$funnel,$vacancy,$token] = $this->context();
        $payload = ['name' => 'Funnel Dinamis', 'vacancy_id' => $vacancy->id, 'draft_token' => $token];
        $this->postJson(route('funnels.update', $funnel->id), $payload + ['schema' => 'null'])->assertUnprocessable();
        $schema = FunnelSchema::defaults();
        $schema['design']['primary_color'] = 'red;display:none';
        $this->postJson(route('funnels.update', $funnel->id), $payload + ['schema' => json_encode($schema)])->assertUnprocessable();
        $schema = FunnelSchema::defaults();
        $schema['blocks'][0]['background_color'] = 'red;display:none';
        $this->postJson(route('funnels.update', $funnel->id), $payload + ['schema' => json_encode($schema)])->assertUnprocessable();
        $schema = FunnelSchema::defaults();
        $schema['rules'][] = ['field' => 'email', 'operator' => 'eq', 'value' => '', 'action' => 'SKIP_STEP', 'target' => 'missing'];
        $this->postJson(route('funnels.update', $funnel->id), $payload + ['schema' => json_encode($schema)])->assertUnprocessable();
    }

    public function test_published_landing_form_and_thank_you_render_the_configured_content_and_styles(): void
    {
        [$funnel,$vacancy,$token] = $this->context();
        $schema = FunnelSchema::defaults();
        $schema['blocks'][0]['title'] = 'Landing siap dipakai';
        $schema['blocks'][0]['button_text'] = 'Mulai langkah saya';
        $schema['blocks'][1]['title'] = 'Isi formulir khusus';
        $schema['blocks'][1]['background_color'] = '#e1f5ef';
        $schema['blocks'][1]['text_color'] = '#345678';
        $schema['blocks'][] = ['id' => 'hidden_notice', 'type' => 'notice', 'title' => 'Blok rahasia draft', 'text' => 'Jangan tampilkan', 'hidden' => true];
        $schema['design']['primary_color'] = '#123456';
        $schema['thank_you']['headline'] = 'Terima kasih dari funnel ini';
        $schema['thank_you']['button_text'] = 'Konsultasi sekarang';
        $saved = $this->postJson(route('funnels.update', $funnel->id), ['name' => 'Funnel Dinamis', 'vacancy_id' => $vacancy->id, 'draft_token' => $token, 'schema' => json_encode($schema)])->assertOk();
        $this->post(route('funnels.publish', $funnel->id), ['draft_token' => $saved->json('draft_token')])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get(route('apply.show', 'funnel-dinamis'))->assertOk()->assertSee('Landing siap dipakai')->assertSee('Isi formulir khusus')->assertSee('background:#e1f5ef', false)->assertSee('--configured-text:#345678', false)->assertSee('Mulai langkah saya')->assertSee('#123456')->assertDontSee('Blok rahasia draft');
        $entry = session('funnel_token.'.$funnel->id);
        $this->post(route('apply.submit', 'funnel-dinamis'), ['idempotency_key' => $entry['token'], 'version_id' => $entry['version'], 'consent' => 1, 'answers' => ['name' => 'CPMI Uji', 'whatsapp' => '081234567890']])->assertSessionHasNoErrors();
        $submission = DB::table('lead_submissions')->first();
        $this->get(route('apply.thank-you', ['funnel-dinamis', $submission->public_token]))->assertOk()->assertSee('Terima kasih dari funnel ini')->assertSee('Konsultasi sekarang')->assertSee('#123456');
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_canvas_reuses_public_sections_with_safe_content_and_live_vacancy_data(): void
    {
        [$funnel, $vacancy] = $this->context();
        $vacancy->update(['salary' => 800, 'currency' => 'SGD', 'contract_duration' => '2 tahun']);
        $schema = FunnelSchema::defaults();
        $schema['blocks'][0]['title'] = '<script>alert(1)</script>';
        $schema['blocks'][0]['url'] = 'https://example.com/hero.jpg';
        $schema['blocks'][0]['alt'] = 'Pendampingan karir global';
        $schema['blocks'][0]['trust_items'] = ['Proses resmi', 'Tim profesional'];
        $schema['blocks'][0]['badges'] = ['Singapura', 'Baby Sitter', '<script>badge</script>'];
        $schema['blocks'][] = ['id' => 'vacancy', 'type' => 'vacancy_summary', 'title' => 'Lowongan terikat'];
        $this->postJson(route('funnels.live-preview', $funnel->id), ['schema' => json_encode($schema), 'vacancy_id' => $vacancy->id, 'page' => 'landing', 'canvas' => true])
            ->assertOk()->assertSee('data-render-block="hero"', false)->assertSee('data-render-form', false)
            ->assertSee('landing-hero-copy')->assertSee('Singapura')->assertSee('Baby Sitter')->assertSee('&lt;script&gt;badge&lt;/script&gt;', false)->assertDontSee('<script>badge</script>', false)->assertSee('Pendampingan karir global')->assertSee('SGD 800')->assertSee('2 tahun')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertDatabaseCount('acquisition_leads', 0);
        $this->assertDatabaseCount('cs_assignment_events', 0);
    }

    public function test_readiness_warns_for_optional_handoff_and_blocks_automatic_handoff_without_cs(): void
    {
        [$funnel, $vacancy, $token] = $this->context();
        $schema = FunnelSchema::defaults();
        $payload = ['schema' => json_encode($schema), 'vacancy_id' => $vacancy->id];
        $this->postJson(route('funnels.readiness', $funnel->id), $payload)->assertOk()->assertJsonPath('ready', true)->assertJsonFragment(['severity' => 'warning']);
        $schema['post_submit'] = 'THANK_YOU_THEN_WHATSAPP';
        $payload['schema'] = json_encode($schema);
        $this->postJson(route('funnels.readiness', $funnel->id), $payload)->assertOk()->assertJsonPath('ready', false)->assertJsonFragment(['severity' => 'blocker']);
        $saved = $this->postJson(route('funnels.update', $funnel->id), $payload + ['name' => 'Funnel Dinamis', 'draft_token' => $token])->assertOk();
        $this->postJson(route('funnels.publish', $funnel->id), ['draft_token' => $saved->json('draft_token')])->assertUnprocessable()->assertJsonValidationErrors('publish');
        $this->assertDatabaseCount('funnel_versions', 0);
        $group = DB::table('cs_groups')->insertGetId(['name' => 'Tim siap', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cs_members')->insert(['group_id' => $group, 'name' => 'CS aktif', 'whatsapp' => '+6281234567890', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cs_vacancy_mappings')->insert(['group_id' => $group, 'vacancy_id' => $vacancy->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson(route('funnels.readiness', $funnel->id), $payload)->assertOk()->assertJsonPath('ready', true);
        $this->post(route('funnels.publish', $funnel->id), ['draft_token' => $saved->json('draft_token')])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('funnel_versions', 1);
        $this->assertDatabaseCount('cs_assignment_events', 0);
        $this->assertNull(DB::table('cs_groups')->value('last_member_id'));
    }

    public function test_readiness_enforces_vacancy_scope_and_has_no_assignment_side_effects(): void
    {
        [$funnel, $vacancy] = $this->context();
        $other = Vacancy::factory()->create(['status' => 'published']);
        $role = DB::table('crm_roles')->insertGetId(['name' => 'Editor terbatas', 'permissions' => json_encode(['funnel.read']), 'scope_type' => 'VACANCY', 'scope_refs' => json_encode([$vacancy->id]), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create(['crm_role_id' => $role]));
        $this->postJson(route('funnels.readiness', $funnel->id), ['vacancy_id' => $other->id, 'schema' => json_encode(FunnelSchema::defaults())])->assertForbidden();
        $this->postJson(route('funnels.readiness', $funnel->id), ['vacancy_id' => $vacancy->id, 'schema' => json_encode(FunnelSchema::defaults())])->assertOk();
        $this->get(route('funnels.builder', $funnel->id))->assertOk()->assertDontSee($other->title);
        $this->assertDatabaseCount('cs_assignment_events', 0);
    }

    public function test_image_upload_is_scoped_and_renders_in_public_funnel(): void
    {
        Storage::fake('public');
        [$funnel, $vacancy, $token] = $this->context();
        $upload = $this->postJson(route('funnels.upload-media', $funnel->id), ['image' => UploadedFile::fake()->image('foto.png', 600, 400)])->assertOk();
        $url = $upload->json('url');
        Storage::disk('public')->assertExists(ltrim($url, '/'));
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $schema = FunnelSchema::defaults();
        $schema['blocks'][0]['url'] = $url;
        $schema['blocks'][0]['media_source'] = 'upload';
        $schema['blocks'][0]['alt'] = 'Gambar karir';
        $saved = $this->postJson(route('funnels.update', $funnel->id), ['schema' => json_encode($schema), 'name' => 'Funnel Dinamis', 'vacancy_id' => $vacancy->id, 'draft_token' => $token])->assertOk();
        $this->post(route('funnels.publish', $funnel->id), ['draft_token' => $saved->json('draft_token')])->assertSessionHasNoErrors();
        $this->get(route('apply.show', $funnel->slug))->assertOk()->assertSee($url);
        $this->postJson(route('funnels.upload-media', $funnel->id), ['image' => UploadedFile::fake()->create('berkas.pdf', 10, 'application/pdf')])->assertUnprocessable();
        $this->postJson(route('funnels.upload-media', $funnel->id), ['image' => UploadedFile::fake()->image('besar.jpg')->size(6000)])->assertUnprocessable();
        $role = DB::table('crm_roles')->insertGetId(['name' => 'Pembaca gambar', 'permissions' => json_encode(['funnel.read']), 'scope_type' => 'OPERATIONAL_ALL', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create(['crm_role_id' => $role]));
        $this->postJson(route('funnels.upload-media', $funnel->id), ['image' => UploadedFile::fake()->image('terlarang.jpg')])->assertForbidden();
        $this->get('/funnel-media/1/../../.env')->assertNotFound();
    }
}
