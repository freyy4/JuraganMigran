<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_countries', function (Blueprint $table): void {
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->primary(['company_id', 'country_id']);
        });
        DB::table('companies')->whereNotNull('country_id')->orderBy('id')->chunkById(500, function ($companies): void {
            DB::table('company_countries')->insert($companies->map(fn ($company) => ['company_id' => $company->id, 'country_id' => $company->country_id])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_countries');
    }
};
