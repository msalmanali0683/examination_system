<?php

namespace App\Services\Generation;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\SubjectSlotAssignment;
use Illuminate\Support\Collection;

/**
 * For every subject that holds a whole slot to itself in the current
 * timetable, finds the best other slot it could share instead — one where
 * no occupant shares a student with it and the same-semester day rule still
 * holds — and says whether the active rooms can seat everyone together.
 * When they can't, it says how many students would be left without a seat:
 * the number of seats still missing for that sharing to work.
 *
 * Each row is judged on its own against the timetable as it stands today;
 * it is a read-only answer, not a plan (moving two subjects at once can
 * compete for the same rooms, which is what the Generate Timetable "share
 * slots" setting works out properly).
 */
class SlotSharingAdvisor
{
    public const FITS = 'fits';

    public const SHORT = 'short';

    public const NONE = 'none';

    /**
     * @return Collection<int, array{subjectId: int, code: string, title: string, semester: string, students: int, fromLabel: string, status: string, toLabel: ?string, with: string, seatsShort: int, roomsHint: int}>
     */
    public function analyze(ExamSession $session): Collection
    {
        $sessionId = $session->id;

        $assignments = SubjectSlotAssignment::where('exam_session_id', $sessionId)
            ->whereNotNull('time_slot_id')
            ->where('is_excluded', false)
            ->get(['subject_id', 'time_slot_id', 'is_pinned']);

        if ($assignments->count() < 2) {
            return collect();
        }

        $slotOf = $assignments->pluck('time_slot_id', 'subject_id')->all();
        $pinnedIds = $assignments->where('is_pinned', true)->pluck('subject_id')->all();
        $subjectIds = array_keys($slotOf);

        $enrollments = Enrollment::where('exam_session_id', $sessionId)
            ->whereIn('subject_id', $subjectIds)
            ->select('id', 'student_id', 'subject_id', 'section')
            ->with('subject:id,code,title')
            ->get();

        $graph = (new ConflictGraphBuilder)->build($enrollments);
        $bySubject = $enrollments->groupBy('subject_id');
        $semesters = SubjectSemesters::forSubjects($sessionId, $subjectIds);

        $slots = $session->timeSlots()->orderBy('date')->orderBy('start_time')->get();
        $labelOf = [];
        $dayOfSlot = [];
        $positionInDay = [];
        $slotsInDay = [];

        foreach ($slots as $slot) {
            $day = $slot->date->format('Y-m-d');
            $positionInDay[$slot->id] = $slotsInDay[$day] ?? 0;
            $slotsInDay[$day] = ($slotsInDay[$day] ?? 0) + 1;
            $dayOfSlot[$slot->id] = $day;
            $labelOf[$slot->id] = $slot->date->format('d M Y').' '.substr($slot->start_time, 0, 5);
        }

        $occupants = [];
        $subjectsByDay = [];

        foreach ($slotOf as $subjectId => $slotId) {
            $occupants[$slotId][] = $subjectId;

            if (isset($dayOfSlot[$slotId])) {
                $subjectsByDay[$dayOfSlot[$slotId]][] = $subjectId;
            }
        }

        $rooms = $session->rooms()->where('is_active', true)->get()
            ->map(fn ($room) => ['room_id' => $room->id, 'rows' => $room->rows, 'columns' => $room->columns, 'capacity' => $room->capacity, 'occupied' => []])
            ->sortByDesc('capacity')
            ->values()
            ->all();
        $largestRoom = (int) ($rooms[0]['capacity'] ?? 0);

        $strategy = (new SeatAllocationService)->strategyFor($session->seating_strategy, $session->mixed_subjects_per_room);
        $roomsOffBySlot = SlotAvailability::roomsOffBySlot($session);

        $unseatedIf = function (array $subjectIdsInSlot, int $slotId) use ($bySubject, $rooms, $roomsOffBySlot, $strategy): int {
            $subset = collect($subjectIdsInSlot)->flatMap(fn ($id) => $bySubject->get($id) ?? collect());
            $off = $roomsOffBySlot[$slotId] ?? [];
            $usable = $off === [] ? $rooms : array_values(array_filter($rooms, fn ($room) => ! in_array($room['room_id'], $off, true)));

            return $strategy->allocate($subset, $usable)->warnings->where('type', 'unseated')->count();
        };

        $sameSemester = function (int $a, int $b) use ($semesters): bool {
            $semA = $semesters[$a] ?? [];
            $semB = $semesters[$b] ?? [];

            return empty($semA) || empty($semB) || count(array_intersect($semA, $semB)) > 0;
        };

        $rows = collect();

        foreach ($subjectIds as $subjectId) {
            $current = $slotOf[$subjectId];

            // Only a subject with a slot all to itself: moving it frees a whole slot. A pinned one is
            // fixed by the admin, so it isn't suggested for moving.
            if (count($occupants[$current] ?? []) !== 1 || in_array($subjectId, $pinnedIds, true)) {
                continue;
            }

            $best = null;

            foreach ($slots as $slot) {
                $targetId = $slot->id;
                $there = $occupants[$targetId] ?? [];

                if ($targetId === $current || $there === []) {
                    continue;
                }

                if ($this->sharesAStudent($graph, $subjectId, $there)) {
                    continue;
                }

                if ($this->breaksSameSemesterDayRule($graph, $subjectId, $targetId, $subjectsByDay[$dayOfSlot[$targetId]] ?? [], $slotOf, $positionInDay, $slotsInDay[$dayOfSlot[$targetId]], $sameSemester)) {
                    continue;
                }

                $short = $unseatedIf([...$there, $subjectId], $targetId);

                if ($best === null || $short < $best['short']) {
                    $best = ['slotId' => $targetId, 'with' => $there, 'short' => $short];
                }

                if ($short === 0) {
                    break; // the earliest slot it fits in is as good as it gets
                }
            }

            $enrolled = $bySubject->get($subjectId, collect());
            $subject = $enrolled->first()?->subject;

            if ($subject === null) {
                continue;
            }

            $status = $best === null ? self::NONE : ($best['short'] === 0 ? self::FITS : self::SHORT);
            $short = $best['short'] ?? 0;

            $rows->push([
                'subjectId' => $subjectId,
                'code' => $subject->code,
                'title' => $subject->title,
                'semester' => SemesterExtractor::label($enrolled->pluck('section')),
                'students' => $enrolled->count(),
                'fromLabel' => $labelOf[$current] ?? '—',
                'status' => $status,
                'toLabel' => $best === null ? null : ($labelOf[$best['slotId']] ?? null),
                'with' => $best === null ? '' : collect($best['with'])
                    ->map(fn ($id) => $bySubject->get($id)?->first()?->subject?->code ?? "#{$id}")
                    ->implode(', '),
                'seatsShort' => $short,
                'roomsHint' => ($short > 0 && $largestRoom > 0) ? (int) ceil($short / $largestRoom) : 0,
            ]);
        }

        $rank = [self::FITS => 0, self::SHORT => 1, self::NONE => 2];

        return $rows
            ->sortBy(fn (array $row) => sprintf('%d-%06d-%s', $rank[$row['status']], $row['seatsShort'], $row['code']))
            ->values();
    }

    /**
     * @param  array<int, array<int, int>>  $graph
     * @param  int[]  $others
     */
    private function sharesAStudent(array $graph, int $subjectId, array $others): bool
    {
        foreach ($others as $otherId) {
            if (($graph[$subjectId][$otherId] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same rule TimetableGenerator applies: a same-semester subject that shares a student must not land
     * on the same day, unless the two end up at that day's maximum possible separation (e.g. its first
     * and last slot), which is the accepted way to handle a semester with more subjects than days.
     *
     * @param  array<int, array<int, int>>  $graph
     * @param  int[]  $subjectsThatDay  every subject currently placed on the target slot's day
     * @param  array<int, int>  $slotOf  subject_id => slot_id
     * @param  array<int, int>  $positionInDay
     */
    private function breaksSameSemesterDayRule(array $graph, int $subjectId, int $targetSlotId, array $subjectsThatDay, array $slotOf, array $positionInDay, int $slotsThatDay, callable $sameSemester): bool
    {
        $dayMaxGap = $slotsThatDay - 1;

        foreach ($subjectsThatDay as $otherId) {
            if ($otherId === $subjectId || ($graph[$subjectId][$otherId] ?? 0) === 0 || ! $sameSemester($subjectId, $otherId)) {
                continue;
            }

            $otherSlot = $slotOf[$otherId] ?? null;

            if ($dayMaxGap > 0 && $otherSlot !== null && isset($positionInDay[$otherSlot])
                && abs($positionInDay[$targetSlotId] - $positionInDay[$otherSlot]) === $dayMaxGap) {
                continue;
            }

            return true;
        }

        return false;
    }
}
