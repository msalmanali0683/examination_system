<?php

namespace Tests\Unit\Services\Generation;

use App\Services\Generation\RoomFiller;
use PHPUnit\Framework\TestCase;

class RoomFillerTest extends TestCase
{
    public function test_seat_order_fills_one_column_completely_before_the_next(): void
    {
        $order = (new RoomFiller)->seatOrder(rows: 3, columns: 2);

        $this->assertSame([
            ['row' => 1, 'column' => 1],
            ['row' => 2, 'column' => 1],
            ['row' => 3, 'column' => 1],
            ['row' => 1, 'column' => 2],
            ['row' => 2, 'column' => 2],
            ['row' => 3, 'column' => 2],
        ], $order);
    }

    public function test_fill_room_places_items_in_order_and_stops_at_capacity(): void
    {
        $result = (new RoomFiller)->fillRoom([10, 20, 30, 40], rows: 2, columns: 2, capacity: 3);

        $this->assertCount(3, $result['placements']);
        $this->assertSame(10, $result['placements'][0]['item_id']);
        $this->assertSame(['row' => 1, 'column' => 1], ['row' => $result['placements'][0]['row'], 'column' => $result['placements'][0]['column']]);
        $this->assertSame([40], $result['remaining']);
    }

    public function test_fill_room_skips_already_occupied_seats(): void
    {
        $result = (new RoomFiller)->fillRoom(
            itemIds: [10, 20],
            rows: 2,
            columns: 1,
            capacity: 2,
            occupied: [['row' => 1, 'column' => 1]],
        );

        $this->assertCount(1, $result['placements']);
        $this->assertSame(['item_id' => 10, 'row' => 2, 'column' => 1], $result['placements'][0]);
        $this->assertSame([20], $result['remaining']);
    }

    public function test_fill_room_returns_everything_as_remaining_when_capacity_is_zero(): void
    {
        $result = (new RoomFiller)->fillRoom([10, 20], rows: 5, columns: 5, capacity: 0);

        $this->assertSame([], $result['placements']);
        $this->assertSame([10, 20], $result['remaining']);
    }
}
