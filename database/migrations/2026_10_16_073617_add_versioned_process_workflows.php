<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status', 30)->default('draft');
            $table->json('draft_schema');
            $table->timestamps();
        });
        Schema::create('process_workflow_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_workflow_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->unique(['process_workflow_id', 'version']);
        });
        foreach (['vacancies', 'applications'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('workflow_version_id')->nullable()->constrained('process_workflow_versions')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['applications', 'vacancies'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('workflow_version_id'));
        }
        Schema::dropIfExists('process_workflow_versions');
        Schema::dropIfExists('process_workflows');
    }
};
