<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_reference_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('resource', 60);
            $table->string('code', 100);
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('default_priority', 30)->default('normal');
            $table->unsignedInteger('sla_hours')->nullable();
            $table->string('default_role', 100)->nullable();
            $table->boolean('system_protected')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['resource', 'code']);
            $table->index(['resource', 'is_active', 'name']);
        });
        foreach (['sponsor' => 'Sponsor', 'employer' => 'Employer', 'placement_partner' => 'Mitra Penempatan', 'lpk' => 'LPK', 'clinic' => 'Klinik', 'agency' => 'Agency', 'other' => 'Lainnya'] as $code => $name) {
            DB::table('master_reference_entries')->insert(['resource' => 'jenis-perusahaan', 'code' => $code, 'name' => $name, 'system_protected' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['posisi-pekerjaan' => ['vacancies', 'position_name'], 'jenis-tugas' => ['tasks', 'task_type'], 'kategori-kendala' => ['application_blockers', 'category']] as $resource => [$table, $field]) {
            foreach (DB::table($table)->whereNotNull($field)->where($field, '!=', '')->distinct()->pluck($field) as $value) {
                $code = strlen($value) <= 100 ? $value : hash('sha256', $value);
                DB::table('master_reference_entries')->insertOrIgnore(['resource' => $resource, 'code' => $code, 'name' => $value, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('master_reference_entries');
    }
};
