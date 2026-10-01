<?php

namespace App\Services\Generation;

use App\Models\Enrollment;
use App\Services\Generation\DTOs\SeatPlacement;
use App\Services\Generation\DTOs\SeatingWarning;
use Illuminate\Support\Collection;

/**
 * Per-slot breakdown builders shared by SlotCapacitySimulator (what-if slots
 * built straight from enrollment data) and RequirementCalculator (the real
 * timetable's slots) — same shape either way, since both ultimately allocate
 * a set of subjects into a room pool via a SeatingStrategy and need to show
 * the same "which room/section filled it" and "which subject is in this
 * slot" detail.
 */
class SlotBreakdownBuilder
{
    /**
     * Room-by-room detail for one slot: which room, its capacity, and which section(s) of which
     * subject(s) fill it. Grouped by room in the order rooms first received a placement.
     *
     * @param  SeatPlacement[]  $placements
     * @param  Collection<int, Enrollment>  $enrollmentsById
     * @param  array<int, array{room_id: int, name: string, capacity: int}>  $roomTemplate
     * @return array<int, array{roomName: string, capacity: int, filled: int, remaining: int, sections: array<int, array{subjectCode: string, subjectTitle: string, section: string, count: int}>}>
     */
    public static function roomBreakdown(array $placements, Collection $enrollmentsById, array $roomTemplate): array
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
                    'roomName' => $room['name'] ?? 'Unknown room',
                    'capacity' => $room['capacity'] ?? 0,
                    'filled' => $filled,
                    'remaining' => ($room['capacity'] ?? 0) - $filled,
                    'sections' => $sections,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Which section(s) of which subject(s) went unseated, and how many — grouped the same way
     * roomBreakdown() groups what WAS seated, so the two line up for display.
     *
     * @param  Collection<int, SeatingWarning>  $unseated
     * @param  Collection<int, Enrollment>  $enrollmentsById
     * @return array<int, array{subjectCode: string, subjectTitle: string, section: string, count: int}>
     */
    public static function unseatedBreakdown(Collection $unseated, Collection $enrollmentsById): array
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
     * Every subject grouped into this slot. A subject's semester uses every section it has in the
     * whole session (not just the ones in this slot), same basis simple/formatted datesheets use.
     *
     * @param  int[]  $subjectIds
     * @param  Collection<int, Collection<int, Enrollment>>  $enrollmentsBySubject
     * @return array<int, array{code: string, title: string, semester: string}>
     */
    public static function subjectsInSlot(array $subjectIds, Collection $enrollmentsBySubject): array
    {
        return collect($subjectIds)
            ->filter(fn (int $subjectId) => $enrollmentsBySubject->has($subjectId))
            ->map(function (int $subjectId) use ($enrollmentsBySubject) {
                $rows = $enrollmentsBySubject->get($subjectId);
                $subject = $rows->first()->subject;

                return [
                    'code' => $subject->code,
                    'title' => $subject->title,
                    'semester' => SemesterExtractor::label($rows->pluck('section')),
                ];
            })
            ->sortBy('title')
            ->values()
            ->all();
    }
}
