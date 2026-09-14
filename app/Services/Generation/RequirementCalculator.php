<?php

namespace App\Services\Generation;

use App\Models\ExamSession;
use App\Models\SessionTeacherConstraint;
use App\Models\Teacher;
use App\Services\Generation\DTOs\SlotRequirement;
use Illuminate\Support\Collection;

/**
 * Answers "do we have enough rooms and teachers for every slot" before the
 * admin commits to generating seating/duties — computed from real data
 * (each room's actual capacity, each teacher's actual availability), not a
 * manual estimate.
 */
class RequirementCalculator
{
    public function __construct(private SeatAllocationService $seatAllocationService = new SeatAllocationService)
    {
    }

    /**
     * @return Collection<int, SlotRequirement>
     */
    public function calculate(ExamSession $session): Collection
    {
        $activePreview = $this->seatAllocationService->preview($session)->keyBy(fn ($p) => $p['slot']->id);
        $allRoomsPreview = $this->seatAllocationService->previewAgainstAllRooms($session)->keyBy(fn ($p) => $p['slot']->id);

        $roomsAvailable = $session->sessionRooms()->where('is_active', true)->count();

        // Every active teacher is available by default; a constraint row
        // only exists where the admin explicitly excluded them or marked
        // specific days unavailable (see TeacherConstraints::apply()).
        $activeTeacherCount = Teacher::where('is_active', true)->count();
        $constraints = SessionTeacherConstraint::where('exam_session_id', $session->id)->get();
        $excludedCount = $constraints->where('is_excluded', true)->count();
        $constrainedNotExcluded = $constraints->where('is_excluded', false);

        return $activePreview->map(function ($active) use ($allRoomsPreview, $roomsAvailable, $activeTeacherCount, $excludedCount, $constrainedNotExcluded, $session) {
            $slot = $active['slot'];
            $unseated = $active['result']->warnings->where('type', 'unseated');
            $studentCount = collect($active['result']->placements)->count() + $unseated->count();

            $roomsNeeded = $allRoomsPreview->get($slot->id)['roomsUsed'] ?? $active['roomsUsed'];

            $unavailableThisDay = $constrainedNotExcluded->filter(fn ($c) => ! $c->isAvailableOn($slot->date))->count();
            $teachersAvailable = $activeTeacherCount - $excludedCount - $unavailableThisDay;

            return new SlotRequirement(
                timeSlotId: $slot->id,
                label: $slot->date->format('d M Y').' '.substr($slot->start_time, 0, 5),
                studentCount: $studentCount,
                roomsNeeded: $roomsNeeded,
                roomsAvailable: $roomsAvailable,
                teachersNeeded: $roomsNeeded * $session->invigilators_per_room,
                teachersAvailable: max(0, $teachersAvailable),
                hasUnseatedStudents: $unseated->isNotEmpty(),
            );
        })->values();
    }

    public function isFullyMet(ExamSession $session): bool
    {
        return $this->calculate($session)->every(fn (SlotRequirement $r) => $r->isMet());
    }
}
