<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetaConnectTest extends TestCase
{
    use RefreshDatabase;

    private bool $webhookReady = true;

    private bool $permissionGranted = true;

    private string $phoneVerification = 'VERIFIED';

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceScheme('https');
        config(['conversation.app_id' => '111', 'conversation.app_secret' => 'app-secret', 'conversation.verify_token' => 'verify-secret', 'conversation.graph_version' => 'v25.0']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $data = match ($path) {
                '/v25.0/oauth/access_token' => ['access_token' => 'server-token'],
                '/v25.0/debug_token' => ['data' => ['is_valid' => true, 'app_id' => '111', 'expires_at' => now()->addDays(30)->timestamp]],
                '/v25.0/me/permissions' => ['data' => array_map(fn ($permission) => ['permission' => $permission, 'status' => $this->permissionGranted ? 'granted' : 'declined'], config('meta-connect.permissions'))],
                '/v25.0/me/businesses' => ['data' => [['id' => '200', 'name' => 'Juragan Migran']]],
                '/v25.0/200/owned_whatsapp_business_accounts' => ['data' => [['id' => '300', 'name' => 'JM WhatsApp']]],
                '/v25.0/300/phone_numbers' => ['data' => [['id' => '400', 'display_phone_number' => '+628123456789', 'verified_name' => 'JM Utama', 'quality_rating' => 'GREEN', 'code_verification_status' => $this->phoneVerification, 'platform_type' => 'CLOUD_API']]],
                '/v25.0/400' => ['id' => '400', 'verified_name' => 'JM Utama', 'quality_rating' => 'GREEN', 'code_verification_status' => $this->phoneVerification, 'platform_type' => 'CLOUD_API'],
                '/v25.0/300/subscribed_apps' => $request->method() === 'POST' ? ['success' => $this->webhookReady] : ['data' => [['whatsapp_business_api_data' => ['id' => '111']]]],
                '/v25.0/111/subscriptions' => ['data' => [['object' => 'whatsapp_business_account', 'active' => true, 'callback_url' => route('whatsapp.receive'), 'fields' => [['name' => 'messages']]]]],
                '/v25.0/300/message_templates' => ['data' => []],
                '/v25.0/400/messages' => ['messages' => [['id' => 'wamid.test']]],
                default => ['error' => ['code' => 100]],
            };

            return Http::response($data, isset($data['error']) ? 400 : 200);
        });
    }

    private function user(string $role = 'super_admin'): User
    {
        return User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', $role)->value('id'), 'team_id' => 1]);
    }

    private function authorized(): string
    {
        $this->actingAs($this->user());
        $start = $this->postJson(route('meta-connect.start'))->assertOk()->json();
        parse_str(parse_url($start['authorizationUrl'], PHP_URL_QUERY), $query);
        $this->get(route('meta-connect.callback', ['state' => $query['state'], 'code' => 'one-use-code']))->assertOk()->assertDontSee('server-token');

        return $start['sessionId'];
    }

    private function finalize(string $session): int
    {
        return $this->postJson(route('meta-connect.finalize', $session), ['businessId' => '200', 'wabaId' => '300', 'phoneNumberId' => '400', 'channelName' => 'JM CS Utama'])->assertOk()->assertJsonPath('channel.status', 'TEST_REQUIRED')->assertDontSee('server-token')->json('channel.id');
    }

    public function test_settings_use_guided_connect_and_keep_technical_fields_in_advanced_only(): void
    {
        $this->actingAs($this->user('admin_operations'));
        $page = $this->get(route('crm.settings'))->assertOk()->assertSee('Hubungkan dengan Meta')->assertSee('meta-connect.js')->assertDontSee('name="access_token"', false)->assertDontSee('data-cc-meta-connect', false);
        $this->postJson(route('crm.channel'), [])->assertForbidden();
        if ($directory = getenv('META_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($directory.'/settings.html', $page->getContent());
        }
        $this->actingAs($this->user('cs'))->getJson(route('meta-connect.preflight'))->assertForbidden();
    }

    public function test_preflight_and_callback_validate_configuration_state_user_expiry_and_replay(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        config(['conversation.app_secret' => null]);
        $this->postJson(route('meta-connect.start'))->assertUnprocessable()->assertJsonPath('code', 'META_APP_NOT_CONFIGURED');
        Http::assertNothingSent();
        config(['conversation.app_secret' => 'app-secret']);
        $start = $this->postJson(route('meta-connect.start'))->assertOk()->json();
        parse_str(parse_url($start['authorizationUrl'], PHP_URL_QUERY), $query);
        $this->actingAs($this->user())->get(route('meta-connect.callback', ['state' => $query['state'], 'code' => 'code']))->assertSee('AUTH_STATE_INVALID');
        Http::assertNothingSent();
        $this->actingAs($user)->get(route('meta-connect.callback', ['state' => $query['state'], 'code' => 'code']))->assertOk();
        $calls = Http::recorded()->count();
        $this->get(route('meta-connect.callback', ['state' => $query['state'], 'code' => 'code']))->assertSee('AUTH_STATE_INVALID');
        $this->assertCount($calls, Http::recorded());
        $this->assertNotSame('server-token', DB::table('meta_auth_sessions')->value('credential'));
        $this->assertSame('server-token', Crypt::decryptString(DB::table('meta_auth_sessions')->value('credential')));
        $this->travel(61)->minutes();
        $this->getJson(route('meta-connect.state', $start['sessionId']))->assertUnprocessable()->assertJsonPath('code', 'AUTH_SESSION_EXPIRED');
    }

    public function test_discovery_and_resume_are_scoped_and_do_not_expose_credentials(): void
    {
        $session = $this->authorized();
        $this->getJson(route('meta-connect.businesses', $session))->assertOk()->assertJsonPath('items.0.name', 'Juragan Migran');
        $this->getJson(route('meta-connect.wabas', ['session' => $session, 'businessId' => '999']))->assertUnprocessable()->assertJsonPath('code', 'ASSET_NOT_ALLOWED');
        $this->getJson(route('meta-connect.phones', ['session' => $session, 'businessId' => '200', 'wabaId' => '300']))->assertOk()->assertJsonPath('items.0.id', '400');
        $this->postJson(route('meta-connect.select', $session), ['businessId' => '200', 'wabaId' => '300', 'phoneNumberId' => '400'])->assertOk();
        $this->getJson(route('meta-connect.state', $session))->assertOk()->assertJsonPath('selection.phoneNumberId', '400')->assertDontSee('server-token')->assertDontSee('credential');
        $this->actingAs($this->user())->getJson(route('meta-connect.state', $session))->assertNotFound();
    }

    public function test_finalize_is_idempotent_and_reconnect_retains_channel_history(): void
    {
        $session = $this->authorized();
        $channel = $this->finalize($session);
        $this->finalize($session);
        $this->assertDatabaseCount('whatsapp_channels', 1);
        $this->assertDatabaseHas('meta_auth_sessions', ['id' => $session, 'credential' => null]);
        $user = auth()->user();
        $start = $this->postJson(route('meta-connect.start'), ['channelId' => $channel])->assertOk()->json();
        parse_str(parse_url($start['authorizationUrl'], PHP_URL_QUERY), $query);
        $this->get(route('meta-connect.callback', ['state' => $query['state'], 'code' => 'new-code']))->assertOk();
        $this->assertSame($channel, $this->finalize($start['sessionId']));
        $this->assertDatabaseCount('whatsapp_channels', 1);
        $this->assertDatabaseHas('whatsapp_channels', ['id' => $channel, 'connection_method' => 'META_CONNECT', 'connected_by' => $user->id]);
        $this->assertStringNotContainsString('server-token', DB::table('whatsapp_connection_audits')->get()->toJson());
    }

    public function test_failed_webhook_never_activates_channel_and_errors_are_sanitized(): void
    {
        $session = $this->authorized();
        $this->webhookReady = false;
        $this->postJson(route('meta-connect.finalize', $session), ['businessId' => '200', 'wabaId' => '300', 'phoneNumberId' => '400', 'channelName' => 'JM'])->assertUnprocessable()->assertJsonPath('code', 'WEBHOOK_SUBSCRIBE_FAILED')->assertDontSee('server-token');
        $this->assertDatabaseCount('whatsapp_channels', 0);
    }

    public function test_tests_require_window_or_approved_template_and_retries_do_not_send_twice(): void
    {
        $channel = $this->finalize($this->authorized());
        $request = ['requestId' => (string) Str::uuid(), 'recipient' => '081234567890', 'consent' => true];
        $this->postJson(route('meta-connect.test-message', $channel), $request)->assertUnprocessable()->assertJsonPath('code', 'TEST_TEMPLATE_REQUIRED');
        $template = DB::table('crm_whatsapp_templates')->insertGetId(['channel_id' => $channel, 'name' => 'hello_world', 'language' => 'en_US', 'category' => 'UTILITY', 'status' => 'APPROVED', 'components' => json_encode([['type' => 'BODY', 'text' => 'Hello']]), 'last_synced_at' => now()]);
        $request['templateId'] = $template;
        $this->postJson(route('meta-connect.test-message', $channel), $request)->assertOk()->assertJsonPath('test.status', 'SENT');
        $this->postJson(route('meta-connect.test-message', $channel), $request)->assertOk();
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/messages')));
        $this->assertDatabaseCount('acquisition_leads', 0);
        $this->assertDatabaseCount('candidates', 0);
        $this->getJson(route('meta-connect.tests', $channel))->assertOk()->assertDontSee('server-token');
    }

    public function test_missing_permission_and_unverified_phone_do_not_activate_a_connection(): void
    {
        $session = $this->authorized();
        $this->permissionGranted = false;
        $payload = ['businessId' => '200', 'wabaId' => '300', 'phoneNumberId' => '400', 'channelName' => 'JM'];
        $this->postJson(route('meta-connect.finalize', $session), $payload)->assertUnprocessable()->assertJsonPath('code', 'META_PERMISSION_REQUIRED');
        $this->assertDatabaseCount('whatsapp_channels', 0);
        $this->permissionGranted = true;
        $session = $this->authorized();
        $this->phoneVerification = 'NOT_VERIFIED';
        $this->postJson(route('meta-connect.finalize', $session), $payload)->assertUnprocessable()->assertJsonPath('code', 'PHONE_VERIFICATION_REQUIRED');
        $this->assertDatabaseCount('whatsapp_channels', 0);
        $this->postJson(route('meta-connect.select', $session), ['businessId' => '200', 'phoneNumberId' => '400'])->assertUnprocessable()->assertJsonValidationErrors('wabaId');
    }

    public function test_meta_routes_obey_the_crm_feature_flag(): void
    {
        $this->actingAs($this->user());
        config(['conversation.enabled' => false]);
        $this->getJson(route('meta-connect.preflight'))->assertNotFound();
        $this->postJson(route('meta-connect.start'))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_channel_requires_both_message_tests_before_routing_and_disconnect_retains_history(): void
    {
        $channel = $this->finalize($this->authorized());
        $routing = ['name' => 'JM CS', 'routing_method' => 'ROUND_ROBIN', 'prefer_existing_owner' => true, 'strict_owner' => true];
        $this->postJson(route('meta-connect.routing', $channel), $routing)->assertUnprocessable()->assertJsonPath('code', 'TEST_REQUIRED');
        DB::table('whatsapp_channels')->where('id', $channel)->update(['inbound_test_at' => now(), 'outbound_test_at' => now()]);
        $this->postJson(route('meta-connect.routing', $channel), $routing)->assertOk()->assertJsonPath('channel.status', 'CONNECTED');
        $this->postJson(route('meta-connect.disconnect', $channel), ['confirm' => true])->assertOk()->assertJsonPath('channel.status', 'DISCONNECTED')->assertJsonPath('channel.is_active', 0);
        $this->assertDatabaseCount('whatsapp_channels', 1);
        $this->assertSame('', Crypt::decryptString(DB::table('whatsapp_channels')->value('access_token')));
        $this->assertDatabaseHas('whatsapp_connection_audits', ['channel_id' => $channel, 'operation' => 'disconnected']);
    }
}
