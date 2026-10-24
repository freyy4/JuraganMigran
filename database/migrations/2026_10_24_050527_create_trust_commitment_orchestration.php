<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $t): void {
            $t->json('protocol_rubric')->nullable();
            $t->string('protocol_reason_code')->nullable();
        });
        Schema::create('protocol_settings', function (Blueprint $t): void {
            $t->id();
            $t->json('policy');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        DB::table('protocol_settings')->insert(['id' => 1, 'policy' => json_encode(['stages' => config('protocol.stages'), 'golden_hours' => config('protocol.golden_hours'), 'new_application_mode' => config('protocol.new_application_mode')]), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('protocol_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->unique()->constrained()->restrictOnDelete();
            $t->string('mode')->default('SHADOW');
            $t->string('stage');
            $t->unsignedInteger('version')->default(1);
            $t->json('definition');
            $t->string('legacy_stage')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('protocol_stage_instances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('run_id')->constrained('protocol_runs')->cascadeOnDelete();
            $t->string('stage');
            $t->string('queue');
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('next_action');
            $t->timestamp('due_at');
            $t->string('blocker_status')->default('CLEAR');
            $t->foreignId('blocker_id')->nullable()->constrained('application_blockers')->nullOnDelete();
            $t->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->timestamp('entered_at');
            $t->timestamp('exited_at')->nullable();
            $t->unique(['run_id', 'stage']);
            $t->index(['exited_at', 'queue', 'due_at']);
        });
        Schema::create('protocol_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('run_id')->constrained('protocol_runs')->cascadeOnDelete();
            $t->uuid('request_key')->unique();
            $t->string('stage');
            $t->string('action');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('fingerprint', 64);
            $t->text('payload');
            $t->timestamp('created_at');
            $t->index(['run_id', 'stage', 'action']);
        });
        Schema::create('protocol_content_versions', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->unsignedInteger('version');
            $t->string('status')->default('Draft');
            $t->foreignId('vacancy_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('source');
            $t->text('body');
            $t->timestamp('effective_at');
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->unique(['title', 'version']);
        });
        Schema::create('protocol_family_consents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('run_id')->unique()->constrained('protocol_runs')->cascadeOnDelete();
            $t->string('status')->default('NOT_CONTACTED');
            $t->string('signatory_role');
            $t->foreignId('contact_id')->nullable()->constrained('candidate_contacts')->restrictOnDelete();
            $t->foreignId('document_id')->nullable()->constrained('candidate_documents')->restrictOnDelete();
            $t->string('template_version')->nullable();
            $t->string('verification_method')->nullable();
            $t->string('endorsement')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('protocol_appointments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('run_id')->unique()->constrained('protocol_runs')->cascadeOnDelete();
            $t->foreignId('medical_event_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('pic_id')->constrained('users')->restrictOnDelete();
            $t->string('office_location');
            $t->timestamp('scheduled_at');
            $t->string('transport');
            $t->string('status')->default('CONFIRMED');
            $t->json('checklist');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('protocol_golden_windows', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('run_id')->unique()->constrained('protocol_runs')->cascadeOnDelete();
            $t->foreignId('medical_event_id')->constrained()->restrictOnDelete();
            $t->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->foreignId('enrollment_id')->nullable()->constrained('training_enrollments')->restrictOnDelete();
            $t->timestamp('fit_at');
            $t->timestamp('closed_at')->nullable();
            $t->string('status')->default('OPEN');
            $t->unsignedTinyInteger('escalation_level')->default(0);
            $t->json('sla_hours');
            $t->timestamps();
        });
        Schema::create('protocol_routing_profiles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('available')->default(true);
            $t->unsignedInteger('capacity')->default(30);
            $t->json('queues');
            $t->json('country_codes');
            $t->unsignedTinyInteger('start_hour')->default(8);
            $t->unsignedTinyInteger('end_hour')->default(17);
            $t->timestamps();
        });
        $rolePermissions = ['cs' => ['protocol.lead'], 'supervisor' => ['protocol.lead', 'protocol.senior', 'protocol.override'], 'admin_operations' => ['protocol.ops', 'health.mcu.schedule.write', 'health.mcu.summary.read', 'process.update', 'candidate.read.basic', 'candidate.read.timeline']];
        foreach (DB::table('crm_roles')->get() as $role) {
            $extra = $role->code === 'migration_owner' ? ['protocol.lead', 'protocol.senior', 'protocol.ops', 'protocol.medical', 'protocol.placement', 'protocol.override', 'health.mcu.result.write', 'health.mcu.schedule.write'] : ($rolePermissions[$role->code] ?? []);
            DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...json_decode($role->permissions, true), ...$extra])))]);
        }
        foreach (['senior_interviewer' => ['Senior CS / Interviewer', ['protocol.senior', 'candidate.read.contact', 'health.prescreen.read']], 'medical_coordinator' => ['Koordinator Medis', ['protocol.medical', 'protocol.ops', 'health.mcu.schedule.write', 'health.mcu.result.write', 'health.mcu.summary.read', 'health.prescreen.read', 'health.prescreen.write']], 'placement_coordinator' => ['Koordinator Placement / LPK', ['protocol.placement']]] as $code => [$name, $permissions]) {
            DB::table('crm_roles')->insertOrIgnore(['code' => $code, 'name' => $name, 'scope_type' => $code === 'senior_interviewer' ? 'TEAM' : 'OPERATIONAL_ALL', 'permissions' => json_encode([...$permissions, 'candidate.read.basic', 'candidate.read.timeline', 'document.read.metadata', 'process.read', 'process.update', 'schedule.read', 'schedule.create', 'schedule.update']), 'is_admin' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('interviews', fn (Blueprint $t) => $t->dropColumn(['protocol_rubric', 'protocol_reason_code']));
        foreach (['protocol_routing_profiles', 'protocol_golden_windows', 'protocol_appointments', 'protocol_family_consents', 'protocol_content_versions', 'protocol_actions', 'protocol_stage_instances', 'protocol_runs', 'protocol_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
