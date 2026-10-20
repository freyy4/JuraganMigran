<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('site:audit')->daily()->withoutOverlapping();

Schedule::command('ops:monitor')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('tracking:dispatch')->everyMinute()->withoutOverlapping();

Schedule::command('crm:dispatch')->everyTenSeconds()->withoutOverlapping();

Schedule::command('whatsapp:qr-health')->everyMinute()->withoutOverlapping();

Schedule::command('crm:process-workspace')->everyMinute()->withoutOverlapping();
