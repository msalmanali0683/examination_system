<?php

namespace App\Services\Generation;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SeatAssignment;
use App\Models\SubjectSlotAssignment;
use App\Models\TimeSlot;
use App\Services\Generation\DTOs\SeatingResult;
use App\Services\Generation\DTOs\SeatingWarning;
use App\Services\Generation\Strategies\CombineSectionsSeatingStrategy;
use App\Services\Generation\Strategies\MixedSeatingStrategy;
use App\Services\Generation\Strategies\SeatingStrategy;
use App\Services\Generation\Strategies\StrictSeatingStrategy;
use Illuminate\Support\Facades\DB;

class SeatAllocationService
{
    /**
     * Regenerates seating for every time slot in the session, one slot at a
     * time. Already-locked seats (manual drag-drop overrides from the
     * review step) are left untouched and treated as occupied obstacles for
     * everyone else; only unlocked seats are recomputed.
     */
    public function generate(ExamSession $session): SeatingResult
    {
        $strategy = $this->strategyFor($session->seating_strategy);

        $subjectToSlot = SubjectSlotAssignment::where('exam_session_id', $session->id)
            ->whereNotNull('time_slot_id')
            ->pluck('time_slot_id', 'subject_id');

        $sessionRooms = $session->sessionRooms()->where('is_active', true)->with('room')->get();

        $warnings = collect();

        foreach ($session->timeSlots as $slot) {
            $subjectIds = $subjectToSlot->filter(fn ($slotId) => $slotId === $slot->id)->keys();

            if ($subjectIds->isEmpty()) {
                continue;
            }

            $warnings = $warnings->merge($this->generateForSlot($session, $slot, $subjectIds->all(), $sessionRooms, $strategy));
        }

        return new SeatingResult([], $warnings);
    }

    /**
     * @param  int[]  $subjectIds
     * @param  \Illuminate\Support\Collection<int, \App\Models\SessionRoom>  $sessionRooms
     * @return \Illuminate\Support\Collection<int, SeatingWarning>
     */
    private function generateForSlot(ExamSession $session, TimeSlot $slot, array $subjectIds, $sessionRooms, SeatingStrategy $strategy)
    {
        $lockedAssignments = SeatAssignment::where('exam_session_id', $session->id)
            ->where('time_slot_id', $slot->id)
            ->where('is_locked', true)
            ->with('enrollment')
            ->get();

        $lockedEnrollmentIds = $lockedAssignments->pluck('enrollment_id');

        $enrollments = Enrollment::whereIn('enrollments.subject_id', $subjectIds)
            ->where('enrollments.exam_session_id', $session->id)
            ->whereNotIn('enrollments.id', $lockedEnrollmentIds)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->orderBy('students.roll_no')
            ->select('enrollments.id', 'enrollments.subject_id', 'enrollments.section')
            ->get();

        if ($enrollments->isEmpty()) {
            return collect();
        }

        $occupiedByRoom = $lockedAssignments->groupBy('room_id')->map(
            fn ($seats) => $seats->map(fn ($s) => [
                'row' => $s->row_number,
                'column' => $s->column_number,
                'subject_id' => $s->enrollment->subject_id,
            ])->all()
        );

        $rooms = $sessionRooms->map(fn ($sr) => [
            'room_id' => $sr->room_id,
            'rows' => $sr->room->rows,
            'columns' => $sr->room->columns,
            'capacity' => $sr->effectiveCapacity(),
            'occupied' => $occupiedByRoom->get($sr->room_id, []),
        ])->sortByDesc('capacity')->values()->all();

        $result = $strategy->allocate($enrollments, $rooms);

        DB::transaction(function () use ($session, $slot, $lockedEnrollmentIds, $result) {
            SeatAssignment::where('exam_session_id', $session->id)
                ->where('time_slot_id', $slot->id)
                ->whereNotIn('enrollment_id', $lockedEnrollmentIds)
                ->delete();

            foreach ($result->placements as $placement) {
                SeatAssignment::create([
                    'exam_session_id' => $session->id,
                    'enrollment_id' => $placement->enrollmentId,
                    'time_slot_id' => $slot->id,
                    'room_id' => $placement->roomId,
                    'row_number' => $placement->row,
                    'column_number' => $placement->column,
                    'is_locked' => false,
                ]);
            }
        });

        return $result->warnings;
    }

    private function strategyFor(string $seatingStrategy): SeatingStrategy
    {
        return match ($seatingStrategy) {
            'combine_sections' => new CombineSectionsSeatingStrategy,
            'mixed' => new MixedSeatingStrategy,
            default => new StrictSeatingStrategy,
        };
    }
}
