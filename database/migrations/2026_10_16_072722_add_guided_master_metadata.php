<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->string('region', 100)->nullable();
            $table->string('flag', 16)->nullable();
        });
        Schema::table('persons', function (Blueprint $table): void {
            $table->string('marital_status', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->dropColumn(['region', 'flag']);
        });
        Schema::table('persons', function (Blueprint $table): void {
            $table->dropColumn('marital_status');
        });
    }
};
