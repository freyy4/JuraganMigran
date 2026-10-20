<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('path');
            $table->string('alt');
            $table->string('mime');
            $table->json('variants')->nullable();
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('site_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('type')->default('page');
            $table->string('status')->default('draft')->index();
            $table->boolean('system')->default(false);
            $table->boolean('in_navigation')->default(false);
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->json('draft_schema');
            $table->timestamps();
        });
        Schema::create('site_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_page_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['site_page_id', 'version']);
        });
        Schema::create('site_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->unique();
            $table->string('target');
            $table->unsignedSmallInteger('status')->default(301);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->foreignId('site_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('funnel_id')->nullable()->constrained('application_funnels')->restrictOnDelete();
            $table->string('sector')->nullable();
            $table->string('hiring_organization')->nullable();
            $table->boolean('hiring_organization_approved')->default(false);
            $table->string('employment_class')->nullable();
            $table->string('minimum_education')->nullable();
            $table->string('salary_period')->default('MONTH');
            $table->decimal('salary_max', 18, 2)->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('public_notes')->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('public_enabled')->default(false);
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('valid_through')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
        });
        Schema::create('site_attribution_touches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('anonymous_session_id')->index();
            $table->foreignId('lead_id')->nullable()->constrained('acquisition_leads')->nullOnDelete();
            $table->foreignId('vacancy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 500);
            $table->string('referrer_host')->nullable();
            $table->string('channel');
            $table->json('context');
            $table->string('device_class');
            $table->timestamps();
        });
        Schema::create('site_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('anonymous_session_id')->index();
            $table->foreignId('vacancy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event')->index();
            $table->string('path', 500);
            $table->json('context');
            $table->boolean('is_test')->default(false);
            $table->timestamps();
        });
        Schema::create('site_contact_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('acquisition_leads')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->text('message');
            $table->string('status')->default('new');
            $table->string('consent_version');
            $table->timestamps();
        });
        Schema::create('website_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 500);
            $table->string('source');
            $table->json('metrics');
            $table->timestamps();
        });
        foreach (config('site.templates') as $slug => $template) {
            DB::table('site_pages')->insert(['slug' => $slug, 'title' => $template['title'], 'type' => $template['type'] ?? 'page', 'system' => true, 'in_navigation' => $template['navigation'] ?? false, 'sequence' => $template['sequence'] ?? 0, 'draft_schema' => json_encode($template['schema']), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('crm_roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?? [];
            $add = match ($role->code) {
                'migration_owner' => ['site.read', 'site.edit', 'site.publish', 'site.globals', 'site.assets', 'site.health'],
                'super_admin' => ['site.read', 'site.edit', 'site.publish', 'site.globals', 'site.assets', 'site.health'],
                'admin_operations' => ['site.read', 'site.edit', 'site.assets'],
                'marketing' => ['site.read', 'site.edit', 'site.assets'],
                'management' => ['site.read'],
                default => [],
            };
            if ($add) {
                DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...$add])))]);
            }
        }
    }

    public function down(): void
    {
        foreach (['website_health_snapshots', 'site_contact_requests', 'site_events', 'site_attribution_touches'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('vacancies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_asset_id');
            $table->dropConstrainedForeignId('funnel_id');
            $table->dropColumn(['sector', 'hiring_organization', 'hiring_organization_approved', 'employment_class', 'minimum_education', 'salary_period', 'salary_max', 'responsibilities', 'public_notes', 'featured', 'public_enabled', 'publish_at', 'valid_through', 'seo_title', 'seo_description']);
        });
        foreach (['site_redirects', 'site_page_versions', 'site_pages', 'site_assets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
