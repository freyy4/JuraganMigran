<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_journey_templates', function (Blueprint $table): void {
            $table->json('policy')->nullable();
        });
        Schema::create('care_document_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_document_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('checked_by')->constrained('users')->restrictOnDelete();
            $table->json('checks');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_document_checks');
        Schema::table('care_journey_templates', fn (Blueprint $table) => $table->dropColumn('policy'));
    }
};
