<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use App\Services\Generation\DTOs\SlotRequirement;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SlotCapacitySimulator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class CapacityCheck extends Component
{
    public ExamSession $examSession;

    public bool $showCurrent = false;

    /**
     * How many simulated slots run per day — used only to turn the
     * auto-grouped slot count into a day count; it has no effect on how
     * subjects are grouped (see SlotCapacitySimulator).
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
            ]);
        } catch (ValidationException $e) {
            $this->dispatch('open-modal', 'capacity-check-error');

            throw $e;
        }

        $min = $this->minSubjectsPerSlot === '' ? null : (int) $this->minSubjectsPerSlot;
        $max = $this->maxSubjectsPerSlot === '' ? null : (int) $this->maxSubjectsPerSlot;

        $this->slotRequirementsData = (new SlotCapacitySimulator)->simulate($this->examSession, $min, $max)
            ->map(fn (SlotRequirement $r) => get_object_vars($r))
            ->all();
        $this->showSlotSimulation = true;
    }

    public function render()
    {
        $slotRequirements = collect($this->slotRequirementsData)->map(fn (array $data) => new SlotRequirement(...$data));

        return view('livewire.sessions.capacity-check', [
            'currentRequirements' => $this->showCurrent ? (new RequirementCalculator)->calculate($this->examSession) : collect(),
            'slotRequirements' => $slotRequirements,
            'daysNeeded' => ($slotRequirements->isEmpty() || $this->slotsPerDay < 1) ? 0 : (int) ceil($slotRequirements->count() / $this->slotsPerDay),
            'peakRoomsNeeded' => $slotRequirements->max('roomsNeeded') ?? 0,
            'peakTeachersNeeded' => $slotRequirements->max('teachersNeeded') ?? 0,
        ]);
    }
}
