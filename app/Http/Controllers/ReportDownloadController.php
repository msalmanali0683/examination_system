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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ReportDownloadController extends Controller
{
    public function seatingChartExcel(ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');

        return Excel::download(new SeatingChartExport($examSession), $this->filename($examSession, 'Seating-Chart', 'xlsx'));
    }

    public function seatingChartPdf(ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');

        $charts = (new ReportDataBuilder)->seatingCharts($examSession);

        return Pdf::loadView('reports.seating-chart-pdf', ['charts' => $charts, 'session' => $examSession])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($examSession, 'Seating-Chart', 'pdf'));
    }

    public function datesheetExcel(ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');

        return Excel::download(new MasterDatesheetExport($examSession), $this->filename($examSession, 'Datesheet', 'xlsx'));
    }

    public function datesheetPdf(ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');

        $rowsByDate = (new ReportDataBuilder)->datesheetRowsByDate($examSession);

        return Pdf::loadView('reports.master-datesheet-pdf', ['rowsByDate' => $rowsByDate, 'session' => $examSession])
            ->setPaper('a4', 'landscape')
            ->download($this->filename($examSession, 'Datesheet', 'pdf'));
    }

    public function dutySheetExcel(ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');

        return Excel::download(new DutySheetExport($examSession), $this->filename($examSession, 'Duty-Roster', 'xlsx'));
    }

    public function dutySheetPdf(ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');

        $teacherGroups = (new ReportDataBuilder)->dutyRowsByTeacher($examSession);

        return Pdf::loadView('reports.duty-sheet-pdf', ['teacherGroups' => $teacherGroups, 'session' => $examSession])
            ->setPaper('a4', 'portrait')
            ->download($this->filename($examSession, 'Duty-Roster', 'pdf'));
    }

    public function subjectWiseSeatingExcel(ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');

        return Excel::download(new SubjectWiseSeatingExport($examSession), $this->filename($examSession, 'Subject-wise-Seating', 'xlsx'));
    }

    public function subjectWiseSeatingPdf(ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');

        $subjects = (new ReportDataBuilder)->subjectWiseSeatingRows($examSession);

        return Pdf::loadView('reports.subject-wise-seating-pdf', ['subjects' => $subjects, 'session' => $examSession])
            ->setPaper('a4', 'portrait')
            ->download($this->filename($examSession, 'Subject-wise-Seating', 'pdf'));
    }

    public function batchScheduleExcel(ExamSession $examSession): BinaryFileResponse
    {
        Gate::authorize('view_reports');

        return Excel::download(new BatchScheduleExport($examSession), $this->filename($examSession, 'Batch-Schedule', 'xlsx'));
    }

    public function batchSchedulePdf(ExamSession $examSession): Response
    {
        Gate::authorize('view_reports');

        $sections = (new ReportDataBuilder)->batchScheduleRows($examSession);

        return Pdf::loadView('reports.batch-schedule-pdf', ['sections' => $sections, 'session' => $examSession])
            ->setPaper('a4', 'portrait')
            ->download($this->filename($examSession, 'Batch-Schedule', 'pdf'));
    }

    private function filename(ExamSession $examSession, string $report, string $extension): string
    {
        return Str::slug($examSession->name).'-'.Str::slug($report).'.'.$extension;
    }
}
