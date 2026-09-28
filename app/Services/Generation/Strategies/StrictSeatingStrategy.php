<?php

namespace App\Services\Generation\Strategies;

use App\Services\Generation\DTOs\SeatingResult;
use App\Services\Generation\DTOs\SeatingWarning;
use App\Services\Generation\DTOs\SeatPlacement;
use App\Services\Generation\RoomFiller;
use Illuminate\Support\Collection;

/**
 * One room (or, when a group is too large, several consecutive rooms) is
 * dedicated exclusively to one subject+section group — matching the
 * department's real practice (verified against real "Sitting Plan"
 * templates: each subject+section always gets its own room, sorted by
 * roll number, filled column by column).
 */
class StrictSeatingStrategy implements SeatingStrategy
{
    public function __construct(protected RoomFiller $filler = new RoomFiller)
    {
    }

    public function allocate(Collection $enrollments, array $rooms): SeatingResult
    {
        $groups = $enrollments
            ->groupBy(fn ($e) => $e->subject_id.'|'.$e->section)
            ->map(fn (Collection $group) => $group->pluck('id')->all());

        return $this->allocateGroups($groups, $rooms);
    }

    /**
     * Groups are processed largest-first. Each group prefers the room with the
     * fewest free seats that still hold it whole (minimizing wasted
     * capacity); when it doesn't fit anywhere whole, it's split starting from
     * the room with the most free seats (minimizing how many rooms the split
     * needs). "Free" means within the room's capacity and not already held by
     * a locked seat, so a manually placed student never makes a group lose
     * out on a room it would otherwise have fit. This makes room selection
     * independent of the order rooms are given in.
     *
     * @param  Collection<string, int[]>  $groups
     * @param  array<int, array{room_id: int, rows: int, columns: int, capacity: int, occupied: array}>  $rooms
     */
    protected function allocateGroups(Collection $groups, array $rooms): SeatingResult
    {
        $placements = [];
        $warnings = collect();
        $available = array_keys($rooms);
        $free = array_map(fn (array $room) => $this->filler->freeSeats($room['rows'], $room['columns'], $room['capacity'], $room['occupied'] ?? []), $rooms);

        foreach ($groups->sortByDesc(fn (array $ids) => count($ids)) as $groupKey => $itemIds) {
            $bestFit = null;

            foreach ($available as $index) {
                if ($free[$index] >= count($itemIds)
                    && ($bestFit === null || $free[$index] < $free[$bestFit])) {
                    $bestFit = $index;
                }
            }

            $order = $bestFit !== null
                ? [$bestFit]
                : (function () use ($available, $free) {
                    $indexes = $available;
                    usort($indexes, fn ($a, $b) => $free[$b] <=> $free[$a]);

                    return $indexes;
                })();

            foreach ($order as $index) {
                if (empty($itemIds)) {
                    break;
                }

                $room = $rooms[$index];
                $result = $this->filler->fillRoom($itemIds, $room['rows'], $room['columns'], $room['capacity'], $room['occupied'] ?? []);

                foreach ($result['placements'] as $p) {
                    $placements[] = new SeatPlacement($p['item_id'], $room['room_id'], $p['row'], $p['column']);
                }

                if (count($result['placements']) > 0) {
                    $available = array_values(array_diff($available, [$index]));
                }

                $itemIds = $result['remaining'];
            }

            foreach ($itemIds as $enrollmentId) {
                $warnings->push(new SeatingWarning(
                    $enrollmentId,
                    "No room capacity left to seat this student (group: {$groupKey})."
                ));
            }
        }

        return new SeatingResult($placements, $warnings);
    }
}
