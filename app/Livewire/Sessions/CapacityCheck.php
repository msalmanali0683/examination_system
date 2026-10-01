<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use App\Services\Generation\DTOs\SlotRequirement;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SeatAllocationService;
use App\Services\Generation\SlotCapacitySimulator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class CapacityCheck extends Component
{
    public ExamSession $examSession;

    public bool $showCurrent = false;

    /**
     * How many simulated slots run per day — turns the auto-grouped slot count into a day count, and
     * labels each slot "Day X, Slot Y" accordingly; has no effect on how subjects are grouped (see
     * SlotCapacitySimulator), purely a display concern layered on afterward.
     */
    public int $slotsPerDay = 2;

    /**
     * Optional targets for how many subjects share a simulated slot.
     * Left blank ('') for "no constraint" — plain string properties so an
     * emptied number input doesn't fail Livewire's int-cast hydration;
     * simulateSlots() converts them to nullable ints before simulating.
     */
    public string $minSubjectsPerSlot = '';

    public string $maxSubjectsPerSlot = '';

    /**
     * Which seating strategy to simulate room-packing with — defaults to the session's own saved
     * strategy (the most useful "what if I generated today" starting point), but can be swapped to
     * compare what-ifs without touching the session's real settings. Strict is the most conservative
     * (one room per subject+section); Combine Sections/Mixed/the overflow variants let several groups
     * share a room, which can fit more subjects into fewer simulated slots.
     */
    public string $seatingStrategy = 'strict';

    public int $mixedSubjectsPerRoom = 2;

    public bool $showSlotSimulation = false;

    /**
     * The last computed simulation, as plain arrays (Livewire can't persist
     * arbitrary DTOs across requests). Packing is real work — every active
     * room and every subject pairing gets tried — so it only runs when the
     * admin clicks Simulate, never as a side effect of an unrelated click
     * (e.g. Check Capacity above) triggering a re-render.
     *
     * @var array<int, array>
     */
    public array $slotRequirementsData = [];

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('generate_roster');
        $this->examSession = $examSession;
        $this->seatingStrategy = $examSession->seating_strategy;
        $this->mixedSubjectsPerRoom = $examSession->mixed_subjects_per_room;
    }

    public function checkCurrent(): void
    {
        $this->authorize('generate_roster');
        $this->showCurrent = true;
    }

    public function simulateSlots(): void
    {
        $this->authorize('generate_roster');

        try {
            $this->validate([
                'slotsPerDay' => ['required', 'integer', 'min:1'],
                'minSubjectsPerSlot' => ['nullable', 'integer', 'min:1'],
                'maxSubjectsPerSlot' => [
                    'nullable', 'integer', 'min:1',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if ($value !== null && $value !== '' && $this->minSubjectsPerSlot !== '' && (int) $value < (int) $this->minSubjectsPerSlot) {
                            $fail('The maximum subjects per slot must be at least the minimum.');
                        }
                    },
                ],
                'seatingStrategy' => ['required', Rule::in(array_keys(ExamSession::SEATING_STRATEGIES))],
                'mixedSubjectsPerRoom' => ['required_if:seatingStrategy,mixed', 'integer', 'min:2', 'max:10'],
            ]);
        } catch (ValidationException $e) {
            $this->dispatch('open-modal', 'capacity-check-error');

            throw $e;
        }

        $min = $this->minSubjectsPerSlot === '' ? null : (int) $this->minSubjectsPerSlot;
        $max = $this->maxSubjectsPerSlot === '' ? null : (int) $this->maxSubjectsPerSlot;
        $strategy = (new SeatAllocationService)->strategyFor($this->seatingStrategy, $this->mixedSubjectsPerRoom);

        $this->slotRequirementsData = (new SlotCapacitySimulator($strategy))->simulate($this->examSession, $min, $max)
            ->map(fn (SlotRequirement $r) => get_object_vars($r))
            ->all();
        $this->showSlotSimulation = true;
    }

    /**
     * The query string for this simulation's download links — min/max/strategy (and mixedSubjectsPerRoom
     * when relevant) change simulate()'s grouping and packing; per_day is display-only (it relabels
     * slots "Day X, Slot Y" the same way render() does below) but still passed through so the download
     * matches whatever's currently on screen.
     *
     * @return array<string, int|string>
     */
    public function simulationQuery(): array
    {
        return array_filter([
            'min' => $this->minSubjectsPerSlot !== '' ? (int) $this->minSubjectsPerSlot : null,
            'max' => $this->maxSubjectsPerSlot !== '' ? (int) $this->maxSubjectsPerSlot : null,
            'per_day' => $this->slotsPerDay,
            'strategy' => $this->seatingStrategy,
            'mixed_per_room' => $this->seatingStrategy === 'mixed' ? $this->mixedSubjectsPerRoom : null,
        ], fn ($value) => $value !== null);
    }

    public function render()
    {
        $slotRequirements = collect($this->slotRequirementsData)->map(fn (array $data) => new SlotRequirement(...$data));
        $slotRequirements = SlotCapacitySimulator::withDayAndSlotLabels($slotRequirements, $this->slotsPerDay);

        return view('livewire.sessions.capacity-check', [
            'currentRequirements' => $this->showCurrent ? (new RequirementCalculator)->calculate($this->examSession) : collect(),
            'slotRequirements' => $slotRequirements,
            'daysNeeded' => ($slotRequirements->isEmpty() || $this->slotsPerDay < 1) ? 0 : (int) ceil($slotRequirements->count() / $this->slotsPerDay),
            'peakRoomsNeeded' => $slotRequirements->max('roomsNeeded') ?? 0,
            'peakTeachersNeeded' => $slotRequirements->max('teachersNeeded') ?? 0,
        ]);
    }
}
