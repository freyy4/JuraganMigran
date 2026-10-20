<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acquisition_leads', function (Blueprint $table): void {
            $table->timestamp('next_followup_at')->nullable();
            $table->index(['assigned_cs_id', 'followup_status', 'created_at'], 'crm_lead_queue');
            $table->index(['next_followup_at', 'id'], 'crm_lead_due');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('workflow_key', 160)->nullable()->unique('crm_task_workflow');
            $table->index(['status', 'due_at', 'id'], 'crm_task_queue');
            $table->index(['assigned_to', 'due_at'], 'crm_task_owner');
        });
        Schema::table('followup_events', fn (Blueprint $table) => $table->json('context')->nullable());
        Schema::table('cpmi_requests', function (Blueprint $table): void {
            $table->string('priority', 20)->default('normal');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->index(['status', 'created_at', 'id'], 'crm_request_queue');
            $table->index(['candidate_id', 'created_at'], 'crm_request_candidate');
        });
        Schema::table('application_blockers', function (Blueprint $table): void {
            $table->index(['status', 'due_at', 'id'], 'crm_blocker_queue');
            $table->index(['owner_id', 'priority'], 'crm_blocker_owner');
        });
        Schema::table('candidates', fn (Blueprint $table) => $table->index(['status', 'registered_at', 'id'], 'crm_candidate_registry'));
        Schema::table('applications', fn (Blueprint $table) => $table->index(['assigned_cs_id', 'status', 'applied_at'], 'crm_application_registry'));
    }

    public function down(): void
    {
        Schema::table('acquisition_leads', function (Blueprint $table): void {
            $table->dropIndex('crm_lead_queue');
            $table->dropIndex('crm_lead_due');
            $table->dropColumn('next_followup_at');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique('crm_task_workflow');
            $table->dropIndex('crm_task_queue');
            $table->dropIndex('crm_task_owner');
            $table->dropColumn('workflow_key');
        });
        Schema::table('followup_events', fn (Blueprint $table) => $table->dropColumn('context'));
        Schema::table('cpmi_requests', function (Blueprint $table): void {
            $table->dropIndex('crm_request_queue');
            $table->dropIndex('crm_request_candidate');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['priority', 'verified_at']);
        });
        Schema::table('application_blockers', function (Blueprint $table): void {
            $table->dropIndex('crm_blocker_queue');
            $table->dropIndex('crm_blocker_owner');
        });
        Schema::table('candidates', fn (Blueprint $table) => $table->dropIndex('crm_candidate_registry'));
        Schema::table('applications', fn (Blueprint $table) => $table->dropIndex('crm_application_registry'));
    }
};
