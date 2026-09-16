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
     * can lag by minutes after a fresh cron entry is saved. This is the
     * belt-and-suspenders fix: right after dispatching a report job, the
     * caller also calls this, which spawns a genuinely separate, detached
     * OS process to drain the queue immediately — proc_open() with a
     * backgrounded shell command returns in a few milliseconds regardless
     * of how long the spawned process takes.
     *
     * Deliberately NOT Laravel's dispatch(...)->afterResponse(): that only
     * frees the browser early on servers that support
     * fastcgi_finish_request(). This host's web SAPI (LiteSpeed's lsphp)
     * does not, so afterResponse() silently blocked the whole request
     * until the report finished building — confirmed live, and worse than
     * not having this at all. proc_open() doesn't depend on that; verified
     * against this host's actual web SAPI (not just CLI) before shipping.
     */
    public static function spawnBackgroundDrain(): void
    {
        if (app()->runningUnitTests() || PHP_OS_FAMILY === 'Windows' || ! function_exists('proc_open')) {
            return;
        }

        $logFile = storage_path('logs/queue-drain.log');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $command = 'php artisan queue:work --stop-when-empty --max-time=250 --tries=1 --sleep=0 >> '
            .escapeshellarg($logFile).' 2>&1 &';

        $process = @proc_open($command, $descriptors, $pipes, base_path());

        if (! is_resource($process)) {
            logger()->warning('GenerateReportFile::spawnBackgroundDrain() — proc_open() did not return a process resource.');

            return;
        }

        fclose($pipes[0]);
        $exitCode = proc_close($process);

        // A non-zero exit here is the wrapping shell itself failing (e.g.
        // unable to fork at all) — the backgrounded job never even
        // started. Not fatal: the next poll tick or the cron schedule
        // gets another chance, but worth knowing about if it keeps
        // happening.
        if ($exitCode !== 0) {
            logger()->warning("GenerateReportFile::spawnBackgroundDrain() — background shell exited with code {$exitCode}, the drain may not have started.");
        }
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
