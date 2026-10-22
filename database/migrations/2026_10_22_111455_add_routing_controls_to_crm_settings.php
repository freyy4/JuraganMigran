<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $table): void {
            $table->boolean('routing_enabled')->default(true);
            $table->foreignId('fallback_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('crm_agent_profiles', function (Blueprint $table): void {
            $table->boolean('routing_enabled')->default(true);
            $table->json('routing_tag_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_agent_profiles', function (Blueprint $table): void {
            $table->dropColumn(['routing_enabled', 'routing_tag_ids']);
        });
        Schema::table('whatsapp_channels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fallback_user_id');
            $table->dropColumn('routing_enabled');
        });
    }
};
