<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_qr_sessions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('channel_id')->unique()->constrained('whatsapp_channels');
            $t->string('gateway_session')->unique();
            $t->text('webhook_credential')->nullable();
            $t->string('state')->default('NOT_CONNECTED');
            $t->uuid('pairing_id')->nullable();
            $t->foreignId('pairing_user_id')->nullable()->constrained('users');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('paired_at')->nullable();
            $t->timestamp('last_heartbeat_at')->nullable();
            $t->timestamp('last_inbound_at')->nullable();
            $t->timestamp('last_outbound_at')->nullable();
            $t->unsignedInteger('reconnect_attempts')->default(0);
            $t->timestamp('next_retry_at')->nullable();
            $t->boolean('reauth_required')->default(false);
            $t->boolean('activated')->default(false);
            $t->boolean('cleanup_pending')->default(false);
            $t->string('last_error_code')->nullable();
            $t->string('lease_owner')->nullable();
            $t->timestamp('lease_expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('whatsapp_channel_health_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('channel_id')->constrained('whatsapp_channels');
            $t->string('state');
            $t->string('error_code')->nullable();
            $t->json('metrics')->nullable();
            $t->timestamp('created_at');
            $t->index(['channel_id', 'created_at']);
        });
        Schema::table('crm_webhook_events', function (Blueprint $t): void {
            $t->string('provider')->default('META_CLOUD_API');
            $t->foreignId('channel_id')->nullable()->constrained('whatsapp_channels');
        });
        Schema::table('crm_conversations', function (Blueprint $t): void {
            $t->foreignId('merged_into_id')->nullable()->constrained('crm_conversations')->nullOnDelete();
        });
        Schema::table('crm_messages', function (Blueprint $t): void {
            $t->string('provider')->default('META_CLOUD_API');
            $t->string('provider_message_id')->nullable();
        });
        foreach (DB::table('crm_roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?? [];
            $extra = [];
            if (in_array('WHATSAPP_CONNECTION_VIEW', $permissions, true)) {
                $extra[] = 'WHATSAPP_CHANNEL_VIEW';
            }
            if (in_array('WHATSAPP_CONNECTION_MANAGE', $permissions, true)) {
                $extra = [...$extra, 'WHATSAPP_CHANNEL_CONNECT_QR', 'WHATSAPP_CHANNEL_RESCAN', 'WHATSAPP_CHANNEL_MANAGE_TEAM', 'WHATSAPP_CHANNEL_MANAGE_ROUTING'];
            }
            if (in_array('WHATSAPP_CONNECTION_DISCONNECT', $permissions, true)) {
                $extra[] = 'WHATSAPP_CHANNEL_DISCONNECT';
            }
            if (in_array('WHATSAPP_CONNECTION_ADVANCED', $permissions, true)) {
                $extra[] = 'WHATSAPP_CHANNEL_DIAGNOSTICS';
            }
            DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$extra])))]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_conversations', fn (Blueprint $t) => $t->dropConstrainedForeignId('merged_into_id'));
        Schema::table('crm_messages', fn (Blueprint $t) => $t->dropColumn(['provider', 'provider_message_id']));
        Schema::table('crm_webhook_events', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('channel_id');
            $t->dropColumn('provider');
        });
        Schema::dropIfExists('whatsapp_channel_health_events');
        Schema::dropIfExists('whatsapp_qr_sessions');
    }
};
