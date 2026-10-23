<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_journey_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('country_code')->unique();
            $table->string('name');
            $table->json('stages');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        foreach (['DEFAULT' => 'Jalur umum', 'SG' => 'Singapura', 'MY' => 'Malaysia', 'TW' => 'Taiwan'] as $code => $name) {
            $stages = ['trust' => 'Program Resmi & Edukasi', 'screening' => 'Screening Awal Kesehatan', 'family' => 'Izin Keluarga', 'mcu' => 'MCU', 'lpk' => 'Masuk LPK'];
            if ($code === 'TW') {
                $stages['teto'] = 'TETO';
            }
            $stages += ['opp' => 'OPP', 'flight' => 'Keberangkatan'];
            DB::table('care_journey_templates')->insert(['country_code' => $code, 'name' => $name, 'stages' => json_encode($stages), 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('care_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_key', 191)->unique();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('stage')->nullable();
            $table->string('visibility')->default('candidate.read.timeline');
            $table->boolean('auto_generated')->default(false);
            $table->text('payload');
            $table->string('signature', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->index(['candidate_id', 'occurred_at']);
        });
        Schema::create('care_issues', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category');
            $table->string('severity');
            $table->string('status')->default('OPEN');
            $table->string('verification')->default('REPORTED');
            $table->text('details');
            $table->text('closure')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('ack_due_at');
            $table->timestamp('action_due_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('action_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('substantiated_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'ack_due_at']);
        });
        Schema::create('care_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained('care_issues')->restrictOnDelete();
            $table->unsignedInteger('level');
            $table->json('recipient_roles');
            $table->string('reason');
            $table->timestamp('created_at');
            $table->unique(['issue_id', 'level']);
        });
        Schema::create('care_support_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained('care_issues')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type');
            $table->string('stage');
            $table->string('status')->default('REQUESTED');
            $table->boolean('emergency')->default(false);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->text('details');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('care_reserve_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_id')->unique()->constrained('care_support_requests')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reference')->unique();
            $table->timestamp('created_at');
        });
        Schema::create('care_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('type');
            $table->string('rule_version');
            $table->text('payload');
            $table->string('fingerprint', 64)->unique();
            $table->timestamp('created_at');
            $table->index(['candidate_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['care_snapshots', 'care_reserve_transactions', 'care_support_requests', 'care_escalations', 'care_issues', 'care_events', 'care_journey_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
