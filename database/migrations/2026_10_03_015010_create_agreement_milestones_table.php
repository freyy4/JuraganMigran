<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained('commercial_agreements')->cascadeOnDelete();
            $table->string('name');
            $table->string('trigger_type')->index();
            $table->foreignId('trigger_stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->decimal('percentage', 8, 4)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('IDR');
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_milestones');
    }
};
