<?php

namespace App\Services\Generation;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use App\Services\Generation\DTOs\SeatingResult;
use App\Services\Generation\DTOs\SlotRequirement;
use App\Services\Generation\Strategies\StrictSeatingStrategy;
use Illuminate\Support\Collection;

/**
 * Automatically groups every enrolled subject into as few simultaneous
 * slots as possible — directly from enrollment data, so it works as soon
 * as enrollments are uploaded, before any real timetable or subject-slot
 * assignment exists. Subjects are packed largest-first into the first
 * slot that can take them: never one that shares a student with anything
 * already in that slot (a real clash, exactly like the real Timetable
 * Generator avoids), and never one that would leave a student unseated
 * given the session's actual active rooms. A slot only grows as long as
 * both hold; once nothing else fits, a new slot opens. Every section of a
 * subject always lands in the same slot (a subject's paper is always one
 * slot in this app).
 */
class SlotCapacitySimulator
{
    private StrictSeatingStrategy $strategy;

    public function __construct()
    {
        $this->strategy = new StrictSeatingStrategy;
    }

    /**
     * @return Collection<int, SlotRequirement>
     */
    public function simulate(ExamSession $session): Collection
    {
        $enrollments = Enrollment::where('exam_session_id', $session->id)
            ->select('id', 'student_id', 'subject_id', 'section')
            ->get();

        if ($enrollments->isEmpty()) {
            return collect();
        }

        // Indexed by subject_id so every capacity/clash check below is a
        // handful of array lookups instead of re-scanning every enrollment
        // in the session — this runs dozens of times per subject while
        // packing, so that difference is the gap between instant and slow.
        $enrollmentsBySubject = $enrollments->groupBy('subject_id');

        $subjectIds = $enrollmentsBySubject
            ->sortByDesc(fn (Collection $rows) => $rows->count())
            ->keys()
            ->all();

        $conflictGraph = (new ConflictGraphBuilder)->build($enrollments);
        $roomTemplate = $this->activeRoomTemplate($session);
        $roomsAvailable = count($roomTemplate);
        $teachersAvailable = $this->teachersAvailable($session);

        $bins = $this->packIntoBins($subjectIds, $conflictGraph, $enrollmentsBySubject, $roomTemplate);

        return collect($bins)->values()->map(function (array $subjectIdsInSlot, int $index) use ($enrollmentsBySubject, $roomTemplate, $roomsAvailable, $teachersAvailable, $session) {
            $result = $this->allocate($enrollmentsBySubject, $subjectIdsInSlot, $roomTemplate);
            $unseated = $result->warnings->where('type', 'unseated');
            $studentCount = collect($result->placements)->count() + $unseated->count();

            // roomsUsed only counts rooms that actually received a student,
            // capped by however many rooms exist — if a lone subject alone
            // exceeds total active capacity, that cap can coincidentally
            // equal roomsAvailable. Never let that read as "no shortfall".
            $roomsNeeded = collect($result->placements)->pluck('roomId')->unique()->count();
            if ($unseated->isNotEmpty() && $roomsNeeded <= $roomsAvailable) {
                $roomsNeeded = $roomsAvailable + 1;
            }

            return new SlotRequirement(
                timeSlotId: $index + 1,
                label: 'Simulated Slot '.($index + 1).' ('.count($subjectIdsInSlot).' '.str('subject')->plural(count($subjectIdsInSlot)).')',
                studentCount: $studentCount,
                roomsNeeded: $roomsNeeded,
                roomsAvailable: $roomsAvailable,
                teachersNeeded: $roomsNeeded * $session->invigilators_per_room,
                teachersAvailable: $teachersAvailable,
                hasUnseatedStudents: $unseated->isNotEmpty(),
            );
        });
    }

    /**
     * @param  int[]  $subjectIds  largest-first
     * @param  array<int, array<int, int>>  $conflictGraph
     * @param  Collection<int, Collection>  $enrollmentsBySubject
     * @param  array<int, array{room_id: int, rows: int, columns: int, capacity: int, occupied: array}>  $roomTemplate
     * @return array<int, int[]>
     */
    private function packIntoBins(array $subjectIds, array $conflictGraph, Collection $enrollmentsBySubject, array $roomTemplate): array
    {
        $bins = [];

        foreach ($subjectIds as $subjectId) {
            $placed = false;

            foreach ($bins as $index => $binSubjectIds) {
                if ($this->clashes($conflictGraph, $subjectId, $binSubjectIds)) {
                    continue;
                }

                $candidate = [...$binSubjectIds, $subjectId];

                if ($this->allocate($enrollmentsBySubject, $candidate, $roomTemplate)->warnings->where('type', 'unseated')->isEmpty()) {
                    $bins[$index][] = $subjectId;
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $bins[] = [$subjectId];
            }
        }

        return $bins;
    }

    /**
     * @param  array<int, array<int, int>>  $graph
     * @param  int[]  $binSubjectIds
     */
    private function clashes(array $graph, int $subjectId, array $binSubjectIds): bool
    {
        foreach ($binSubjectIds as $existingId) {
            if (($graph[$subjectId][$existingId] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Collection>  $enrollmentsBySubject
     * @param  int[]  $subjectIds
     * @param  array<int, array{room_id: int, rows: int, columns: int, capacity: int, occupied: array}>  $roomTemplate
     */
    private function allocate(Collection $enrollmentsBySubject, array $subjectIds, array $roomTemplate): SeatingResult
    {
        $subset = collect($subjectIds)->flatMap(fn ($id) => $enrollmentsBySubject->get($id) ?? collect());

        return $this->strategy->allocate($subset, $roomTemplate);
    }

    /**
     * Pre-sorted (largest capacity first) with a fresh, empty "occupied"
     * list baked in — every allocate() call below reuses this same array
     * read-only (PHP arrays copy on write, so nothing leaks between the
     * dozens of independent simulations run while packing).
     *
     * @return array<int, array{room_id: int, rows: int, columns: int, capacity: int, occupied: array}>
     */
    private function activeRoomTemplate(ExamSession $session): array
    {
        return $session->sessionRooms()->where('is_active', true)->with('room')->get()
            ->map(fn ($sr) => [
                'room_id' => $sr->room_id,
                'rows' => $sr->room->rows,
                'columns' => $sr->room->columns,
                'capacity' => $sr->effectiveCapacity(),
                'occupied' => [],
            ])
            ->sortByDesc('capacity')
            ->values()
            ->all();
    }

    /**
     * A teacher counts as available to invigilate a simulated slot unless
     * this session has explicitly excluded them, or capped their max
     * duties at zero (the same effect as exclusion, just expressed via the
     * min/max fields on the Teacher Constraints screen instead of the
     * checkbox). Day-specific unavailability doesn't apply here since a
     * simulated slot has no real date to check it against.
     */
    private function teachersAvailable(ExamSession $session): int
    {
        $unavailableTeacherIds = SessionTeacherConstraint::where('exam_session_id', $session->id)
            ->get()
            ->filter(fn (SessionTeacherConstraint $c) => $c->is_excluded || $c->effectiveMaxDuties() <= 0)
            ->pluck('teacher_id');

        return Teacher::where('is_active', true)
            ->whereNotIn('id', $unavailableTeacherIds)
            ->count();
    }
}
