<?php

namespace App\Services\Generation;

class RoomFiller
{
    /**
     * The single source of truth for seat fill order, matching the
     * department's real seating-chart convention: column 1 is filled
     * completely (top to bottom) before moving to column 2, etc. (verified
     * against real "Sitting Plan" templates — students sorted by roll
     * number, listed down each "Row #" column before the next one starts).
     *
     * @return array<int, array{row: int, column: int}>
     */
    public function seatOrder(int $rows, int $columns): array
    {
        $seats = [];

        for ($column = 1; $column <= $columns; $column++) {
            for ($row = 1; $row <= $rows; $row++) {
                $seats[] = ['row' => $row, 'column' => $column];
            }
        }

        return $seats;
    }

    /**
     * Fill a single room's seats (respecting capacity and any already-locked
     * occupied seats, which are skipped as obstacles) with as many items as
     * fit, in the order given.
     *
     * @param  int[]  $itemIds
     * @param  array<int, array{row: int, column: int}>  $occupied
     * @return array{placements: array<int, array{item_id: int, row: int, column: int}>, remaining: int[]}
     */
    public function fillRoom(array $itemIds, int $rows, int $columns, int $capacity, array $occupied = []): array
    {
        $occupiedKeys = array_flip(array_map(
            fn (array $seat) => "{$seat['row']}:{$seat['column']}",
            $occupied
        ));

        $seatOrder = array_slice($this->seatOrder($rows, $columns), 0, max(0, $capacity));

        $placements = [];
        $remaining = $itemIds;

        foreach ($seatOrder as $seat) {
            if (empty($remaining)) {
                break;
            }

            $key = "{$seat['row']}:{$seat['column']}";

            if (isset($occupiedKeys[$key])) {
                continue;
            }

            $placements[] = [
                'item_id' => array_shift($remaining),
                'row' => $seat['row'],
                'column' => $seat['column'],
            ];
        }

        return ['placements' => $placements, 'remaining' => $remaining];
    }

    /**
     * How many students fillRoom() could still seat in this room: the seats
     * within its capacity that are not already held by a locked (manually
     * placed) student. Strategies must pick rooms by THIS number, not by
     * nominal capacity — otherwise a locked seat in the room a group "just
     * fits" leaves that group one student short even though other rooms
     * are free.
     *
     * @param  array<int, array{row: int, column: int}>  $occupied
     */
    public function freeSeats(int $rows, int $columns, int $capacity, array $occupied = []): int
    {
        $capacity = max(0, min($capacity, $rows * $columns));

        if (empty($occupied)) {
            return $capacity;
        }

        $occupiedKeys = array_flip(array_map(
            fn (array $seat) => "{$seat['row']}:{$seat['column']}",
            $occupied
        ));

        $free = 0;

        foreach (array_slice($this->seatOrder($rows, $columns), 0, $capacity) as $seat) {
            if (! isset($occupiedKeys["{$seat['row']}:{$seat['column']}"])) {
                $free++;
            }
        }

        return $free;
    }

    /**
     * Same fill order convention (top to bottom within a column, in
     * ascending column order) but restricted to a specific subset of a
     * room's columns — used by Mixed seating, where a whole column is
     * dedicated to one subject/section rather than the whole room.
     *
     * @param  int[]  $columns
     * @return array<int, array{row: int, column: int}>
     */
    public function seatOrderForColumns(int $rows, array $columns): array
    {
        $seats = [];

        foreach ($columns as $column) {
            for ($row = 1; $row <= $rows; $row++) {
                $seats[] = ['row' => $row, 'column' => $column];
            }
        }

        return $seats;
    }

    /**
     * @param  int[]  $itemIds
     * @param  int[]  $columns
     * @param  array<int, array{row: int, column: int}>  $occupied
     * @return array{placements: array<int, array{item_id: int, row: int, column: int}>, remaining: int[]}
     */
    public function fillColumns(array $itemIds, int $rows, array $columns, array $occupied = []): array
    {
        $occupiedKeys = array_flip(array_map(
            fn (array $seat) => "{$seat['row']}:{$seat['column']}",
            $occupied
        ));

        $placements = [];
        $remaining = $itemIds;

        foreach ($this->seatOrderForColumns($rows, $columns) as $seat) {
            if (empty($remaining)) {
                break;
            }

            $key = "{$seat['row']}:{$seat['column']}";

            if (isset($occupiedKeys[$key])) {
                continue;
            }

            $placements[] = [
                'item_id' => array_shift($remaining),
                'row' => $seat['row'],
                'column' => $seat['column'],
            ];
        }

        return ['placements' => $placements, 'remaining' => $remaining];
    }
}
