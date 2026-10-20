<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_obligation_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_obligation_id')->constrained('financial_obligations')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamp('paid_at')->nullable()->index();
            $table->string('reference')->nullable();
            $table->text('evidence_file')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_obligation_payments');
    }
};
