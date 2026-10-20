<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('component');
            $table->text('basis');
            $table->string('agreement_reference')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('balance', 18, 2);
            $table->date('due_date')->nullable()->index();
            $table->string('status')->default('open')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_obligations');
    }
};
