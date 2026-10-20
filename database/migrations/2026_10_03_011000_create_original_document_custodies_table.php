<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('original_document_custodies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_document_id')->constrained('candidate_documents')->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applications')->nullOnDelete();
            $table->string('status')->default('received')->index();
            $table->string('current_holder')->nullable();
            $table->string('current_location')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('original_document_custodies');
    }
};
