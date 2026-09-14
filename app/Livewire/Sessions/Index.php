<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Index extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public ?string $start_date = null;

    public ?string $end_date = null;

    public function mount(): void
    {
        $this->authorize('manage_sessions');
    }

    public function addSession(): void
    {
        $this->authorize('manage_sessions');
        $this->reset(['name', 'start_date', 'end_date']);
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage_sessions');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $session = ExamSession::create($validated);

        $this->redirect(route('sessions.show', $session), navigate: true);
    }

    public function cancel(): void
    {
        $this->reset(['name', 'start_date', 'end_date']);
        $this->showForm = false;
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.sessions.index', [
            'sessions' => ExamSession::orderByDesc('start_date')->get(),
        ]);
    }
}
