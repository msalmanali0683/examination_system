<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SlotCapacitySimulator;
use Livewire\Component;

class CapacityCheck extends Component
{
    public ExamSession $examSession;

    public bool $showCurrent = false;

    /**
     * How many subjects sit together in one hypothetical slot, and how
     * many such slots run per day — used to simulate "if N papers were
     * held at once, how many rooms/teachers would that take" directly
     * from enrollment data, independent of any real timetable. Every
     * section of a subject is always kept in the same simulated slot,
     * matching how the real timetable always keeps a subject's sections
     * together.
     */
    public int $subjectsPerSlot = 2;

    public int $slotsPerDay = 2;

    public bool $showSlotSimulation = false;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('generate_roster');
        $this->examSession = $examSession;
    }

    public function checkCurrent(): void
    {
        $this->authorize('generate_roster');
        $this->showCurrent = true;
    }

    public function simulateSlots(): void
    {
        $this->authorize('generate_roster');
        $this->validate([
            'subjectsPerSlot' => ['required', 'integer', 'min:1'],
            'slotsPerDay' => ['required', 'integer', 'min:1'],
        ]);
        $this->showSlotSimulation = true;
    }

    public function render()
    {
        $slotRequirements = $this->showSlotSimulation
            ? (new SlotCapacitySimulator)->simulate($this->examSession, $this->subjectsPerSlot)
            : collect();

        return view('livewire.sessions.capacity-check', [
            'currentRequirements' => $this->showCurrent ? (new RequirementCalculator)->calculate($this->examSession) : collect(),
            'slotRequirements' => $slotRequirements,
            'daysNeeded' => $slotRequirements->isEmpty() ? 0 : (int) ceil($slotRequirements->count() / $this->slotsPerDay),
            'peakRoomsNeeded' => $slotRequirements->max('roomsNeeded') ?? 0,
            'peakTeachersNeeded' => $slotRequirements->max('teachersNeeded') ?? 0,
        ]);
    }
}
