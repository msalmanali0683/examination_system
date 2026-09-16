<?php

namespace App\Http\Controllers;

use App\Exports\BatchScheduleExport;
use App\Exports\DutySheetExport;
use App\Exports\FormattedDatesheetExport;
use App\Exports\MasterDatesheetExport;
use App\Exports\SeatingChartExport;
use App\Exports\SubjectWiseSeatingExport;
use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportDownloadController extends Controller
{
    public function seatingChartExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);
        $showInvigilators = $this->showInvigilators($request);

        return Excel::download(new SeatingChartExport($examSession, $date, $showInvigilators, $timeSlotIds), $this->filename($examSession, 'Seating-Chart', 'xlsx', $date));
    }

    public function seatingChartPdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        $charts = (new ReportDataBuilder)->seatingCharts($examSession, $date, $timeSlotIds);

        return Pdf::loadView('reports.seating-chart-pdf', ['charts' => $charts, 'session' => $examSession, 'showInvigilators' => $this->showInvigilators($request)])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($examSession, 'Seating-Chart', 'pdf', $date));
    }

    public function datesheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        return Excel::download(new MasterDatesheetExport($examSession, $date, $this->showInvigilators($request), $timeSlotIds), $this->filename($examSession, 'Datesheet', 'xlsx', $date));
    }

    public function datesheetPdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        $rowsByDate = (new ReportDataBuilder)->datesheetRowsByDate($examSession, $date, $timeSlotIds);

        return Pdf::loadView('reports.master-datesheet-pdf', ['rowsByDate' => $rowsByDate, 'session' => $examSession, 'showInvigilators' => $this->showInvigilators($request)])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($examSession, 'Datesheet', 'pdf', $date));
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
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        return Excel::download(new FormattedDatesheetExport($examSession, $date, $this->showInvigilators($request), $timeSlotIds), $this->filename($examSession, 'Formatted-Datesheet', 'xlsx', $date));
    }

    public function dutySheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        return Excel::download(new DutySheetExport($examSession, $date, $this->showRoomSubject($request), $timeSlotIds), $this->filename($examSession, 'Duty-Roster', 'xlsx', $date));
    }

    public function dutySheetPdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        $teacherGroups = (new ReportDataBuilder)->dutyRowsByTeacher($examSession, $date, $timeSlotIds);

        return Pdf::loadView('reports.duty-sheet-pdf', ['teacherGroups' => $teacherGroups, 'session' => $examSession, 'showRoomSubject' => $this->showRoomSubject($request)])
            ->setPaper('a4', 'portrait')
            ->download($this->filename($examSession, 'Duty-Roster', 'pdf', $date));
    }

    public function subjectWiseSeatingExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        return Excel::download(new SubjectWiseSeatingExport($examSession, $date, $this->showInvigilators($request), $timeSlotIds), $this->filename($examSession, 'Subject-wise-Seating', 'xlsx', $date));
    }

    public function subjectWiseSeatingPdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        $subjects = (new ReportDataBuilder)->subjectWiseSeatingRows($examSession, $date, $timeSlotIds);

        return Pdf::loadView('reports.subject-wise-seating-pdf', ['subjects' => $subjects, 'session' => $examSession, 'showInvigilators' => $this->showInvigilators($request)])
            ->setPaper('a4', 'portrait')
            ->download($this->filename($examSession, 'Subject-wise-Seating', 'pdf', $date));
    }

    public function batchScheduleExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        return Excel::download(new BatchScheduleExport($examSession, $date, $this->showInvigilators($request), $timeSlotIds), $this->filename($examSession, 'Batch-Schedule', 'xlsx', $date));
    }

    public function batchSchedulePdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);
        $timeSlotIds = $this->filterTimeSlotIds($request, $examSession);

        $sections = (new ReportDataBuilder)->batchScheduleRows($examSession, $date, $timeSlotIds);

        return Pdf::loadView('reports.batch-schedule-pdf', ['sections' => $sections, 'session' => $examSession, 'showInvigilators' => $this->showInvigilators($request)])
            ->setPaper('a4', 'portrait')
            ->download($this->filename($examSession, 'Batch-Schedule', 'pdf', $date));
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
