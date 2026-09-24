<?php

namespace App\Livewire\Rooms;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A session's own rooms — added, edited, imported and deleted from inside
 * the session, and never shared with any other one.
 */
class Index extends Component
{
    use GuardsFinalizedSession;
    use WithPagination;

    public ExamSession $examSession;

    public int $perPage = 25;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public ?int $rows = null;

    public ?int $columns = null;

    public ?int $capacity = null;

    public string $room_type = 'regular';

    public bool $is_active = true;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('manage_rooms');
        $this->examSession = $examSession;
    }

    public function addRoom(): void
    {
        $this->authorize('manage_rooms');
        $this->resetForm();
        $this->showForm = true;
    }

    public function editRoom(int $id): void
    {
        $this->authorize('manage_rooms');
        $room = $this->examSession->rooms()->findOrFail($id);

        $this->editingId = $room->id;
        $this->name = $room->name;
        $this->rows = $room->rows;
        $this->columns = $room->columns;
        $this->capacity = $room->capacity;
        $this->room_type = $room->room_type;
        $this->is_active = $room->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage_rooms');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $maxCapacity = (int) $this->rows * (int) $this->columns;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('rooms', 'name')->where('exam_session_id', $this->examSession->id)->ignore($this->editingId)],
            'rows' => ['required', 'integer', 'min:1'],
            'columns' => ['required', 'integer', 'min:1'],
            'capacity' => ['required', 'integer', 'min:1', "max:{$maxCapacity}"],
            'room_type' => ['required', Rule::in(['regular', 'lab'])],
        ]);
        $validated['is_active'] = $this->is_active;

        if ($this->editingId && $this->shrinksBelowExistingSeats($this->editingId, $validated['rows'], $validated['columns'])) {
            $this->addError('rows', 'This room already has seat assignments outside that grid — regenerate or move those seats first, then resize the room.');

            return;
        }

        $this->examSession->rooms()->updateOrCreate(['id' => $this->editingId], $validated);

        $this->resetForm();
        $this->showForm = false;
        session()->flash('status', 'Room saved.');
    }

    /**
     * A smaller grid would silently strand any seat already placed outside
     * it — invisible in the seating chart grid but still counted, which is
     * exactly what happened to session 1's ITC-5xx rooms.
     */
    private function shrinksBelowExistingSeats(int $roomId, int $rows, int $columns): bool
    {
        return SeatAssignment::where('room_id', $roomId)
            ->where(fn ($q) => $q->where('row_number', '>', $rows)->orWhere('column_number', '>', $columns))
            ->exists();
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage_rooms');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $room = $this->examSession->rooms()->findOrFail($id);
        $room->update(['is_active' => ! $room->is_active]);
    }

    /**
     * Deleting a room also removes every seat and duty assignment that
     * used it (both tables' room_id are cascadeOnDelete()) — fine while
     * the session can still be regenerated, which is why a finalized
     * session refuses it outright.
     */
    public function deleteRoom(int $id): void
    {
        $this->authorize('manage_rooms');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $this->examSession->rooms()->findOrFail($id)->delete();
        session()->flash('status', 'Room deleted.');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'rows', 'columns', 'capacity', 'room_type', 'is_active']);
        $this->room_type = 'regular';
        $this->is_active = true;
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.rooms.index', [
            'rooms' => $this->examSession->rooms()->orderBy('name')->paginate($this->perPage),
        ]);
    }
}
