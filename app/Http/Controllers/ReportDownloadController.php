<?php

namespace App\Http\Controllers;

use App\Exports\BatchScheduleExport;
use App\Exports\DutySheetExport;
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
        $showInvigilators = $this->showInvigilators($request);

        return Excel::download(new SeatingChartExport($examSession, $date, $showInvigilators), $this->filename($examSession, 'Seating-Chart', 'xlsx', $date));
    }

    public function seatingChartPdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        $charts = (new ReportDataBuilder)->seatingCharts($examSession, $date);

        return Pdf::loadView('reports.seating-chart-pdf', ['charts' => $charts, 'session' => $examSession, 'showInvigilators' => $this->showInvigilators($request)])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($examSession, 'Seating-Chart', 'pdf', $date));
    }

    public function datesheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return Excel::download(new MasterDatesheetExport($examSession, $date, $this->showInvigilators($request)), $this->filename($examSession, 'Datesheet', 'xlsx', $date));
    }

    public function datesheetPdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        $rowsByDate = (new ReportDataBuilder)->datesheetRowsByDate($examSession, $date);

        return Pdf::loadView('reports.master-datesheet-pdf', ['rowsByDate' => $rowsByDate, 'session' => $examSession, 'showInvigilators' => $this->showInvigilators($request)])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($examSession, 'Datesheet', 'pdf', $date));
    }

    public function dutySheetExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return Excel::download(new DutySheetExport($examSession, $date), $this->filename($examSession, 'Duty-Roster', 'xlsx', $date));
    }

    public function dutySheetPdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        $teacherGroups = (new ReportDataBuilder)->dutyRowsByTeacher($examSession, $date);

        return Pdf::loadView('reports.duty-sheet-pdf', ['teacherGroups' => $teacherGroups, 'session' => $examSession])
            ->setPaper('a4', 'portrait')
            ->download($this->filename($examSession, 'Duty-Roster', 'pdf', $date));
    }

    public function subjectWiseSeatingExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return Excel::download(new SubjectWiseSeatingExport($examSession, $date, $this->showInvigilators($request)), $this->filename($examSession, 'Subject-wise-Seating', 'xlsx', $date));
    }

    public function subjectWiseSeatingPdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        $subjects = (new ReportDataBuilder)->subjectWiseSeatingRows($examSession, $date);

        return Pdf::loadView('reports.subject-wise-seating-pdf', ['subjects' => $subjects, 'session' => $examSession, 'showInvigilators' => $this->showInvigilators($request)])
            ->setPaper('a4', 'portrait')
            ->download($this->filename($examSession, 'Subject-wise-Seating', 'pdf', $date));
    }

    public function batchScheduleExcel(Request $request, ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        return Excel::download(new BatchScheduleExport($examSession, $date, $this->showInvigilators($request)), $this->filename($examSession, 'Batch-Schedule', 'xlsx', $date));
    }

    public function batchSchedulePdf(Request $request, ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');
        $date = $this->filterDate($request, $examSession);

        $sections = (new ReportDataBuilder)->batchScheduleRows($examSession, $date);

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
     * Defaults to true (current behaviour) unless explicitly turned off
     * with ?show_invigilators=0 — used by every report except the Duty
     * Roster, which exists specifically to show invigilators.
     */
    private function showInvigilators(Request $request): bool
    {
        return $request->query('show_invigilators', '1') !== '0';
    }

    private function filename(ExamSession $examSession, string $report, string $extension, ?string $date = null): string
    {
        $suffix = $date ? '-'.$date : '';

        return Str::slug($examSession->name).'-'.Str::slug($report).$suffix.'.'.$extension;
    }
}
