<?php

namespace App\Livewire\Sessions;

use App\Livewire\Concerns\GuardsFinalizedSession;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use Livewire\Component;

class StudentLookup extends Component
{
    use GuardsFinalizedSession;

    public ExamSession $examSession;

    public string $query = '';

    public function mount(ExamSession $examSession): void
    {
        $this->authorize('view_reports');
        $this->examSession = $examSession;
    }

    /**
     * Removes one bad enrollment (e.g. a student who dropped the course)
     * without wiping and re-importing the whole session's enrollments —
     * the only other way to remove one is Show::resetEnrollments(), which
     * takes every enrollment with it. Cascades to the student's own seat
     * assignment for this subject (enrollments.id is
     * seat_assignments.enrollment_id's cascadeOnDelete()) but leaves
     * every other student's seating/duties for this subject/slot alone,
     * unlike the bulk reset which has to clear those wholesale.
     */
    public function removeEnrollment(int $enrollmentId): void
    {
        $this->authorize('manage_enrollments');

        if ($this->blockedByFinalization($this->examSession)) {
            return;
        }

        $enrollment = Enrollment::where('exam_session_id', $this->examSession->id)->find($enrollmentId);

        if (! $enrollment) {
            session()->flash('error', 'That enrollment no longer exists — the list may be out of date.');

            return;
        }

        session()->flash('status', "Removed {$enrollment->student->name}'s enrollment in {$enrollment->subject->code}.");

        $enrollment->delete();
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
