<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_assets', function (Blueprint $table): void {
            $table->string('display_name')->nullable();
            $table->string('tags')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('status', 20)->default('active')->index('mw_asset_status');
        });
    }

    public function down(): void
    {
        Schema::table('site_assets', function (Blueprint $table): void {
            $table->dropIndex('mw_asset_status');
            $table->dropColumn(['display_name', 'tags', 'category', 'status']);
        });
    }
};
