<?php

namespace Tests\Unit\Services\Generation\Strategies;

use App\Services\Generation\Strategies\StrictSeatingStrategy;
use PHPUnit\Framework\TestCase;

class StrictSeatingStrategyTest extends TestCase
{
    private function enrollment(int $id, int $subjectId, string $section = 'A'): object
    {
        return (object) ['id' => $id, 'subject_id' => $subjectId, 'section' => $section];
    }

    private function room(int $roomId, int $rows, int $columns, ?int $capacity = null): array
    {
        return [
            'room_id' => $roomId,
            'rows' => $rows,
            'columns' => $columns,
            'capacity' => $capacity ?? $rows * $columns,
            'occupied' => [],
        ];
    }

    public function test_one_group_fits_in_one_room(): void
    {
        $enrollments = collect([
            $this->enrollment(1, 100, 'A'),
            $this->enrollment(2, 100, 'A'),
        ]);

        $result = (new StrictSeatingStrategy)->allocate($enrollments, [$this->room(1, 5, 5)]);

        $this->assertCount(2, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());
        $this->assertSame(1, $result->placements[0]->roomId);
        $this->assertSame(1, $result->placements[1]->roomId);
    }

    public function test_different_subject_section_groups_never_share_a_room(): void
    {
        $enrollments = collect([
            $this->enrollment(1, 100, 'A'),
            $this->enrollment(2, 200, 'A'),
        ]);

        $result = (new StrictSeatingStrategy)->allocate($enrollments, [
            $this->room(1, 5, 5),
            $this->room(2, 5, 5),
        ]);

        $roomOf = collect($result->placements)->pluck('roomId', 'enrollmentId');
        $this->assertNotSame($roomOf[1], $roomOf[2]);
    }

    public function test_oversized_group_splits_across_consecutive_rooms(): void
    {
        // 5 students, room capacity 3 -> needs a second room for the rest.
        $enrollments = collect(array_map(fn ($i) => $this->enrollment($i, 100, 'A'), range(1, 5)));

        $result = (new StrictSeatingStrategy)->allocate($enrollments, [
            $this->room(1, 3, 1),
            $this->room(2, 3, 1),
        ]);

        $this->assertCount(5, $result->placements);
        $roomOf = collect($result->placements)->pluck('roomId', 'enrollmentId');
        $this->assertSame(1, $roomOf[1]);
        $this->assertSame(1, $roomOf[2]);
        $this->assertSame(1, $roomOf[3]);
        $this->assertSame(2, $roomOf[4]);
        $this->assertSame(2, $roomOf[5]);
    }

    public function test_running_out_of_rooms_produces_warnings_not_a_crash(): void
    {
        $enrollments = collect([
            $this->enrollment(1, 100, 'A'),
            $this->enrollment(2, 100, 'A'),
            $this->enrollment(3, 100, 'A'),
        ]);

        $result = (new StrictSeatingStrategy)->allocate($enrollments, [$this->room(1, 2, 1)]);

        $this->assertCount(2, $result->placements);
        $this->assertCount(1, $result->warnings);
        $this->assertSame(3, $result->warnings->first()->enrollmentId);
    }

    public function test_largest_group_is_placed_in_the_largest_room_first(): void
    {
        $bigGroup = array_map(fn ($i) => $this->enrollment($i, 100, 'A'), range(1, 4));
        $smallGroup = [$this->enrollment(5, 200, 'A')];

        $result = (new StrictSeatingStrategy)->allocate(collect([...$smallGroup, ...$bigGroup]), [
            $this->room(1, 2, 1), // small room, capacity 2
            $this->room(2, 4, 1), // big room, capacity 4
        ]);

        $roomOf = collect($result->placements)->pluck('roomId', 'enrollmentId');
        $this->assertSame(2, $roomOf[1]); // big group -> big room
        $this->assertTrue($result->warnings->isEmpty());
    }

    public function test_a_locked_seat_counts_against_the_room_a_group_would_otherwise_just_fit(): void
    {
        // Group A has exactly 6 students and the 6-seat room is its natural home - but one of that room's seats
        // is already held by a locked student from somewhere else. A must move to the bigger room, not lose a seat.
        $enrollments = collect();
        foreach (range(1, 6) as $i) {
            $enrollments->push($this->enrollment($i, 100, 'A'));
        }

        $small = $this->room(1, 2, 3);
        $small['occupied'] = [['row' => 1, 'column' => 3, 'subject_id' => 999]];
        $big = $this->room(2, 4, 3);

        $result = (new StrictSeatingStrategy)->allocate($enrollments, [$small, $big]);

        $this->assertTrue($result->warnings->isEmpty(), 'nobody should be left unseated');
        $this->assertCount(6, $result->placements);
        $this->assertSame([2], collect($result->placements)->pluck('roomId')->unique()->values()->all());
    }

    public function test_a_locked_seat_beyond_the_capacity_limit_does_not_shrink_the_room(): void
    {
        // Capacity 4 of a 2x3 grid fills seats (r1c1, r2c1, r1c2, r2c2); a locked student in c3 is outside that
        // range and must not count against the room.
        $enrollments = collect(range(1, 4))->map(fn ($i) => $this->enrollment($i, 100, 'A'));
        $room = $this->room(1, 2, 3, 4);
        $room['occupied'] = [['row' => 1, 'column' => 3, 'subject_id' => 999]];

        $result = (new StrictSeatingStrategy)->allocate($enrollments, [$room, $this->room(2, 10, 10)]);

        $this->assertTrue($result->warnings->isEmpty());
        $this->assertSame([1], collect($result->placements)->pluck('roomId')->unique()->values()->all());
    }

    public function test_a_group_split_across_rooms_uses_each_rooms_free_seats(): void
    {
        $enrollments = collect(range(1, 7))->map(fn ($i) => $this->enrollment($i, 100, 'A'));
        $a = $this->room(1, 2, 3); // 6 seats, 2 locked -> 4 free
        $a['occupied'] = [['row' => 1, 'column' => 1, 'subject_id' => 999], ['row' => 2, 'column' => 1, 'subject_id' => 999]];
        $b = $this->room(2, 2, 2); // 4 free seats

        $result = (new StrictSeatingStrategy)->allocate($enrollments, [$a, $b]);

        $seats = collect($result->placements)->map(fn ($p) => "{$p->roomId}:{$p->row}:{$p->column}");

        $this->assertTrue($result->warnings->isEmpty());
        $this->assertCount(7, $result->placements);
        $this->assertCount(7, $seats->unique());
        $this->assertNotContains('1:1:1', $seats->all());
        $this->assertNotContains('1:2:1', $seats->all());
    }
}
