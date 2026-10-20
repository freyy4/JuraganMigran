<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_leads', function (Blueprint $t): void {
            $t->string('crm_stage')->default('NEW')->index();
            $t->timestamp('crm_stage_entered_at')->nullable();
            $t->string('crm_source')->nullable();
            $t->string('lost_reason')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->unsignedInteger('crm_version')->default(0);
        });
        DB::table('acquisition_leads')->where('status', 'qualified')->update(['crm_stage' => 'QUALIFIED']);
        DB::table('acquisition_leads')->where('status', 'converted')->update(['crm_stage' => 'CONVERTED']);
        Schema::create('whatsapp_channels', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('provider')->default('META_CLOUD_API');
            $t->string('waba_id');
            $t->string('phone_number_id')->unique();
            $t->string('display_phone_number');
            $t->text('access_token');
            $t->string('status')->default('CONFIGURED');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->string('routing_method')->default('ROUND_ROBIN');
            $t->boolean('prefer_existing_owner')->default(true);
            $t->boolean('strict_owner')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('last_user_id')->nullable();
            $t->timestamp('last_webhook_at')->nullable();
            $t->json('business_hours')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('crm_conversations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('channel_id')->constrained('whatsapp_channels')->restrictOnDelete();
            $t->foreignId('lead_id')->constrained('acquisition_leads')->restrictOnDelete();
            $t->string('wa_contact_id');
            $t->string('status')->default('OPEN')->index();
            $t->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('last_inbound_at')->nullable();
            $t->timestamp('last_outbound_at')->nullable();
            $t->timestamp('service_window_expires_at')->nullable();
            $t->unsignedInteger('unread_count')->default(0);
            $t->timestamps();
            $t->unique(['channel_id', 'wa_contact_id']);
            $t->index(['assigned_user_id', 'last_message_at', 'id']);
            $t->index(['team_id', 'status', 'last_message_at']);
        });
        Schema::create('crm_messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('conversation_id')->constrained('crm_conversations')->restrictOnDelete();
            $t->string('external_message_id')->nullable()->unique();
            $t->uuid('client_key')->nullable()->unique();
            $t->string('direction');
            $t->string('message_type')->default('text');
            $t->text('text')->nullable();
            $t->text('provider_payload')->nullable();
            $t->string('media_mime')->nullable();
            $t->string('media_name')->nullable();
            $t->string('status')->default('QUEUED')->index();
            $t->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('provider_timestamp')->nullable();
            $t->timestamp('status_at')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_code')->nullable();
            $t->timestamps();
            $t->index(['conversation_id', 'provider_timestamp', 'id']);
        });
        Schema::create('crm_webhook_events', function (Blueprint $t): void {
            $t->id();
            $t->string('event_key', 64)->unique();
            $t->text('payload')->nullable();
            $t->string('status')->default('PENDING')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('crm_delivery_events', function (Blueprint $t): void {
            $t->id();
            $t->string('external_message_id')->index();
            $t->foreignId('channel_id')->constrained('whatsapp_channels');
            $t->string('status');
            $t->timestamp('provider_timestamp');
            $t->string('error_code')->nullable();
            $t->unique(['channel_id', 'external_message_id', 'status']);
        });
        Schema::create('crm_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->string('color_token')->default('blue');
            $t->string('category')->default('PROGRAM');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('crm_lead_tags', function (Blueprint $t): void {
            $t->foreignId('lead_id')->constrained('acquisition_leads');
            $t->foreignId('tag_id')->constrained('crm_tags');
            $t->foreignId('added_by')->nullable()->constrained('users');
            $t->timestamp('created_at');
            $t->primary(['lead_id', 'tag_id']);
        });
        Schema::create('crm_follow_ups', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lead_id')->constrained('acquisition_leads');
            $t->foreignId('assigned_user_id')->constrained('users');
            $t->timestamp('due_at')->index();
            $t->string('type');
            $t->text('note')->nullable();
            $t->string('status')->default('SCHEDULED')->index();
            $t->timestamp('completed_at')->nullable();
            $t->string('outcome')->nullable();
            $t->timestamps();
        });
        Schema::create('crm_activities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lead_id')->constrained('acquisition_leads');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type');
            $t->text('summary');
            $t->timestamp('created_at');
            $t->index(['lead_id', 'id']);
        });
        Schema::create('crm_quick_replies', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->text('body');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('crm_whatsapp_templates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('channel_id')->constrained('whatsapp_channels');
            $t->string('name');
            $t->string('language');
            $t->string('category');
            $t->string('status');
            $t->text('components');
            $t->timestamp('last_synced_at');
            $t->unique(['channel_id', 'name', 'language']);
        });
        Schema::create('crm_stages', function (Blueprint $t): void {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->unsignedInteger('display_order');
            $t->string('color_token')->default('blue');
            $t->json('transitions');
            $t->timestamps();
        });
        foreach (array_keys(config('conversation.stages')) as $order => $code) {
            DB::table('crm_stages')->insert(['code' => $code, 'name' => config('conversation.stages.'.$code), 'display_order' => $order, 'transitions' => json_encode(config('conversation.transitions.'.$code, [])), 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('crm_agent_profiles', function (Blueprint $t): void {
            $t->foreignId('user_id')->primary()->constrained('users');
            $t->string('availability')->default('ONLINE');
            $t->unsignedInteger('conversation_cap')->default(30);
            $t->timestamps();
        });
        Schema::create('crm_lead_interests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lead_id')->constrained('acquisition_leads');
            $t->foreignId('vacancy_id')->constrained('vacancies');
            $t->foreignId('application_id')->nullable()->constrained('applications');
            $t->timestamps();
            $t->unique(['lead_id', 'vacancy_id']);
        });
        DB::table('acquisition_leads')->whereNotNull('next_followup_at')->whereNotNull('assigned_cs_id')->orderBy('id')->chunkById(200, function ($leads): void {
            foreach ($leads as $lead) {
                DB::table('crm_follow_ups')->insert(['lead_id' => $lead->id, 'assigned_user_id' => $lead->assigned_cs_id, 'due_at' => $lead->next_followup_at, 'type' => 'OTHER', 'note' => 'Jadwal dari CRM sebelumnya.', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        Schema::create('crm_saved_views', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('name');
            $t->json('filters');
            $t->timestamps();
        });
        foreach (DB::table('crm_roles')->get() as $role) {
            $extra = config('conversation.role_permissions.'.$role->code, []);
            DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...json_decode($role->permissions, true), ...$extra])))]);
        }
    }

    public function down(): void
    {
        foreach (['crm_saved_views', 'crm_stages', 'crm_agent_profiles', 'crm_lead_interests', 'crm_whatsapp_templates', 'crm_quick_replies', 'crm_activities', 'crm_follow_ups', 'crm_lead_tags', 'crm_tags', 'crm_delivery_events', 'crm_webhook_events', 'crm_messages', 'crm_conversations', 'whatsapp_channels'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('acquisition_leads', fn (Blueprint $t) => $t->dropColumn(['crm_stage', 'crm_stage_entered_at', 'crm_source', 'lost_reason', 'archived_at', 'crm_version']));
        foreach (DB::table('crm_roles')->get() as $role) {
            DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_diff(json_decode($role->permissions, true), config('conversation.permissions'))))]);
        }
    }
};
