<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('permissions');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('crm_role_id')->nullable()->constrained('crm_roles')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('crm_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('crm_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('endpoint');
            $table->text('secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_integrations');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('crm_settings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_role_id');
            $table->dropColumn('is_active');
        });
        Schema::dropIfExists('crm_roles');
    }
};
