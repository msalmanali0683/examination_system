<?php

namespace App\Services\Generation;

use App\Services\Generation\DTOs\DutyPlacement;
use App\Services\Generation\DTOs\DutyResult;
use App\Services\Generation\DTOs\DutyWarning;

/**
 * Greedy lowest-duty-count-first invigilator assignment. Pure and
 * DB-agnostic: everything it needs is passed in as plain arrays so it can
 * be unit-tested without a database.
 */
class DutyFairnessService
{
    /**
     * @param  array<int, array{id: int, roomIds: int[], unavailableTeacherIds: int[]}>  $slots  chronological order
     * @param  array<int, array{id: int, minDuties: int, maxDuties: int}>  $teachers  the eligible pool (active, not excluded from the session)
     * @param  DutyPlacement[]  $lockedPlacements  already-locked duties: counted toward running load, occupy their room/slot as obstacles, and make that teacher unavailable elsewhere in the same slot
     */
    public function generate(array $slots, array $teachers, array $lockedPlacements, int $invigilatorsPerRoom): DutyResult
    {
        $counts = [];

        foreach ($teachers as $teacher) {
            $counts[$teacher['id']] = 0;
        }

        $lockedCountByRoomSlot = [];
        $lockedTeacherBySlot = [];

        foreach ($lockedPlacements as $locked) {
            $counts[$locked->teacherId] = ($counts[$locked->teacherId] ?? 0) + 1;

            $key = $locked->timeSlotId.':'.$locked->roomId;
            $lockedCountByRoomSlot[$key] = ($lockedCountByRoomSlot[$key] ?? 0) + 1;
            $lockedTeacherBySlot[$locked->timeSlotId][$locked->teacherId] = true;
        }

        $placements = [];
        $warnings = collect();

        foreach ($slots as $slot) {
            $usedThisSlot = $lockedTeacherBySlot[$slot['id']] ?? [];
            $unavailable = array_flip($slot['unavailableTeacherIds']);

            foreach ($slot['roomIds'] as $roomId) {
                $key = $slot['id'].':'.$roomId;
                $needed = $invigilatorsPerRoom - ($lockedCountByRoomSlot[$key] ?? 0);

                for ($i = 0; $i < $needed; $i++) {
                    $pick = $this->pickLowest($teachers, $counts, $usedThisSlot, $unavailable);

                    if ($pick === null) {
                        $warnings->push(new DutyWarning(
                            $slot['id'],
                            $roomId,
                            'Not enough eligible teachers to fill every invigilator slot in this room.'
                        ));

                        continue;
                    }

                    $placements[] = new DutyPlacement($pick, $slot['id'], $roomId);
                    $counts[$pick]++;
                    $usedThisSlot[$pick] = true;
                }
            }
        }

        return new DutyResult($placements, $warnings);
    }

    /**
     * @param  array<int, array{id: int, minDuties: int, maxDuties: int}>  $teachers
     * @param  array<int, int>  $counts
     * @param  array<int, bool>  $usedThisSlot
     * @param  array<int, int>  $unavailable
     */
    private function pickLowest(array $teachers, array $counts, array $usedThisSlot, array $unavailable): ?int
    {
        $best = null;
        $bestCount = null;

        foreach ($teachers as $teacher) {
            $id = $teacher['id'];

            if (isset($usedThisSlot[$id]) || isset($unavailable[$id])) {
                continue;
            }

            if ($counts[$id] >= $teacher['maxDuties']) {
                continue;
            }

            if ($bestCount === null || $counts[$id] < $bestCount) {
                $best = $id;
                $bestCount = $counts[$id];
            }
        }

        return $best;
    }
}
