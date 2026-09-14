<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use App\Models\TimeSlot;
use Livewire\Component;

class TimeSlots extends Component
{
    public ExamSession $examSession;

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?string $date = null;

    public ?string $start_time = null;

    public ?string $end_time = null;

    public string $label = '';

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_sessions');
        $this->examSession = $examSession;
    }

    public function addSlot(): void
    {
        $this->authorize('manage_sessions');
        $this->resetForm();
        $this->showForm = true;
    }

    public function editSlot(int $id): void
    {
        $this->authorize('manage_sessions');
        $slot = $this->examSession->timeSlots()->findOrFail($id);

        $this->editingId = $slot->id;
        $this->date = $slot->date->toDateString();
        $this->start_time = substr($slot->start_time, 0, 5);
        $this->end_time = substr($slot->end_time, 0, 5);
        $this->label = (string) $slot->label;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage_sessions');

        $validated = $this->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
        $validated['label'] = $validated['label'] ?: null;

        $this->examSession->timeSlots()->updateOrCreate(['id' => $this->editingId], $validated);

        $this->resetForm();
        $this->showForm = false;
    }

    public function deleteSlot(int $id): void
    {
        $this->authorize('manage_sessions');
        $this->examSession->timeSlots()->findOrFail($id)->delete();
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'date', 'start_time', 'end_time', 'label']);
    }

    public function render()
    {
        return view('livewire.sessions.time-slots', [
            'slots' => $this->examSession->timeSlots()->orderBy('date')->orderBy('start_time')->get(),
        ]);
    }
}
