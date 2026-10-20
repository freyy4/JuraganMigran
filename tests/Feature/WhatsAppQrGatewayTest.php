<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppQrGatewayTest extends TestCase
{
    use RefreshDatabase;

    private string $remoteState = 'SCAN_QR_CODE';

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp-qr.enabled' => true, 'whatsapp-qr.url' => 'http://127.0.0.1:3001', 'whatsapp-qr.api_key' => 'gateway-secret', 'whatsapp-qr.webhook_secret' => 'hook-secret', 'whatsapp-qr.encrypted_storage' => true]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/auth/qr')) {
                return Http::response(['mimetype' => 'image/png', 'data' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=']);
            }
            if (str_ends_with($request->url(), '/sendText')) {
                return Http::response(['id' => 'gateway-out-1']);
            }

            return Http::response(['name' => 'default', 'status' => $this->remoteState, 'me' => ['id' => '628123456789@c.us', 'pushName' => 'JM Utama']]);
        });
        $this->actingAs(User::factory()->create(['team_id' => 1]));
    }

    private function create(): int
    {
        return $this->postJson(route('whatsapp-qr.create'), ['name' => 'JM QR Utama'])->assertOk()->json('channel.id');
    }

    private function pair(): int
    {
        $channel = $this->create();
        $this->postJson(route('whatsapp-qr.start', $channel))->assertOk()->assertJsonPath('state', 'WAITING_FOR_SCAN');
        $this->remoteState = 'WORKING';
        $this->getJson(route('whatsapp-qr.status', $channel))->assertOk()->assertJsonPath('state', 'CONNECTED')->assertJsonPath('channel.active', false);
        $this->postJson(route('whatsapp-qr.routing', $channel), ['routing_method' => 'ROUND_ROBIN', 'prefer_existing_owner' => true, 'strict_owner' => true])->assertOk()->assertJsonPath('channel.active', true);

        return $channel;
    }

    private function webhook(int $channel, string $id = 'msg-1', string $body = 'Halo JM'): void
    {
        $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->first();
        $payload = ['id' => 'event-'.$id, 'session' => $session->gateway_session, 'event' => 'message', 'payload' => ['id' => $id, 'from' => '628111222333@c.us', 'fromMe' => false, 'timestamp' => now()->timestamp, 'body' => $body]];
        $raw = json_encode($payload);
        $secret = Crypt::decryptString($session->webhook_credential);
        $this->call('POST', route('whatsapp-qr.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512', 'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $raw, $secret)], $raw)->assertOk();
    }

    public function test_both_connection_choices_render_and_gateway_is_not_faked_when_unconfigured(): void
    {
        $page = $this->get(route('crm.settings'))->assertOk()->assertSee('Scan QR WhatsApp Business')->assertSee('Hubungkan dengan Meta')->assertSee('Compatibility Mode')->assertDontSee('gateway-secret');
        if ($directory = getenv('QR_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            } file_put_contents($directory.'/settings.html', $page->getContent());
        }
        config(['whatsapp-qr.enabled' => false]);
        $this->postJson(route('whatsapp-qr.create'), ['name' => 'QR'])->assertUnprocessable()->assertJsonPath('code', 'QR_NOT_CONFIGURED');
        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_channels', 0);
    }

    public function test_qr_is_private_expiring_uncached_and_invalidated_when_pairing_succeeds(): void
    {
        $channel = $this->create();
        $data = $this->postJson(route('whatsapp-qr.start', $channel))->assertOk()->assertDontSee('gateway-secret')->json();
        $this->get($data['qrImageUrl'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Pragma', 'no-cache');
        $owner = auth()->user();
        $this->actingAs(User::factory()->create())->get($data['qrImageUrl'])->assertForbidden();
        $this->actingAs($owner);
        $this->travel(21)->seconds();
        $this->get($data['qrImageUrl'])->assertGone();
        $this->getJson(route('whatsapp-qr.status', $channel))->assertOk()->assertJsonPath('state', 'QR_EXPIRED');
        $new = $this->postJson(route('whatsapp-qr.rescan', $channel))->assertOk()->json();
        $this->assertNotSame($data['pairingSessionId'], $new['pairingSessionId']);
        $this->remoteState = 'WORKING';
        $this->getJson(route('whatsapp-qr.status', $channel))->assertOk()->assertJsonPath('qrImageUrl', null);
        $this->get($new['qrImageUrl'])->assertNotFound();
    }

    public function test_cs_cannot_scan_manage_or_inspect_sessions_but_existing_inbox_permissions_remain(): void
    {
        $channel = $this->create();
        $this->actingAs(User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]));
        $this->postJson(route('whatsapp-qr.start', $channel))->assertForbidden();
        $this->getJson(route('whatsapp-qr.index'))->assertForbidden();
        $this->getJson(route('whatsapp-qr.diagnostics', $channel))->assertForbidden();
        $this->get(route('crm.inbox'))->assertOk();
    }

    public function test_inbound_signature_deduplication_lead_identity_and_normalized_messages(): void
    {
        $channel = $this->pair();
        $this->postJson(route('whatsapp-qr.webhook'), ['session' => 'default', 'event' => 'message'])->assertForbidden();
        $this->webhook($channel);
        $this->webhook($channel);
        $this->assertDatabaseCount('crm_webhook_events', 1);
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('crm_messages', 1);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('candidates', 0);
        $this->assertDatabaseHas('crm_messages', ['provider' => 'QR_WEB_SESSION', 'provider_message_id' => 'msg-1', 'text' => 'Halo JM']);
        $this->webhook($channel, 'msg-2', 'Lanjut konsultasi');
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('crm_conversations', 1);
    }

    public function test_outbound_uses_qr_provider_and_internal_notes_never_call_gateway(): void
    {
        $channel = $this->pair();
        $this->webhook($channel);
        $this->artisan('crm:dispatch')->assertSuccessful();
        $conversation = DB::table('crm_conversations')->first();
        DB::table('crm_conversations')->where('id', $conversation->id)->update(['assigned_user_id' => auth()->id(), 'service_window_expires_at' => now()->subDays(2)]);
        $payload = ['mode' => 'note', 'text' => 'Catatan internal', 'client_key' => (string) Str::uuid(), 'version' => $conversation->version];
        $this->postJson(route('crm.send', $conversation->id), $payload)->assertStatus(202);
        $this->assertCount(0, Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendText')));
        $payload = ['mode' => 'text', 'text' => 'Balasan dari CS', 'client_key' => (string) Str::uuid(), 'version' => $conversation->version];
        $this->postJson(route('crm.send', $conversation->id), $payload)->assertStatus(202);
        $this->postJson(route('crm.send', $conversation->id), $payload)->assertStatus(202);
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendText')));
        $this->assertDatabaseHas('crm_messages', ['direction' => 'OUTBOUND', 'status' => 'SENT', 'provider_message_id' => 'gateway-out-1']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_disconnect_preserves_history_and_deletes_remote_session(): void
    {
        $channel = $this->pair();
        $this->webhook($channel);
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->postJson(route('whatsapp-qr.disconnect', $channel), ['confirm' => true])->assertOk()->assertJsonPath('state', 'DISCONNECTED_BY_ADMIN');
        $this->assertDatabaseCount('crm_messages', 1);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseHas('whatsapp_qr_sessions', ['channel_id' => $channel, 'webhook_credential' => null, 'pairing_id' => null]);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/sessions/default'));
    }

    public function test_disconnected_number_can_be_removed_without_losing_history_or_blocking_new_connections(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $channel = $this->pair();
        $this->webhook($channel);
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->postJson(route('whatsapp-qr.remove', $channel), ['confirm' => true])->assertUnprocessable();
        $this->postJson(route('whatsapp-qr.disconnect', $channel), ['confirm' => true])->assertOk();
        DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->update(['cleanup_pending' => true]);
        $this->postJson(route('whatsapp-qr.remove', $channel), ['confirm' => true])->assertUnprocessable();
        DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->update(['cleanup_pending' => false]);
        $this->postJson(route('whatsapp-qr.remove', $channel), ['confirm' => true])->assertOk()->assertJsonPath('removed', true);
        $this->getJson(route('whatsapp-qr.index'))->assertOk()->assertJsonCount(0, 'channels');
        $this->postJson(route('whatsapp-qr.start', $channel))->assertNotFound();
        $this->assertDatabaseCount('crm_messages', 1);
        $this->assertDatabaseCount('crm_conversations', 1);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertNotNull(DB::table('whatsapp_channels')->find($channel)->removed_at);
        $newChannel = $this->create();
        $this->assertNotSame($channel, $newChannel);
        $this->assertDatabaseHas('whatsapp_qr_sessions', ['channel_id' => $newChannel, 'gateway_session' => 'default']);
        $this->actingAs(User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]));
        $this->postJson(route('whatsapp-qr.remove', $newChannel), ['confirm' => true])->assertForbidden();
    }

    public function test_session_lease_blocks_concurrent_controls_and_health_is_sanitized(): void
    {
        $channel = $this->create();
        DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->update(['lease_owner' => 'another-worker', 'lease_expires_at' => now()->addMinute()]);
        $this->postJson(route('whatsapp-qr.start', $channel))->assertUnprocessable()->assertJsonPath('code', 'LEASE_BUSY');
        $this->getJson(route('whatsapp-qr.diagnostics', $channel))->assertOk()->assertDontSee('hook-secret')->assertDontSee('webhook_credential');
        $this->postJson(route('whatsapp-qr.create'), ['name' => 'Second'])->assertUnprocessable()->assertJsonPath('code', 'CHANNEL_CAPACITY');
    }

    public function test_migration_keeps_logical_channel_lead_and_conversation_ids(): void
    {
        $channel = $this->pair();
        $this->webhook($channel);
        $this->artisan('crm:dispatch')->assertSuccessful();
        $conversation = DB::table('crm_conversations')->first();
        $owner = $conversation->assigned_user_id;
        $this->postJson(route('whatsapp-qr.disconnect', $channel), ['confirm' => true])->assertOk();
        $target = DB::table('whatsapp_channels')->insertGetId(['name' => 'JM Official', 'waba_id' => '123', 'phone_number_id' => '456', 'display_phone_number' => '+628123456789', 'access_token' => Crypt::encryptString('official-token'), 'status' => 'CONNECTED', 'is_active' => true, 'webhook_ready' => true, 'inbound_test_at' => now(), 'outbound_test_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson(route('whatsapp-qr.migrate', $channel), ['target_channel_id' => $target, 'confirm' => true])->assertOk()->assertJsonPath('channelId', $channel)->assertJsonPath('provider', 'META_CLOUD_API');
        $this->assertDatabaseHas('crm_conversations', ['id' => $conversation->id, 'channel_id' => $channel, 'assigned_user_id' => $owner]);
        $this->assertDatabaseCount('acquisition_leads', 1);
        $this->assertDatabaseCount('crm_messages', 1);
        $this->assertDatabaseHas('whatsapp_channels', ['id' => $channel, 'provider' => 'META_CLOUD_API', 'phone_number_id' => '456']);
        $this->assertDatabaseHas('whatsapp_qr_sessions', ['channel_id' => $channel, 'state' => 'MIGRATED', 'webhook_credential' => null]);
    }

    public function test_stale_heartbeat_blocks_outbound_and_monitor_respects_kill_switch(): void
    {
        $channel = $this->pair();
        $this->webhook($channel);
        $this->artisan('crm:dispatch')->assertSuccessful();
        $conversation = DB::table('crm_conversations')->first();
        DB::table('crm_conversations')->where('id', $conversation->id)->update(['assigned_user_id' => auth()->id()]);
        DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->update(['last_heartbeat_at' => now()->subMinutes(3)]);
        $this->postJson(route('crm.send', $conversation->id), ['mode' => 'text', 'text' => 'Belum boleh terkirim', 'client_key' => (string) Str::uuid(), 'version' => $conversation->version])->assertUnprocessable();
        $this->artisan('crm:dispatch')->assertSuccessful();
        $this->assertSame(0, DB::table('crm_messages')->where('direction', 'OUTBOUND')->count());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendText'));
        config(['whatsapp-qr.enabled' => false]);
        $before = Http::recorded()->count();
        $this->artisan('whatsapp:qr-health')->assertSuccessful();
        $this->assertCount($before, Http::recorded());
    }

    public function test_meta_actions_cannot_disconnect_or_reconfigure_a_qr_session(): void
    {
        $channel = $this->pair();
        $this->postJson(route('meta-connect.disconnect', $channel), ['confirm' => true])->assertNotFound();
        $this->postJson(route('meta-connect.health', $channel))->assertNotFound();
        $this->assertDatabaseHas('whatsapp_channels', ['id' => $channel, 'is_active' => true, 'provider' => 'QR_WEB_SESSION']);
        $this->assertDatabaseHas('whatsapp_qr_sessions', ['channel_id' => $channel, 'state' => 'CONNECTED']);
    }
}
