<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_qualification_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('vacancy_requirement_id')->constrained('vacancy_requirements')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->string('result')->default('not_checked')->index();
            $table->text('reason')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'vacancy_requirement_id'], 'application_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_qualification_results');
    }
};
