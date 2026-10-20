<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->string('requirement_type')->default('required');
            $table->boolean('is_required')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['vacancy_id', 'document_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancy_document_requirements');
    }
};
