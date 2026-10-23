<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('auth_version')->default(0);
        });
        Schema::create('auth_factors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('secret');
            $table->json('recovery_hashes')->nullable();
            $table->unsignedBigInteger('last_counter')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('auth_challenges', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('audience', 10);
            $table->string('purpose', 30);
            $table->string('session_binding', 64);
            $table->string('code_hash', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('auth_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->string('user_agent', 255);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'token_hash']);
        });
        Schema::create('auth_session_records', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_hash', 64)->index();
            $table->string('device_label', 255);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('authenticated_at');
            $table->timestamp('last_active_at');
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::create('auth_security_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->index();
        });
        Schema::create('auth_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('audience', 10);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['auth_reset_tokens', 'auth_security_notifications', 'auth_session_records', 'auth_devices', 'auth_challenges', 'auth_factors'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('auth_version'));
    }
};
