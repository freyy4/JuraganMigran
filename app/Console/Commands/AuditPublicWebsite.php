<?php

namespace App\Console\Commands;

use App\Services\WebsiteHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPublicWebsite extends Command
{
    protected $signature = 'site:audit';

    protected $description = 'Periksa kualitas konten, SEO, dan publikasi lowongan website';

    public function handle(WebsiteHealth $health): int
    {
        $report = $health->report();
        DB::table('website_health_snapshots')->insert(['path' => '/', 'source' => 'content_audit', 'metrics' => json_encode(['issues' => $report['issues'], 'published_vacancies' => $report['published']]), 'created_at' => now(), 'updated_at' => now()]);
        $this->info('Audit selesai: '.count($report['issues']).' masalah konten/publikasi.');

        return self::SUCCESS;
    }
}
