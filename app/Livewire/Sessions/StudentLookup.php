<?php

namespace App\Livewire\Sessions;

use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use Livewire\Component;

class StudentLookup extends Component
{
    public ExamSession $examSession;

    public string $query = '';

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('view_reports');
        $this->examSession = $examSession;
    }

    public function render()
    {
        return view('livewire.sessions.student-lookup', [
            'groups' => $this->search(),
        ]);
    }

    private function search()
    {
        $term = trim($this->query);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $enrollments = Enrollment::where('exam_session_id', $this->examSession->id)
            ->whereHas('student', function ($q) use ($term) {
                $q->where('roll_no', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%");
            })
            ->with(['student', 'subject', 'seatAssignment.room', 'seatAssignment.timeSlot'])
            ->get();

        $duties = DutyAssignment::where('exam_session_id', $this->examSession->id)
            ->with('teacher')
            ->get()
            ->groupBy(fn (DutyAssignment $d) => $d->time_slot_id.'-'.$d->room_id)
            ->map(fn ($rows) => $rows->pluck('teacher.name')->implode(' & '));

        return $enrollments
            ->groupBy('student_id')
            ->map(function ($rows) use ($duties) {
                $rows = $rows->map(function (Enrollment $e) use ($duties) {
                    $e->invigilators = $e->seatAssignment
                        ? $duties->get("{$e->seatAssignment->time_slot_id}-{$e->seatAssignment->room_id}", '')
                        : '';

                    return $e;
                })->sortBy(fn (Enrollment $e) => $e->seatAssignment?->timeSlot?->date);

                return (object) [
                    'student' => $rows->first()->student,
                    'enrollments' => $rows->values(),
                ];
            })
            ->sortBy(fn ($group) => $group->student->roll_no)
            ->take(20)
            ->values();
    }
}
