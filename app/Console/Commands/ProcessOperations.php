<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\HolidayEvent;
use App\Services\HolidayEligibility;
use App\Services\MobilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessOperations extends Command
{
    protected $signature = 'ops:monitor';

    protected $description = 'Hitung ulang kelayakan libur dan pengingat kembali LPK';

    public function handle(HolidayEligibility $engine, MobilityService $mobility): int
    {
        foreach (HolidayEvent::where('status', 'ACTIVE')->get() as $holiday) {
            Application::withoutGlobalScope('access')->whereHas('trainingEnrollments', fn ($q) => $q->withoutGlobalScope('access')->whereIn('status', ['ongoing', 'on_hold']))->with('vacancy.jobOrder')->chunkById(100, function ($apps) use ($holiday, $engine): void {
                foreach ($apps as $app) {
                    $result = $engine->evaluate($app, $holiday);
                    DB::table('holiday_eligibilities')->updateOrInsert(['application_id' => $app->id, 'holiday_event_id' => $holiday->id], ['result' => $result['result'], 'snapshot' => json_encode($result), 'evaluated_at' => now()]);
                }
            });
        }
        $this->info('Pengingat / eskalasi baru: '.$mobility->monitor());

        return self::SUCCESS;
    }
}
