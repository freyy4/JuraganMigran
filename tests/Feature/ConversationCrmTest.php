<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Services\ConversationCrm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationCrmTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'cs', int $team = 1): User
    {
        return User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', $role)->value('id'), 'team_id' => $team]);
    }

    private function channel(): int
    {
        return DB::table('whatsapp_channels')->insertGetId(['name' => 'JM Utama', 'waba_id' => '1234', 'phone_number_id' => '9876', 'display_phone_number' => '+628123456789', 'access_token' => Crypt::encryptString('private-token'), 'status' => 'CONNECTED', 'team_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(string $id = 'wamid.inbound', string $from = '628123456789'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '9876'], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Fajar Pratama']]], 'messages' => [['id' => $id, 'from' => $from, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Saya tertarik program Jepang']]]]]]]]];
    }

    private function incoming(): object
    {
        $this->channel();
        DB::transaction(fn () => app(ConversationCrm::class)->ingest($this->payload()));

        return DB::table('crm_conversations')->first();
    }

    public function test_signed_webhook_is_durable_idempotent_and_creates_only_a_lead(): void
    {
        config(['conversation.app_secret' => 'test-secret', 'conversation.verify_token' => 'verify']);
        $cs = $this->user();
        $this->channel();
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify&hub.challenge=123')->assertOk()->assertContent('123');
        $this->postJson('/api/webhooks/whatsapp', $this->payload())->assertForbidden();
        $body = json_encode($this->payload());
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'test-secret')], $body)->assertOk();
        }
        $this->assertDatabaseCount('crm_webhook_events', 1);
        $this->assertDatabaseCount('crm_messages', 0);
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('crm_messages', 1);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('candidates', 0);
        $this->assertDatabaseHas('crm_conversations', ['assigned_user_id' => $cs->id, 'unread_count' => 1]);
        DB::transaction(fn () => app(ConversationCrm::class)->ingest($this->payload()));
        $this->assertDatabaseCount('crm_messages', 1);
        $this->assertDatabaseHas('crm_conversations', ['unread_count' => 1]);
    }

    public function test_existing_funnel_lead_owner_and_attribution_are_preserved(): void
    {
        $cs = $this->user();
        $this->user();
        $this->channel();
        $lead = DB::table('acquisition_leads')->insertGetId(['public_token' => (string) Str::uuid(), 'name' => 'Existing Funnel', 'normalized_phone' => '+628123456789', 'assigned_cs_id' => $cs->id, 'crm_source' => 'Meta Ads', 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(ConversationCrm::class)->ingest($this->payload()));
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseHas('crm_conversations', ['lead_id' => $lead, 'assigned_user_id' => $cs->id]);
        $this->assertDatabaseHas('acquisition_leads', ['id' => $lead, 'crm_source' => 'Meta Ads', 'name' => 'Existing Funnel']);
    }

    public function test_inbox_profile_and_settings_render_without_exposing_credentials(): void
    {
        $cs = $this->user();
        $conversation = $this->incoming();
        $inbox = $this->actingAs($cs)->get(route('crm.inbox', ['conversation' => $conversation->id]))->assertOk()->assertSee('Fajar Pratama')->assertDontSee('private-token');
        if ($directory = getenv('CRM_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($directory.'/inbox.html', $inbox->getContent());
            file_put_contents($directory.'/pipeline.html', $this->get(route('crm.pipeline'))->getContent());
            file_put_contents($directory.'/profile.html', $this->get(route('crm.profile', $conversation->lead_id))->getContent());
        }
        $this->get(route('crm.profile', $conversation->lead_id))->assertOk()->assertSee('Timeline aktivitas');
        $this->get(route('crm.pipeline'))->assertOk()->assertSee('Pipeline Lead');
        $this->get(route('crm.followups'))->assertOk();
        $this->get(route('crm.settings'))->assertForbidden();
        $settings = $this->actingAs($this->user('super_admin'))->get(route('crm.settings'))->assertOk()->assertDontSee('private-token');
        if ($directory = getenv('CRM_QA_FIXTURES')) {
            file_put_contents($directory.'/settings.html', $settings->getContent());
        }
        $this->getJson(route('crm.api-inbox', ['conversation' => $conversation->id]))->assertOk()->assertDontSee('provider_payload');
    }

    public function test_other_cs_cannot_read_or_reply_and_stale_owner_version_is_rejected(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $conversation = $this->incoming();
        $this->actingAs($other)->get(route('crm.inbox', ['conversation' => $conversation->id]))->assertNotFound();
        $this->get(route('crm.profile', $conversation->lead_id))->assertNotFound();
        $this->postJson(route('crm.send', $conversation->id), ['mode' => 'text', 'text' => 'Hello', 'version' => 0, 'client_key' => (string) Str::uuid()])->assertNotFound();
        DB::table('crm_conversations')->where('id', $conversation->id)->update(['version' => 1]);
        $this->actingAs($owner)->postJson(route('crm.send', $conversation->id), ['mode' => 'text', 'text' => 'Hello', 'version' => 0, 'client_key' => (string) Str::uuid()])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_live_inbox_reports_owner_version_and_reply_access_after_transfer(): void
    {
        $owner = $this->user();
        $supervisor = $this->user('supervisor');
        $other = $this->user();
        $conversation = $this->incoming();
        $url = route('crm.api-inbox', ['conversation' => $conversation->id]);
        $this->actingAs($owner)->getJson($url)->assertOk()
            ->assertJsonPath('selected.can_reply', true)
            ->assertJsonPath('selected.owner_name', $owner->name);
        $this->actingAs($supervisor)->post(route('crm.assign', $conversation->id), [
            'user_id' => $other->id, 'version' => '0', 'reason' => 'Transfer shift',
        ])->assertRedirect();
        $this->getJson($url)->assertOk()
            ->assertJsonPath('selected.version', 1)
            ->assertJsonPath('selected.owner_name', $other->name)
            ->assertJsonPath('selected.can_reply', false);
        $this->actingAs($owner)->getJson($url)->assertNotFound();
        $this->postJson(route('crm.send', $conversation->id), [
            'mode' => 'text', 'text' => 'Draft lama', 'version' => '0', 'client_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertDatabaseMissing('crm_messages', ['text' => 'Draft lama']);
    }

    public function test_multipart_composer_accepts_string_version_and_rejects_stale_version_as_json(): void
    {
        $owner = $this->user();
        $conversation = $this->incoming();
        $data = ['mode' => 'note', 'text' => 'Catatan dari form', 'version' => '0', 'client_key' => (string) Str::uuid()];
        $this->actingAs($owner)->post(route('crm.send', $conversation->id), $data, ['Accept' => 'application/json'])
            ->assertAccepted()->assertJsonPath('status', 'SAVED');
        DB::table('crm_conversations')->where('id', $conversation->id)->update(['version' => 1]);
        $data['client_key'] = (string) Str::uuid();
        $this->post(route('crm.send', $conversation->id), $data, ['Accept' => 'application/json'])
            ->assertConflict()->assertJsonStructure(['message']);
        $this->assertDatabaseCount('crm_messages', 2);
    }

    public function test_internal_note_never_reaches_meta_and_freeform_requires_open_window(): void
    {
        $cs = $this->user();
        $conversation = $this->incoming();
        DB::table('crm_conversations')->where('id', $conversation->id)->update(['service_window_expires_at' => now()->subMinute()]);
        $this->actingAs($cs)->postJson(route('crm.send', $conversation->id), ['mode' => 'text', 'text' => 'Hi', 'version' => 0, 'client_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson(route('crm.send', $conversation->id), ['mode' => 'note', 'text' => 'Internal only', 'version' => 0, 'client_key' => (string) Str::uuid()])->assertAccepted();
        $this->artisan('crm:dispatch')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseHas('crm_messages', ['text' => 'Internal only', 'direction' => 'INTERNAL', 'status' => 'SAVED']);
    }

    public function test_outbound_is_queued_once_and_delivery_never_regresses(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.outbound']]])]);
        $cs = $this->user();
        $conversation = $this->incoming();
        $data = ['mode' => 'text', 'text' => 'Halo Fajar', 'version' => 0, 'client_key' => (string) Str::uuid()];
        $this->actingAs($cs)->postJson(route('crm.send', $conversation->id), $data)->assertAccepted();
        $this->postJson(route('crm.send', $conversation->id), $data)->assertAccepted();
        $this->assertDatabaseCount('crm_messages', 2);
        Http::assertNothingSent();
        $this->artisan('crm:dispatch')->assertSuccessful();
        Http::assertSentCount(1);
        $payload = ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '9876'], 'statuses' => [['id' => 'wamid.outbound', 'status' => 'read', 'timestamp' => now()->timestamp], ['id' => 'wamid.outbound', 'status' => 'sent', 'timestamp' => now()->subMinute()->timestamp]]]]]]]];
        DB::transaction(fn () => app(ConversationCrm::class)->ingest($payload));
        $this->assertDatabaseHas('crm_messages', ['external_message_id' => 'wamid.outbound', 'status' => 'READ']);
    }

    public function test_stage_validation_followup_and_conversion_preserve_canonical_identity(): void
    {
        $cs = $this->user();
        $conversation = $this->incoming();
        $this->actingAs($cs)->postJson(route('crm.move', $conversation->lead_id), ['stage' => 'QUALIFIED', 'version' => 0])->assertUnprocessable();
        foreach (['CONTACTED', 'DISCUSSION', 'QUALIFIED'] as $version => $stage) {
            $this->postJson(route('crm.move', $conversation->lead_id), compact('stage', 'version'))->assertOk();
        }
        $this->post(route('crm.followup', $conversation->lead_id), ['due_at' => now()->subDay()->format('Y-m-d\TH:i'), 'type' => 'CALL'])->assertRedirect();
        $this->get(route('crm.followups'))->assertOk()->assertSee('Fajar Pratama');
        $vacancy = Application::factory()->create(['assigned_cs_id' => $cs->id])->vacancy_id;
        $this->post(route('crm.convert', $conversation->lead_id), ['vacancy_id' => $vacancy])->assertRedirect();
        $personCount = DB::table('persons')->count();
        $applicationCount = DB::table('applications')->count();
        $this->post(route('crm.convert', $conversation->lead_id), ['vacancy_id' => $vacancy])->assertRedirect();
        $this->assertDatabaseCount('persons', $personCount);
        $this->assertDatabaseCount('applications', $applicationCount);
        $this->assertDatabaseHas('acquisition_leads', ['id' => $conversation->lead_id, 'crm_stage' => 'CONVERTED']);
        $this->get(route('crm.profile', $conversation->lead_id))->assertOk()->assertSee('Buka Candidate 360');
    }

    public function test_pipeline_paginates_high_volume_and_enforces_scope(): void
    {
        $cs = $this->user();
        $other = $this->user();
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['public_token' => (string) Str::uuid(), 'name' => 'Lead '.$i, 'normalized_phone' => '+628'.str_pad((string) $i, 10, '0', STR_PAD_LEFT), 'assigned_cs_id' => $i === 1000 ? $other->id : $cs->id, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('acquisition_leads')->insert($rows);
        $this->actingAs($cs)->getJson(route('crm.pipeline'))->assertOk()->assertJsonPath('rows.total', 1000)->assertJsonCount(50, 'rows.data')->assertDontSee('normalized_phone');
    }

    public function test_closed_window_accepts_only_channel_approved_template_and_worker_sends_it(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.template']]])]);
        $cs = $this->user();
        $conversation = $this->incoming();
        DB::table('crm_conversations')->where('id', $conversation->id)->update(['service_window_expires_at' => now()->subHour()]);
        $template = DB::table('crm_whatsapp_templates')->insertGetId(['channel_id' => $conversation->channel_id, 'name' => 'follow_up', 'language' => 'id', 'category' => 'UTILITY', 'status' => 'PENDING', 'components' => json_encode([['type' => 'BODY', 'text' => 'Halo {{1}}']]), 'last_synced_at' => now()]);
        $data = ['mode' => 'template', 'template_id' => $template, 'variables' => ['Fajar'], 'version' => 0, 'client_key' => (string) Str::uuid()];
        $this->actingAs($cs)->postJson(route('crm.send', $conversation->id), $data)->assertUnprocessable();
        DB::table('crm_whatsapp_templates')->where('id', $template)->update(['status' => 'APPROVED']);
        $this->postJson(route('crm.send', $conversation->id), $data)->assertAccepted();
        $this->artisan('crm:dispatch')->assertSuccessful();
        Http::assertSent(fn ($r) => $r['type'] === 'template' && $r['template']['name'] === 'follow_up' && $r['template']['components'][0]['parameters'][0]['text'] === 'Fajar');
    }

    public function test_owner_change_between_enqueue_and_send_blocks_the_provider_call(): void
    {
        Http::fake();
        $owner = $this->user();
        $other = $this->user();
        $conversation = $this->incoming();
        $this->actingAs($owner)->postJson(route('crm.send', $conversation->id), ['mode' => 'text', 'text' => 'Halo', 'version' => 0, 'client_key' => (string) Str::uuid()])->assertAccepted();
        $supervisor = $this->user('supervisor');
        $this->actingAs($supervisor)->post(route('crm.assign', $conversation->id), ['user_id' => $other->id, 'reason' => 'Pergantian shift', 'version' => 0])->assertRedirect();
        $this->artisan('crm:dispatch')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseHas('crm_messages', ['direction' => 'OUTBOUND', 'status' => 'FAILED', 'error_code' => 'POLICY_OR_OWNER_CHANGED']);
        $this->actingAs($owner)->get(route('crm.inbox', ['conversation' => $conversation->id]))->assertNotFound();
        $this->actingAs($other)->get(route('crm.profile', $conversation->lead_id))->assertOk();
    }

    public function test_supervisor_team_scope_and_lost_reason_are_enforced(): void
    {
        $cs = $this->user();
        $conversation = $this->incoming();
        $supervisor = $this->user('supervisor', 2);
        $this->actingAs($supervisor)->post(route('crm.assign', $conversation->id), ['user_id' => $supervisor->id, 'reason' => 'Transfer', 'version' => 0])->assertNotFound();
        $this->actingAs($cs)->postJson(route('crm.move', $conversation->lead_id), ['stage' => 'LOST', 'version' => 0])->assertUnprocessable();
        $this->postJson(route('crm.move', $conversation->lead_id), ['stage' => 'LOST', 'version' => 0, 'reason' => 'Waktu belum cocok'])->assertOk();
        $this->postJson(route('crm.move', $conversation->lead_id), ['stage' => 'NEW', 'version' => 1])->assertOk();
        $this->assertDatabaseHas('crm_activities', ['lead_id' => $conversation->lead_id, 'event_type' => 'stage_changed']);
    }

    public function test_round_robin_uses_active_eligible_cs_and_skips_inactive_owner(): void
    {
        $one = $this->user();
        $two = $this->user();
        $inactive = $this->user();
        $inactive->update(['is_active' => false]);
        $this->channel();
        DB::table('acquisition_leads')->insert(['public_token' => (string) Str::uuid(), 'name' => 'Old owner', 'normalized_phone' => '+628123456789', 'assigned_cs_id' => $inactive->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(fn () => app(ConversationCrm::class)->ingest($this->payload()));
        DB::transaction(fn () => app(ConversationCrm::class)->ingest($this->payload('wamid.second', '628223456789')));
        $this->assertDatabaseHas('crm_conversations', ['wa_contact_id' => '628123456789', 'assigned_user_id' => $one->id]);
        $this->assertDatabaseHas('crm_conversations', ['wa_contact_id' => '628223456789', 'assigned_user_id' => $two->id]);
    }

    public function test_manual_lead_duplicate_and_tag_privacy_validation(): void
    {
        $cs = $this->user();
        $this->actingAs($cs)->post(route('crm.create-lead'), ['name' => 'Manual lead', 'whatsapp' => '08123456789', 'source' => 'Referral'])->assertRedirect();
        $this->postJson(route('crm.create-lead'), ['name' => 'Duplicate', 'whatsapp' => '+628123456789', 'source' => 'Event'])->assertUnprocessable();
        $lead = DB::table('acquisition_leads')->first();
        $admin = $this->user('admin_operations');
        $this->actingAs($admin)->postJson(route('crm.save-tag'), ['name' => 'Diagnosis HIV', 'category' => 'ENGAGEMENT', 'color_token' => 'blue'])->assertUnprocessable();
        $this->post(route('crm.save-tag'), ['name' => 'Jepang', 'category' => 'COUNTRY', 'color_token' => 'green'])->assertRedirect();
        $tag = DB::table('crm_tags')->value('id');
        $this->actingAs($cs)->post(route('crm.tag', $lead->id), ['tag_id' => $tag])->assertRedirect();
        $this->assertDatabaseHas('crm_lead_tags', ['lead_id' => $lead->id, 'tag_id' => $tag]);
        $this->post(route('crm.tag', $lead->id), ['tag_id' => $tag, 'remove' => 1])->assertRedirect();
        $this->assertDatabaseCount('crm_lead_tags', 0);
    }

    public function test_embedded_signup_state_is_single_use_and_token_never_returns_to_browser(): void
    {
        config(['conversation.app_id' => '123', 'conversation.signup_config_id' => '456', 'conversation.app_secret' => 'app-secret']);
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'signup-private-token']),
            'graph.facebook.com/*/1234/phone_numbers*' => Http::response(['data' => [['id' => '9876', 'display_phone_number' => '+628123456789', 'verified_name' => 'JM Signup']]]),
            'graph.facebook.com/*/1234/subscribed_apps' => Http::response(['success' => true]),
        ]);
        $admin = $this->user('super_admin');
        $state = $this->actingAs($admin)->postJson(route('crm.connect-start'))->assertOk()->json('state');
        $data = ['state' => $state, 'code' => 'temporary-code', 'waba_id' => '1234', 'phone_number_id' => '9876'];
        $this->postJson(route('crm.connect-callback'), $data)->assertOk()->assertDontSee('signup-private-token');
        $stored = DB::table('whatsapp_channels')->first();
        $this->assertNotSame('signup-private-token', $stored->access_token);
        $this->assertSame('signup-private-token', Crypt::decryptString($stored->access_token));
        $this->postJson(route('crm.connect-callback'), $data)->assertStatus(419);
    }

    public function test_feature_flag_keeps_old_workflow_available(): void
    {
        $cs = $this->user();
        config(['conversation.enabled' => false]);
        $this->actingAs($cs)->get(route('crm.inbox'))->assertNotFound();
        $this->get(route('crm.pipeline'))->assertNotFound();
        $this->get(route('acquisition.leads'))->assertOk();
    }

    public function test_manual_queue_claim_is_exclusive_and_attaches_lead_owner(): void
    {
        $first = $this->user();
        $second = $this->user();
        $channel = $this->channel();
        DB::table('whatsapp_channels')->where('id', $channel)->update(['routing_method' => 'MANUAL']);
        DB::transaction(fn () => app(ConversationCrm::class)->ingest($this->payload()));
        $conversation = DB::table('crm_conversations')->first();
        $this->assertNull($conversation->assigned_user_id);
        $this->actingAs($first)->get(route('crm.inbox', ['conversation' => $conversation->id]))->assertOk();
        $this->post(route('crm.claim', $conversation->id), ['version' => 0])->assertRedirect();
        $this->actingAs($second)->postJson(route('crm.claim', $conversation->id), ['version' => 0])->assertNotFound();
        $this->assertDatabaseHas('acquisition_leads', ['id' => $conversation->lead_id, 'assigned_cs_id' => $first->id]);
    }

    public function test_inbound_attachment_scope_and_provider_url_validation(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['mime_type' => 'application/pdf', 'file_size' => 100, 'url' => 'http://127.0.0.1/private'])]);
        $cs = $this->user();
        $other = $this->user();
        $conversation = $this->incoming();
        $payload = $this->payload('wamid.document');
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'document';
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['document'] = ['id' => '123456', 'mime_type' => 'application/pdf', 'filename' => 'passport.pdf'];
        DB::transaction(fn () => app(ConversationCrm::class)->ingest($payload));
        $message = DB::table('crm_messages')->where('external_message_id', 'wamid.document')->value('id');
        $this->actingAs($other)->get(route('crm.attachment', [$conversation->id, $message]))->assertNotFound();
        $this->actingAs($cs)->get(route('crm.attachment', [$conversation->id, $message]))->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_failed_webhook_can_be_retried_without_duplicating_messages_and_payload_expires(): void
    {
        $this->user();
        $this->channel();
        $id = DB::table('crm_webhook_events')->insertGetId(['event_key' => hash('sha256', 'dead'), 'payload' => Crypt::encryptString(json_encode($this->payload())), 'status' => 'DEAD', 'attempts' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->user('super_admin');
        $this->actingAs($admin)->post(route('crm.retry-event', $id))->assertRedirect();
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->assertDatabaseHas('crm_webhook_events', ['id' => $id, 'status' => 'PROCESSED']);
        $this->assertDatabaseCount('crm_messages', 1);
        $this->travel(8)->days();
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->assertDatabaseHas('crm_webhook_events', ['id' => $id, 'payload' => null]);
    }

    public function test_outbound_failure_has_controlled_retry_and_unknown_delivery_cannot_be_retried(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['error' => ['code' => 131000]], 400)->push(['messages' => [['id' => 'wamid.retry']]])]);
        $cs = $this->user();
        $conversation = $this->incoming();
        $this->actingAs($cs)->postJson(route('crm.send', $conversation->id), ['mode' => 'text', 'text' => 'Halo', 'version' => 0, 'client_key' => (string) Str::uuid()])->assertAccepted();
        $this->artisan('crm:dispatch')->assertSuccessful();
        $message = DB::table('crm_messages')->where('direction', 'OUTBOUND')->first();
        $this->assertSame('FAILED', $message->status);
        $this->post(route('crm.retry-message', [$conversation->id, $message->id]))->assertRedirect();
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->assertDatabaseHas('crm_messages', ['id' => $message->id, 'status' => 'SENT']);
        DB::table('crm_messages')->where('id', $message->id)->update(['status' => 'UNKNOWN']);
        $this->postJson(route('crm.retry-message', [$conversation->id, $message->id]))->assertUnprocessable();
    }

    public function test_outbound_pdf_is_private_validated_and_uploaded_by_worker(): void
    {
        config(['documents.scan_enabled' => false]);
        Storage::fake('local');
        Http::fake(['graph.facebook.com/*/media' => Http::response(['id' => '555']), 'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.pdf']]])]);
        $cs = $this->user();
        $conversation = $this->incoming();
        $file = UploadedFile::fake()->createWithContent('info.pdf', "%PDF-1.4\nDocument information\n%%EOF");
        $this->actingAs($cs)->post(route('crm.send', $conversation->id), ['mode' => 'text', 'text' => 'Informasi program', 'attachment' => $file, 'version' => 0, 'client_key' => (string) Str::uuid()])->assertRedirect();
        Http::assertNothingSent();
        $this->assertCount(1, Storage::disk('local')->allFiles('crm-outbox'));
        $this->artisan('crm:dispatch')->assertSuccessful();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/messages') && $request['type'] === 'document' && $request['document']['id'] === '555' && ! isset($request['_local_media']));
        $this->assertCount(0, Storage::disk('local')->allFiles('crm-outbox'));
        $this->assertDatabaseHas('crm_messages', ['external_message_id' => 'wamid.pdf', 'status' => 'SENT']);
    }

    public function test_legacy_owner_keeps_integration_access_after_crm_upgrade_without_granting_cs_admin_access(): void
    {
        $role = DB::table('crm_roles')->where('code', 'migration_owner')->first();
        $permissions = array_values(array_diff(json_decode($role->permissions, true), config('conversation.permissions')));
        DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        $owner = $this->user('migration_owner');
        $cs = $this->user();
        $before = DB::table('crm_roles')->where('code', 'cs')->value('permissions');
        $this->actingAs($owner)->get(route('crm.settings'))->assertForbidden();
        $migration = require database_path('migrations/2026_10_17_105237_grant_conversation_permissions_to_legacy_owner.php');
        $migration->up();
        $migration->up();
        $this->get(route('crm.settings'))->assertOk()->assertSee('Channel WhatsApp');
        $this->assertTrue($owner->hasPermission('crm.whatsapp.manage'));
        $this->assertSame($before, DB::table('crm_roles')->where('code', 'cs')->value('permissions'));
        $this->actingAs($cs)->get(route('crm.settings'))->assertForbidden();
    }
}
