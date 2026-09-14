<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Show extends Component
{
    public ExamSession $examSession;

    public bool $editingDetails = false;

    public string $name = '';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    public function editDetails(): void
    {
        $this->authorize('manage_sessions');
        $this->name = $this->examSession->name;
        $this->start_date = $this->examSession->start_date->toDateString();
        $this->end_date = $this->examSession->end_date->toDateString();
        $this->editingDetails = true;
    }

    public function saveDetails(): void
    {
        $this->authorize('manage_sessions');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $this->examSession->update($validated);
        $this->editingDetails = false;
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.sessions.show');
    }
}
