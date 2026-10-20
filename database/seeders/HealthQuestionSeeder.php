<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HealthQuestionSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('health_questions')->updateOrInsert(['vacancy_id' => null, 'question' => 'Apakah sudah ada hasil pemeriksaan kesehatan dari klinik?'], ['category' => 'Administrasi', 'is_required' => true, 'sequence' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}
