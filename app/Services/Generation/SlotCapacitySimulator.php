<?php

namespace App\Services\Generation;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Teacher;
use App\Services\Generation\DTOs\SlotRequirement;
use Illuminate\Support\Collection;

/**
 * Answers "if N subjects were examined at the same time, how many rooms
 * and teachers would that slot need" — directly from enrollment data, so
 * it works as soon as enrollments are uploaded, before any real timetable
 * or subject-slot assignment exists. Every subject's students (every
 * section combined — a subject's paper is always one slot in this app)
 * are grouped into batches of $subjectsPerSlot, largest subject first,
 * and each batch is simulated as its own hypothetical slot.
 */
class SlotCapacitySimulator
{
    public function __construct(private SeatAllocationService $seatAllocationService = new SeatAllocationService)
    {
    }

    /**
     * @return Collection<int, SlotRequirement>
     */
    public function simulate(ExamSession $session, int $subjectsPerSlot): Collection
    {
        $subjectSizes = Enrollment::where('exam_session_id', $session->id)
            ->selectRaw('subject_id, COUNT(*) as student_count')
            ->groupBy('subject_id')
            ->orderByDesc('student_count')
            ->pluck('student_count', 'subject_id');

        if ($subjectSizes->isEmpty()) {
            return collect();
        }

        $roomsAvailable = $session->sessionRooms()->where('is_active', true)->count();
        $teachersAvailable = Teacher::where('is_active', true)->count();

        return collect($subjectSizes->keys()->all())
            ->chunk(max(1, $subjectsPerSlot))
            ->values()
            ->map(function (Collection $subjectIds, int $index) use ($session, $roomsAvailable, $teachersAvailable) {
                $preview = $this->seatAllocationService->previewForSubjects($session, $subjectIds->all());
                $unseated = $preview['result']->warnings->where('type', 'unseated');
                $studentCount = collect($preview['result']->placements)->count() + $unseated->count();

                return new SlotRequirement(
                    timeSlotId: $index + 1,
                    label: 'Simulated Slot '.($index + 1).' ('.$subjectIds->count().' '.str('subject')->plural($subjectIds->count()).')',
                    studentCount: $studentCount,
                    roomsNeeded: $preview['roomsUsed'],
                    roomsAvailable: $roomsAvailable,
                    teachersNeeded: $preview['roomsUsed'] * $session->invigilators_per_room,
                    teachersAvailable: $teachersAvailable,
                    hasUnseatedStudents: $unseated->isNotEmpty(),
                );
            });
    }
}
