<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vacancy;
use App\Services\AttributionService;
use App\Services\ConversationCrm;
use App\Services\ConversionService;
use App\Services\CsRouter;
use App\Services\DuplicateResolutionService;
use App\Services\IdentityResolutionService;
use App\Services\LeadUpsertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class UnifiedAcquisitionCrmTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $role = 'cs'): User
    {
        return User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', $role)->value('id'), 'team_id' => 1]);
    }

    private function funnel(): object
    {
        $this->actingAs($this->agent('super_admin'));
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $this->post(route('funnels.store'), ['name' => 'Jepang', 'slug' => 'identity-jp', 'vacancy_id' => $vacancy->id])->assertSessionHasNoErrors();
        $funnel = DB::table('application_funnels')->first();
        $this->post(route('funnels.publish', $funnel->id))->assertSessionHasNoErrors();

        return DB::table('application_funnels')->find($funnel->id);
    }

    private function form(object $funnel, string $phone = '081234567890', ?string $key = null): void
    {
        $key ??= (string) Str::uuid();
        $this->withSession(['funnel_token.'.$funnel->id => ['token' => $key, 'version' => $funnel->active_version_id]])->post(route('apply.submit', $funnel->slug), ['idempotency_key' => $key, 'version_id' => $funnel->active_version_id, 'answers' => ['name' => 'Siti Rahma', 'whatsapp' => $phone], 'consent' => 1])->assertSessionHasNoErrors()->assertRedirect();
    }

    private function inbound(string $external = 'message-1', string $phone = '6281234567890'): void
    {
        $channel = DB::table('whatsapp_channels')->first();
        if (! $channel) {
            DB::table('whatsapp_channels')->insert(['name' => 'JM', 'phone_number_id' => '12345', 'waba_id' => '123', 'display_phone_number' => '+628111222333', 'access_token' => Crypt::encryptString('test'), 'team_id' => 1, 'prefer_existing_owner' => false, 'created_at' => now(), 'updated_at' => now()]);
            $channel = DB::table('whatsapp_channels')->first();
        }
        app(ConversationCrm::class)->ingestNormalized([['kind' => 'message', 'provider' => 'META_CLOUD_API', 'channel_id' => $channel->id, 'external' => $external, 'from' => $phone, 'name' => 'Siti Rahma', 'type' => 'text', 'text' => 'Saya tertarik program Jepang', 'timestamp' => now()->timestamp]]);
    }

    public function test_form_then_whatsapp_keeps_one_contact_lead_owner_and_campaign(): void
    {
        $funnel = $this->funnel();
        $cs = $this->agent();
        $this->get(route('apply.show', $funnel->slug).'?utm_source=Meta%20Ads&utm_medium=paid_social&utm_campaign=Jepang');
        $this->form($funnel);
        $lead = DB::table('acquisition_leads')->first();
        DB::table('acquisition_leads')->where('id', $lead->id)->update(['assigned_cs_id' => $cs->id]);
        DB::table('crm_agent_profiles')->updateOrInsert(['user_id' => $cs->id], ['availability' => 'OFFLINE']);
        $this->inbound();
        $this->inbound();
        $this->assertDatabaseCount('crm_contacts', 1);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('crm_messages', 1);
        $this->assertDatabaseCount('candidates', 0);
        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('crm_lead_interests', 1);
        $this->assertDatabaseHas('crm_conversations', ['lead_id' => $lead->id, 'assigned_user_id' => $cs->id]);
        $this->assertSame('Meta Ads', app(AttributionService::class)->summary($lead->id)['first']->source);
        $this->assertSame('WhatsApp', app(AttributionService::class)->summary($lead->id)['latest']->source);
        $this->assertDatabaseHas('acquisition_leads', ['id' => $lead->id, 'crm_source' => 'Meta Ads']);
        $this->assertDatabaseHas('crm_activities', ['lead_id' => $lead->id, 'event_type' => 'form_submitted']);
    }

    public function test_whatsapp_then_multiple_forms_uses_one_identity_and_retains_raw_evidence(): void
    {
        $funnel = $this->funnel();
        $this->inbound();
        $this->form($funnel, '+62 812-3456-7890');
        $this->form($funnel);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('crm_contacts', 1);
        $this->assertDatabaseCount('lead_submissions', 2);
        $this->assertDatabaseCount('crm_lead_interests', 1);
        $this->assertDatabaseCount('candidates', 0);
        $this->assertDatabaseHas('acquisition_leads', ['crm_source' => 'WhatsApp']);
        $this->assertNotNull(json_decode(DB::table('lead_submissions')->value('payload'), true)['encrypted']);
    }

    public function test_submission_retry_is_idempotent_but_a_new_intent_keeps_another_submission(): void
    {
        $funnel = $this->funnel();
        $key = (string) Str::uuid();
        $this->form($funnel, '081234567890', $key);
        $this->form($funnel, '081234567890', $key);
        $this->assertDatabaseCount('lead_submissions', 1);
        $this->form($funnel);
        $this->assertDatabaseCount('lead_submissions', 2);
        $this->assertDatabaseCount('acquisition_leads', 1);
    }

    public function test_same_name_and_shared_email_never_silently_merge_different_numbers(): void
    {
        $a = app(LeadUpsertService::class)->upsert('Siti Rahma', '081234567890', ' SITI@example.test ', 'Meta Ads');
        $b = app(LeadUpsertService::class)->upsert('Siti Rahma', '081234567891', 'siti@example.test', 'WhatsApp');
        $this->assertNotSame($a->contact_id, $b->contact_id);
        $this->assertDatabaseCount('acquisition_leads', 2);
        $this->assertDatabaseCount('crm_duplicate_candidates', 1);
        $this->assertDatabaseHas('crm_duplicate_candidates', ['status' => 'PENDING']);
        $this->assertSame($a->id, app(IdentityResolutionService::class)->resolve('+62 (812) 3456-7890')->id);
    }

    public function test_conversion_is_atomic_and_reuses_candidate_for_multiple_opportunities(): void
    {
        $cs = $this->agent('super_admin');
        $this->actingAs($cs);
        $lead = app(LeadUpsertService::class)->upsert('Siti', '081234567890');
        DB::table('acquisition_leads')->where('id', $lead->id)->update(['crm_stage' => 'QUALIFIED', 'status' => 'qualified', 'assigned_cs_id' => $cs->id]);
        $vacancy = Vacancy::factory()->create();
        $this->post(route('crm.convert', $lead->id), [])->assertSessionHasErrors('vacancy_id');
        $this->assertDatabaseCount('candidates', 0);
        $this->post(route('crm.convert', $lead->id), ['vacancy_id' => $vacancy->id])->assertRedirect();
        $this->post(route('crm.convert', $lead->id), ['vacancy_id' => $vacancy->id])->assertRedirect();
        $this->post(route('crm.convert', $lead->id), ['vacancy_id' => Vacancy::factory()->create()->id])->assertRedirect();
        $this->assertDatabaseCount('candidates', 1);
        $this->assertDatabaseCount('applications', 2);
        $this->assertDatabaseHas('candidates', ['source_lead_id' => $lead->id]);
        $this->assertDatabaseCount('crm_contacts', 1);
        $this->assertNotNull(app(AttributionService::class)->summary($lead->id)['conversion']);
    }

    public function test_failed_conversion_rolls_back_candidate_application_and_interests(): void
    {
        $cs = $this->agent();
        $lead = app(LeadUpsertService::class)->upsert('Siti', '081234567890');
        $vacancy = Vacancy::factory()->create();
        DB::table('acquisition_leads')->where('id', $lead->id)->update(['crm_stage' => 'QUALIFIED']);
        $mock = Mockery::mock(AttributionService::class);
        $mock->shouldReceive('record')->andThrow(new \RuntimeException('ledger unavailable'));
        app()->instance(AttributionService::class, $mock);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $cs);
        try {
            app(ConversionService::class)->convert($request, $lead->id, $vacancy->id);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('ledger unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('candidates', 0);
        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('crm_lead_interests', 0);
        $this->assertDatabaseHas('acquisition_leads', ['id' => $lead->id, 'candidate_id' => null, 'crm_stage' => 'QUALIFIED']);
    }

    public function test_duplicate_merge_requires_comparison_preserves_history_and_aliases(): void
    {
        $admin = $this->agent('super_admin');
        $a = app(LeadUpsertService::class)->upsert('Siti Rahma', '081234567890');
        $b = app(LeadUpsertService::class)->upsert('Siti Rahma', '081234567891');
        app(AttributionService::class)->record($b->id, 'paid-second', 'FORM_SUBMITTED', 'Meta Ads');
        $pair = DB::table('crm_duplicate_candidates')->first();
        $this->actingAs($admin)->post(route('crm.duplicate-resolve', $pair->id), ['action' => 'merge', 'primary_id' => $a->id, 'reason' => 'Kontak diverifikasi', 'review_token' => (string) Str::uuid()])->assertConflict();
        $view = $this->get(route('crm.duplicate-compare', $pair->id))->assertOk();
        $this->post(route('crm.duplicate-resolve', $pair->id), ['action' => 'merge', 'primary_id' => $a->id, 'reason' => 'Kontak diverifikasi', 'review_token' => $view->viewData('token')])->assertRedirect();
        $this->assertDatabaseCount('acquisition_leads', 2);
        $this->assertDatabaseHas('acquisition_leads', ['id' => $b->id, 'merged_into_id' => $a->id]);
        $this->assertSame($a->id, app(IdentityResolutionService::class)->resolve('081234567891')->id);
        $this->assertDatabaseHas('crm_attribution_touchpoints', ['event_key' => 'paid-second', 'lead_id' => $a->id]);
        $this->assertDatabaseCount('crm_lead_merge_records', 1);
        $this->get(route('crm.profile', $b->id))->assertRedirect(route('crm.profile', $a->id));
        $this->getJson(route('crm.pipeline'))->assertOk()->assertJsonPath('summary.total', 1);
    }

    public function test_duplicate_comparison_and_attribution_respect_lead_scope_and_sanitize_context(): void
    {
        $user = $this->agent();
        $a = app(LeadUpsertService::class)->upsert('Siti', '081234567890');
        $b = app(LeadUpsertService::class)->upsert('Siti', '081234567891');
        $pair = DB::table('crm_duplicate_candidates')->first();
        app(AttributionService::class)->record($a->id, 'unsafe-context', 'FORM_SUBMITTED', 'Meta Ads', ['nik' => 'private-nik', 'access_token' => 'secret-token', 'landing_url' => 'https://example.test/apply?passport=private']);
        $context = DB::table('crm_attribution_touchpoints')->where('event_key', 'unsafe-context')->value('context');
        $this->assertStringNotContainsString('private', $context);
        $this->assertStringNotContainsString('secret-token', $context);
        $this->actingAs($user)->get(route('crm.duplicate-compare', $pair->id))->assertNotFound();
        $this->get(route('crm.attribution', $a->id))->assertNotFound();
        $this->get(route('crm.duplicates'))->assertOk()->assertDontSee('75%');
    }

    public function test_export_preview_uses_filters_scope_and_excludes_sensitive_columns(): void
    {
        $admin = $this->agent('super_admin');
        $a = app(LeadUpsertService::class)->upsert('Siti', '081234567890', null, 'Meta Ads');
        app(LeadUpsertService::class)->upsert('Gina', '081234567891', null, 'WhatsApp');
        $this->actingAs($admin)->postJson(route('crm.export-preview'), ['selection' => 'filtered', 'filters' => ['source' => 'Meta Ads']])->assertOk()->assertJsonPath('count', 1)->assertDontSee('NIK')->assertDontSee('Paspor');
        $this->postJson(route('crm.export-preview'), ['selection' => 'ids', 'ids' => [$a->id], 'filters' => []])->assertJsonPath('count', 1);
        $this->actingAs($this->agent())->postJson(route('crm.export-preview'), ['selection' => 'filtered'])->assertForbidden();
    }

    public function test_stale_duplicate_review_cannot_merge_and_high_confidence_requires_review_before_conversion(): void
    {
        $admin = $this->agent('super_admin');
        $a = app(LeadUpsertService::class)->upsert('Siti', '081234567890', 'siti@example.test');
        app(LeadUpsertService::class)->upsert('Siti', '081234567891', 'siti@example.test');
        DB::table('acquisition_leads')->where('id', $a->id)->update(['crm_stage' => 'QUALIFIED']);
        $pair = DB::table('crm_duplicate_candidates')->first();
        $this->actingAs($admin)->post(route('crm.convert', $a->id), ['vacancy_id' => Vacancy::factory()->create()->id])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('candidates', 0);
        $view = $this->get(route('crm.duplicate-compare', $pair->id))->assertOk();
        DB::table('acquisition_leads')->where('id', $a->id)->update(['crm_version' => 1]);
        $this->post(route('crm.duplicate-resolve', $pair->id), ['action' => 'merge', 'primary_id' => $a->id, 'reason' => 'Verifikasi', 'review_token' => $view->viewData('token')])->assertConflict();
        $this->assertDatabaseCount('crm_lead_merge_records', 0);
    }

    public function test_pipeline_configuration_orders_stages_and_conversion_cannot_be_dragged(): void
    {
        $admin = $this->agent('super_admin');
        $this->actingAs($admin)->post(route('crm.create-stage'), ['name' => 'Verifikasi awal', 'color_token' => 'purple', 'sla_hours' => 24])->assertRedirect();
        $custom = DB::table('crm_stages')->where('name', 'Verifikasi awal')->first();
        $this->assertSame(24, $custom->sla_hours);
        $ids = DB::table('crm_stages')->orderByDesc('display_order')->pluck('id')->all();
        $this->postJson(route('crm.reorder-stages'), ['ids' => $ids])->assertOk();
        $this->assertSame($ids, DB::table('crm_stages')->orderBy('display_order')->pluck('id')->all());
        $lead = app(LeadUpsertService::class)->upsert('Siti', '081234567890');
        $this->postJson(route('crm.move', $lead->id), ['stage' => 'CONVERTED', 'version' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('candidates', 0);
        $this->putJson(route('crm.card-layout'), ['scope' => 'USER', 'version' => 0, 'fields' => ['interest', 'message', 'owner', 'avatar', 'source', 'tags', 'followup']])->assertOk()->assertJsonPath('fields.0', 'interest');
        $this->get(route('crm.pipeline'))->assertOk()->assertSee('Pratinjau kartu')->assertSee('Cari duplikat');
    }

    public function test_campaign_drilldown_uses_ledger_without_double_counting_repeat_touchpoints(): void
    {
        $admin = $this->agent('super_admin');
        $lead = app(LeadUpsertService::class)->upsert('Siti', '081234567890');
        app(AttributionService::class)->record($lead->id, 'campaign-1', 'FORM_SUBMITTED', 'Meta Ads', ['utm_campaign' => 'Jepang']);
        app(AttributionService::class)->record($lead->id, 'campaign-2', 'WHATSAPP_INBOUND', 'WhatsApp', ['utm_campaign' => 'Jepang']);
        $response = $this->actingAs($admin)->get(route('crm.campaign-attribution', ['campaign' => 'Jepang']))->assertOk();
        $this->assertSame(1, $response->viewData('counts')['Lead']);
        $this->assertSame(0, $response->viewData('counts')['Application']);
        $this->getJson(route('crm.pipeline', ['campaign' => 'Jepang']))->assertOk()->assertJsonPath('summary.total', 1);
        $this->get(route('crm.attribution', $lead->id))->assertOk()->assertSee('Meta Ads');
    }

    public function test_backfill_keeps_ambiguous_legacy_people_separate_with_review_suggestions(): void
    {
        $migration = require database_path('migrations/2026_10_20_162855_add_unified_crm_identity.php');
        $migration->down();
        foreach (['081234567890', '+6281234567890'] as $phone) {
            DB::table('acquisition_leads')->insert(['public_token' => (string) Str::uuid(), 'name' => 'Legacy Siti', 'normalized_phone' => $phone, 'crm_source' => 'Meta Ads', 'created_at' => now(), 'updated_at' => now()]);
        }
        $migration->up();
        $this->assertDatabaseCount('acquisition_leads', 2);
        $this->assertDatabaseCount('crm_contacts', 2);
        $this->assertDatabaseHas('crm_duplicate_candidates', ['confidence' => 100, 'status' => 'PENDING']);
        $this->assertDatabaseCount('crm_contact_identities', 1);
        $this->assertDatabaseCount('crm_attribution_touchpoints', 2);
    }

    public function test_multi_vacancy_forms_share_identity_but_keep_separate_interests_without_candidates(): void
    {
        $funnel = $this->funnel();
        $this->form($funnel);
        $vacancy = Vacancy::factory()->create(['status' => 'published']);
        $this->post(route('funnels.store'), ['name' => 'Taiwan', 'slug' => 'identity-tw', 'vacancy_id' => $vacancy->id])->assertSessionHasNoErrors();
        $other = DB::table('application_funnels')->where('slug', 'identity-tw')->first();
        $this->post(route('funnels.publish', $other->id))->assertSessionHasNoErrors();
        $this->form(DB::table('application_funnels')->find($other->id));
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('crm_contacts', 1);
        $this->assertDatabaseCount('crm_lead_interests', 2);
        $this->assertDatabaseCount('lead_submissions', 2);
        $this->assertDatabaseCount('applications', 0);
        $this->assertDatabaseCount('candidates', 0);
    }

    public function test_import_reuses_identity_and_does_not_create_candidate_or_reassign_existing_owner(): void
    {
        $admin = $this->agent('super_admin');
        $owner = $this->agent();
        $lead = app(LeadUpsertService::class)->upsert('Siti', '081234567890', null, 'Meta Ads');
        DB::table('acquisition_leads')->where('id', $lead->id)->update(['assigned_cs_id' => $owner->id]);
        $file = UploadedFile::fake()->createWithContent('leads.csv', "name,whatsapp,email,source\nSiti,081234567890,siti@example.test,Import\n");
        $this->actingAs($admin)->post(route('crm.import'), ['file' => $file])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('candidates', 0);
        $this->assertDatabaseHas('acquisition_leads', ['id' => $lead->id, 'assigned_cs_id' => $owner->id, 'crm_source' => 'Meta Ads']);
        $this->assertDatabaseHas('crm_attribution_touchpoints', ['lead_id' => $lead->id, 'event_type' => 'LEAD_IMPORTED']);
    }

    public function test_merging_two_leads_keeps_suggestions_for_a_third_person_pending(): void
    {
        $admin = $this->agent('super_admin');
        $a = app(LeadUpsertService::class)->upsert('Siti Rahma', '081234567890');
        $b = app(LeadUpsertService::class)->upsert('Siti Rahma', '081234567891');
        $c = app(LeadUpsertService::class)->upsert('Siti Rahma', '081234567892');
        app(DuplicateResolutionService::class)->merge($a->id, $b->id, $admin->id, 'Dua kontak sudah diverifikasi');
        $this->assertDatabaseHas('crm_duplicate_candidates', ['primary_lead_id' => $a->id, 'secondary_lead_id' => $c->id, 'status' => 'PENDING']);
        $this->assertDatabaseHas('crm_contacts', ['id' => $b->contact_id, 'status' => 'MERGED']);
        $this->assertSame($a->id, app(IdentityResolutionService::class)->resolve('081234567891')->id);
    }

    public function test_merge_preserves_raw_submissions_messages_tags_tasks_and_interests(): void
    {
        $funnel = $this->funnel();
        $admin = auth()->user();
        $a = app(LeadUpsertService::class)->upsert('Siti Rahma', '081234567890');
        $this->form($funnel, '081234567891');
        $b = DB::table('acquisition_leads')->where('normalized_phone', '+6281234567891')->first();
        $this->inbound('second-phone', '6281234567891');
        $submission = DB::table('lead_submissions')->first();
        $tag = DB::table('crm_tags')->insertGetId(['name' => 'Jepang test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('crm_lead_tags')->insert(['lead_id' => $b->id, 'tag_id' => $tag, 'added_by' => $admin->id, 'created_at' => now()]);
        DB::table('crm_follow_ups')->insert(['lead_id' => $b->id, 'assigned_user_id' => $admin->id, 'due_at' => now()->addDay(), 'type' => 'CALL', 'created_at' => now(), 'updated_at' => now()]);
        app(DuplicateResolutionService::class)->merge($a->id, $b->id, $admin->id, 'Nomor kedua diverifikasi');
        $this->assertDatabaseCount('lead_submissions', 1);
        $this->assertDatabaseHas('lead_submissions', ['id' => $submission->id, 'lead_id' => $a->id, 'payload' => $submission->payload]);
        $this->assertDatabaseCount('crm_messages', 1);
        $this->assertDatabaseHas('crm_conversations', ['lead_id' => $a->id]);
        $this->assertDatabaseHas('crm_lead_tags', ['lead_id' => $a->id, 'tag_id' => $tag]);
        $this->assertDatabaseHas('crm_follow_ups', ['lead_id' => $a->id]);
        $this->assertDatabaseHas('crm_lead_interests', ['lead_id' => $a->id, 'vacancy_id' => $submission->vacancy_id]);
        $this->assertNotNull(DB::table('acquisition_leads')->where('id', $a->id)->value('next_followup_at'));
    }

    public function test_inactive_form_owner_returns_to_unassigned_queue_when_no_route_is_available(): void
    {
        $owner = $this->agent();
        $lead = app(LeadUpsertService::class)->upsert('Siti', '081234567890');
        DB::table('acquisition_leads')->where('id', $lead->id)->update(['assigned_cs_id' => $owner->id]);
        $owner->update(['is_active' => false]);
        $this->assertNull(app(CsRouter::class)->assign($lead->id, Vacancy::factory()->create()->id));
        $this->assertDatabaseHas('acquisition_leads', ['id' => $lead->id, 'assigned_cs_id' => null]);
    }
}
