<?php

use App\Services\AttributionService;
use App\Services\DuplicateResolutionService;
use App\Services\IdentityResolutionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $t): void {
            $t->id();
            $t->string('primary_name');
            $t->string('status')->default('ACTIVE');
            $t->foreignId('candidate_id')->nullable()->constrained('candidates')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::table('crm_stages', fn (Blueprint $t) => $t->unsignedInteger('sla_hours')->nullable());
        Schema::create('crm_contact_identities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('contact_id')->constrained('crm_contacts')->restrictOnDelete();
            $t->string('identity_type', 20);
            $t->string('raw_value');
            $t->string('normalized_value');
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['identity_type', 'normalized_value'], 'crm_identity_unique');
        });
        Schema::table('acquisition_leads', function (Blueprint $t): void {
            $t->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $t->foreignId('merged_into_id')->nullable()->constrained('acquisition_leads')->restrictOnDelete();
            $t->timestamp('converted_at')->nullable();
        });
        Schema::table('candidates', fn (Blueprint $t) => $t->foreignId('source_lead_id')->nullable()->constrained('acquisition_leads')->restrictOnDelete());
        Schema::create('crm_attribution_touchpoints', function (Blueprint $t): void {
            $t->id();
            $t->string('event_key')->unique();
            $t->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained('acquisition_leads')->restrictOnDelete();
            $t->uuid('anonymous_session_id')->nullable()->index();
            $t->string('event_type', 50);
            $t->string('channel')->default('UNKNOWN');
            $t->string('source')->default('UNKNOWN');
            $t->json('context');
            $t->timestamp('event_at')->index();
            $t->timestamp('created_at');
            $t->index(['lead_id', 'event_at', 'id'], 'crm_attribution_timeline');
        });
        Schema::create('crm_duplicate_candidates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('primary_lead_id')->constrained('acquisition_leads')->restrictOnDelete();
            $t->foreignId('secondary_lead_id')->constrained('acquisition_leads')->restrictOnDelete();
            $t->unsignedSmallInteger('confidence');
            $t->string('reason');
            $t->string('status')->default('PENDING');
            $t->timestamps();
            $t->unique(['primary_lead_id', 'secondary_lead_id'], 'crm_duplicate_pair');
        });
        Schema::create('crm_lead_merge_records', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('primary_lead_id')->constrained('acquisition_leads')->restrictOnDelete();
            $t->foreignId('secondary_lead_id')->constrained('acquisition_leads')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('reason');
            $t->text('snapshot_encrypted');
            $t->timestamp('merged_at');
        });
        // Backfill exact identities only. Ambiguous records remain separate for review.
        DB::table('acquisition_leads')->orderBy('id')->chunkById(200, function ($leads): void {
            foreach ($leads as $lead) {
                app(IdentityResolutionService::class)->attachLegacy($lead);
                $submission = DB::table('lead_submissions')->where('lead_id', $lead->id)->where('is_test', false)->orderBy('id')->first();
                $context = $submission ? (json_decode($submission->attribution, true) ?: []) : [];
                app(AttributionService::class)->record($lead->id, 'legacy-lead-'.$lead->id, 'LEAD_CREATED', $lead->crm_source ?: ($context['utm_source'] ?? 'UNKNOWN'), $context, $lead->created_at);
            }
        });
        DB::table('lead_submissions')->where('is_test', false)->whereNotNull('lead_id')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('crm_lead_interests')->insertOrIgnore(['lead_id' => $row->lead_id, 'vacancy_id' => $row->vacancy_id, 'application_id' => $row->application_id, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
                app(AttributionService::class)->record($row->lead_id, 'submission-'.$row->id, 'FORM_SUBMITTED', 'UNKNOWN', json_decode($row->attribution, true) ?: [], $row->created_at);
            }
        });
        DB::table('acquisition_leads')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $lead) {
                app(DuplicateResolutionService::class)->suggest($lead->id);
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_stages', fn (Blueprint $t) => $t->dropColumn('sla_hours'));
        Schema::dropIfExists('crm_lead_merge_records');
        Schema::dropIfExists('crm_duplicate_candidates');
        Schema::dropIfExists('crm_attribution_touchpoints');
        Schema::table('candidates', fn (Blueprint $t) => $t->dropConstrainedForeignId('source_lead_id'));
        Schema::table('acquisition_leads', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('contact_id');
            $t->dropConstrainedForeignId('merged_into_id');
            $t->dropColumn('converted_at');
        });
        Schema::dropIfExists('crm_contact_identities');
        Schema::dropIfExists('crm_contacts');
    }
};
