<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_assets', fn (Blueprint $table) => $table->string('sha256', 64)->nullable()->index('mw_asset_hash'));
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->foreignId('funnel_id')->nullable()->constrained('application_funnels')->restrictOnDelete();
            $table->json('workspace_content')->nullable();
        });
        Schema::table('lead_sources', function (Blueprint $table): void {
            $table->index(['captured_at', 'id'], 'mw_source_date');
            $table->index(['candidate_id', 'captured_at'], 'mw_source_candidate');
            $table->index(['campaign_id', 'captured_at'], 'mw_source_campaign');
        });
        Schema::table('site_attribution_touches', function (Blueprint $table): void {
            $table->index(['created_at', 'anonymous_session_id'], 'mw_site_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('site_assets', function (Blueprint $table): void {
            $table->dropIndex('mw_asset_hash');
            $table->dropColumn('sha256');
        });
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('funnel_id');
            $table->dropColumn('workspace_content');
        });
        Schema::table('lead_sources', function (Blueprint $table): void {
            foreach (['mw_source_date', 'mw_source_candidate', 'mw_source_campaign'] as $index) {
                $table->dropIndex($index);
            }
        });
        Schema::table('site_attribution_touches', fn (Blueprint $table) => $table->dropIndex('mw_site_sessions'));
    }
};
