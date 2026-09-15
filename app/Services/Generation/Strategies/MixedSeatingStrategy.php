<?php

namespace App\Services\Generation\Strategies;

use App\Services\Generation\DTOs\SeatingResult;
use App\Services\Generation\DTOs\SeatingWarning;
use App\Services\Generation\DTOs\SeatPlacement;
use App\Services\Generation\RoomFiller;
use Illuminate\Support\Collection;

/**
 * A room's columns are split into `groupSize` column-groups (e.g. 2: odd
 * columns vs even columns), each column-group dedicated to exactly one
 * subject+section for the entire room — a whole column is one subject top
 * to bottom. Left/right neighbors (adjacent columns) always differ in
 * subject; front/back neighbors (same column) share one, deliberately —
 * this is simpler and more predictable to invigilate than per-seat mixing.
 *
 * Groups are matched to a room's column-group capacities by size (largest
 * remaining group to the largest capacity slot) to minimize wasted seats.
 * A group larger than its assigned slot carries its overflow to the next
 * room; a room with fewer waiting groups than `groupSize` simply leaves
 * the unmatched column-groups empty rather than inventing a group.
 */
class MixedSeatingStrategy implements SeatingStrategy
{
    private int $groupSize;

    public function __construct(int $groupSize = 2, protected RoomFiller $filler = new RoomFiller)
    {
        $this->groupSize = max(2, $groupSize);
    }

    public function allocate(Collection $enrollments, array $rooms): SeatingResult
    {
        $queue = $enrollments
            ->groupBy(fn ($e) => $e->subject_id.'|'.$e->section)
            ->values()
            ->map(fn (Collection $group) => [
                'subject_id' => $group->first()->subject_id,
                'ids' => $group->pluck('id')->all(),
            ])
            ->all();

        $placements = [];

        foreach ($rooms as $room) {
            $queue = array_values(array_filter($queue, fn ($g) => ! empty($g['ids'])));

            if (empty($queue)) {
                break;
            }

            // A room's capacity can be overridden below its physical
            // rows*columns (e.g. distancing). Since each column is fully
            // dedicated to one subject, capacity is enforced in whole-column
            // increments — dropping trailing columns — rather than letting
            // any column-group spill past the room's configured limit.
            $usableColumns = $room['rows'] > 0
                ? min($room['columns'], intdiv($room['capacity'], $room['rows']))
                : 0;

            if ($usableColumns < 1) {
                continue;
            }

            $columnGroups = $this->columnGroupsFor($usableColumns, $this->groupSize);
            $capacities = array_map(fn (array $cols) => count($cols) * $room['rows'], $columnGroups);
            arsort($capacities); // largest column-group slot first, keys preserved

            $order = $this->queueIndicesLargestFirst($queue);

            $pairIndex = 0;
            foreach (array_keys($capacities) as $groupIndex) {
                if ($pairIndex >= count($order)) {
                    break;
                }

                $queueIndex = $order[$pairIndex];
                $pairIndex++;

                $result = $this->filler->fillColumns(
                    $queue[$queueIndex]['ids'],
                    $room['rows'],
                    $columnGroups[$groupIndex],
                    $room['occupied']
                );

                foreach ($result['placements'] as $p) {
                    $placements[] = new SeatPlacement($p['item_id'], $room['room_id'], $p['row'], $p['column']);
                }

                $queue[$queueIndex]['ids'] = $result['remaining'];
            }
        }

        $warnings = collect();

        foreach ($queue as $group) {
            foreach ($group['ids'] as $enrollmentId) {
                $warnings->push(new SeatingWarning($enrollmentId, 'No room capacity left to seat this student.'));
            }
        }

        return new SeatingResult($placements, $warnings);
    }

    /**
     * Column c (1-indexed) belongs to column-group (c-1) % groupSize, so
     * with groupSize=2 columns alternate 1,3,5.. vs 2,4,6..; with
     * groupSize=3 they cycle 1,4.. / 2,5.. / 3,6.. and so on.
     *
     * @return array<int, int[]> groupIndex => column numbers
     */
    private function columnGroupsFor(int $columns, int $groupSize): array
    {
        $groups = array_fill(0, $groupSize, []);

        for ($column = 1; $column <= $columns; $column++) {
            $groups[($column - 1) % $groupSize][] = $column;
        }

        return $groups;
    }

    /**
     * @param  array<int, array{subject_id: int, ids: int[]}>  $queue
     * @return int[] queue indices, largest remaining group first
     */
    private function queueIndicesLargestFirst(array $queue): array
    {
        $indices = array_keys($queue);

        usort($indices, fn ($a, $b) => count($queue[$b]['ids']) <=> count($queue[$a]['ids']));

        return $indices;
    }
}
