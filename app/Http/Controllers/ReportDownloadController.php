<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportFile;
use App\Models\ExamSession;
use App\Models\ReportFile;
use App\Services\Reports\ReportFileCache;
use App\Services\Reports\ReportFileGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportDownloadController extends Controller
{
    public function __construct(
        private readonly ReportFileGenerator $generator = new ReportFileGenerator,
        private readonly ReportFileCache $cache = new ReportFileCache,
    ) {}

    public function seatingChartExcel(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'seating-chart.xlsx', 'seatingChartExcel', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Seating-Chart', 'xlsx');
    }

    public function seatingChartPdf(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'seating-chart.pdf', 'seatingChartPdf', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Seating-Chart', 'pdf');
    }

    public function datesheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'datesheet.xlsx', 'datesheetExcel', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Datesheet', 'xlsx');
    }

    public function datesheetPdf(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'datesheet.pdf', 'datesheetPdf', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Datesheet', 'pdf');
    }

    /**
     * A minimal, student-facing datesheet: date, day, subject and slot
     * only — no room, section or invigilator detail.
     */
    public function simpleDatesheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'simple-datesheet.xlsx', 'simpleDatesheetExcel', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Simple-Datesheet', 'xlsx');
    }

    public function simpleDatesheetPdf(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'simple-datesheet.pdf', 'simpleDatesheetPdf', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Simple-Datesheet', 'pdf');
    }

    /**
     * Same underlying data as the Master Datesheet, laid out wide instead
     * of flat — one row per subject per slot, with up to several
     * {room, count, invigilator} triples on that row — matching the
     * department's own "Formatted Datesheet" template. No PDF version:
     * the room columns are as wide as the busiest slot needs, which
     * doesn't paginate sensibly on a printed page.
     */
    public function formattedDatesheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'formatted-datesheet.xlsx', 'formattedDatesheetExcel', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Formatted-Datesheet', 'xlsx');
    }

    public function dutySheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'duty-roster.xlsx', 'dutySheetExcel', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showRoomSubject', $this->showRoomSubject($request), 'Duty-Roster', 'xlsx');
    }

    public function dutySheetPdf(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'duty-roster.pdf', 'dutySheetPdf', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showRoomSubject', $this->showRoomSubject($request), 'Duty-Roster', 'pdf');
    }

    public function subjectWiseSeatingExcel(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'subject-wise-seating.xlsx', 'subjectWiseSeatingExcel', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Subject-wise-Seating', 'xlsx');
    }

    public function subjectWiseSeatingPdf(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'subject-wise-seating.pdf', 'subjectWiseSeatingPdf', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Subject-wise-Seating', 'pdf');
    }

    public function batchScheduleExcel(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'batch-schedule.xlsx', 'batchScheduleExcel', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Batch-Schedule', 'xlsx');
    }

    public function batchSchedulePdf(Request $request, ExamSession $examSession): BinaryFileResponse|RedirectResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->resolve($examSession, 'batch-schedule.pdf', 'batchSchedulePdf', $date,
            $this->filterTimeSlotIds($request, $examSession), 'showInvigilators', $this->showInvigilators($request), 'Batch-Schedule', 'pdf');
    }

    /**
     * Serves the report straight away if it's already cached on disk.
     * Otherwise queues a background build (see App\Jobs\GenerateReportFile)
     * — never generating inline here, since a large session's report can
     * take well over a minute and risk a timeout — and, if the queue
     * happens to run synchronously (e.g. in tests), immediately serves the
     * file that just produced. If it's genuinely still in flight, redirects
     * back with a status message instead of leaving the request hanging.
     */
    private function resolve(ExamSession $examSession, string $reportKey, string $method, ?string $date, ?array $timeSlotIds, string $flagKey, bool $flagValue, string $report, string $extension): BinaryFileResponse|RedirectResponse
    {
        $filters = $this->generator->normalizeFilters($date, $timeSlotIds, [$flagKey => $flagValue]);

        $file = $this->cache->find($examSession, $reportKey, $filters);

        if (! $file) {
            [, $shouldDispatch] = $this->cache->enqueue($examSession, $reportKey, $filters);

            if ($shouldDispatch) {
                GenerateReportFile::dispatch($examSession->id, $reportKey, $filters, $method, $date, $timeSlotIds, $flagValue, false);
                GenerateReportFile::spawnBackgroundDrain();
            }

            $file = $this->cache->find($examSession, $reportKey, $filters);
        }

        if (! $file) {
            return redirect()->route('sessions.show', $examSession)
                ->with('status', "Generating the {$report} report in the background \u{2014} this can take a minute or two for a large session. This page will update automatically.");
        }

        return $this->serve($examSession, $report, $extension, $date, $file);
    }

    /**
     * Streams an already-generated report file straight from disk under a
     * human-friendly download name — the file itself lives under a hashed
     * internal path (see ReportFileCache).
     */
    private function serve(ExamSession $examSession, string $report, string $extension, ?string $date, ReportFile $file): BinaryFileResponse
    {
        return response()->download(
            Storage::disk('local')->path($file->disk_path),
            $this->filename($examSession, $report, $extension, $date)
        );
    }

    /**
     * Reads the optional ?date=Y-m-d query filter, ignoring it unless it's
     * both a valid date and an actual exam date for this session — an
     * invalid or stale value just falls back to reporting on every date
     * rather than erroring.
     */
    private function filterDate(Request $request, ExamSession $examSession): ?string
    {
        $date = $request->query('date');

        if (! $date || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return $examSession->timeSlots()->whereDate('date', $date)->exists() ? $date : null;
    }

    /**
     * Reads the optional ?slots=1,2,3 query filter (comma-separated
     * time_slot ids) — only IDs that actually belong to this session are
     * kept, so a stale or tampered value can only narrow the report, never
     * error or leak another session's slots. An empty result after
     * filtering falls back to null (every slot), same as an invalid date.
     *
     * @return int[]|null
     */
    private function filterTimeSlotIds(Request $request, ExamSession $examSession): ?array
    {
        $raw = $request->query('slots');

        if (! $raw) {
            return null;
        }

        $ids = array_filter(array_map('intval', explode(',', $raw)));

        if (empty($ids)) {
            return null;
        }

        $valid = $examSession->timeSlots()->whereIn('id', $ids)->pluck('id')->all();

        return empty($valid) ? null : $valid;
    }

    /**
     * Defaults to true (current behaviour) unless explicitly turned off
     * with ?show_invigilators=0 — used by every report except the Duty
     * Roster, which exists specifically to show invigilators.
     */
    private function showInvigilators(Request $request): bool
    {
        return $request->query('show_invigilators', '1') !== '0';
    }

    /**
     * Duty Roster only — whether the Room / Subject(s) columns print,
     * defaulting to true (current behaviour) unless turned off with
     * ?show_room_subject=0.
     */
    private function showRoomSubject(Request $request): bool
    {
        return $request->query('show_room_subject', '1') !== '0';
    }

    private function filename(ExamSession $examSession, string $report, string $extension, ?string $date = null): string
    {
        $suffix = $date ? '-'.$date : '';

        return Str::slug($examSession->name).'-'.Str::slug($report).$suffix.'.'.$extension;
    }
}
