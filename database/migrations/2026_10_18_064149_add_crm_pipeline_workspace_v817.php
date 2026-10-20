<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_bulk_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->constrained('users');
            $table->uuid('idempotency_key');
            $table->string('action');
            $table->json('selection');
            $table->json('payload');
            $table->string('status')->default('QUEUED');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['requested_by', 'idempotency_key']);
        });
        Schema::create('crm_card_layouts', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->json('fields');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_card_layouts');
        Schema::dropIfExists('crm_bulk_jobs');
    }
};
