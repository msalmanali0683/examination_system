<?php

namespace App\Exports;

use App\Models\ExamSession;
use App\Services\Generation\SlotCapacitySimulator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithTitle;

class CapacitySimulationExport implements FromView, WithTitle
{
    public function __construct(
        private readonly ExamSession $session,
        private readonly Collection $subjectRequirements,
        private readonly Collection $slotRequirements,
        private readonly ?int $min = null,
        private readonly ?int $max = null,
        private readonly ?string $strategyKey = null,
    ) {}

    public function view(): View
    {
        return view('reports.capacity-simulation', [
            'session' => $this->session,
            'subjectRequirements' => $this->subjectRequirements,
            'slotRequirements' => $this->slotRequirements,
            'datesheetRowsByDay' => SlotCapacitySimulator::datesheetRowsByDay($this->slotRequirements),
            'min' => $this->min,
            'max' => $this->max,
            'strategyLabel' => ExamSession::SEATING_STRATEGIES[$this->strategyKey] ?? $this->strategyKey,
        ]);
    }

    public function title(): string
    {
        return 'Capacity Simulation';
    }
}
