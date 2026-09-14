<?php

namespace App\Services\Generation\Strategies;

use App\Services\Generation\DTOs\SeatingResult;
use App\Services\Generation\DTOs\SeatingWarning;
use App\Services\Generation\DTOs\SeatPlacement;
use App\Services\Generation\RoomFiller;
use Illuminate\Support\Collection;

/**
 * Rooms are filled to capacity with students from different subjects
 * mixed together (no per-group room exclusivity), round-robin interleaved
 * by subject so neighboring seats differ where possible. Before each seat
 * is committed, its already-placed up/down/left/right neighbors are
 * checked; if every remaining candidate would clash, one is placed anyway
 * and reported as a warning rather than leaving the seat empty or failing.
 */
class MixedSeatingStrategy implements SeatingStrategy
{
    public function __construct(protected RoomFiller $filler = new RoomFiller)
    {
    }

    public function allocate(Collection $enrollments, array $rooms): SeatingResult
    {
        $queue = $this->interleaveBySubject($enrollments);
        $placements = [];
        $warnings = collect();

        foreach ($rooms as $room) {
            if (empty($queue)) {
                break;
            }

            $seatOrder = array_slice(
                $this->filler->seatOrder($room['rows'], $room['columns']),
                0,
                max(0, $room['capacity'])
            );

            $occupiedKeys = array_flip(array_map(
                fn (array $seat) => "{$seat['row']}:{$seat['column']}",
                $room['occupied']
            ));

            $grid = [];
            foreach ($room['occupied'] as $seat) {
                $grid["{$seat['row']}:{$seat['column']}"] = $seat['subject_id'];
            }

            foreach ($seatOrder as $seat) {
                if (empty($queue)) {
                    break;
                }

                $key = "{$seat['row']}:{$seat['column']}";

                if (isset($occupiedKeys[$key])) {
                    continue;
                }

                $neighborSubjects = $this->neighborSubjects($seat['row'], $seat['column'], $grid);
                $chosenIndex = $this->firstNonClashing($queue, $neighborSubjects);

                if ($chosenIndex === null) {
                    $chosenIndex = 0;
                    $warnings->push(new SeatingWarning(
                        $queue[0]['enrollment_id'],
                        "Could not avoid seating this student next to another student of the same subject (room seat row {$seat['row']}, column {$seat['column']})."
                    ));
                }

                $item = array_splice($queue, $chosenIndex, 1)[0];
                $placements[] = new SeatPlacement($item['enrollment_id'], $room['room_id'], $seat['row'], $seat['column']);
                $grid[$key] = $item['subject_id'];
            }
        }

        foreach ($queue as $item) {
            $warnings->push(new SeatingWarning($item['enrollment_id'], 'No room capacity left to seat this student.'));
        }

        return new SeatingResult($placements, $warnings);
    }

    /**
     * @param  array<int, array{enrollment_id: int, subject_id: int}>  $queue
     * @param  int[]  $avoidSubjects
     */
    private function firstNonClashing(array $queue, array $avoidSubjects): ?int
    {
        foreach ($queue as $index => $item) {
            if (! in_array($item['subject_id'], $avoidSubjects, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $grid  "row:column" => subject_id
     * @return int[]
     */
    private function neighborSubjects(int $row, int $column, array $grid): array
    {
        $keys = [
            ($row - 1).":{$column}",
            ($row + 1).":{$column}",
            "{$row}:".($column - 1),
            "{$row}:".($column + 1),
        ];

        return array_values(array_filter(array_map(fn ($key) => $grid[$key] ?? null, $keys), fn ($v) => $v !== null));
    }

    /**
     * Round-robin merge so consecutive items differ in subject wherever
     * enough distinct subjects exist, sorted by roll number within each.
     *
     * @param  Collection<int, object{id: int, subject_id: int}>  $enrollments
     * @return array<int, array{enrollment_id: int, subject_id: int}>
     */
    private function interleaveBySubject(Collection $enrollments): array
    {
        // Plain array (not a Collection) so the reference-based foreach
        // below reliably mutates the queues in place while draining them.
        $bySubject = $enrollments
            ->groupBy('subject_id')
            ->map(fn (Collection $group) => $group->pluck('id')->all())
            ->all();

        $result = [];

        while (array_filter($bySubject)) {
            foreach ($bySubject as $subjectId => &$ids) {
                if (! empty($ids)) {
                    $result[] = ['enrollment_id' => array_shift($ids), 'subject_id' => $subjectId];
                }
            }
            unset($ids);
        }

        return $result;
    }
}
