<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCrmBulkJob;
use App\Services\ConversationCrm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessCrmWorkspace extends Command
{
    protected $signature = 'crm:process-workspace {--limit=5}';

    protected $description = 'Process durable CRM pipeline bulk operations';

    public function handle(ConversationCrm $crm): int
    {
        foreach (DB::table('crm_bulk_jobs')->where('action', 'export')->where('completed_at', '<', now()->subDay())->whereNotNull('result')->cursor() as $expired) {
            $path = json_decode($expired->result, true)['path'] ?? null;
            if ($path && preg_match('~^crm-exports/[0-9a-f-]+\.(csv|xlsx)$~', $path)) {
                Storage::disk('local')->delete($path);
            }
        }
        foreach (DB::table('crm_bulk_jobs')->whereIn('status', ['QUEUED', 'RUNNING'])->orderBy('created_at')->limit(max(1, min(20, (int) $this->option('limit'))))->pluck('id') as $id) {
            $lock = Cache::lock('crm-bulk:'.$id, 900);
            if (! $lock->get()) {
                continue;
            }
            try {
                (new ProcessCrmBulkJob($id))->handle($crm);
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }
}
