<?php

namespace App\Livewire\Sessions;

use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Models\SubjectSlotAssignment;
use App\Services\Reports\ReportCatalog;
use Livewire\Component;

/**
 * The Reports tab's menu — one card per report type, linking to its own
 * filter-and-generate page (see ReportShow) instead of exposing every
 * report's filters and download buttons on a single shared panel. Keeps
 * only what's needed to decide whether a report type is reachable yet
 * (its seating/duties/timetable data must exist first).
 */
class ReportDownloads extends Component
{
    public ExamSession $examSession;

    public function render()
    {
        $hasSeating = SeatAssignment::where('exam_session_id', $this->examSession->id)->exists();
        $hasDuties = DutyAssignment::where('exam_session_id', $this->examSession->id)->exists();
        $hasTimetable = SubjectSlotAssignment::where('exam_session_id', $this->examSession->id)->whereNotNull('time_slot_id')->exists();

        $reportTypes = collect(ReportCatalog::TYPES)->map(fn ($config, $type) => [
            ...$config,
            'type' => $type,
            'available' => match ($config['gate']) {
                'duties' => $hasDuties,
                'timetable' => $hasTimetable,
                default => $hasSeating,
            },
        ]);

        return view('livewire.sessions.report-downloads', [
            'reportTypes' => $reportTypes,
        ]);
    }
}
