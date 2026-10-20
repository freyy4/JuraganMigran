<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\PipelineStage;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\FunnelSchema;
use App\Services\TrackingHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrackingHubTest extends TestCase
{
    use RefreshDatabase;

    private function destination(): int
    {
        return DB::table('tracking_destinations')->insertGetId(['provider' => 'META', 'display_name' => 'Meta Uji', 'pixel_reference' => '123456', 'secret_reference' => 'JM_TRACKING_META_TEST', 'active' => true, 'server_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function consent(): int
    {
        return DB::table('tracking_consent_snapshots')->insertGetId(['anonymous_session_id' => (string) Str::uuid(), 'analytics' => true, 'advertising' => true, 'policy_version' => 'JM-TRACKING-1', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_events_are_deduplicated_allowlisted_and_outages_retry_then_dead_letter(): void
    {
        $dest = $this->destination();
        DB::table('tracking_event_mappings')->insert(['destination_id' => $dest, 'canonical_event' => 'QUALIFIED_LEAD', 'provider_event' => 'Lead', 'created_at' => now(), 'updated_at' => now()]);
        $consent = $this->consent();
        $hub = app(TrackingHub::class);
        $hub->emit('QUALIFIED_LEAD', 'qualification-1', [], $consent, ['fbclid' => 'CLICK-UJI', 'health' => 'RAHASIA', 'nik' => 'RAHASIA', 'path' => '/lowongan?nik=RAHASIA']);
        $hub->emit('QUALIFIED_LEAD', 'qualification-1', [], $consent, []);
        $this->assertDatabaseCount('canonical_tracking_events', 1);
        $this->assertDatabaseCount('tracking_outbox', 1);
        $this->assertStringNotContainsString('RAHASIA', DB::table('canonical_tracking_events')->value('safe_context'));
        putenv('JM_TRACKING_META_TEST=TOKEN-RAHASIA');
        $_ENV['JM_TRACKING_META_TEST'] = 'TOKEN-RAHASIA';
        Http::fake(['graph.facebook.com/*' => Http::response([], 503)]);
        $outbox = DB::table('tracking_outbox')->first();
        for ($i = 0; $i < 5; $i++) {
            DB::table('tracking_outbox')->where('id', $outbox->id)->update(['next_attempt_at' => now()->subMinute()]);
            $hub->deliver($outbox->id);
        }
        $this->assertDatabaseHas('tracking_outbox', ['id' => $outbox->id, 'status' => 'DEAD']);
        $this->assertDatabaseCount('tracking_delivery_attempts', 5);
        Http::assertSentCount(5);
        $this->artisan('ops:monitor')->assertSuccessful();
        $this->artisan('ops:monitor')->assertSuccessful();
        $this->assertDatabaseCount('operations_alerts', 1);
        $this->assertDatabaseHas('operations_alerts', ['category' => 'TRACKING_DEAD_LETTER']);
        $this->assertStringNotContainsString('TOKEN-RAHASIA', json_encode(DB::table('tracking_delivery_attempts')->get()));
        putenv('JM_TRACKING_META_TEST');
        unset($_ENV['JM_TRACKING_META_TEST']);
    }

    public function test_public_browser_cannot_claim_crm_milestones_and_consent_can_be_withdrawn(): void
    {
        $this->postJson(route('tracking-public.event'), ['event' => 'DEPARTED', 'event_id' => Str::uuid()])->assertUnprocessable();
        $this->postJson(route('tracking-public.consent'), ['analytics' => true, 'advertising' => true])->assertOk();
        $old = session('tracking_consent_id');
        $id = (string) Str::uuid();
        $this->postJson(route('tracking-public.event'), ['event' => 'PAGE_VIEW', 'event_id' => $id])->assertOk();
        $this->postJson(route('tracking-public.event'), ['event' => 'PAGE_VIEW', 'event_id' => $id])->assertOk();
        $this->assertDatabaseCount('canonical_tracking_events', 1);
        $this->postJson(route('tracking-public.consent'), ['analytics' => false, 'advertising' => false])->assertOk();
        $this->assertNotNull(DB::table('tracking_consent_snapshots')->where('id', $old)->value('withdrawn_at'));
        $this->postJson(route('tracking-public.event'), ['event' => 'PAGE_VIEW', 'event_id' => Str::uuid()])->assertJsonPath('skipped', true);
    }

    public function test_config_and_tests_are_authorized_audited_and_do_not_count_as_production(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('tracking.index'))->assertOk();
        $this->postJson(route('tracking.destination.store'), ['provider' => 'META', 'display_name' => 'Tujuan Meta', 'pixel_reference' => '123456', 'active' => true])->assertOk();
        $dest = DB::table('tracking_destinations')->first();
        $this->postJson(route('tracking.mapping'), ['destination_id' => $dest->id, 'canonical_event' => 'FORM_SUBMIT', 'provider_event' => 'Lead'])->assertOk();
        $mapping = DB::table('tracking_event_mappings')->first();
        $this->patchJson(route('tracking.api-mapping-update', $mapping->id), ['provider_event' => 'CustomLead'])->assertOk();
        $this->assertDatabaseHas('tracking_event_mappings', ['canonical_event' => 'FORM_SUBMIT', 'provider_event' => 'CustomLead', 'version' => 2]);
        $this->postJson(route('tracking.test'), ['event' => 'FORM_SUBMIT', 'source' => 'server'])->assertJsonPath('is_test', true);
        $this->getJson(route('tracking.index'))->assertJsonPath('counts', []);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tracking.destination_saved']);
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $this->actingAs($cs)->postJson(route('tracking.destination.store'), [])->assertForbidden();
    }

    public function test_delayed_stage_event_preserves_original_consent_and_context(): void
    {
        $app = Application::factory()->create();
        $lead = DB::table('acquisition_leads')->insertGetId(['name' => 'Lead Uji', 'public_token' => Str::uuid(), 'normalized_phone' => '+6281222222222', 'status' => 'qualified', 'created_at' => now(), 'updated_at' => now()]);
        $funnel = DB::table('application_funnels')->insertGetId(['name' => 'Funnel Uji', 'slug' => 'stage-uji', 'vacancy_id' => $app->vacancy_id, 'status' => 'published', 'draft_schema' => json_encode(FunnelSchema::defaults()), 'created_at' => now(), 'updated_at' => now()]);
        $version = DB::table('funnel_versions')->insertGetId(['funnel_id' => $funnel, 'vacancy_id' => $app->vacancy_id, 'version' => 1, 'schema_snapshot' => json_encode(FunnelSchema::defaults()), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lead_submissions')->insert(['public_token' => Str::uuid(), 'idempotency_key' => Str::uuid(), 'lead_id' => $lead, 'application_id' => $app->id, 'funnel_id' => $funnel, 'version_id' => $version, 'vacancy_id' => $app->vacancy_id, 'payload' => '{}', 'attribution' => '{}', 'duplicate_state' => 'NEW_LEAD', 'consent_version' => '1', 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $consent = $this->consent();
        $dest = $this->destination();
        $stage = PipelineStage::factory()->create(['code' => 'MCU-UJI']);
        DB::table('tracking_event_mappings')->insert(['destination_id' => $dest, 'canonical_event' => 'MCU_REACHED', 'provider_event' => 'CustomMcu', 'stage_code' => $stage->code, 'created_at' => now(), 'updated_at' => now()]);
        $hub = app(TrackingHub::class);
        $hub->emit('FORM_SUBMIT', 'original-submit', ['lead_id' => $lead], $consent, ['fbclid' => 'ORIGINAL-CLICK', 'channel' => 'PAID_SOCIAL']);
        $hub->stage($app->id, $stage->id, 999);
        $hub->stage($app->id, $stage->id, 999);
        $event = DB::table('canonical_tracking_events')->where('canonical_event', 'MCU_REACHED')->first();
        $this->assertSame($consent, $event->consent_snapshot_id);
        $this->assertSame('ORIGINAL-CLICK', json_decode($event->safe_context, true)['fbclid']);
        $this->assertDatabaseCount('tracking_outbox', 1);
        DB::table('tracking_consent_snapshots')->where('id', $consent)->update(['withdrawn_at' => now()]);
        Http::fake();
        $hub->deliver(DB::table('tracking_outbox')->value('id'));
        Http::assertNothingSent();
        $this->assertDatabaseHas('tracking_outbox', ['status' => 'SKIPPED']);
    }

    public function test_funnel_tracking_is_versioned_and_editor_requires_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $vacancy = Vacancy::factory()->create();
        $schema = FunnelSchema::defaults();
        $dest = $this->destination();
        $funnel = DB::table('application_funnels')->insertGetId(['name' => 'Funnel Tracking', 'slug' => 'tracking-builder-uji', 'vacancy_id' => $vacancy->id, 'draft_schema' => json_encode($schema), 'owner_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('application_funnels')->find($funnel);
        $token = hash('sha256', json_encode([$row->name, (int) $row->vacancy_id, $schema, $row->status]));
        $this->get(route('funnels.builder', ['funnel' => $funnel, 'tab' => 'tracking']))->assertOk()->assertSee('Tracking Funnel');
        $this->postJson(route('tracking.funnel', $funnel), ['draft_token' => $token, 'mappings' => [['destination_id' => $dest, 'event' => 'FORM_SUBMIT', 'provider_event' => 'Lead']]])->assertOk();
        $draft = json_decode(DB::table('application_funnels')->where('id', $funnel)->value('draft_schema'), true);
        $this->assertSame('Lead', $draft['tracking']['mappings'][0]['provider_event']);
        $this->assertDatabaseCount('funnel_versions', 0);
    }

    public function test_funnel_success_is_independent_of_provider_outage(): void
    {
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $dest = $this->destination();
        $schema = FunnelSchema::defaults();
        $schema['tracking'] = ['mappings' => [['destination_id' => $dest, 'event' => 'FORM_SUBMIT', 'provider_event' => 'Lead', 'version' => 1]]];
        $funnel = DB::table('application_funnels')->insertGetId(['name' => 'Uji outage', 'slug' => 'uji-outage', 'vacancy_id' => $vacancy->id, 'status' => 'published', 'draft_schema' => json_encode($schema), 'created_at' => now(), 'updated_at' => now()]);
        $version = DB::table('funnel_versions')->insertGetId(['funnel_id' => $funnel, 'vacancy_id' => $vacancy->id, 'version' => 1, 'schema_snapshot' => json_encode($schema), 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('application_funnels')->where('id', $funnel)->update(['active_version_id' => $version]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->postJson(route('tracking-public.consent'), ['analytics' => true, 'advertising' => true])->assertOk();
        $this->get('/apply/uji-outage?fbclid=CLICK-UJI')->assertOk();
        $entry = session('funnel_token.'.$funnel);
        $this->post('/apply/uji-outage', ['idempotency_key' => $entry['token'], 'version_id' => $entry['version'], 'answers' => ['name' => 'Siti', 'whatsapp' => '081234567890'], 'consent' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('tracking_outbox', 1);
        Http::assertNothingSent();
    }
}
