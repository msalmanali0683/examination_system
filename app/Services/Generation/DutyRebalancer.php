<?php

namespace App\Services\Generation;

use App\Services\Generation\DTOs\DutyPlacement;
use App\Services\Generation\DTOs\DutyResult;
use App\Services\Generation\DTOs\DutyWarning;

/**
 * Bounded best-effort pass over an already-greedy DutyResult: for teachers
 * left below their session minimum, tries to swap one of their eligible
 * slots away from a teacher who is comfortably above their own minimum —
 * without ever pushing anyone below their min or above their max. Not
 * guaranteed to fully resolve every shortfall (a teacher excluded from
 * most slots, or with too few rooms to go around, may never reach their
 * minimum); anything still unresolved becomes a warning, never an
 * exception.
 */
class DutyRebalancer
{
    private const MAX_PASSES = 3;

    /**
     * @param  array<int, array{id: int, minDuties: int, maxDuties: int}>  $teachers
     * @param  array<int, array{id: int, roomIds: int[], unavailableTeacherIds: int[]}>  $slots
     * @param  DutyPlacement[]  $lockedPlacements
     */
    public function rebalance(DutyResult $result, array $teachers, array $slots, array $lockedPlacements): DutyResult
    {
        $placements = $result->placements;
        $teachersById = collect($teachers)->keyBy('id');
        $counts = $this->countsFor($teachers, $placements, $lockedPlacements);
        $slotUnavailable = [];

        foreach ($slots as $slot) {
            $slotUnavailable[$slot['id']] = array_flip($slot['unavailableTeacherIds']);
        }

        $lockedTeacherBySlot = [];

        foreach ($lockedPlacements as $locked) {
            $lockedTeacherBySlot[$locked->timeSlotId][$locked->teacherId] = true;
        }

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $needyIds = collect($teachers)
                ->filter(fn ($t) => $counts[$t['id']] < $t['minDuties'])
                ->pluck('id');

            if ($needyIds->isEmpty()) {
                break;
            }

            $swappedAny = false;

            foreach ($needyIds as $needyId) {
                if ($counts[$needyId] >= $teachersById[$needyId]['minDuties']) {
                    continue; // an earlier swap this pass already resolved them
                }

                $swapIndex = $this->findSwap($placements, $needyId, $teachersById, $counts, $slotUnavailable, $lockedTeacherBySlot);

                if ($swapIndex === null) {
                    continue;
                }

                $old = $placements[$swapIndex];
                $placements[$swapIndex] = new DutyPlacement($needyId, $old->timeSlotId, $old->roomId);
                $counts[$old->teacherId]--;
                $counts[$needyId]++;
                $swappedAny = true;
            }

            if (! $swappedAny) {
                break;
            }
        }

        $warnings = $result->warnings->merge(
            collect($teachers)
                ->filter(fn ($t) => $counts[$t['id']] < $t['minDuties'])
                ->map(fn ($t) => new DutyWarning(
                    0,
                    null,
                    "Teacher #{$t['id']} has only {$counts[$t['id']]} duty(ies) assigned, below the session minimum of {$t['minDuties']}.",
                    'unmet_minimum'
                ))
        );

        return new DutyResult($placements, $warnings);
    }

    /**
     * @param  DutyPlacement[]  $placements
     * @param  \Illuminate\Support\Collection<int, array{id: int, minDuties: int, maxDuties: int}>  $teachersById
     * @param  array<int, int>  $counts
     * @param  array<int, array<int, int>>  $slotUnavailable
     * @param  array<int, array<int, bool>>  $lockedTeacherBySlot
     */
    private function findSwap(array $placements, int $needyId, $teachersById, array $counts, array $slotUnavailable, array $lockedTeacherBySlot): ?int
    {
        $needy = $teachersById[$needyId];

        foreach ($placements as $index => $placement) {
            $donorId = $placement->teacherId;

            if ($donorId === $needyId) {
                continue;
            }

            $donor = $teachersById->get($donorId);

            if ($donor === null) {
                continue;
            }

            // Donor must stay at/above their own minimum after losing this duty.
            if (($counts[$donorId] - 1) < $donor['minDuties']) {
                continue;
            }

            // Needy must not exceed their own maximum after gaining it.
            if (($counts[$needyId] + 1) > $needy['maxDuties']) {
                continue;
            }

            if (isset($slotUnavailable[$placement->timeSlotId][$needyId])) {
                continue;
            }

            if (isset($lockedTeacherBySlot[$placement->timeSlotId][$needyId])) {
                continue;
            }

            if ($this->teacherAlreadyInSlot($placements, $placement->timeSlotId, $needyId)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param  DutyPlacement[]  $placements
     */
    private function teacherAlreadyInSlot(array $placements, int $timeSlotId, int $teacherId): bool
    {
        foreach ($placements as $placement) {
            if ($placement->timeSlotId === $timeSlotId && $placement->teacherId === $teacherId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{id: int, minDuties: int, maxDuties: int}>  $teachers
     * @param  DutyPlacement[]  $placements
     * @param  DutyPlacement[]  $lockedPlacements
     * @return array<int, int>
     */
    private function countsFor(array $teachers, array $placements, array $lockedPlacements): array
    {
        $counts = [];

        foreach ($teachers as $teacher) {
            $counts[$teacher['id']] = 0;
        }

        foreach ($lockedPlacements as $locked) {
            $counts[$locked->teacherId] = ($counts[$locked->teacherId] ?? 0) + 1;
        }

        foreach ($placements as $placement) {
            $counts[$placement->teacherId] = ($counts[$placement->teacherId] ?? 0) + 1;
        }

        return $counts;
    }
}
