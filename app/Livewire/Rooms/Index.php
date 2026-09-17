<?php

namespace App\Livewire\Rooms;

use App\Models\DutyAssignment;
use App\Models\Room;
use App\Models\SeatAssignment;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public int $perPage = 25;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public ?int $rows = null;

    public ?int $columns = null;

    public ?int $capacity = null;

    public string $room_type = 'regular';

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize('manage_rooms');
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
        $room = Room::findOrFail($id);

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

        $maxCapacity = (int) $this->rows * (int) $this->columns;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('rooms', 'name')->ignore($this->editingId)],
            'rows' => ['required', 'integer', 'min:1'],
            'columns' => ['required', 'integer', 'min:1'],
            'capacity' => ['required', 'integer', 'min:1', "max:{$maxCapacity}"],
            'room_type' => ['required', Rule::in(['regular', 'lab'])],
        ]);
        $validated['is_active'] = $this->is_active;

        if ($this->editingId && $this->shrinksBelowExistingSeats($this->editingId, $validated['rows'], $validated['columns'])) {
            $this->addError('rows', 'This room already has seat assignments outside that grid in a session that isn\'t finalized yet — regenerate or move those seats first, then resize the room.');

            return;
        }

        Room::updateOrCreate(['id' => $this->editingId], $validated);

        $this->resetForm();
        $this->showForm = false;
        session()->flash('status', 'Room saved.');
    }

    /**
     * A smaller grid would silently strand any seat already placed outside
     * it — invisible in the seating chart grid but still counted, which is
     * exactly what happened to session 1's ITC-5xx rooms. Finalized
     * sessions are historical and excluded since they can't be
     * regenerated anyway.
     */
    private function shrinksBelowExistingSeats(int $roomId, int $rows, int $columns): bool
    {
        return SeatAssignment::where('room_id', $roomId)
            ->where(fn ($q) => $q->where('row_number', '>', $rows)->orWhere('column_number', '>', $columns))
            ->whereHas('examSession', fn ($q) => $q->where('status', '!=', 'finalized'))
            ->exists();
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage_rooms');
        $room = Room::findOrFail($id);
        $room->update(['is_active' => ! $room->is_active]);
    }

    /**
     * Deleting a room cascades to every seat/duty assignment that ever
     * used it (both tables' room_id are cascadeOnDelete()), which would
     * silently erase a finalized session's seating chart or duty roster
     * — the one thing a finalized session is supposed to never lose (see
     * ExamSession::deleteSession()). Blocked the same way that guard
     * blocks deleting the session itself; a non-finalized session's data
     * can always be regenerated, so only finalized use blocks this.
     */
    public function deleteRoom(int $id): void
    {
        $this->authorize('manage_rooms');
        $room = Room::findOrFail($id);

        if ($this->usedInFinalizedSession($room)) {
            session()->flash('error', "{$room->name} has seat or duty assignments in a finalized session and can't be deleted — unlock that session first if it really needs to change.");

            return;
        }

        $room->delete();
        session()->flash('status', 'Room deleted.');
    }

    private function usedInFinalizedSession(Room $room): bool
    {
        return SeatAssignment::where('room_id', $room->id)
            ->whereHas('examSession', fn ($q) => $q->where('status', 'finalized'))
            ->exists()
            || DutyAssignment::where('room_id', $room->id)
                ->whereHas('examSession', fn ($q) => $q->where('status', 'finalized'))
                ->exists();
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
            'rooms' => Room::orderBy('name')->paginate($this->perPage),
        ]);
    }
}
