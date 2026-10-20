<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('position_name');
            $table->unsignedInteger('quota')->default(0);
            $table->decimal('salary', 18, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('contract_duration')->nullable();
            $table->longText('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
