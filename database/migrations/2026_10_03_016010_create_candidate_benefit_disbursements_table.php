<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_benefit_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('benefit_plan_id')->constrained('candidate_benefit_plans')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('purpose')->nullable();
            $table->timestamp('disbursed_at')->nullable()->index();
            $table->string('recipient')->nullable();
            $table->text('evidence_file')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_benefit_disbursements');
    }
};
