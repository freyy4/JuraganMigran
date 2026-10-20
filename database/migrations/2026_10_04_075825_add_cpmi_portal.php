<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('sensitive_class')->default('DOCUMENT');
        });
        foreach (DB::table('document_types')->get(['id', 'code', 'name']) as $type) {
            $label = strtolower($type->code.' '.$type->name);
            $class = preg_match('/mcu|medical|kesehatan|health/', $label) ? 'HEALTH' : (preg_match('/ktp|nik|passport|paspor|kartu keluarga|akta/', $label) ? 'IDENTITY' : 'DOCUMENT');
            DB::table('document_types')->where('id', $type->id)->update(['sensitive_class' => $class]);
        }
        Schema::create('portal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('normalized_phone', 30)->unique();
            $table->string('status')->default('invited');
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('consent_version')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
        });
        Schema::create('portal_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->boolean('contact_verified')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('cpmi_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('SUBMITTED');
            $table->json('payload');
            $table->text('public_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
        });
        Schema::create('portal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('title');
            $table->text('body_safe');
            $table->string('deep_link');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->uuid('portal_upload_key')->nullable()->unique();
        });
        Schema::table('portal_accounts', function (Blueprint $table) {
            $table->boolean('in_app_notifications')->default(true);
            $table->boolean('whatsapp_notifications')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('sensitive_class');
        });
        Schema::table('candidate_documents', function (Blueprint $table) {
            $table->dropColumn('portal_upload_key');
        });
        foreach (['portal_notifications', 'cpmi_requests', 'portal_invitations', 'portal_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
