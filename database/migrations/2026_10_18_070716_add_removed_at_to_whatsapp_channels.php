<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $table): void {
            $table->dropColumn('removed_at');
        });
    }
};
