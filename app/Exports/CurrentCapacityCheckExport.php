<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Reports\ReportDataBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class CurrentCapacityCheckExport implements FromView, WithTitle
{
    public function __construct(
        private readonly ExamSession $session,
        private readonly Collection $subjectRequirements,
        private readonly Collection $slotRequirements,
    ) {}

    public function view(): View
    {
        return view('reports.current-capacity-check', [
            'session' => $this->session,
            'subjectRequirements' => $this->subjectRequirements,
            'slotRequirements' => $this->slotRequirements,
            'datesheetRowsByDate' => (new ReportDataBuilder)->simpleDatesheetRowsByDate($this->session),
            'strategyLabel' => ExamSession::SEATING_STRATEGIES[$this->session->seating_strategy] ?? $this->session->seating_strategy,
        ]);
    }

    public function title(): string
    {
        return 'Capacity Check';
    }
}
