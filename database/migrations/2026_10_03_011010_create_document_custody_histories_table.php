<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_custody_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custody_id')->constrained('original_document_custodies')->cascadeOnDelete();
            $table->string('action')->index();
            $table->string('from_holder')->nullable();
            $table->string('to_holder')->nullable();
            $table->string('location')->nullable();
            $table->text('evidence_file')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_custody_histories');
    }
};
