<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\TrustCommitmentProtocol;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncTrustCommitmentProtocol extends Command
{
    protected $signature = 'protocol:sync {--dry-run : Count applications awaiting a safe Shadow mapping}';

    protected $description = 'Map existing applications into Shadow mode without changing their pipeline';

    public function handle(TrustCommitmentProtocol $protocol): int
    {
        if (! $protocol->available()) {
            return self::SUCCESS;
        }
        $query = Application::withoutGlobalScopes()->whereNull('deleted_at')->whereNotIn('status', ['closed', 'cancelled', 'withdrawn', 'rejected', 'completed', 'departed'])->whereNotIn('id', DB::table('protocol_runs')->select('application_id'));
        $this->info($query->count().' applications awaiting Shadow mapping.');
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        $query = Application::withoutGlobalScopes()->whereNull('deleted_at')->whereNotIn('status', ['closed', 'cancelled', 'withdrawn', 'rejected', 'completed', 'departed']);
        $query->orderBy('id')->chunkById(100, function ($applications) use ($protocol): void {
            foreach ($applications as $application) {
                $protocol->initialize($application, true);
                if ($medical = $application->medicalEvents()->latest('id')->first()) {
                    $protocol->medicalSaved($medical);
                }
                foreach ($application->trainingEnrollments as $enrollment) {
                    $protocol->trainingSaved($enrollment);
                }
            }
        });
        $this->info('Shadow mapping complete. Existing pipeline and medical facts preserved.');

        return self::SUCCESS;
    }
}
