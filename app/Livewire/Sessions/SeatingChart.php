<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\ActivityLog;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\TimeSlot;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SeatAllocationService;
use Livewire\Attributes\Layout;
use Livewire\Component;

class SeatingChart extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    public ?int $activeSlotId = null;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('view_reports');
        $this->examSession = $examSession;

        $this->activeSlotId = TimeSlot::where('exam_session_id', $examSession->id)
            ->whereHas('seatAssignments')
            ->orderBy('date')
            ->orderBy('start_time')
            ->value('id');
    }

    public function selectSlot(int $slotId): void
    {
        $this->authorize('view_reports');
        $this->activeSlotId = $slotId;
    }

    /**
     * Manual drag-drop move: validates the target is a real, active room
     * for this session, in bounds, and free, then relocates the seat and
     * locks it so the next "Generate Seating" run (which only touches
     * unlocked seats) leaves this placement alone.
     */
    public function moveSeat(int $enrollmentId, int $roomId, int $row, int $column): void
    {
        $this->authorize('edit_assignments');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $seat = SeatAssignment::where('exam_session_id', $this->examSession->id)
            ->where('enrollment_id', $enrollmentId)
            ->first();

        if (! $seat) {
            session()->flash('error', 'That seat no longer exists — the board may be out of date.');

            return;
        }

        if ($seat->is_locked) {
            session()->flash('error', 'That seat is locked — unlock it first.');

            return;
        }

        $room = $this->examSession->rooms()->wherePivot('is_active', true)->where('rooms.id', $roomId)->first();

        if (! $room) {
            session()->flash('error', 'That room is not active for this session.');

            return;
        }

        if ($row < 1 || $row > $room->rows || $column < 1 || $column > $room->columns) {
            session()->flash('error', 'That seat is outside the room\'s grid.');

            return;
        }

        $occupied = SeatAssignment::where('exam_session_id', $this->examSession->id)
            ->where('time_slot_id', $seat->time_slot_id)
            ->where('room_id', $roomId)
            ->where('row_number', $row)
            ->where('column_number', $column)
            ->exists();

        if ($occupied) {
            session()->flash('error', 'That seat is already taken.');

            return;
        }

        $seat->update([
            'room_id' => $roomId,
            'row_number' => $row,
            'column_number' => $column,
            'is_locked' => true,
        ]);

        session()->flash('status', "Seat moved to {$room->name} — locked so it won't move on the next regeneration.");
    }

    public function toggleLock(int $seatAssignmentId): void
    {
        $this->authorize('edit_assignments');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $seat = SeatAssignment::where('exam_session_id', $this->examSession->id)->find($seatAssignmentId);

        if ($seat) {
            $seat->update(['is_locked' => ! $seat->is_locked]);
        }
    }

    /**
     * Handles both the very first generation (nothing seated yet, shown
     * as the empty state's own button) and every later regeneration
     * (locked seats always left untouched) — one action either way,
     * since SeatAllocationService::generate() is already safe to call
     * repeatedly. The pre-flight checks (timetable exists, capacity is
     * met) only matter the first time; once seats exist those questions
     * are already answered by the fact seating succeeded before.
     */
    public function regenerate(): void
    {
        $this->authorize('generate_roster');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $hasSlots = $this->examSession->subjectSlotAssignments()->whereNotNull('time_slot_id')->exists();

        if (! $hasSlots) {
            session()->flash('error', 'Generate the timetable first — seating needs subjects assigned to slots.');

            return;
        }

        if (! (new RequirementCalculator)->isFullyMet($this->examSession)) {
            session()->flash('error', 'Not enough active rooms for one or more slots, or an unresolved clash remains — see the Capacity Check page before generating.');

            return;
        }

        $result = (new SeatAllocationService)->generate($this->examSession);

        if ($this->activeSlotId === null) {
            $this->activeSlotId = TimeSlot::where('exam_session_id', $this->examSession->id)
                ->whereHas('seatAssignments')
                ->orderBy('date')
                ->orderBy('start_time')
                ->value('id');
        }

        ActivityLog::record($this->examSession, 'seating.generated', $result->warnings->isEmpty()
            ? 'Generated seating for every slot.'
            : "Generated seating with {$result->warnings->count()} warning(s).");

        session()->flash(
            $result->warnings->isEmpty() ? 'status' : 'error',
            $result->warnings->isEmpty()
                ? 'Seating generated — locked seats were left untouched.'
                : "Seating generated with {$result->warnings->count()} warning(s) — locked seats were left untouched."
        );
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $slots = TimeSlot::where('exam_session_id', $this->examSession->id)
            ->whereHas('seatAssignments')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $rooms = collect();

        if ($this->activeSlotId) {
            $seats = SeatAssignment::where('exam_session_id', $this->examSession->id)
                ->where('time_slot_id', $this->activeSlotId)
                ->with(['room', 'enrollment.student', 'enrollment.subject'])
                ->get();

            $capacities = $this->examSession->rooms()
                ->wherePivot('is_active', true)
                ->get()
                ->mapWithKeys(fn ($r) => [$r->id => $r->pivot->capacity_override ?? $r->capacity]);

            $rooms = $seats->groupBy('room_id')->map(function ($roomSeats) use ($capacities) {
                $room = $roomSeats->first()->room;
                $seatsByPosition = $roomSeats->keyBy(fn ($s) => $s->row_number.':'.$s->column_number);

                $grid = [];

                for ($row = 1; $row <= $room->rows; $row++) {
                    for ($col = 1; $col <= $room->columns; $col++) {
                        $grid[$row][$col] = $seatsByPosition->get("{$row}:{$col}");
                    }
                }

                return [
                    'room' => $room,
                    'grid' => $grid,
                    'seatedCount' => $roomSeats->count(),
                    'capacity' => $capacities->get($room->id, $room->capacity),
                ];
            })->sortBy(fn ($r) => $r['room']->name)->values();
        }

        return view('livewire.sessions.seating-chart', [
            'slots' => $slots,
            'rooms' => $rooms,
        ]);
    }
}
