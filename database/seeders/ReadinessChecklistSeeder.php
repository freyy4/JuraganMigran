<?php

namespace Database\Seeders;

use App\Models\Application;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReadinessChecklistSeeder extends Seeder
{
    public function run(): void
    {
        Application::orderBy('id')->each(function (Application $application): void {
            DB::transaction(function () use ($application): void {
                $application->newQuery()->whereKey($application->id)->lockForUpdate()->firstOrFail();
                if ($application->readinessChecklists()->where('type', 'departure')->exists()) {
                    return;
                }
                $checklist = $application->readinessChecklists()->create(['type' => 'departure']);
                foreach (['passport' => 'Paspor', 'visa' => 'Visa', 'agreement' => 'Perjanjian', 'ticket' => 'Tiket', 'opp' => 'OPP', 'original_documents' => 'Dokumen Asli'] as $code => $name) {
                    $checklist->items()->create(['code' => $code, 'name' => $name, 'is_required' => true, 'status' => 'pending']);
                }
            });
        });
    }
}
