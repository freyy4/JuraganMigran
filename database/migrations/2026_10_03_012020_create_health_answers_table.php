<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_interview_id')->constrained('health_interviews')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('health_questions')->restrictOnDelete();
            $table->text('answer')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['health_interview_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_answers');
    }
};
