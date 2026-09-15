<?php

namespace App\Services\Generation\Strategies;

use App\Services\Generation\DTOs\SeatingResult;
use App\Services\Generation\DTOs\SeatingWarning;
use App\Services\Generation\DTOs\SeatPlacement;
use App\Services\Generation\RoomFiller;
use Illuminate\Support\Collection;

/**
 * One room is dedicated to a "primary" group — grouped the same way as
 * Strict (subject+section) or Combine Sections (subject only), depending
 * on $groupBy — but any seats the primary group doesn't use are filled
 * with a second, "overflow" group instead of being left empty, so a small
 * leftover group doesn't force an extra room. $overflowSource controls
 * which group is eligible to fill the leftover seats:
 *  - 'same_subject': another section of the SAME subject. Only meaningful
 *    with $groupBy = 'subject_section' (Strict's own grouping) — Combine
 *    Sections already merges every section of a subject, so there's no
 *    "other section" left to add.
 *  - 'other_subject': any different subject's group.
 * A room's seat order is unaffected: the primary group fills column by
 * column from the top as usual, and the overflow group (if any) simply
 * continues filling whatever seats are left in that same order — reusing
 * RoomFiller::fillRoom() a second time with the primary group's seats
 * marked as occupied naturally achieves this.
 */
class GroupedWithOverflowStrategy implements SeatingStrategy
{
    public function __construct(
        private readonly string $groupBy,
        private readonly string $overflowSource,
        protected RoomFiller $filler = new RoomFiller
    ) {
    }

    public function allocate(Collection $enrollments, array $rooms): SeatingResult
    {
        $groups = $this->buildGroups($enrollments);
        $placements = [];
        $available = array_keys($rooms);

        $order = array_keys($groups);
        usort($order, fn ($a, $b) => count($groups[$b]['ids']) <=> count($groups[$a]['ids']));

        foreach ($order as $key) {
            while (! empty($groups[$key]['ids']) && ! empty($available)) {
                $itemIds = $groups[$key]['ids'];
                $index = $this->bestFitRoom($available, $rooms, count($itemIds))
                    ?? $this->largestAvailableRoom($available, $rooms);

                $room = $rooms[$index];
                $result = $this->filler->fillRoom($itemIds, $room['rows'], $room['columns'], $room['capacity'], $room['occupied']);

                $groups[$key]['ids'] = $result['remaining'];

                // Removed unconditionally, even when it seated no one (a
                // room whose reported capacity ignores pre-occupied locked
                // seats): this loop re-queries best-fit every iteration, so
                // leaving an already-full room available would spin on it.
                $available = array_values(array_diff($available, [$index]));

                if (empty($result['placements'])) {
                    continue;
                }

                foreach ($result['placements'] as $p) {
                    $placements[] = new SeatPlacement($p['item_id'], $room['room_id'], $p['row'], $p['column']);
                }

                if ($this->overflowSource !== 'none') {
                    $occupiedNow = array_merge(
                        $room['occupied'],
                        array_map(fn ($p) => ['row' => $p['row'], 'column' => $p['column']], $result['placements'])
                    );

                    $this->fillOverflow($room, $occupiedNow, $groups[$key]['subject_id'], $key, $groups, $placements);
                }
            }
        }

        $warnings = collect();

        foreach ($groups as $key => $group) {
            foreach ($group['ids'] as $enrollmentId) {
                $warnings->push(new SeatingWarning($enrollmentId, "No room capacity left to seat this student (group: {$key})."));
            }
        }

        return new SeatingResult($placements, $warnings);
    }

    /**
     * Keeps pulling in compatible overflow groups (largest whole-fit
     * first, otherwise the largest available) until the room is full or
     * no compatible group has anyone left — maximizing how much of the
     * leftover space gets used rather than stopping after just one.
     *
     * @param  array<string, array{subject_id:int, ids:int[]}>  $groups
     */
    private function fillOverflow(array $room, array $occupied, int $primarySubjectId, string $primaryKey, array &$groups, array &$placements): void
    {
        while (true) {
            $candidateKey = $this->bestOverflowCandidate($groups, $primarySubjectId, $primaryKey, $room['capacity'] - count($occupied));

            if ($candidateKey === null) {
                return;
            }

            $result = $this->filler->fillRoom($groups[$candidateKey]['ids'], $room['rows'], $room['columns'], $room['capacity'], $occupied);
            $groups[$candidateKey]['ids'] = $result['remaining'];

            if (empty($result['placements'])) {
                return;
            }

            foreach ($result['placements'] as $p) {
                $placements[] = new SeatPlacement($p['item_id'], $room['room_id'], $p['row'], $p['column']);
                $occupied[] = ['row' => $p['row'], 'column' => $p['column']];
            }
        }
    }

    /**
     * Prefers a compatible group that fits entirely within the leftover
     * capacity (so it won't also need a room of its own later); falls
     * back to the largest compatible group otherwise, to leave the
     * smallest possible remainder.
     *
     * @param  array<string, array{subject_id:int, ids:int[]}>  $groups
     */
    private function bestOverflowCandidate(array $groups, int $primarySubjectId, string $primaryKey, int $leftover): ?string
    {
        if ($leftover <= 0) {
            return null;
        }

        $candidates = collect($groups)
            ->except($primaryKey)
            ->filter(fn ($g) => ! empty($g['ids']))
            ->filter(fn ($g) => $this->overflowSource === 'same_subject'
                ? $g['subject_id'] === $primarySubjectId
                : $g['subject_id'] !== $primarySubjectId);

        if ($candidates->isEmpty()) {
            return null;
        }

        $wholeFit = $candidates->filter(fn ($g) => count($g['ids']) <= $leftover)->sortByDesc(fn ($g) => count($g['ids']));

        if ($wholeFit->isNotEmpty()) {
            return $wholeFit->keys()->first();
        }

        return $candidates->sortByDesc(fn ($g) => count($g['ids']))->keys()->first();
    }

    /**
     * @param  int[]  $available
     * @param  array<int, array{capacity:int}>  $rooms
     */
    private function bestFitRoom(array $available, array $rooms, int $count): ?int
    {
        $bestFit = null;

        foreach ($available as $index) {
            if ($rooms[$index]['capacity'] >= $count
                && ($bestFit === null || $rooms[$index]['capacity'] < $rooms[$bestFit]['capacity'])) {
                $bestFit = $index;
            }
        }

        return $bestFit;
    }

    /**
     * @param  int[]  $available
     * @param  array<int, array{capacity:int}>  $rooms
     */
    private function largestAvailableRoom(array $available, array $rooms): int
    {
        $indexes = $available;
        usort($indexes, fn ($a, $b) => $rooms[$b]['capacity'] <=> $rooms[$a]['capacity']);

        return $indexes[0];
    }

    /**
     * @return array<string, array{subject_id:int, ids:int[]}>
     */
    private function buildGroups(Collection $enrollments): array
    {
        $key = $this->groupBy === 'subject'
            ? fn ($e) => (string) $e->subject_id
            : fn ($e) => $e->subject_id.'|'.$e->section;

        return $enrollments
            ->groupBy($key)
            ->map(fn (Collection $group) => [
                'subject_id' => $group->first()->subject_id,
                'ids' => $group->pluck('id')->all(),
            ])
            ->all();
    }
}
