<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('interview_questions')->restrictOnDelete();
            $table->longText('answer')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['interview_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_answers');
    }
};
