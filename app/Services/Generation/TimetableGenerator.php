<?php

namespace App\Services\Generation;

use App\Services\Generation\DTOs\ConflictReportRow;
use App\Services\Generation\DTOs\TimetableResult;
use Illuminate\Support\Collection;

class TimetableGenerator
{
    /**
     * Assigns every unpinned subject to a time slot, avoiding student
     * clashes wherever possible. Two different rules apply depending on
     * whether the clashing subjects are in the same semester:
     *
     * - Same semester (the normal case — a whole cohort sitting every
     *   paper together): never share so much as a day, since practically
     *   every student in that cohort would be affected. When a semester
     *   genuinely has more subjects than available days, doubling a day
     *   up becomes unavoidable — when that happens, the two papers are
     *   placed as far apart within that day as the day's slots allow
     *   (e.g. its first and last slot), never adjacent.
     * - Different semesters (a repeater/backlog student sitting a paper
     *   from another semester alongside their current one): sharing a
     *   day is fine — that student just sits two papers that day. Only
     *   the exact same time slot is avoided, since that's the only
     *   version that's actually impossible for them to attend.
     *
     * A subject with no semester recorded for it (or an unknown pairing)
     * is treated as same-semester with everything — conservative, so
     * callers that don't supply semester data (including every existing
     * caller/test) get the original "never share a day" behavior
     * unchanged.
     *
     * Pinned subjects are fixed obstacles and are never moved.
     * Hardest-to-place subjects (most total shared-student weight) are
     * processed first.
     *
     * Among multiple equally-good (day, slot) options, the least-loaded
     * day is preferred, then the least-loaded slot within it — so
     * subjects spread out across the whole available window instead of
     * piling into the first few days that happen to be clash-free.
     *
     * $roomsFit, when given, is consulted alongside the above: for a
     * candidate slot, it's called with the subject IDs that would occupy
     * it (everything already there plus this one) and must return
     * whether the session's active rooms can actually seat all of them
     * together. A slot that fails this is only ever chosen when nothing
     * better does — same "least bad, but report it" fallback as an
     * unavoidable clash — so a subject ending up alone in a slot is never
     * treated as a problem on its own.
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
     * @param  ?callable(int[]): bool  $roomsFit  optional: given the subject IDs that would share a slot,
     *                                            returns whether the active rooms can seat them all. Omit to
     *                                            skip room-capacity awareness entirely (prior behavior).
     * @param  array<int, int[]>  $semesterBySubject  subject_id => the semester number(s) it belongs to
     *                                                (a subject can span more than one, e.g. mixed sections).
     *                                                Missing/empty for a subject means "unknown" — treated as
     *                                                same-semester with everything (see above).
     */
    public function generate(
        array $subjectIds,
        array $pinned,
        array $timeSlotIds,
        array $conflictGraph,
        array $subjectLabels,
        array $slotDays = [],
        ?callable $roomsFit = null,
        array $semesterBySubject = [],
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
        // once so every subject's placement search reuses it, and so a
        // slot's position within its day (0 = first, last index = last)
        // is known for the "spread same-semester overflow apart" rule.
        $daySlots = [];
        $positionInDay = [];
        foreach ($timeSlotIds as $slotId) {
            $day = $dayOf($slotId);
            $daySlots[$day][] = $slotId;
            $positionInDay[$slotId] = count($daySlots[$day]) - 1;
        }

        $sameSemester = function (int $a, int $b) use ($semesterBySubject): bool {
            $semA = $semesterBySubject[$a] ?? [];
            $semB = $semesterBySubject[$b] ?? [];

            if (empty($semA) || empty($semB)) {
                return true;
            }

            return count(array_intersect($semA, $semB)) > 0;
        };

        usort($unpinned, fn ($a, $b) => $this->totalWeight($conflictGraph, $b) <=> $this->totalWeight($conflictGraph, $a));

        foreach ($unpinned as $subjectId) {
            $best = null;

            foreach ($daySlots as $day => $slotIds) {
                $dayOccupantIds = $dayOccupants[$day] ?? [];
                $sameSemesterOccupantIds = array_values(array_filter(
                    $dayOccupantIds,
                    fn ($occupantId) => $sameSemester($subjectId, $occupantId)
                ));
                $sameSemesterDayWeight = $this->clashWeight($conflictGraph, $subjectId, $sameSemesterOccupantIds);
                $dayLoad = count($dayOccupantIds);

                foreach ($slotIds as $slotId) {
                    $slotOccupantIds = $slotOccupants[$slotId] ?? [];

                    $gapScore = PHP_INT_MAX;
                    foreach ($sameSemesterOccupantIds as $occupantId) {
                        $occupantSlot = $placed[$occupantId] ?? null;
                        if ($occupantSlot !== null && isset($positionInDay[$occupantSlot])) {
                            $gapScore = min($gapScore, abs($positionInDay[$slotId] - $positionInDay[$occupantSlot]));
                        }
                    }

                    $candidate = [
                        'day' => $day,
                        'slot' => $slotId,
                        'slotClashFree' => $this->clashWeight($conflictGraph, $subjectId, $slotOccupantIds) === 0,
                        'fits' => $roomsFit === null || $roomsFit([...$slotOccupantIds, $subjectId]),
                        'sameSemesterDayWeight' => $sameSemesterDayWeight,
                        'gapScore' => $gapScore,
                        'dayLoad' => $dayLoad,
                        'slotLoad' => count($slotOccupantIds),
                    ];

                    if ($best === null || $this->isBetterCandidate($candidate, $best)) {
                        $best = $candidate;
                    }
                }
            }

            $bestDay = $best['day'];
            $bestSlot = $best['slot'];

            $subjectLabel = $subjectLabels[$subjectId] ?? "#{$subjectId}";

            // Same-semester subjects sharing this day but landing in a
            // DIFFERENT slot — an unavoidable overflow (more subjects in
            // that semester than there were days), spaced as far apart
            // within the day as possible rather than a same-day-same-time
            // clash. Occupants in the exact same slot are reported
            // separately below instead, since that's a different (worse)
            // problem.
            if ($best['sameSemesterDayWeight'] > 0) {
                foreach ($dayOccupants[$bestDay] as $occupantId) {
                    $shared = $conflictGraph[$subjectId][$occupantId] ?? 0;

                    if ($occupantId === $subjectId || $shared === 0 || ! $sameSemester($subjectId, $occupantId)) {
                        continue;
                    }

                    if (($placed[$occupantId] ?? null) === $bestSlot) {
                        continue;
                    }

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

            // Exact same slot — a real double-booking, not just a shared
            // day. Checked against whoever is already in that slot before
            // this subject joins it (any semester: this is never fine).
            if (! $best['slotClashFree']) {
                foreach ($slotOccupants[$bestSlot] ?? [] as $occupantId) {
                    $shared = $conflictGraph[$subjectId][$occupantId] ?? 0;

                    if ($shared === 0) {
                        continue;
                    }

                    $occupantLabel = $subjectLabels[$occupantId] ?? "#{$occupantId}";

                    $conflicts->push(new ConflictReportRow(
                        subjectId: $subjectId,
                        subjectLabel: $subjectLabel,
                        conflictingSubjectId: $occupantId,
                        conflictingSubjectLabel: $occupantLabel,
                        sharedStudentCount: $shared,
                        message: "{$subjectLabel} and {$occupantLabel} share {$shared} student(s) and had to be placed in the exact same time slot — no clash-free slot remained anywhere. Add another slot, or pin one of them elsewhere.",
                    ));
                }
            }

            if ($roomsFit !== null && ! $best['fits']) {
                $conflicts->push(new ConflictReportRow(
                    subjectId: $subjectId,
                    subjectLabel: $subjectLabel,
                    conflictingSubjectId: 0,
                    conflictingSubjectLabel: '',
                    sharedStudentCount: 0,
                    message: "{$subjectLabel}: no slot has enough active room capacity left for it, even alone — it was placed anyway. Activate more rooms, or add another slot.",
                ));
            }

            $placed[$subjectId] = $bestSlot;
            $slotOccupants[$bestSlot][] = $subjectId;
            $dayOccupants[$bestDay][] = $subjectId;
        }

        return new TimetableResult($placed, $conflicts);
    }

    /**
     * @param  array{day: string, slot: int, slotClashFree: bool, fits: bool, sameSemesterDayWeight: int, gapScore: int, dayLoad: int, slotLoad: int}  $a
     * @param  array{day: string, slot: int, slotClashFree: bool, fits: bool, sameSemesterDayWeight: int, gapScore: int, dayLoad: int, slotLoad: int}  $b
     */
    private function isBetterCandidate(array $a, array $b): bool
    {
        if ($a['slotClashFree'] !== $b['slotClashFree']) {
            return $a['slotClashFree'];
        }

        if ($a['fits'] !== $b['fits']) {
            return $a['fits'];
        }

        if ($a['sameSemesterDayWeight'] !== $b['sameSemesterDayWeight']) {
            return $a['sameSemesterDayWeight'] < $b['sameSemesterDayWeight'];
        }

        if ($a['gapScore'] !== $b['gapScore']) {
            return $a['gapScore'] > $b['gapScore'];
        }

        if ($a['dayLoad'] !== $b['dayLoad']) {
            return $a['dayLoad'] < $b['dayLoad'];
        }

        if ($a['slotLoad'] !== $b['slotLoad']) {
            return $a['slotLoad'] < $b['slotLoad'];
        }

        return false;
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
