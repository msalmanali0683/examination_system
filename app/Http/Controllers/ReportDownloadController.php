<?php

namespace App\Http\Controllers;

use App\Exports\DutySheetExport;
use App\Exports\MasterDatesheetExport;
use App\Exports\SeatingChartExport;
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

    private function filename(ExamSession $examSession, string $report, string $extension): string
    {
        return Str::slug($examSession->name).'-'.Str::slug($report).'.'.$extension;
    }
}
