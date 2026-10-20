<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('type')->default('general');
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('meeting_link')->nullable();
            $table->string('status')->default('scheduled')->index();
            $table->string('outcome')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
