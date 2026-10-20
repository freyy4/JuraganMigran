<?php

namespace App\Console\Commands;

use App\Services\TrackingHub;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchTracking extends Command
{
    protected $signature = 'tracking:dispatch {--limit=50}';

    protected $description = 'Kirim antrean event iklan di luar transaksi pendaftaran';

    public function handle(TrackingHub $hub): int
    {
        $rows = DB::table('tracking_outbox')->whereIn('status', ['PENDING', 'RETRY', 'PROCESSING'])->where('next_attempt_at', '<=', now())->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', now()))->limit(min(200, max(1, (int) $this->option('limit'))))->pluck('id');
        foreach ($rows as $id) {
            $hub->deliver($id);
        }$this->info('Antrean diperiksa: '.$rows->count());

        return self::SUCCESS;
    }
}
