<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sponsor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receivable_id')->constrained('sponsor_receivables')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamp('paid_at')->nullable()->index();
            $table->string('reference_number')->nullable();
            $table->text('evidence_file')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsor_payments');
    }
};
