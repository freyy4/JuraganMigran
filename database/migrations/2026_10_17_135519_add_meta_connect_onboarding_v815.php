<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $t): void {
            $t->string('connection_method')->default('MANUAL_LEGACY');
            $t->string('business_id')->nullable();
            $t->string('verified_name')->nullable();
            $t->string('quality_rating')->nullable();
            $t->json('permission_snapshot')->nullable();
            $t->boolean('webhook_ready')->default(false);
            $t->timestamp('webhook_verified_at')->nullable();
            $t->timestamp('last_health_check_at')->nullable();
            $t->timestamp('credential_expires_at')->nullable();
            $t->timestamp('connected_at')->nullable();
            $t->timestamp('inbound_test_at')->nullable();
            $t->timestamp('outbound_test_at')->nullable();
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('meta_auth_sessions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('channel_id')->nullable()->constrained('whatsapp_channels');
            $t->string('state_hash', 64)->unique();
            $t->string('status')->default('AUTH_PENDING');
            $t->text('credential')->nullable();
            $t->json('selection')->nullable();
            $t->string('failure_code')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('auth_expires_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('credential_expires_at')->nullable();
            $t->timestamp('processing_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'expires_at']);
        });
        Schema::create('whatsapp_connection_audits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('channel_id')->nullable()->constrained('whatsapp_channels');
            $t->foreignId('actor_user_id')->nullable()->constrained('users');
            $t->string('operation');
            $t->string('result');
            $t->json('metadata');
            $t->timestamp('created_at');
        });
        Schema::create('whatsapp_connection_tests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('channel_id')->constrained('whatsapp_channels');
            $t->foreignId('user_id')->constrained('users');
            $t->string('external_message_id')->nullable()->unique();
            $t->string('status')->default('PENDING');
            $t->string('error_code')->nullable();
            $t->string('recipient_masked');
            $t->timestamp('status_at')->nullable();
            $t->timestamps();
        });
        foreach (DB::table('crm_roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?? [];
            $extra = array_values(array_filter(config('conversation.role_permissions.'.$role->code, []), fn ($permission) => str_starts_with($permission, 'WHATSAPP_') || $permission === 'crm.settings.read'));
            if ($role->code === 'migration_owner' && $role->is_admin && $role->scope_type === 'OPERATIONAL_ALL' && in_array('admin.integration.manage', $permissions, true)) {
                $extra = array_values(array_filter(config('conversation.permissions'), fn ($permission) => str_starts_with($permission, 'WHATSAPP_')));
            }
            DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$extra])))]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connection_tests');
        Schema::dropIfExists('whatsapp_connection_audits');
        Schema::dropIfExists('meta_auth_sessions');
        Schema::table('whatsapp_channels', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('connected_by');
            $t->dropColumn(['connection_method', 'business_id', 'verified_name', 'quality_rating', 'permission_snapshot', 'webhook_ready', 'webhook_verified_at', 'last_health_check_at', 'credential_expires_at', 'connected_at', 'inbound_test_at', 'outbound_test_at']);
        });
    }
};
