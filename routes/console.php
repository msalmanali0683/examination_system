<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:cleanup-stale-imports')->daily();

/**
 * Shared hosting has no persistent worker daemon, so the queue is drained
 * this way instead: every minute, process whatever's waiting (report
 * generation — see App\Jobs\GenerateReportFile) and exit as soon as it's
 * empty. --max-time keeps a run from overlapping into the next minute's
 * tick under normal load; withoutOverlapping() is the hard guarantee if a
 * single large report still runs long. This still requires the host to
 * actually trigger `php artisan schedule:run` once a minute (a cron job) —
 * see the deploy notes.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1')
    ->everyMinute()
    ->withoutOverlapping();
