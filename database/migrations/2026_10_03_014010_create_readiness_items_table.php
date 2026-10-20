<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readiness_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('readiness_checklist_id')->constrained('readiness_checklists')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_required')->default(true);
            $table->string('status')->default('pending')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['readiness_checklist_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readiness_items');
    }
};
