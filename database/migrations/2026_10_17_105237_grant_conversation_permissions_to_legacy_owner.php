<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('crm_roles')->where('code', 'migration_owner')->where('active', true)->where('is_admin', true)->where('scope_type', 'OPERATIONAL_ALL')->first();
        if (! $role) {
            return;
        }
        $permissions = json_decode($role->permissions, true) ?? [];
        if (! in_array('admin.integration.manage', $permissions, true)) {
            return;
        }
        DB::table('crm_roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, ...config('conversation.permissions', [])]))), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Preserve permission grants because subsequent administrator changes may depend on them.
    }
};
