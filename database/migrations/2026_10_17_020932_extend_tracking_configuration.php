<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_destinations', function (Blueprint $table): void {
            $table->string('environment')->default('PRODUCTION');
            $table->string('consent_category')->default('MARKETING');
            $table->json('allowed_domains')->nullable();
            $table->text('notes')->nullable();
            $table->text('encrypted_secret')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('tracking_event_mappings', function (Blueprint $table): void {
            $table->string('conversion_label')->nullable();
        });
        Schema::create('tracking_client_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('canonical_tracking_events')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('tracking_destinations')->restrictOnDelete();
            $table->string('provider_event');
            $table->string('status')->default('QUEUED_CLIENT');
            $table->timestamp('created_at');
            $table->unique(['event_id', 'destination_id']);
        });
        foreach (DB::table('crm_roles')->get() as $role) {
            $permissions = json_decode($role->permissions, true) ?? [];
            $extra = config('operations.role_permissions.'.$role->code, []);
            if ($role->code === 'migration_owner') {
                $extra = config('operations.permissions');
            }
            if (in_array('tracking.destination.manage', $permissions, true)) {
                $extra = array_merge($extra, ['tracking.destination.read', 'tracking.destination.create', 'tracking.destination.update', 'tracking.destination.test']);
            }
            if (in_array('tracking.eventmap.manage', $permissions, true)) {
                $extra = array_merge($extra, ['tracking.event_map.manage', 'funnel.tracking.read', 'funnel.tracking.update']);
            }
            DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique(array_merge($permissions, $extra))))]);
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_client_deliveries');
        Schema::table('tracking_event_mappings', fn (Blueprint $table) => $table->dropColumn('conversion_label'));
        Schema::table('tracking_destinations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['environment', 'consent_category', 'allowed_domains', 'notes', 'encrypted_secret', 'last_verified_at']);
        });
    }
};
