<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_roles', function (Blueprint $table) {
            $table->string('code')->nullable()->unique();
            $table->string('scope_type')->default('OPERATIONAL_ALL');
            $table->json('scope_refs')->nullable();
            $table->boolean('active')->default(true);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp', 30)->nullable();
            $table->unsignedBigInteger('team_id')->nullable()->index();
        });
        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('crm_roles')->restrictOnDelete();
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('scope_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('user_role_assignments')->cascadeOnDelete();
            $table->string('scope_type');
            $table->json('scope_refs')->nullable();
            $table->timestamps();
        });
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->cascadeOnDelete();
            $table->json('permissions');
            $table->string('reason');
            $table->string('status')->default('pending');
            $table->timestamp('valid_from');
            $table->timestamp('valid_until');
            $table->timestamps();
        });
        Schema::create('cs_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('last_member_id')->nullable();
            $table->timestamps();
        });
        Schema::create('cs_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('cs_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('whatsapp', 30);
            $table->string('active_number', 30)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'whatsapp']);
        });
        Schema::create('cs_vacancy_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacancy_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('cs_groups')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('vacancy_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('status')->default('draft');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('application_funnels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('vacancy_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->json('draft_schema');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('funnel_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('application_funnels')->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->string('change_summary')->nullable();
            $table->timestamps();
            $table->unique(['funnel_id', 'version']);
        });
        Schema::create('acquisition_leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_token')->unique();
            $table->string('name');
            $table->string('normalized_phone', 30)->unique();
            $table->string('email')->nullable()->index();
            $table->string('status')->default('new');
            $table->foreignId('assigned_cs_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_member_id')->nullable()->constrained('cs_members')->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('followup_status')->default('NOT_CONTACTED');
            $table->timestamps();
        });
        Schema::create('lead_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_token')->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('lead_id')->nullable()->constrained('acquisition_leads')->nullOnDelete();
            $table->foreignId('funnel_id')->constrained('application_funnels')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('funnel_versions')->restrictOnDelete();
            $table->foreignId('vacancy_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->json('attribution');
            $table->string('duplicate_state');
            $table->string('consent_version');
            $table->timestamp('consented_at');
            $table->boolean('marketing_consent')->default(false);
            $table->boolean('is_test')->default(false);
            $table->timestamps();
        });
        Schema::create('cs_assignment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('acquisition_leads')->nullOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('cs_members')->nullOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('lead_submissions')->nullOnDelete();
            $table->string('status');
            $table->string('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('followup_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('acquisition_leads')->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('funnel_analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('application_funnels')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('funnel_versions')->nullOnDelete();
            $table->string('visitor_hash', 64);
            $table->string('event');
            $table->unsignedInteger('step')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestamps();
        });
        foreach (config('governance.roles') as $code => [$name,$scope,$permissions]) {
            DB::table('crm_roles')->insert(['code' => $code, 'name' => DB::table('crm_roles')->where('name', $name)->exists() ? $name.' (PRD)' : $name, 'scope_type' => $scope, 'permissions' => json_encode($permissions), 'is_admin' => $code === 'super_admin', 'created_at' => now(), 'updated_at' => now()]);
        }
        $legacy = DB::table('crm_roles')->insertGetId(['code' => 'migration_owner', 'name' => 'Pemilik akses sebelum PRD', 'scope_type' => 'OPERATIONAL_ALL', 'permissions' => json_encode(config('governance.permissions')), 'is_admin' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->whereNull('crm_role_id')->update(['crm_role_id' => $legacy]);
        DB::table('audit_logs')->insert(['action' => 'governance.migrate_explicit_roles', 'new_values' => json_encode(['role_id' => $legacy, 'reason' => 'Preserve previously authorized access using explicit permissions; Super Admin remains technical only.']), 'created_at' => now()]);
    }

    public function down(): void
    {
        foreach (['funnel_analytics_events', 'followup_events', 'cs_assignment_events', 'lead_submissions', 'acquisition_leads', 'funnel_versions', 'application_funnels', 'whatsapp_templates', 'cs_vacancy_mappings', 'cs_members', 'cs_groups', 'access_grants', 'scope_grants', 'user_role_assignments'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('users')->whereIn('crm_role_id', DB::table('crm_roles')->whereNotNull('code')->pluck('id'))->update(['crm_role_id' => null]);
        DB::table('crm_roles')->whereNotNull('code')->delete();
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'team_id']);
        });
        Schema::table('crm_roles', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'scope_type', 'scope_refs', 'active']);
        });
    }
};
