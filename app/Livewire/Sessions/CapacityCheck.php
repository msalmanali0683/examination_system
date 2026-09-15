<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\Strategies\MixedSeatingStrategy;
use Livewire\Component;

class CapacityCheck extends Component
{
    public ExamSession $examSession;

    public bool $showCurrent = false;

    /**
     * The "what if I combined N subjects per room" number the admin is
     * experimenting with — independent of the session's actual saved
     * seating strategy, so checking it never changes real settings.
     */
    public int $whatIfSubjectsPerRoom = 2;

    public bool $showWhatIf = false;

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

    public function checkWhatIf(): void
    {
        $this->authorize('generate_roster');
        $this->validate([
            'whatIfSubjectsPerRoom' => ['required', 'integer', 'min:2', 'max:50'],
        ]);
        $this->showWhatIf = true;
    }

    public function render()
    {
        $calculator = new RequirementCalculator;

        return view('livewire.sessions.capacity-check', [
            'currentRequirements' => $this->showCurrent ? $calculator->calculate($this->examSession) : collect(),
            'whatIfRequirements' => $this->showWhatIf
                ? $calculator->calculate($this->examSession, new MixedSeatingStrategy($this->whatIfSubjectsPerRoom))
                : collect(),
        ]);
    }
}
