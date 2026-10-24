<?php

namespace App\Console\Commands;

use App\Models\Candidate;
use App\Services\CareJourney;
use App\Services\TrustCommitmentProtocol;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorCareJourney extends Command
{
    protected $signature = 'care:monitor';

    protected $description = 'Normalize journey events and escalate unresolved welfare incidents';

    public function handle(CareJourney $care): int
    {
        if (! config('care.enabled')) {
            return self::SUCCESS;
        }
        DB::table('care_issues')->whereNotIn('status', ['RESOLVED', 'CLOSED'])->orderBy('id')->chunkById(100, function ($rows) use ($care): void {
            foreach ($rows as $row) {
                $care->monitorIssue($row->id);
            }
        });
        Candidate::withoutGlobalScopes()->whereNull('deleted_at')->orderBy('id')->chunkById(100, function ($candidates) use ($care): void {
            foreach ($candidates as $candidate) {
                $care->sync($candidate);
            }
        });
        app(TrustCommitmentProtocol::class)->monitor();
        $this->info('Journey events synchronized; unresolved incident SLA checked.');

        return self::SUCCESS;
    }
}
