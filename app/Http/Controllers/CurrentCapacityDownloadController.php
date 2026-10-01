<?php

namespace App\Http\Controllers;

use App\Exports\CurrentCapacityCheckExport;
use App\Models\ExamSession;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SlotCapacitySimulator;
use App\Services\Reports\ReportDataBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads for the Check Capacity page's "Current Strategy" card — unlike
 * CapacitySimulationDownloadController (a what-if preview with no fixed
 * data), this reflects the session's real, already-generated timetable and
 * its own saved seating strategy, so it takes no min/max/strategy query
 * params. Built fresh on every request, never cached, since (like the
 * simulation download) it's a capacity preview rather than committed
 * seating/duty data.
 */
class CurrentCapacityDownloadController extends Controller
{
    public function excel(ExamSession $examSession): Response
    {
        Gate::authorize('generate_roster');

        [$subjectRequirements, $slotRequirements] = $this->data($examSession);

        return Excel::download(
            new CurrentCapacityCheckExport($examSession, $subjectRequirements, $slotRequirements),
            $this->filename($examSession, 'xlsx')
        );
    }

    public function pdf(ExamSession $examSession): Response
    {
        Gate::authorize('generate_roster');

        [$subjectRequirements, $slotRequirements] = $this->data($examSession);

        return Pdf::loadView('reports.current-capacity-check-pdf', [
            'session' => $examSession,
            'subjectRequirements' => $subjectRequirements,
            'slotRequirements' => $slotRequirements,
            'datesheetRowsByDate' => (new ReportDataBuilder)->simpleDatesheetRowsByDate($examSession),
            'strategyLabel' => ExamSession::SEATING_STRATEGIES[$examSession->seating_strategy] ?? $examSession->seating_strategy,
        ])->setPaper('a4', 'portrait')->download($this->filename($examSession, 'pdf'));
    }

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function data(ExamSession $examSession): array
    {
        return [
            (new SlotCapacitySimulator)->subjectRequirements($examSession),
            (new RequirementCalculator)->calculate($examSession),
        ];
    }

    private function filename(ExamSession $examSession, string $extension): string
    {
        return Str::slug($examSession->name).'-capacity-check.'.$extension;
    }
}
