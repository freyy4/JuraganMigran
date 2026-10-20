<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsor_receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('agreement_milestone_id')->constrained('agreement_milestones')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('IDR');
            $table->date('due_date')->nullable()->index();
            $table->string('invoice_number')->nullable()->unique();
            $table->string('status')->default('eligible')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsor_receivables');
    }
};
