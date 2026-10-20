<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_questions', function (Blueprint $table) {
            $table->string('code')->nullable()->unique();
            $table->string('answer_type')->default('LEGACY_TEXT');
            $table->json('options')->nullable();
            $table->text('helper_text')->nullable();
            $table->json('clarification_rule')->nullable();
            $table->json('followup_rule')->nullable();
            $table->json('applicability_rule')->nullable();
            $table->boolean('restricted')->default(false);
        });
        foreach (['health_screening_templates', 'document_requirement_sets'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('scope_type')->default('GLOBAL')->index();
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->string('status')->default('draft');
                $table->json('draft_schema');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['scope_type', 'scope_id']);
            });
        }
        foreach (['health_template_versions' => ['health_screening_template_id', 'health_screening_templates'], 'document_requirement_versions' => ['document_requirement_set_id', 'document_requirement_sets']] as $name => [$key, $parent]) {
            Schema::create($name, function (Blueprint $table) use ($key, $parent) {
                $table->id();
                $table->foreignId($key)->constrained($parent, indexName: $parent === 'document_requirement_sets' ? 'doc_req_version_set_fk' : 'health_version_template_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->json('snapshot');
                $table->date('effective_from')->nullable();
                $table->date('effective_until')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('policy_approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('policy_reference')->nullable();
                $table->timestamp('published_at');
                $table->unique([$key, 'version'], $parent === 'document_requirement_sets' ? 'doc_req_version_unique' : 'health_template_version_unique');
            });
        }
        Schema::table('health_interviews', function (Blueprint $table) {
            $table->string('source')->default('LEGACY_GENERIC')->index();
            $table->foreignId('health_template_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('outcome')->nullable()->index();
            $table->unsignedInteger('clarification_count')->default(0);
        });
        Schema::create('health_screening_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_interview_id')->constrained()->restrictOnDelete();
            $table->string('outcome');
            $table->text('encrypted_reason');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at');
        });
        Schema::table('health_answers', function (Blueprint $table) {
            $table->text('structured_value')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
        });
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('issuer_category')->nullable();
            $table->json('allowed_file_types')->nullable();
            $table->unsignedInteger('max_file_size_kb')->default(10240);
            $table->text('verification_help_text')->nullable();
        });
        Schema::table('vacancy_document_requirements', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->constrained('pipeline_stages')->restrictOnDelete();
            $table->boolean('must_verify_before_exit')->default(false);
            $table->boolean('original_required')->default(false);
            $table->unsignedInteger('minimum_validity_days')->default(0);
            $table->json('condition_rule')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });
        Schema::create('document_requirement_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_requirement_version_id')->constrained(indexName: 'doc_req_rule_version_fk')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('pipeline_stages')->restrictOnDelete();
            $table->string('required_level')->default('REQUIRED');
            $table->boolean('must_verify_before_exit')->default(false);
            $table->boolean('original_required')->default(false);
            $table->string('expiry_policy')->default('VALID');
            $table->unsignedInteger('minimum_validity_days')->default(0);
            $table->json('condition_rule')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->string('correction_code')->nullable();
            $table->string('action_owner')->default('INTERNAL_REVIEW')->index();
            $table->foreignId('action_assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('action_due_at')->nullable()->index();
        });
        Schema::create('document_verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_document_id')->constrained()->restrictOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->string('reason_code')->nullable();
            $table->text('safe_reason')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
        });
        Schema::create('document_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('owner');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'document_type_id']);
        });
        Schema::create('document_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('pipeline_stages')->restrictOnDelete();
            $table->string('status')->default('requested');
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('valid_until');
            $table->timestamps();
        });
        Schema::table('document_custody_histories', function (Blueprint $table) {
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('reason')->nullable();
            $table->string('case_reference')->nullable();
        });
        Schema::table('original_document_custodies', function (Blueprint $table) {
            $table->string('custody_state')->default('RECEIVED_BY_JM')->index();
            $table->timestamp('expected_return_at')->nullable();
        });
        DB::table('original_document_custodies')->where('status', 'returned')->update(['custody_state' => 'RETURNED_TO_CPMI']);
        DB::table('original_document_custodies')->where('status', 'transferred')->update(['custody_state' => 'TEMPORARILY_RELEASED']);
        Schema::create('document_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('whatsapp_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('queued');
            $table->text('safe_message');
            $table->uuid('batch_key');
            $table->timestamp('created_at');
            $table->unique(['batch_key', 'application_id', 'document_type_id'], 'doc_followup_batch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_followups');
        Schema::dropIfExists('health_screening_review_events');
        Schema::table('original_document_custodies', function (Blueprint $table) {
            $table->dropIndex(['custody_state']);
            $table->dropColumn(['custody_state', 'expected_return_at']);
        });
        Schema::table('document_custody_histories', fn (Blueprint $table) => $table->dropColumn(['from_status', 'to_status', 'reason', 'case_reference']));
        Schema::dropIfExists('document_exceptions');
        Schema::dropIfExists('document_actions');
        Schema::dropIfExists('document_verification_events');
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->dropIndex(['action_owner']);
            $table->dropIndex(['action_due_at']);
            $table->dropConstrainedForeignId('action_assigned_to');
            $table->dropColumn(['correction_code', 'action_owner', 'action_due_at']);
        });
        Schema::dropIfExists('document_requirement_rules');
        Schema::table('vacancy_document_requirements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stage_id');
            $table->dropColumn(['must_verify_before_exit', 'original_required', 'minimum_validity_days', 'condition_rule', 'sort_order', 'is_active']);
        });
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn(['issuer_category', 'allowed_file_types', 'max_file_size_kb', 'verification_help_text']));
        Schema::table('health_answers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answered_by');
            $table->dropColumn(['structured_value', 'answered_at']);
        });
        Schema::table('health_interviews', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['outcome']);
            $table->dropConstrainedForeignId('health_template_version_id');
            $table->dropColumn(['source', 'outcome', 'clarification_count']);
        });
        foreach (['health_template_versions', 'document_requirement_versions', 'health_screening_templates', 'document_requirement_sets'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('health_questions', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'answer_type', 'options', 'helper_text', 'clarification_rule', 'followup_rule', 'applicability_rule', 'restricted']);
        });
    }
};
