<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_policies', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->unsignedInteger('version')->default(1);
            $t->json('scope');
            $t->json('rules');
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('holiday_events', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->date('start_date');
            $t->date('end_date');
            $t->timestamp('return_deadline');
            $t->string('holiday_type');
            $t->json('lpk_ids');
            $t->json('country_ids')->nullable();
            $t->foreignId('policy_id')->constrained('holiday_policies')->restrictOnDelete();
            $t->string('status')->default('DRAFT')->index();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('holiday_eligibilities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $t->foreignId('holiday_event_id')->constrained('holiday_events')->cascadeOnDelete();
            $t->string('result')->index();
            $t->json('snapshot');
            $t->timestamp('evaluated_at');
            $t->unique(['application_id', 'holiday_event_id']);
        });
        Schema::table('training_leave_requests', function (Blueprint $t): void {
            $t->foreignId('holiday_event_id')->nullable()->constrained('holiday_events')->restrictOnDelete();
            $t->string('journey_status')->nullable()->index();
            $t->timestamp('mandatory_return_at')->nullable()->index();
            $t->string('destination_city')->nullable();
            $t->text('emergency_contact')->nullable();
            $t->json('eligibility_snapshot')->nullable();
            $t->json('override_snapshot')->nullable();
            $t->timestamp('candidate_acknowledged_at')->nullable();
        });
        Schema::create('pickup_points', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('address', 500);
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->text('instructions')->nullable();
            $t->boolean('safe_meeting_point')->default(false);
            $t->string('contact_number')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('transport_batches', function (Blueprint $t): void {
            $t->id();
            $t->string('code')->unique();
            $t->string('route_name');
            $t->string('destination');
            $t->timestamp('pickup_at');
            $t->timestamp('eta')->nullable();
            $t->foreignId('pic_id')->constrained('users')->restrictOnDelete();
            $t->string('vehicle')->nullable();
            $t->unsignedInteger('capacity');
            $t->string('status')->default('PLANNED');
            $t->text('route_notes')->nullable();
            $t->timestamps();
        });
        Schema::create('mobility_cases', function (Blueprint $t): void {
            $t->id();
            $t->string('code')->unique();
            $t->foreignId('application_id')->constrained('applications')->restrictOnDelete();
            $t->foreignId('leave_request_id')->nullable()->constrained('training_leave_requests')->restrictOnDelete();
            $t->string('purpose');
            $t->string('origin');
            $t->string('destination');
            $t->timestamp('appointment_at');
            $t->text('route_notes')->nullable();
            $t->string('contact_name')->nullable();
            $t->string('contact_number')->nullable();
            $t->string('status')->default('PLANNED')->index();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('mobility_legs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('mobility_case_id')->constrained('mobility_cases')->cascadeOnDelete();
            $t->string('direction');
            $t->string('transport_mode');
            $t->string('origin');
            $t->string('destination');
            $t->timestamp('scheduled_at');
            $t->timestamp('eta')->nullable();
            $t->foreignId('batch_id')->nullable()->constrained('transport_batches')->restrictOnDelete();
            $t->foreignId('pickup_point_id')->nullable()->constrained('pickup_points')->restrictOnDelete();
            $t->string('pickup_override')->nullable();
            $t->unsignedInteger('pickup_sequence')->default(1);
            $t->string('status')->default('PLANNED');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->unique(['mobility_case_id', 'direction']);
        });
        Schema::create('qr_passes', function (Blueprint $t): void {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('mobility_leg_id')->constrained('mobility_legs')->restrictOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->text('encrypted_token');
            $t->timestamp('expires_at')->index();
            $t->timestamp('used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('qr_scan_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('qr_pass_id')->constrained('qr_passes')->cascadeOnDelete();
            $t->foreignId('scanner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action');
            $t->string('result');
            $t->timestamp('created_at');
        });
        Schema::create('mobility_handover_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('mobility_case_id')->constrained('mobility_cases')->restrictOnDelete();
            $t->foreignId('custody_id')->constrained('original_document_custodies')->restrictOnDelete();
            $t->string('from_party');
            $t->string('to_party');
            $t->string('status')->default('EXPECTED');
            $t->timestamp('handed_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->text('evidence_path')->nullable();
            $t->timestamps();
            $t->unique(['mobility_case_id', 'custody_id']);
        });
        Schema::create('operations_alerts', function (Blueprint $t): void {
            $t->id();
            $t->string('dedupe_key')->unique();
            $t->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $t->string('category');
            $t->string('message', 1000);
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tracking_destinations', function (Blueprint $t): void {
            $t->id();
            $t->string('provider');
            $t->string('display_name');
            $t->string('account_reference')->nullable();
            $t->string('pixel_reference')->nullable();
            $t->string('secret_reference')->nullable();
            $t->boolean('browser_enabled')->default(false);
            $t->boolean('server_enabled')->default(false);
            $t->boolean('active')->default(false);
            $t->timestamp('active_from')->nullable();
            $t->timestamp('active_until')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('health')->default('NOT_CONFIGURED');
            $t->timestamp('last_success_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('tracking_event_mappings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('destination_id')->constrained('tracking_destinations')->restrictOnDelete();
            $t->string('canonical_event');
            $t->string('provider_event');
            $t->string('stage_code')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('tracking_consent_snapshots', function (Blueprint $t): void {
            $t->id();
            $t->uuid('anonymous_session_id')->index();
            $t->boolean('analytics')->default(false);
            $t->boolean('advertising')->default(false);
            $t->string('policy_version');
            $t->timestamp('withdrawn_at')->nullable();
            $t->timestamps();
        });
        Schema::create('canonical_tracking_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('event_id')->unique();
            $t->string('idempotency_key')->unique();
            $t->string('canonical_event')->index();
            $t->string('source');
            $t->boolean('is_test')->default(false)->index();
            $t->foreignId('lead_id')->nullable()->constrained('acquisition_leads')->nullOnDelete();
            $t->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $t->foreignId('funnel_id')->nullable()->constrained('application_funnels')->nullOnDelete();
            $t->foreignId('funnel_version_id')->nullable()->constrained('funnel_versions')->nullOnDelete();
            $t->foreignId('vacancy_id')->nullable()->constrained('vacancies')->nullOnDelete();
            $t->foreignId('consent_snapshot_id')->nullable()->constrained('tracking_consent_snapshots')->nullOnDelete();
            $t->foreignId('attribution_id')->nullable()->constrained('site_attribution_touches')->nullOnDelete();
            $t->json('safe_context');
            $t->timestamp('occurred_at')->index();
            $t->timestamps();
        });
        Schema::create('tracking_outbox', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->constrained('canonical_tracking_events')->restrictOnDelete();
            $t->foreignId('destination_id')->constrained('tracking_destinations')->restrictOnDelete();
            $t->string('provider_event');
            $t->unsignedInteger('mapping_version');
            $t->string('status')->default('PENDING')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->index();
            $t->timestamp('leased_until')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'destination_id']);
        });
        Schema::create('tracking_delivery_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('outbox_id')->constrained('tracking_outbox')->cascadeOnDelete();
            $t->unsignedInteger('attempt');
            $t->unsignedInteger('http_status')->nullable();
            $t->string('result');
            $t->timestamp('created_at');
        });
        foreach (config('operations.role_permissions') as $role => $permissions) {
            $row = DB::table('crm_roles')->where('code', $role)->first();
            if ($row) {
                DB::table('crm_roles')->where('id', $row->id)->update(['permissions' => json_encode(array_values(array_unique(array_merge(json_decode($row->permissions, true) ?? [], $permissions))))]);
            }
        }
    }

    public function down(): void
    {
        foreach (['tracking_delivery_attempts', 'tracking_outbox', 'canonical_tracking_events', 'tracking_consent_snapshots', 'tracking_event_mappings', 'tracking_destinations', 'operations_alerts', 'mobility_handover_items', 'qr_scan_events', 'qr_passes', 'mobility_legs', 'mobility_cases', 'transport_batches', 'pickup_points'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('training_leave_requests', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('holiday_event_id');
            $t->dropColumn(['journey_status', 'mandatory_return_at', 'destination_city', 'emergency_contact', 'eligibility_snapshot', 'override_snapshot', 'candidate_acknowledged_at']);
        });
        Schema::dropIfExists('holiday_eligibilities');
        Schema::dropIfExists('holiday_events');
        Schema::dropIfExists('holiday_policies');
    }
};
