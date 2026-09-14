<?php

namespace App\Livewire\Sessions;

use App\Models\ExamSession;
use App\Models\SeatAssignment;
use Livewire\Attributes\Layout;
use Livewire\Component;

class SeatingChart extends Component
{
    public ExamSession $examSession;

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('view_reports');
        $this->examSession = $examSession;
    }

    #[Layout('layouts.app')]
    public function render()
    {
        $seats = SeatAssignment::where('exam_session_id', $this->examSession->id)
            ->with(['room', 'timeSlot', 'enrollment.student', 'enrollment.subject'])
            ->get()
            ->sortBy([
                fn ($a, $b) => $a->timeSlot->date <=> $b->timeSlot->date,
                fn ($a, $b) => $a->timeSlot->start_time <=> $b->timeSlot->start_time,
                fn ($a, $b) => $a->room_id <=> $b->room_id,
                fn ($a, $b) => $a->column_number <=> $b->column_number,
                fn ($a, $b) => $a->row_number <=> $b->row_number,
            ]);

        $bySlotAndRoom = $seats
            ->groupBy(fn ($s) => $s->timeSlot->id)
            ->map(fn ($slotSeats) => $slotSeats->groupBy('room_id'));

        return view('livewire.sessions.seating-chart', [
            'bySlotAndRoom' => $bySlotAndRoom,
        ]);
    }
}
