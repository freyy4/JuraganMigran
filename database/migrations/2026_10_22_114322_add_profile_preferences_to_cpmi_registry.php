<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            $table->string('nationality')->nullable();
            $table->string('current_job')->nullable();
            $table->string('languages')->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('weight_kg', 5, 1)->nullable();
        });
        Schema::table('candidates', fn (Blueprint $table) => $table->json('preferences')->nullable());
    }

    public function down(): void
    {
        Schema::table('candidates', fn (Blueprint $table) => $table->dropColumn('preferences'));
        Schema::table('persons', fn (Blueprint $table) => $table->dropColumn(['nationality', 'current_job', 'languages', 'height_cm', 'weight_kg']));
    }
};
