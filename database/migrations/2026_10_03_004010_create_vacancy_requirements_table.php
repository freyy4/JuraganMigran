<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->foreignId('requirement_definition_id')->constrained('requirement_definitions')->restrictOnDelete();
            $table->string('operator')->nullable();
            $table->text('value')->nullable();
            $table->decimal('value_min', 15, 4)->nullable();
            $table->decimal('value_max', 15, 4)->nullable();
            $table->boolean('is_required')->default(true);
            $table->text('source_reference')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['vacancy_id', 'requirement_definition_id', 'version'], 'vacancy_req_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_requirements');
    }
};
