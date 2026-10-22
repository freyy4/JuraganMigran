<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AssignmentPolicy;
use App\Services\MetaConnect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrmRoutingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'cs', int $team = 1): User
    {
        return User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', $role)->value('id'), 'team_id' => $team]);
    }

    private function channel(string $provider = 'META_CLOUD_API'): object
    {
        $id = DB::table('whatsapp_channels')->insertGetId([
            'name' => 'CRM routing', 'provider' => $provider, 'waba_id' => '123', 'phone_number_id' => (string) Str::uuid(),
            'display_phone_number' => '+628123456789', 'access_token' => Crypt::encryptString('protected-token'),
            'status' => 'CONNECTED', 'team_id' => 1, 'inbound_test_at' => now(), 'outbound_test_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($provider === 'QR_WEB_SESSION') {
            DB::table('whatsapp_qr_sessions')->insert(['channel_id' => $id, 'gateway_session' => 'default', 'state' => 'CONNECTED', 'activated' => true, 'last_heartbeat_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::table('whatsapp_channels')->find($id);
    }

    private function lead(?int $owner = null): object
    {
        $id = DB::table('acquisition_leads')->insertGetId(['public_token' => (string) Str::uuid(), 'name' => 'Calon', 'normalized_phone' => '+628'.random_int(1000000000, 9999999999), 'assigned_cs_id' => $owner, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('acquisition_leads')->find($id);
    }

    private function tag(string $name): int
    {
        return DB::table('crm_tags')->insertGetId(['name' => $name, 'category' => 'PROGRAM', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function profile(User $user, array $values = []): void
    {
        DB::table('crm_agent_profiles')->updateOrInsert(['user_id' => $user->id], $values + ['availability' => 'ONLINE', 'conversation_cap' => 30, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_skill_matching_and_reserved_fallback_use_existing_assignment_engine(): void
    {
        $specialist = $this->user();
        $fallback = $this->user();
        $japan = $this->tag('Jepang');
        $korea = $this->tag('Korea');
        $this->profile($specialist, ['routing_tag_ids' => json_encode([$japan])]);
        $this->profile($fallback, ['routing_tag_ids' => json_encode([$korea])]);
        $channel = $this->channel();
        $channel->fallback_user_id = $fallback->id;
        $lead = $this->lead();
        DB::table('crm_lead_tags')->insert(['lead_id' => $lead->id, 'tag_id' => $japan, 'added_by' => $specialist->id, 'created_at' => now()]);
        $this->assertSame($specialist->id, app(AssignmentPolicy::class)->conversationOwner($channel, $lead));
        DB::table('crm_lead_tags')->where('lead_id', $lead->id)->delete();
        $this->assertSame($fallback->id, app(AssignmentPolicy::class)->conversationOwner($channel, $lead));
        $channel->fallback_user_id = null;
        $this->assertNull(app(AssignmentPolicy::class)->conversationOwner($channel, $lead));
        $this->profile($specialist, ['routing_tag_ids' => '[]']);
        $this->assertSame($specialist->id, app(AssignmentPolicy::class)->conversationOwner($channel, $lead));
    }

    public function test_fallback_never_bypasses_availability_capacity_enabled_flag_or_team(): void
    {
        $fallback = $this->user();
        $channel = $this->channel();
        $channel->fallback_user_id = $fallback->id;
        $lead = $this->lead();
        foreach ([['availability' => 'OFFLINE'], ['routing_enabled' => false], ['conversation_cap' => 1]] as $values) {
            $this->profile($fallback, $values + ['routing_enabled' => true]);
            if (isset($values['conversation_cap'])) {
                DB::table('crm_conversations')->insert(['channel_id' => $channel->id, 'lead_id' => $lead->id, 'wa_contact_id' => '6281234', 'assigned_user_id' => $fallback->id, 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->assertNull(app(AssignmentPolicy::class)->conversationOwner($channel, $lead));
        }
        $this->profile($fallback);
        $fallback->update(['team_id' => 2]);
        $this->assertNull(app(AssignmentPolicy::class)->conversationOwner($channel, $lead));
    }

    public function test_manual_paused_and_out_of_hours_routing_keep_queue_and_existing_owner(): void
    {
        $owner = $this->user();
        $this->profile($owner);
        $channel = $this->channel();
        $channel->fallback_user_id = $owner->id;
        $lead = $this->lead();
        $existing = $this->lead($owner->id);
        foreach ([['routing_method', 'MANUAL'], ['routing_enabled', false], ['business_hours', json_encode(['days' => [], 'start' => '08:00', 'end' => '17:00'])]] as [$field, $value]) {
            $paused = clone $channel;
            $paused->{$field} = $value;
            $this->assertNull(app(AssignmentPolicy::class)->conversationOwner($paused, $lead));
            $this->assertSame($owner->id, app(AssignmentPolicy::class)->conversationOwner($paused, $existing));
        }
    }

    public function test_agent_skill_configuration_is_validated_audited_and_can_be_cleared(): void
    {
        $admin = $this->user('admin_operations');
        $agent = $this->user();
        $tag = $this->tag('Program Jepang');
        $data = ['user_id' => $agent->id, 'availability' => 'ONLINE', 'conversation_cap' => 8, 'routing_enabled' => false, 'routing_tag_ids' => [$tag]];
        $this->actingAs($admin)->postJson(route('crm.save-agent'), $data)->assertRedirect();
        $this->assertDatabaseHas('crm_agent_profiles', ['user_id' => $agent->id, 'routing_enabled' => false, 'conversation_cap' => 8]);
        $this->assertSame([$tag], json_decode(DB::table('crm_agent_profiles')->where('user_id', $agent->id)->value('routing_tag_ids'), true));
        $this->assertDatabaseHas('audit_logs', ['action' => 'crm.agent_configured']);
        $this->postJson(route('crm.save-agent'), array_replace($data, ['routing_tag_ids' => [999999]]))->assertUnprocessable();
        DB::table('crm_tags')->where('id', $tag)->update(['is_active' => false]);
        $this->postJson(route('crm.save-agent'), $data)->assertUnprocessable();
        $this->postJson(route('crm.save-agent'), array_replace($data, ['routing_tag_ids' => [], 'routing_enabled' => true]))->assertRedirect();
        $this->assertDatabaseHas('crm_agent_profiles', ['user_id' => $agent->id, 'routing_tag_ids' => '[]', 'routing_enabled' => true]);
    }

    public function test_qr_routing_persists_fallback_and_legacy_requests_preserve_new_options(): void
    {
        config(['whatsapp-qr.enabled' => true, 'whatsapp-qr.url' => 'http://127.0.0.1:3001', 'whatsapp-qr.api_key' => 'gateway-secret', 'whatsapp-qr.webhook_secret' => 'hook-secret', 'whatsapp-qr.encrypted_storage' => true]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['name' => 'default', 'status' => 'WORKING', 'me' => ['id' => '628123456789@c.us', 'pushName' => 'JM']]));
        $admin = $this->user('admin_operations');
        $fallback = $this->user();
        $channel = $this->channel('QR_WEB_SESSION');
        $data = ['team_id' => 1, 'routing_method' => 'ROUND_ROBIN', 'strict_owner' => true, 'prefer_existing_owner' => true];
        $this->actingAs($admin)->postJson(route('whatsapp-qr.routing', $channel->id), $data + ['routing_enabled' => false, 'fallback_user_id' => $fallback->id])->assertOk()
            ->assertJsonPath('channel.routingEnabled', false)->assertJsonPath('channel.fallbackUserId', $fallback->id)->assertDontSee('protected-token');
        $this->postJson(route('whatsapp-qr.routing', $channel->id), $data)->assertOk()->assertJsonPath('channel.routingEnabled', false)->assertJsonPath('channel.fallbackUserId', $fallback->id);
        $this->postJson(route('whatsapp-qr.routing', $channel->id), $data + ['fallback_user_id' => null])->assertOk()->assertJsonPath('channel.fallbackUserId', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp-qr.routing_configured']);
    }

    public function test_meta_routing_validates_fallback_scope_and_persists_flags(): void
    {
        Http::preventStrayRequests();
        $admin = $this->user('admin_operations');
        $fallback = $this->user();
        $outsider = $this->user('cs', 2);
        $channel = $this->channel();
        $this->partialMock(MetaConnect::class, fn ($mock) => $mock->shouldReceive('health')->andReturn($channel));
        $data = ['name' => $channel->name, 'team_id' => 1, 'routing_method' => 'LEAST_ACTIVE', 'strict_owner' => true, 'prefer_existing_owner' => true];
        $this->actingAs($admin)->postJson(route('meta-connect.routing', $channel->id), $data + ['fallback_user_id' => $outsider->id])->assertUnprocessable()->assertJsonValidationErrors('fallback_user_id');
        $this->postJson(route('meta-connect.routing', $channel->id), $data + ['fallback_user_id' => $fallback->id, 'routing_enabled' => false])->assertOk()->assertJsonPath('channel.routing_enabled', false)->assertJsonPath('channel.fallback_user_id', $fallback->id);
        $this->assertDatabaseHas('whatsapp_channels', ['id' => $channel->id, 'routing_enabled' => false, 'fallback_user_id' => $fallback->id]);
        $this->actingAs($fallback)->postJson(route('meta-connect.routing', $channel->id), $data)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_team_scoped_admin_cannot_change_other_team_channel_or_route_across_teams(): void
    {
        $admin = $this->user('admin_operations');
        $this->user();
        $this->user('cs', 2);
        DB::table('crm_roles')->where('id', $admin->crm_role_id)->update(['scope_type' => 'TEAM']);
        $channel = $this->channel();
        $data = ['name' => $channel->name, 'team_id' => 2, 'routing_method' => 'ROUND_ROBIN', 'strict_owner' => true, 'prefer_existing_owner' => true];
        $this->actingAs($admin)->postJson(route('meta-connect.routing', $channel->id), $data)->assertForbidden();
        DB::table('whatsapp_channels')->where('id', $channel->id)->update(['team_id' => 2]);
        $this->postJson(route('meta-connect.routing', $channel->id), $data)->assertForbidden();
    }
}
