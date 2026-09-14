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
}
