<?php

namespace App\Http\Controllers;

use App\Models\ExamSession;
use App\Models\ReportFile;
use App\Services\Reports\ReportFileGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportDownloadController extends Controller
{
    public function __construct(private readonly ReportFileGenerator $generator = new ReportFileGenerator) {}

    public function seatingChartExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Seating-Chart', 'xlsx', $date, $this->generator->seatingChartExcel(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    public function seatingChartPdf(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Seating-Chart', 'pdf', $date, $this->generator->seatingChartPdf(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    public function datesheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Datesheet', 'xlsx', $date, $this->generator->datesheetExcel(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    public function datesheetPdf(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Datesheet', 'pdf', $date, $this->generator->datesheetPdf(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    /**
     * Same underlying data as the Master Datesheet, laid out wide instead
     * of flat — one row per subject per slot, with up to several
     * {room, count, invigilator} triples on that row — matching the
     * department's own "Formatted Datesheet" template. No PDF version:
     * the room columns are as wide as the busiest slot needs, which
     * doesn't paginate sensibly on a printed page.
     */
    public function formattedDatesheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Formatted-Datesheet', 'xlsx', $date, $this->generator->formattedDatesheetExcel(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    public function dutySheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Duty-Roster', 'xlsx', $date, $this->generator->dutySheetExcel(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showRoomSubject($request)
        ));
    }

    public function dutySheetPdf(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Duty-Roster', 'pdf', $date, $this->generator->dutySheetPdf(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showRoomSubject($request)
        ));
    }

    public function subjectWiseSeatingExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Subject-wise-Seating', 'xlsx', $date, $this->generator->subjectWiseSeatingExcel(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    public function subjectWiseSeatingPdf(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Subject-wise-Seating', 'pdf', $date, $this->generator->subjectWiseSeatingPdf(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    public function batchScheduleExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Batch-Schedule', 'xlsx', $date, $this->generator->batchScheduleExcel(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    public function batchSchedulePdf(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return $this->serve($examSession, 'Batch-Schedule', 'pdf', $date, $this->generator->batchSchedulePdf(
            $examSession, $date, $this->filterTimeSlotIds($request, $examSession), $this->showInvigilators($request)
        ));
    }

    /**
     * Streams an already-generated (or just-now-generated) report file
     * straight from disk under a human-friendly download name — the file
     * itself lives under a hashed internal path (see ReportFileCache).
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
