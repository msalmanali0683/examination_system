<?php

namespace App\Services\Generation;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionTeacherConstraint;
use App\Services\Generation\DTOs\SeatingResult;
use App\Services\Generation\DTOs\SeatingWarning;
use App\Services\Generation\DTOs\SeatPlacement;
use App\Services\Generation\DTOs\SlotRequirement;
use App\Services\Generation\Strategies\SeatingStrategy;
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
    private SeatingStrategy $strategy;

    /**
     * Defaults to Strict (the previous hardcoded behaviour) when no strategy is given. Pass the
     * session's actual configured strategy (or any other, via SeatAllocationService::strategyFor())
     * to simulate what that strategy's room-sharing would really pack — Strict alone is the most
     * conservative assumption and can understate how many subjects fit in one slot compared to
     * Combine Sections/Mixed/the overflow variants, all of which let a room hold more than one group.
     */
    public function __construct(?SeatingStrategy $strategy = null)
    {
        $this->strategy = $strategy ?? new StrictSeatingStrategy;
    }

    /**
     * Relabels each simulated slot as "Day X, Slot Y" given how many slots run per day — a display
     * concern the simulator itself has no notion of (it only knows "slot 1, slot 2, ..."), applied
     * after simulate() wherever a days-per-slot setting is in play (the Check Capacity page and its
     * downloads), so both describe the same simulated slot the same way. Leaves the "(N subjects)"
     * suffix already on each label untouched.
     *
     * @param  Collection<int, SlotRequirement>  $slotRequirements
     * @return Collection<int, SlotRequirement>
     */
    public static function withDayAndSlotLabels(Collection $slotRequirements, int $slotsPerDay): Collection
    {
        $perDay = max(1, $slotsPerDay);

        return $slotRequirements->values()->map(function (SlotRequirement $r, int $index) use ($perDay) {
            $day = intdiv($index, $perDay) + 1;
            $slotInDay = ($index % $perDay) + 1;
            $label = preg_replace('/^Simulated Slot \d+/', "Day {$day}, Slot {$slotInDay}", $r->label);

            return new SlotRequirement(...[...get_object_vars($r), 'label' => $label]);
        });
    }

    /**
     * One row per subject: how many seats it needs (its enrolled student count) — independent of
     * simulate()'s slot packing below, so it's available even for a subject that simulate() couldn't
     * fit anywhere (which would otherwise mean it's simply missing from every simulated slot).
     *
     * @return Collection<int, array{code: string, title: string, sections: string, count: int}>
     */
    public function subjectRequirements(ExamSession $session): Collection
    {
        return Enrollment::where('exam_session_id', $session->id)
            ->with('subject:id,code,title')
            ->get()
            ->groupBy('subject_id')
            ->map(fn (Collection $rows) => [
                'code' => $rows->first()->subject->code,
                'title' => $rows->first()->subject->title,
                'sections' => $rows->pluck('section')->unique()->sort()->values()->implode(', '),
                'count' => $rows->count(),
            ])
            ->sortBy('code')
            ->values();
    }

    /**
     * $minSubjectsPerSlot/$maxSubjectsPerSlot are optional targets, not
     * hard guarantees: max is enforced strictly (a slot never grows past
     * it), while min only biases which slot a subject is offered to first
     * (fill slots still under the minimum before growing ones that have
     * already reached it) — a subject that has no clash-free, capacity-
     * fitting slot to join still opens its own new one regardless, since
     * there's no way to force company on it that doesn't exist. Leave
     * either null for "no constraint" (matches the plain capacity-fit
     * packing this had before).
     *
     * @return Collection<int, SlotRequirement>
     */
    public function simulate(ExamSession $session, ?int $minSubjectsPerSlot = null, ?int $maxSubjectsPerSlot = null): Collection
    {
        $enrollments = Enrollment::where('exam_session_id', $session->id)
            ->select('id', 'student_id', 'subject_id', 'section')
            ->with('subject:id,code,title')
            ->get();

        if ($enrollments->isEmpty()) {
            return collect();
        }

        // Indexed by subject_id so every capacity/clash check below is a
        // handful of array lookups instead of re-scanning every enrollment
        // in the session — this runs dozens of times per subject while
        // packing, so that difference is the gap between instant and slow.
        $enrollmentsBySubject = $enrollments->groupBy('subject_id');

        // Looked up once per enrollment id while building each slot's room breakdown below.
        $enrollmentsById = $enrollments->keyBy('id');

        $subjectIds = $enrollmentsBySubject
            ->sortByDesc(fn (Collection $rows) => $rows->count())
            ->keys()
            ->all();

        $conflictGraph = (new ConflictGraphBuilder)->build($enrollments);
        $roomTemplate = $this->activeRoomTemplate($session);
        $roomsAvailable = count($roomTemplate);
        $seatsAvailable = array_sum(array_column($roomTemplate, 'capacity'));
        $teachersAvailable = $this->teachersAvailable($session);

        $bins = $this->packIntoBins($subjectIds, $conflictGraph, $enrollmentsBySubject, $roomTemplate, $minSubjectsPerSlot, $maxSubjectsPerSlot);

        return collect($bins)->values()->map(function (array $subjectIdsInSlot, int $index) use ($enrollmentsBySubject, $enrollmentsById, $roomTemplate, $roomsAvailable, $seatsAvailable, $teachersAvailable, $session) {
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
                seatsAvailable: $seatsAvailable,
                roomBreakdown: $this->roomBreakdown($result->placements, $enrollmentsById, $roomTemplate),
                unseatedBreakdown: $this->unseatedBreakdown($unseated, $enrollmentsById),
            );
        });
    }

    /**
     * Which section(s) of which subject(s) went unseated, and how many — grouped the same way
     * roomBreakdown() groups what WAS seated, so the two line up for display.
     *
     * @param  Collection<int, SeatingWarning>  $unseated
     * @param  Collection<int, Enrollment>  $enrollmentsById
     * @return array<int, array{subjectCode: string, subjectTitle: string, section: string, count: int}>
     */
    private function unseatedBreakdown(Collection $unseated, Collection $enrollmentsById): array
    {
        return $unseated
            ->groupBy(fn (SeatingWarning $w) => $enrollmentsById[$w->enrollmentId]->subject_id.'|'.$enrollmentsById[$w->enrollmentId]->section)
            ->map(function (Collection $rows) use ($enrollmentsById) {
                $enrollment = $enrollmentsById[$rows->first()->enrollmentId];

                return [
                    'subjectCode' => $enrollment->subject->code,
                    'subjectTitle' => $enrollment->subject->title,
                    'section' => $enrollment->section,
                    'count' => $rows->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Room-by-room detail for one simulated slot: which room, its capacity, and which section(s) of
     * which subject(s) fill it — Strict (what this simulator always uses) gives one subject+section
     * per room, but a group too large for one room spans several, so a room can still show less than
     * its own group's total. Grouped by room in the order rooms first received a placement.
     *
     * @param  SeatPlacement[]  $placements
     * @param  Collection<int, Enrollment>  $enrollmentsById
     * @param  array<int, array{room_id: int, name: string, rows: int, columns: int, capacity: int, occupied: array}>  $roomTemplate
     * @return array<int, array{roomName: string, capacity: int, filled: int, remaining: int, sections: array<int, array{subjectCode: string, subjectTitle: string, section: string, count: int}>}>
     */
    private function roomBreakdown(array $placements, Collection $enrollmentsById, array $roomTemplate): array
    {
        $roomsById = collect($roomTemplate)->keyBy('room_id');

        return collect($placements)
            ->groupBy('roomId')
            ->map(function (Collection $roomPlacements, int $roomId) use ($enrollmentsById, $roomsById) {
                $room = $roomsById->get($roomId);

                $sections = $roomPlacements
                    ->groupBy(fn (SeatPlacement $p) => $enrollmentsById[$p->enrollmentId]->subject_id.'|'.$enrollmentsById[$p->enrollmentId]->section)
                    ->map(function (Collection $rows) use ($enrollmentsById) {
                        $enrollment = $enrollmentsById[$rows->first()->enrollmentId];

                        return [
                            'subjectCode' => $enrollment->subject->code,
                            'subjectTitle' => $enrollment->subject->title,
                            'section' => $enrollment->section,
                            'count' => $rows->count(),
                        ];
                    })
                    ->values()
                    ->all();

                $filled = array_sum(array_column($sections, 'count'));

                return [
                    'roomName' => $room['name'],
                    'capacity' => $room['capacity'],
                    'filled' => $filled,
                    'remaining' => $room['capacity'] - $filled,
                    'sections' => $sections,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  int[]  $subjectIds  largest-first
     * @param  array<int, array<int, int>>  $conflictGraph
     * @param  Collection<int, Collection>  $enrollmentsBySubject
     * @param  array<int, array{room_id: int, rows: int, columns: int, capacity: int, occupied: array}>  $roomTemplate
     * @return array<int, int[]>
     */
    private function packIntoBins(array $subjectIds, array $conflictGraph, Collection $enrollmentsBySubject, array $roomTemplate, ?int $min, ?int $max): array
    {
        $bins = [];

        foreach ($subjectIds as $subjectId) {
            $placed = false;

            foreach ($this->candidateBinOrder($bins, $min) as $index) {
                $binSubjectIds = $bins[$index];

                if ($max !== null && count($binSubjectIds) >= $max) {
                    continue;
                }

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
     * Plain creation order, unless a minimum is set — then bins still
     * short of it are offered first, so they fill toward the minimum
     * before a bin that's already met it is grown further.
     *
     * @param  array<int, int[]>  $bins
     * @return int[]
     */
    private function candidateBinOrder(array $bins, ?int $min): array
    {
        $indexes = array_keys($bins);

        if ($min === null) {
            return $indexes;
        }

        usort($indexes, function ($a, $b) use ($bins, $min) {
            $aBelowMin = count($bins[$a]) < $min;
            $bBelowMin = count($bins[$b]) < $min;

            return $aBelowMin === $bBelowMin ? $a <=> $b : ($aBelowMin ? -1 : 1);
        });

        return $indexes;
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
     * @return array<int, array{room_id: int, name: string, rows: int, columns: int, capacity: int, occupied: array}>
     */
    private function activeRoomTemplate(ExamSession $session): array
    {
        return $session->rooms()->where('is_active', true)->get()
            ->map(fn (Room $room) => [
                'room_id' => $room->id,
                'name' => $room->name,
                'rows' => $room->rows,
                'columns' => $room->columns,
                'capacity' => $room->capacity,
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

        return $session->teachers()->where('is_active', true)
            ->whereNotIn('id', $unavailableTeacherIds)
            ->count();
    }
}
