<?php

namespace App\Services\Generation;

use App\Services\Generation\DTOs\ConflictReportRow;
use App\Services\Generation\DTOs\TimetableResult;
use Illuminate\Support\Collection;

class TimetableGenerator
{
    /**
     * Assigns every unpinned subject to a time slot, avoiding clashes with
     * subjects that share students wherever possible. Pinned subjects are
     * fixed obstacles and are never moved. Hardest-to-place subjects (most
     * total shared-student weight) are processed first. When a subject
     * truly cannot avoid every clash, it's placed in whichever slot has the
     * least total conflict, and the specific clash is reported rather than
     * silently dropped or thrown as an error.
     *
     * @param  int[]  $subjectIds  every subject needing a slot this session
     * @param  array<int, int>  $pinned  subject_id => time_slot_id, fixed and never moved
     * @param  int[]  $timeSlotIds  candidate slots, in preference order (earliest first)
     * @param  array<int, array<int, int>>  $conflictGraph  from ConflictGraphBuilder::build()
     * @param  array<int, string>  $subjectLabels  subject_id => display label, for conflict messages
     */
    public function generate(
        array $subjectIds,
        array $pinned,
        array $timeSlotIds,
        array $conflictGraph,
        array $subjectLabels,
    ): TimetableResult {
        $placed = $pinned;
        $slotOccupants = [];

        foreach ($pinned as $subjectId => $slotId) {
            $slotOccupants[$slotId][] = $subjectId;
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

        usort($unpinned, fn ($a, $b) => $this->totalWeight($conflictGraph, $b) <=> $this->totalWeight($conflictGraph, $a));

        foreach ($unpinned as $subjectId) {
            $bestSlot = null;
            $bestWeight = null;

            foreach ($timeSlotIds as $slotId) {
                $weight = $this->clashWeight($conflictGraph, $subjectId, $slotOccupants[$slotId] ?? []);

                if ($weight === 0) {
                    $bestSlot = $slotId;
                    $bestWeight = 0;
                    break;
                }

                if ($bestWeight === null || $weight < $bestWeight) {
                    $bestSlot = $slotId;
                    $bestWeight = $weight;
                }
            }

            $placed[$subjectId] = $bestSlot;
            $slotOccupants[$bestSlot][] = $subjectId;

            if ($bestWeight > 0) {
                foreach ($slotOccupants[$bestSlot] as $occupantId) {
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
                        message: "{$subjectLabel} and {$occupantLabel} share {$shared} student(s) but were placed in the same slot — no free slot remained without a clash. Consider adding a slot or pinning one of them elsewhere.",
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
