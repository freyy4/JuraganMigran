<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('applications', 'process_priority')) {
            Schema::table('applications', function (Blueprint $table): void {
                $table->string('process_priority', 20)->nullable();
                $table->string('next_action', 255)->nullable();
                $table->timestamp('next_action_due_at')->nullable();
                $table->string('waiting_status', 30)->nullable();
                $table->index(['status', 'current_stage_id', 'assigned_cs_id'], 'process_scope_index');
                $table->index(['status', 'applied_at', 'id'], 'process_period_index');
            });
        }
        if (! Schema::hasColumn('application_stage_histories', 'sla_hours_snapshot')) {
            Schema::table('application_stage_histories', function (Blueprint $table): void {
                $table->unsignedInteger('sla_hours_snapshot')->nullable();
                $table->index(['application_id', 'id'], 'process_stage_visit_index');
            });
        }
        if (! Schema::hasIndex('tasks', 'process_next_task_index')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->index(['application_id', 'status', 'due_at'], 'process_next_task_index');
            });
        }
        if (! Schema::hasIndex('application_blockers', 'process_blocker_index')) {
            Schema::table('application_blockers', function (Blueprint $table): void {
                $table->index(['application_id', 'status', 'due_at'], 'process_blocker_index');
            });
        }
        if (! Schema::hasTable('process_user_views')) {
            Schema::create('process_user_views', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->json('filters');
                $table->timestamps();
                $table->unique(['user_id', 'name'], 'process_saved_view_unique');
            });
        }
        if (! Schema::hasTable('process_message_drafts')) {
            Schema::create('process_message_drafts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('template_id')->constrained('whatsapp_templates')->restrictOnDelete();
                $table->uuid('batch_key');
                $table->text('safe_message');
                $table->string('status')->default('manual_ready');
                $table->timestamps();
                $table->unique(['application_id', 'created_by', 'batch_key'], 'process_message_batch_unique');
            });
        }
        if (! Schema::hasIndex('process_message_drafts', 'process_message_batch_unique')) {
            Schema::table('process_message_drafts', fn (Blueprint $table) => $table->unique(['application_id', 'created_by', 'batch_key'], 'process_message_batch_unique'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('process_message_drafts');
        Schema::dropIfExists('process_user_views');
        Schema::table('application_blockers', fn (Blueprint $table) => $table->dropIndex('process_blocker_index'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex('process_next_task_index'));
        Schema::table('application_stage_histories', function (Blueprint $table): void {
            $table->dropIndex('process_stage_visit_index');
            $table->dropColumn('sla_hours_snapshot');
        });
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropIndex('process_scope_index');
            $table->dropIndex('process_period_index');
            $table->dropColumn(['process_priority', 'next_action', 'next_action_due_at', 'waiting_status']);
        });
    }
};
