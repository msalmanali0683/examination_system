<?php

namespace App\Jobs;

use App\Models\ExamSession;
use App\Services\Reports\ReportFileCache;
use App\Services\Reports\ReportFileGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Builds one report (see ReportFileGenerator) on the queue instead of
 * inline in the HTTP request. A large session's seating chart PDF can take
 * well over a minute — long enough to hit a web server or browser timeout
 * if built synchronously. The "queued" placeholder row this job fills in
 * is created up front by ReportFileCache::enqueue(), so a request never
 * waits on this job; it just polls the row's status.
 */
class GenerateReportFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $filters
     * @param  int[]|null  $timeSlotIds
     */
    public function __construct(
        public int $examSessionId,
        public string $reportKey,
        public array $filters,
        public string $method,
        public ?string $date,
        public ?array $timeSlotIds,
        public bool $flag,
        public bool $force,
    ) {}

    public function handle(): void
    {
        $session = ExamSession::find($this->examSessionId);
        $cache = new ReportFileCache;

        if (! $session) {
            return;
        }

        $cache->markProcessing($session, $this->reportKey, $this->filters);

        try {
            (new ReportFileGenerator)->{$this->method}($session, $this->date, $this->timeSlotIds, $this->flag, $this->force);
        } catch (Throwable $e) {
            $cache->markFailed($session, $this->reportKey, $this->filters, $e->getMessage());
            report($e);
        }
    }

    public function failed(Throwable $e): void
    {
        $session = ExamSession::find($this->examSessionId);

        if ($session) {
            (new ReportFileCache)->markFailed($session, $this->reportKey, $this->filters, $e->getMessage());
        }
    }

    /**
     * Shared hosting has no persistent queue worker daemon — the schedule
     * in routes/console.php drains the queue once a minute via cron, but
     * that depends on the host actually triggering `schedule:run`, which
     * can lag by minutes after a fresh cron entry is saved (or fail
     * silently). This is the belt-and-suspenders fix: right after
     * dispatching a report job, the caller also calls this, which
     * schedules an immediate drain for the moment after THIS request's
     * response has already been sent to the browser — so the click still
     * returns instantly, but the build starts within the same second
     * instead of waiting for the next cron tick.
     */
    public static function drainQueueAfterResponse(): void
    {
        dispatch(function () {
            static::drainQueueNow();
        })->afterResponse();
    }

    /**
     * Processes whatever's currently waiting in the real queue table and
     * stops — the exact command the cron schedule runs, extracted so it
     * can also be triggered immediately (see drainQueueAfterResponse())
     * or called directly, e.g. in tests.
     */
    public static function drainQueueNow(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 250,
            '--tries' => 1,
            '--sleep' => 0,
        ]);
    }
}
