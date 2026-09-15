<?php

namespace App\Services\Generation;

use App\Services\Generation\DTOs\ConflictReportRow;
use App\Services\Generation\DTOs\TimetableResult;
use Illuminate\Support\Collection;

class TimetableGenerator
{
    /**
     * Assigns every unpinned subject to a time slot, avoiding clashes with
     * subjects that share students wherever possible. A clash is checked at
     * the *day* level, not just the exact slot — two subjects that share a
     * student are never placed on the same calendar day at all, even in
     * different time periods, since a student can only sit one paper a day
     * regardless of how many slots that day has. Pinned subjects are fixed
     * obstacles and are never moved. Hardest-to-place subjects (most total
     * shared-student weight) are processed first.
     *
     * Among multiple clash-free days, the least-loaded one (fewest subjects
     * placed there so far) is preferred — and likewise the least-loaded
     * slot within the chosen day — so subjects spread out across the whole
     * available window instead of piling into the first few days that
     * happen to be clash-free, leaving later days empty. When a subject
     * truly cannot avoid every clash, it's placed on whichever day has the
     * least total conflict (ties broken the same way), and the specific
     * clash is reported rather than silently dropped or thrown as an error.
     *
     * @param  int[]  $subjectIds  every subject needing a slot this session
     * @param  array<int, int>  $pinned  subject_id => time_slot_id, fixed and never moved
     * @param  int[]  $timeSlotIds  candidate slots, in preference order (earliest first)
     * @param  array<int, array<int, int>>  $conflictGraph  from ConflictGraphBuilder::build()
     * @param  array<int, string>  $subjectLabels  subject_id => display label, for conflict messages
     * @param  array<int, string>  $slotDays  time_slot_id => calendar-day key (e.g. 'Y-m-d'); slots missing
     *                                        from this map default to being their own day, so callers that
     *                                        don't care about day-grouping (or existing tests) get plain
     *                                        slot-level behavior unchanged.
     */
    public function generate(
        array $subjectIds,
        array $pinned,
        array $timeSlotIds,
        array $conflictGraph,
        array $subjectLabels,
        array $slotDays = [],
    ): TimetableResult {
        $dayOf = fn (int $slotId): string => $slotDays[$slotId] ?? 'slot-'.$slotId;

        $placed = $pinned;
        $slotOccupants = [];
        $dayOccupants = [];

        foreach ($pinned as $subjectId => $slotId) {
            $slotOccupants[$slotId][] = $subjectId;
            $dayOccupants[$dayOf($slotId)][] = $subjectId;
        }

        $unpinned = array_values(array_diff($subjectIds, array_keys($pinned)));
        $conflicts = collect();

        if (empty($timeSlotIds)) {
            foreach ($unpinned as $subjectId) {
                $conflicts->push(new ConflictReportRow(
                    subjectId: $subjectId,
                    subjectLabel: $subjectLabels[$subjectId] ?? "#{$subjectId}",
                    conflictingSubjectId: 0,
                    conflictingSubjectLabel: '',
                    sharedStudentCount: 0,
                    message: ($subjectLabels[$subjectId] ?? "#{$subjectId}").': no time slots exist for this session yet.',
                ));
            }

            return new TimetableResult([], $conflicts);
        }

        // Each day's slots, in original (earliest-first) order — computed
        // once so every subject's placement search reuses it.
        $daySlots = [];
        foreach ($timeSlotIds as $slotId) {
            $daySlots[$dayOf($slotId)][] = $slotId;
        }
        $days = array_keys($daySlots);

        usort($unpinned, fn ($a, $b) => $this->totalWeight($conflictGraph, $b) <=> $this->totalWeight($conflictGraph, $a));

        foreach ($unpinned as $subjectId) {
            $bestDay = null;
            $bestDayWeight = null;
            $bestDayLoad = null;

            foreach ($days as $day) {
                $occupants = $dayOccupants[$day] ?? [];
                $weight = $this->clashWeight($conflictGraph, $subjectId, $occupants);
                $load = count($occupants);

                if ($bestDayWeight === null
                    || $weight < $bestDayWeight
                    || ($weight === $bestDayWeight && $load < $bestDayLoad)) {
                    $bestDay = $day;
                    $bestDayWeight = $weight;
                    $bestDayLoad = $load;
                }
            }

            // Within the chosen day, prefer whichever slot has the fewest
            // subjects so far, spreading across that day's own periods too.
            $bestSlot = null;
            $bestSlotLoad = null;

            foreach ($daySlots[$bestDay] as $slotId) {
                $load = count($slotOccupants[$slotId] ?? []);

                if ($bestSlotLoad === null || $load < $bestSlotLoad) {
                    $bestSlot = $slotId;
                    $bestSlotLoad = $load;
                }
            }

            $placed[$subjectId] = $bestSlot;
            $slotOccupants[$bestSlot][] = $subjectId;
            $dayOccupants[$bestDay][] = $subjectId;

            if ($bestDayWeight > 0) {
                foreach ($dayOccupants[$bestDay] as $occupantId) {
                    $shared = $conflictGraph[$subjectId][$occupantId] ?? 0;

                    if ($occupantId === $subjectId || $shared === 0) {
                        continue;
                    }

                    $subjectLabel = $subjectLabels[$subjectId] ?? "#{$subjectId}";
                    $occupantLabel = $subjectLabels[$occupantId] ?? "#{$occupantId}";

                    $conflicts->push(new ConflictReportRow(
                        subjectId: $subjectId,
                        subjectLabel: $subjectLabel,
                        conflictingSubjectId: $occupantId,
                        conflictingSubjectLabel: $occupantLabel,
                        sharedStudentCount: $shared,
                        message: "{$subjectLabel} and {$occupantLabel} share {$shared} student(s) but were placed on the same day — no clash-free day remained. Consider adding a day/slot or pinning one of them elsewhere.",
                    ));
                }
            }
        }

        return new TimetableResult($placed, $conflicts);
    }

    /**
     * @param  array<int, array<int, int>>  $graph
     * @param  int[]  $occupants
     */
    private function clashWeight(array $graph, int $subjectId, array $occupants): int
    {
        $weight = 0;

        foreach ($occupants as $occupantId) {
            $weight += $graph[$subjectId][$occupantId] ?? 0;
        }

        return $weight;
    }

    /**
     * @param  array<int, array<int, int>>  $graph
     */
    private function totalWeight(array $graph, int $subjectId): int
    {
        return array_sum($graph[$subjectId] ?? []);
    }
}
