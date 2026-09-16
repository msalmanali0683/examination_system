<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class DutySheetExport implements FromView, WithTitle
{
    /**
     * @param  int[]|null  $timeSlotIds
     */
    public function __construct(
        private readonly ExamSession $session,
        private readonly ?string $date = null,
        private readonly bool $showRoomSubject = true,
        private readonly ?array $timeSlotIds = null
    ) {}

    public function view(): View
    {
        return view('reports.duty-sheet', [
            'teacherGroups' => (new ReportDataBuilder)->dutyRowsByTeacher($this->session, $this->date, $this->timeSlotIds),
            'session' => $this->session,
            'showRoomSubject' => $this->showRoomSubject,
        ]);
    }

    public function title(): string
    {
        return 'Duty Roster';
    }
}
