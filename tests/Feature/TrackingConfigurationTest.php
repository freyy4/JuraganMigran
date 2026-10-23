<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vacancy;
use App\Services\FunnelSchema;
use App\Services\TrackingAdapters;
use App\Services\TrackingConfiguration;
use App\Services\TrackingHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TrackingConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function destination(array $changes = []): int
    {
        return DB::table('tracking_destinations')->insertGetId($changes + ['provider' => 'META', 'display_name' => 'Meta Utama', 'pixel_reference' => '123456789', 'active' => true, 'browser_enabled' => true, 'server_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function schema(int $destination): array
    {
        $schema = FunnelSchema::defaults();
        $schema['tracking'] = ['enabled' => true, 'destination_ids' => [$destination], 'events' => ['PAGE_VIEW', 'LEAD_CREATED'], 'rules' => [['id' => 'hero-visible', 'section_key' => 'hero', 'trigger_type' => 'SECTION_VISIBLE', 'canonical_event' => 'VIEW_VACANCY', 'destination_ids' => [$destination], 'consent_required' => true, 'fire_policy' => 'SESSION', 'enabled' => true]]];

        return $schema;
    }

    private function published(array $schema): array
    {
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $funnel = DB::table('application_funnels')->insertGetId(['name' => 'Tracking PRD 8.13', 'slug' => 'tracking-v813', 'vacancy_id' => $vacancy->id, 'status' => 'published', 'draft_schema' => json_encode($schema), 'created_at' => now(), 'updated_at' => now()]);
        $version = DB::table('funnel_versions')->insertGetId(['funnel_id' => $funnel, 'vacancy_id' => $vacancy->id, 'version' => 1, 'schema_snapshot' => json_encode(app(TrackingConfiguration::class)->freeze($schema)), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('application_funnels')->where('id', $funnel)->update(['active_version_id' => $version]);

        return [$funnel, $version];
    }

    public function test_destination_secrets_are_encrypted_never_echoed_and_marketing_cannot_rotate_them(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = ['provider' => 'META', 'display_name' => 'Meta Rahasia', 'pixel_reference' => '123456789', 'access_token' => 'private-access-token', 'environment' => 'PRODUCTION', 'allowed_domains' => ['example.com'], 'active' => true, 'browser_enabled' => true];
        $this->postJson(route('tracking-settings.store'), $payload)->assertOk()->assertDontSee('private-access-token');
        $destination = DB::table('tracking_destinations')->first();
        $this->assertStringNotContainsString('private-access-token', $destination->encrypted_secret);
        $this->assertSame('private-access-token', app(TrackingAdapters::class)->secret($destination)['access_token']);
        $this->assertStringNotContainsString('private-access-token', json_encode(DB::table('audit_logs')->get()));
        $this->get(route('tracking-settings.index'))->assertOk()->assertDontSee('private-access-token')->assertDontSee($destination->encrypted_secret);
        $this->getJson(route('tracking-settings.index'))->assertOk()->assertDontSee('encrypted_secret')->assertDontSee('secret_reference');
        $marketing = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'marketing')->value('id')]);
        $this->actingAs($marketing)->patchJson(route('tracking-settings.update', $destination->id), $payload)->assertForbidden();
        unset($payload['access_token']);
        $this->patchJson(route('tracking-settings.update', $destination->id), $payload)->assertOk();
        $this->assertSame('private-access-token', app(TrackingAdapters::class)->secret(DB::table('tracking_destinations')->first())['access_token']);
    }

    public function test_master_filters_pagination_duplicate_warning_and_local_test_are_honest(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->destination(['environment' => 'STAGING']);
        $this->getJson(route('tracking-settings.index', ['environment' => 'STAGING', 'q' => 'Utama']))->assertJsonPath('destinations.total', 1);
        $this->getJson(route('tracking-settings.index', ['status' => 'inactive']))->assertJsonPath('destinations.total', 0);
        $this->postJson(route('tracking-settings.store'), ['provider' => 'META', 'display_name' => 'Duplikat', 'pixel_reference' => '123456789', 'active' => true])->assertJsonPath('duplicate', true);
        $this->postJson(route('tracking-settings.store'), ['provider' => 'META', 'display_name' => 'Invalid', 'pixel_reference' => '<script>', 'allowed_domains' => ['https://example.com/path']])->assertUnprocessable();
        Http::fake();
        $this->postJson(route('tracking-settings.test', $id), ['mode' => 'event'])->assertJsonPath('is_test', true)->assertJsonPath('delivery', 'LOCAL_VALIDATION_ONLY')->assertJsonPath('status', 'WARNING');
        Http::assertNothingSent();
        $this->assertDatabaseHas('canonical_tracking_events', ['is_test' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tracking.destination_test']);
        if (getenv('MARKETING_QA_FIXTURE')) {
            file_put_contents('/tmp/jm-tracking-settings.html', $this->get(route('tracking-settings.index'))->assertOk()->getContent());
        }
    }

    public function test_rules_reject_sensitive_parameters_arbitrary_events_and_false_submit_claims(): void
    {
        $id = $this->destination();
        foreach (['event', 'parameter', 'trigger', 'section'] as $invalid) {
            $schema = $this->schema($id);
            match ($invalid) {
                'event' => $schema['tracking']['rules'][0]['canonical_event'] = 'DEPARTED',
                'parameter' => $schema['tracking']['rules'][0]['parameter_map'] = ['nik' => '{{answers.nik}}'],
                'trigger' => $schema['tracking']['rules'][0]['canonical_event'] = 'LEAD_CREATED',
                'section' => $schema['tracking']['rules'][0]['section_key'] = 'missing',
            };
            try {
                app(FunnelSchema::class)->validate($schema);
                $this->fail('Unsafe rule should not be accepted: '.$invalid);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertCount(1, app(FunnelSchema::class)->validate($this->schema($id))['tracking']['rules']);
    }

    public function test_section_dispatch_obeys_consent_and_deduplicates_client_and_server_with_one_event_id(): void
    {
        $id = $this->destination();
        [$funnel, $version] = $this->published($this->schema($id));
        $payload = ['event' => 'VIEW_VACANCY', 'event_id' => Str::uuid(), 'funnel_slug' => 'tracking-v813', 'rule_id' => 'hero-visible', 'page_id' => Str::uuid()];
        $this->postJson(route('tracking-public.event'), $payload)->assertJsonPath('skipped', true);
        $this->postJson(route('tracking-public.consent'), ['analytics' => true, 'advertising' => true])->assertOk();
        $response = $this->postJson(route('tracking-public.event'), $payload)->assertOk()->assertJsonPath('destinations.0.id', $id)->assertJsonPath('destinations.0.provider_event', 'ViewContent');
        $eventId = $response->json('event_id');
        $payload['event_id'] = Str::uuid();
        $this->postJson(route('tracking-public.event'), $payload)->assertJsonPath('event_id', $eventId)->assertJsonPath('destinations', []);
        $this->assertDatabaseCount('tracking_outbox', 1);
        $this->assertDatabaseCount('tracking_client_deliveries', 1);
        $this->assertDatabaseCount('canonical_tracking_events', 1);
        $context = DB::table('canonical_tracking_events')->value('safe_context');
        $this->assertStringContainsString('hero', $context);
        $this->assertDatabaseHas('canonical_tracking_events', ['event_id' => $eventId, 'funnel_version_id' => $version]);
        $this->postJson(route('tracking-public.event'), array_merge($payload, ['event' => 'LEAD_CREATED']))->assertUnprocessable();
        $this->postJson(route('tracking-public.consent'), ['analytics' => true, 'advertising' => false])->assertOk();
        $this->postJson(route('tracking-public.event'), ['event' => 'PAGE_VIEW', 'event_id' => Str::uuid(), 'funnel_slug' => 'tracking-v813'])->assertJsonPath('destinations', []);
        $this->assertDatabaseCount('tracking_outbox', 1);
    }

    public function test_disabled_destination_domain_and_staging_cannot_load_pixels(): void
    {
        $id = $this->destination(['allowed_domains' => json_encode(['allowed.example'])]);
        [, $version] = $this->published($this->schema($id));
        $hub = app(TrackingHub::class);
        $this->assertSame([], $hub->browserConfig($version));
        DB::table('tracking_destinations')->where('id', $id)->update(['allowed_domains' => null, 'active' => false]);
        $this->assertSame([], $hub->browserConfig($version));
        DB::table('tracking_destinations')->where('id', $id)->update(['active' => true, 'environment' => 'STAGING']);
        $this->app['env'] = 'production';
        $this->assertSame([], $hub->browserConfig($version));
        $this->app['env'] = 'testing';
    }

    public function test_mapping_is_frozen_in_published_snapshot_and_disabled_tracking_stays_off(): void
    {
        $id = $this->destination();
        $schema = $this->schema($id);
        [, $version] = $this->published($schema);
        DB::table('tracking_event_mappings')->insert(['destination_id' => $id, 'canonical_event' => 'PAGE_VIEW', 'provider_event' => 'ChangedAfterPublish', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('PageView', app(TrackingHub::class)->browserConfig($version, null, 'PAGE_VIEW')[0]['provider_event']);
        $schema['tracking']['enabled'] = false;
        $this->assertSame([], app(TrackingConfiguration::class)->mappings($schema));
        $this->assertStringContainsString('belum diaktifkan', app(TrackingConfiguration::class)->summary($schema)['issues'][0]['message']);
    }

    public function test_conversion_requires_server_commit_and_tracking_failure_does_not_lose_lead(): void
    {
        $id = $this->destination();
        $this->published($this->schema($id));
        $this->get('/apply/tracking-v813')->assertOk();
        $entry = session('funnel_token.1');
        $this->mock(TrackingHub::class, function ($mock): void {
            $mock->shouldReceive('fromRequest')->once()->andThrow(new \RuntimeException('Tracking unavailable'));
        });
        $this->post('/apply/tracking-v813', ['idempotency_key' => $entry['token'], 'version_id' => $entry['version'], 'answers' => ['name' => 'Siti', 'whatsapp' => '081234567890'], 'consent' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('lead_submissions', 1);
        $this->assertDatabaseCount('canonical_tracking_events', 0);
    }

    public function test_builder_and_publish_review_show_tracking_configuration_without_public_badges(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->destination();
        [$funnel] = $this->published($this->schema($id));
        $builder = $this->get(route('funnels.builder', $funnel))->assertOk()->assertSee('Tracking & Integrasi', false)->assertSee('Tracking per Section / Action');
        $public = $this->get('/apply/tracking-v813')->assertOk()->assertDontSee('data-funnel-tracking')->assertDontSee('Tracked:');
        if (getenv('MARKETING_QA_FIXTURE')) {
            file_put_contents('/tmp/jm-tracking-public.html', $public->getContent());
            file_put_contents('/tmp/jm-tracking-editor.html', $builder->getContent());
        }
        $vacancyId = DB::table('application_funnels')->value('vacancy_id');
        $response = $this->postJson(route('funnels.readiness', $funnel), ['schema' => json_encode($this->schema($id)), 'vacancy_id' => $vacancyId])->assertOk()->assertJsonPath('tracking.destinations.0.id', $id)->assertJsonPath('tracking.events.1', 'LEAD_CREATED');
        $this->assertNotEmpty($response->json('tracking.consent'));
    }

    public function test_connection_check_reads_vendor_metadata_without_sending_conversion_or_exposing_token(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->destination(['encrypted_secret' => Crypt::encryptString(json_encode(['access_token' => 'token-must-stay-private']))]);
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['id' => '123456789'])->push(['error' => ['message' => 'token-must-stay-private']], 403)]);
        $this->postJson(route('tracking-settings.test', $id), ['mode' => 'connection'])->assertOk()->assertJsonPath('status', 'HEALTHY')->assertJsonPath('delivery', 'READ_ONLY_CONNECTION_CHECK')->assertDontSee('token-must-stay-private');
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer token-must-stay-private'));
        $this->assertDatabaseCount('canonical_tracking_events', 0);
        $this->assertDatabaseCount('tracking_outbox', 0);
        $this->postJson(route('tracking-settings.test', $id), ['mode' => 'connection'])->assertJsonPath('status', 'ERROR')->assertDontSee('token-must-stay-private');
    }

    public function test_google_tag_and_ads_conversion_mapping_are_distinct_and_server_upload_avoids_client_duplicates(): void
    {
        $id = $this->destination(['provider' => 'GOOGLE', 'pixel_reference' => 'AW-123456', 'server_enabled' => false]);
        $schema = $this->schema($id);
        $schema['tracking']['events'] = ['LEAD_CREATED'];
        $schema['tracking']['rules'] = [];
        $this->assertContains('blocker', array_column(app(TrackingConfiguration::class)->summary($schema)['issues'], 'severity'));
        DB::table('tracking_event_mappings')->insert(['destination_id' => $id, 'canonical_event' => 'LEAD_CREATED', 'provider_event' => '123', 'conversion_label' => 'AbC-label', 'created_at' => now(), 'updated_at' => now()]);
        [, $version] = $this->published($schema);
        $this->assertSame('AbC-label', app(TrackingHub::class)->browserConfig($version, null, 'LEAD_CREATED')[0]['conversion_label']);
        DB::table('tracking_destinations')->where('id', $id)->update(['server_enabled' => true]);
        $this->assertSame([], app(TrackingHub::class)->browserConfig($version, null, 'LEAD_CREATED'));
        $tag = $this->destination(['provider' => 'GOOGLE', 'pixel_reference' => 'G-ABC123', 'server_enabled' => false]);
        $tagSchema = $this->schema($tag);
        $tagSchema['tracking']['events'] = ['PAGE_VIEW'];
        $tagSchema['tracking']['rules'] = [];
        $this->assertSame('page_view', app(TrackingConfiguration::class)->mappings($tagSchema)[0]['provider_event']);
    }

    public function test_publish_freezes_bindings_and_inactive_new_assignments_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->destination();
        $schema = $this->schema($id);
        [$funnel] = $this->published($schema);
        $this->post(route('funnels.publish', $funnel))->assertRedirect()->assertSessionHasNoErrors();
        $published = json_decode(DB::table('funnel_versions')->where('funnel_id', $funnel)->where('version', 2)->value('schema_snapshot'), true);
        $this->assertSame('ViewContent', $published['tracking']['rules'][0]['resolved_mappings'][0]['provider_event']);
        $this->assertSame('PageView', $published['tracking']['mappings'][0]['provider_event']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tracking.publish_review']);
        $inactive = $this->destination(['provider' => 'TIKTOK', 'pixel_reference' => 'C7U9F8D2', 'active' => false]);
        $schema['tracking']['destination_ids'][] = $inactive;
        $row = DB::table('application_funnels')->find($funnel);
        $token = hash('sha256', json_encode([$row->name, (int) $row->vacancy_id, json_decode($row->draft_schema, true), $row->status]));
        $this->postJson(route('tracking.funnel', $funnel), ['draft_token' => $token, 'tracking' => $schema['tracking']])->assertUnprocessable();
    }

    public function test_browser_uses_the_published_version_that_rendered_the_page_after_a_new_publication(): void
    {
        $id = $this->destination();
        [$funnel, $version] = $this->published($this->schema($id));
        $nextSchema = $this->schema($id);
        $nextSchema['tracking']['enabled'] = false;
        $next = DB::table('funnel_versions')->insertGetId(['funnel_id' => $funnel, 'vacancy_id' => DB::table('application_funnels')->value('vacancy_id'), 'version' => 2, 'schema_snapshot' => json_encode(app(TrackingConfiguration::class)->freeze($nextSchema)), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('application_funnels')->where('id', $funnel)->update(['active_version_id' => $next]);
        $this->postJson(route('tracking-public.consent'), ['analytics' => true, 'advertising' => true])->assertOk();
        $this->postJson(route('tracking-public.event'), ['event' => 'PAGE_VIEW', 'event_id' => Str::uuid(), 'funnel_slug' => 'tracking-v813', 'version_id' => $version])->assertOk()->assertJsonPath('destinations.0.id', $id);
        $this->assertDatabaseHas('canonical_tracking_events', ['funnel_version_id' => $version]);
        $this->postJson(route('tracking-public.event'), ['event' => 'PAGE_VIEW', 'event_id' => Str::uuid(), 'funnel_slug' => 'tracking-v813', 'version_id' => 999999])->assertNotFound();
    }

    public function test_tracking_api_paginates_destinations_without_exposing_credentials(): void
    {
        $this->actingAs(User::factory()->create());
        for ($index = 0; $index < 55; $index++) {
            $this->destination(['display_name' => 'API destination '.$index, 'pixel_reference' => (string) (100000 + $index), 'encrypted_secret' => Crypt::encryptString('private-token')]);
        }
        $this->get('/api/settings/tracking-destinations')->assertOk()->assertJsonPath('destinations.total', 55)->assertJsonCount(20, 'destinations.data')->assertDontSee('encrypted_secret')->assertDontSee('private-token');
        $this->getJson('/api/settings/tracking-destinations?page=3')->assertOk()->assertJsonCount(15, 'destinations.data');
        $this->postJson('/api/settings/tracking-destinations', ['provider' => 'TIKTOK', 'display_name' => 'TikTok API', 'pixel_reference' => 'API-C7U9F8D2', 'active' => true])->assertOk();
        $this->assertDatabaseHas('tracking_destinations', ['provider' => 'TIKTOK', 'display_name' => 'TikTok API']);
    }

    public function test_section_api_uses_draft_tokens_and_never_changes_published_tracking(): void
    {
        $this->actingAs(User::factory()->create());
        $destination = $this->destination();
        [$funnel, $version] = $this->published($this->schema($destination));
        $published = DB::table('funnel_versions')->where('id', $version)->value('schema_snapshot');
        $configuration = $this->getJson('/api/funnels/'.$funnel.'/tracking')->assertOk()->assertJsonPath('active_version_id', $version)->assertJsonPath('tracking.enabled', true);
        $token = $configuration->json('draft_token');
        $rule = ['id' => 'hero-click', 'trigger_type' => 'BUTTON_CLICK', 'canonical_event' => 'CTA_CLICK', 'destination_ids' => [$destination], 'fire_policy' => 'PAGE', 'consent_required' => true, 'enabled' => true];
        $url = '/api/funnels/'.$funnel.'/sections/hero/tracking';
        $invalid = $rule;
        $invalid['destination_ids'][] = $destination;
        $this->postJson($url, ['draft_token' => $token, 'rule' => $invalid])->assertUnprocessable();
        $this->postJson($url, ['draft_token' => $token, 'rule' => $rule + ['section_key' => 'form']])->assertUnprocessable();
        $created = $this->postJson($url, ['draft_token' => $token, 'rule' => $rule])->assertCreated()->assertJsonCount(2, 'tracking.rules')->assertJsonPath('tracking.rules.1.section_key', 'hero');
        $fresh = $created->json('draft_token');
        $this->assertNotSame($token, $fresh);
        $this->postJson($url, ['draft_token' => $token, 'rule' => $rule])->assertConflict();
        $this->deleteJson('/api/funnels/'.$funnel.'/sections/form/tracking/hero-click', ['draft_token' => $fresh])->assertNotFound();
        $deleted = $this->deleteJson($url.'/hero-click', ['draft_token' => $fresh])->assertOk()->assertJsonCount(1, 'tracking.rules');
        $this->assertNotSame($fresh, $deleted->json('draft_token'));
        $this->assertSame($published, DB::table('funnel_versions')->where('id', $version)->value('schema_snapshot'));
        $this->assertDatabaseCount('funnel_versions', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tracking.section_rule_added']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tracking.section_rule_removed']);
        $this->putJson('/api/funnels/'.$funnel.'/tracking', ['draft_token' => $deleted->json('draft_token'), 'tracking' => []])->assertOk()->assertJsonPath('tracking', []);
    }

    public function test_tracking_api_permissions_and_vacancy_scope_are_enforced(): void
    {
        $destination = $this->destination();
        [$funnel] = $this->published($this->schema($destination));
        $role = DB::table('crm_roles')->insertGetId(['code' => 'tracking-api-reader', 'name' => 'Tracking reader', 'scope_type' => 'OPERATIONAL_ALL', 'permissions' => json_encode(['funnel.read', 'funnel.tracking.read']), 'created_at' => now(), 'updated_at' => now()]);
        $reader = User::factory()->create(['crm_role_id' => $role]);
        $this->actingAs($reader)->getJson('/api/funnels/'.$funnel.'/tracking')->assertOk();
        $this->putJson('/api/funnels/'.$funnel.'/tracking', [])->assertForbidden();
        $this->postJson('/api/funnels/'.$funnel.'/tracking/test', [])->assertForbidden();
        $this->getJson('/api/settings/tracking-destinations')->assertForbidden();
        DB::table('crm_roles')->where('id', $role)->update(['permissions' => json_encode(['funnel.read', 'funnel.manage_draft', 'funnel.tracking.read', 'funnel.tracking.update']), 'scope_type' => 'VACANCY', 'scope_refs' => json_encode([Vacancy::factory()->create()->id])]);
        $this->getJson('/api/funnels/'.$funnel.'/tracking')->assertUnauthorized();
        $this->actingAs($reader)->getJson('/api/funnels/'.$funnel.'/tracking')->assertNotFound();
        $this->putJson('/api/funnels/'.$funnel.'/tracking', ['draft_token' => str_repeat('a', 64), 'tracking' => ['enabled' => false]])->assertNotFound();
        $this->postJson('/api/funnels/'.$funnel.'/tracking/test', ['draft_token' => str_repeat('a', 64), 'schema' => json_encode($this->schema($destination))])->assertNotFound();
    }

    public function test_section_api_preserves_legacy_vendor_mapping_when_publishing_new_rules(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->destination();
        $schema = FunnelSchema::defaults();
        $schema['tracking'] = ['mappings' => [['destination_id' => $id, 'event' => 'PAGE_VIEW', 'provider_event' => 'LegacyPage', 'version' => 3]]];
        [$funnel] = $this->published($schema);
        $token = $this->getJson('/api/funnels/'.$funnel.'/tracking')->json('draft_token');
        $rule = ['id' => 'legacy-click', 'trigger_type' => 'BUTTON_CLICK', 'canonical_event' => 'CTA_CLICK', 'destination_ids' => [$id], 'fire_policy' => 'PAGE', 'enabled' => true];
        $this->postJson('/api/funnels/'.$funnel.'/sections/hero/tracking', ['draft_token' => $token, 'rule' => $rule])->assertCreated()->assertJsonPath('tracking.mappings.0.provider_event', 'LegacyPage');
        $this->post(route('funnels.publish', $funnel))->assertRedirect()->assertSessionHasNoErrors();
        $snapshot = json_decode(DB::table('funnel_versions')->where('funnel_id', $funnel)->where('version', 2)->value('schema_snapshot'), true);
        $this->assertSame('LegacyPage', $snapshot['tracking']['mappings'][0]['provider_event']);
        $this->assertSame('CTA_CLICK', $snapshot['tracking']['rules'][0]['resolved_mappings'][0]['provider_event']);
    }

    public function test_builder_test_tracking_records_diagnostics_without_mutating_draft_or_creating_leads(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->destination();
        $schema = $this->schema($id);
        [$funnel] = $this->published($schema);
        $row = DB::table('application_funnels')->find($funnel);
        $token = $this->getJson('/api/funnels/'.$funnel.'/tracking')->json('draft_token');
        $schema['blocks'][0]['title'] = 'Unsaved title';
        Http::fake();
        $result = $this->postJson('/api/funnels/'.$funnel.'/tracking/test', ['draft_token' => $token, 'schema' => json_encode($schema), 'rule_id' => 'hero-visible'])->assertOk()->assertJsonPath('is_test', true)->assertJsonPath('delivery', 'LOCAL_VALIDATION_ONLY')->assertJsonPath('canonical_event', 'VIEW_VACANCY')->assertJsonPath('mappings.0.provider_event', 'ViewContent');
        $this->assertDatabaseHas('canonical_tracking_events', ['event_id' => $result->json('event_id'), 'funnel_id' => $funnel, 'is_test' => true]);
        $this->assertSame($row->draft_schema, DB::table('application_funnels')->where('id', $funnel)->value('draft_schema'));
        $this->assertDatabaseCount('acquisition_leads', 0);
        $this->assertDatabaseCount('lead_submissions', 0);
        $this->assertDatabaseCount('tracking_outbox', 0);
        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'tracking.funnel_test']);
        $this->get(route('tracking-settings.diagnostics'))->assertOk()->assertSee('Riwayat Event Uji')->assertSee($result->json('event_id'));
        $this->getJson(route('tracking-settings.api-diagnostics'))->assertOk()->assertJsonPath('counts', [])->assertJsonPath('test_events.data.0.event_id', $result->json('event_id'));
        $this->postJson('/api/funnels/'.$funnel.'/tracking/test', ['draft_token' => str_repeat('0', 64), 'schema' => json_encode($schema)])->assertConflict();
        $this->assertDatabaseCount('canonical_tracking_events', 1);
    }

    public function test_malformed_tracking_payloads_are_validation_errors_and_not_server_errors(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->destination();
        [$funnel] = $this->published($this->schema($id));
        $token = $this->getJson('/api/funnels/'.$funnel.'/tracking')->json('draft_token');
        foreach (['invalid', [null], [['id' => 'bad', 'script' => 'alert(1)']]] as $rules) {
            $this->putJson('/api/funnels/'.$funnel.'/tracking', ['draft_token' => $token, 'tracking' => ['rules' => $rules]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('funnel_versions', 1);
    }

    public function test_funnel_binding_permission_does_not_allow_editing_vendor_mappings(): void
    {
        $id = $this->destination();
        $schema = $this->schema($id);
        [$funnel] = $this->published($schema);
        $role = DB::table('crm_roles')->insertGetId(['code' => 'tracking-binding-only', 'name' => 'Binding only', 'scope_type' => 'OPERATIONAL_ALL', 'permissions' => json_encode(['funnel.read', 'funnel.manage_draft', 'funnel.tracking.read', 'funnel.tracking.update']), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create(['crm_role_id' => $role]));
        $configuration = $this->getJson('/api/funnels/'.$funnel.'/tracking')->assertOk();
        $token = $configuration->json('draft_token');
        $schema['tracking'] = ['mappings' => [['destination_id' => $id, 'event' => 'PAGE_VIEW', 'provider_event' => 'ChangedWithoutPermission']]];
        $this->putJson('/api/funnels/'.$funnel.'/tracking', ['draft_token' => $token, 'tracking' => $schema['tracking']])->assertForbidden();
        $row = DB::table('application_funnels')->find($funnel);
        $this->postJson(route('funnels.update', $funnel), ['draft_token' => $token, 'schema' => json_encode($schema), 'name' => $row->name, 'vacancy_id' => $row->vacancy_id])->assertForbidden();
        $this->putJson('/api/funnels/'.$funnel.'/tracking', ['draft_token' => $token, 'tracking' => ['enabled' => false, 'destination_ids' => [], 'events' => [], 'rules' => []]])->assertOk();
    }
}
