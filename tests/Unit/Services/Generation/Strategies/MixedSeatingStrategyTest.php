<?php

namespace Tests\Unit\Services\Generation\Strategies;

use App\Services\Generation\Strategies\MixedSeatingStrategy;
use PHPUnit\Framework\TestCase;

class MixedSeatingStrategyTest extends TestCase
{
    private function enrollment(int $id, int $subjectId, string $section = 'A'): object
    {
        return (object) ['id' => $id, 'subject_id' => $subjectId, 'section' => $section];
    }

    private function enrollments(int $count, int $subjectId, int &$nextId): array
    {
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->enrollment($nextId++, $subjectId);
        }

        return $result;
    }

    private function room(int $roomId, int $rows, int $columns, array $occupied = [], ?int $capacity = null): array
    {
        return ['room_id' => $roomId, 'rows' => $rows, 'columns' => $columns, 'capacity' => $capacity ?? $rows * $columns, 'occupied' => $occupied];
    }

    /**
     * @return array<int, int> enrollment_id => subject_id
     */
    private function subjectByEnrollmentId(array $enrollments): array
    {
        return collect($enrollments)->mapWithKeys(fn ($e) => [$e->id => $e->subject_id])->all();
    }

    private function columnsUsedBySubject(array $placements, array $subjectByEnrollmentId, int $subjectId): array
    {
        return collect($placements)
            ->filter(fn ($p) => $subjectByEnrollmentId[$p->enrollmentId] === $subjectId)
            ->pluck('column')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function test_two_groups_alternate_whole_columns(): void
    {
        $nextId = 1;
        $a = $this->enrollments(6, 100, $nextId);
        $b = $this->enrollments(6, 200, $nextId);
        $enrollments = collect([...$a, ...$b]);

        $result = (new MixedSeatingStrategy(groupSize: 2))->allocate($enrollments, [$this->room(1, 3, 4)]);

        $this->assertCount(12, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());

        $subjectByEnrollmentId = $this->subjectByEnrollmentId([...$a, ...$b]);
        $this->assertSame([1, 3], $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 100));
        $this->assertSame([2, 4], $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 200));
    }

    public function test_group_size_of_three_cycles_three_subjects_across_columns(): void
    {
        $nextId = 1;
        $a = $this->enrollments(4, 100, $nextId);
        $b = $this->enrollments(4, 200, $nextId);
        $c = $this->enrollments(4, 300, $nextId);
        $enrollments = collect([...$a, ...$b, ...$c]);

        $result = (new MixedSeatingStrategy(groupSize: 3))->allocate($enrollments, [$this->room(1, 2, 6)]);

        $this->assertCount(12, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());

        $subjectByEnrollmentId = $this->subjectByEnrollmentId([...$a, ...$b, ...$c]);
        $used = [
            100 => $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 100),
            200 => $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 200),
            300 => $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 300),
        ];

        // Each subject owns exactly one of the three 2-column cycles, and no
        // two subjects share a column.
        foreach ($used as $columns) {
            $this->assertCount(2, $columns);
        }
        $this->assertCount(6, array_unique(array_merge(...array_values($used))));
    }

    public function test_the_largest_group_is_matched_to_the_largest_column_slot(): void
    {
        // 3 columns split 2-way: columns {1,3} (2 cols) vs {2} (1 col).
        // With 4 rows that's 8 seats vs 4 seats.
        $nextId = 1;
        $big = $this->enrollments(8, 100, $nextId);
        $small = $this->enrollments(3, 200, $nextId);
        $enrollments = collect([...$big, ...$small]);

        $result = (new MixedSeatingStrategy(groupSize: 2))->allocate($enrollments, [$this->room(1, 4, 3)]);

        $this->assertCount(11, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());

        $subjectByEnrollmentId = $this->subjectByEnrollmentId([...$big, ...$small]);
        $this->assertSame([1, 3], $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 100));
        $this->assertSame([2], $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 200));
    }

    public function test_a_group_too_large_for_one_rooms_slot_carries_overflow_to_the_next_room(): void
    {
        $nextId = 1;
        // groupSize 2, each room is 2 rows x 2 columns -> 2 seats per column-group.
        $a = $this->enrollments(4, 100, $nextId); // needs 2 rooms' worth of its column-group
        $b = $this->enrollments(2, 200, $nextId);
        $enrollments = collect([...$a, ...$b]);

        $result = (new MixedSeatingStrategy(groupSize: 2))->allocate($enrollments, [
            $this->room(1, 2, 2),
            $this->room(2, 2, 2),
        ]);

        $this->assertCount(6, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());

        $roomsUsedBySubjectA = collect($result->placements)
            ->filter(fn ($p) => in_array($p->enrollmentId, collect($a)->pluck('id')->all(), true))
            ->pluck('roomId')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 2], $roomsUsedBySubjectA);
    }

    public function test_fewer_groups_than_group_size_leaves_the_extra_column_slot_empty(): void
    {
        $nextId = 1;
        $a = $this->enrollments(2, 100, $nextId);
        $b = $this->enrollments(2, 200, $nextId);
        $enrollments = collect([...$a, ...$b]);

        // groupSize 3 but only 2 subjects exist -> the third column-group's
        // column stays empty rather than forcing a nonexistent third group.
        $result = (new MixedSeatingStrategy(groupSize: 3))->allocate($enrollments, [$this->room(1, 2, 3)]);

        $this->assertCount(4, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());

        $usedColumns = collect($result->placements)->pluck('column')->unique()->sort()->values()->all();
        $this->assertCount(2, $usedColumns);
    }

    public function test_no_capacity_left_is_reported_as_a_warning(): void
    {
        $nextId = 1;
        $a = $this->enrollments(2, 100, $nextId);
        $b = $this->enrollments(2, 200, $nextId);
        $enrollments = collect([...$a, ...$b]);

        $result = (new MixedSeatingStrategy(groupSize: 2))->allocate($enrollments, [$this->room(1, 1, 2)]);

        $this->assertCount(2, $result->placements);
        $this->assertCount(2, $result->warnings);
    }

    public function test_occupied_seats_are_respected_as_obstacles(): void
    {
        $nextId = 1;
        // Column 1 has 2 physical seats but one (1,1) is already occupied
        // by a locked seat from a different subject, leaving only 1 free.
        $a = $this->enrollments(1, 100, $nextId);
        $enrollments = collect($a);

        $result = (new MixedSeatingStrategy(groupSize: 2))->allocate($enrollments, [
            $this->room(1, 2, 2, occupied: [['row' => 1, 'column' => 1, 'subject_id' => 200]]),
        ]);

        $this->assertCount(1, $result->placements);
        $this->assertSame(2, $result->placements[0]->row);
        $this->assertSame(1, $result->placements[0]->column);
    }

    public function test_a_capacity_override_below_the_physical_grid_drops_trailing_columns(): void
    {
        // 10 rows x 5 columns physically (50 seats) but the session caps this
        // room at 40 for distancing. That must leave whole column 5 empty
        // rather than seating 50 students, matching production incident
        // where mixed rooms ignored capacity_override entirely.
        $nextId = 1;
        $a = $this->enrollments(30, 100, $nextId);
        $b = $this->enrollments(20, 200, $nextId);
        $enrollments = collect([...$a, ...$b]);

        $result = (new MixedSeatingStrategy(groupSize: 2))->allocate(
            $enrollments,
            [$this->room(1, 10, 5, capacity: 40)]
        );

        $this->assertCount(40, $result->placements);
        $this->assertCount(10, $result->warnings);

        $usedColumns = collect($result->placements)->pluck('column')->unique()->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4], $usedColumns);

        $subjectByEnrollmentId = $this->subjectByEnrollmentId([...$a, ...$b]);
        $this->assertSame([1, 3], $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 100));
        $this->assertSame([2, 4], $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 200));
    }

    public function test_a_room_whose_capacity_is_smaller_than_one_columns_worth_of_rows_is_skipped(): void
    {
        $nextId = 1;
        $a = $this->enrollments(2, 100, $nextId);
        $enrollments = collect($a);

        // capacity 5 with 10 rows per column doesn't fit even one whole
        // column, so the room contributes nothing.
        $result = (new MixedSeatingStrategy(groupSize: 2))->allocate(
            $enrollments,
            [$this->room(1, 10, 5, capacity: 5)]
        );

        $this->assertCount(0, $result->placements);
        $this->assertCount(2, $result->warnings);
    }

    public function test_group_size_is_never_treated_as_less_than_two(): void
    {
        $nextId = 1;
        $a = $this->enrollments(2, 100, $nextId);
        $b = $this->enrollments(2, 200, $nextId);
        $enrollments = collect([...$a, ...$b]);

        // groupSize 1 would defeat the purpose of "mixed" entirely.
        $result = (new MixedSeatingStrategy(groupSize: 1))->allocate($enrollments, [$this->room(1, 2, 2)]);

        $subjectByEnrollmentId = $this->subjectByEnrollmentId([...$a, ...$b]);
        $this->assertNotEmpty($this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 100));
        $this->assertNotEmpty($this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 200));
        $this->assertNotSame(
            $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 100),
            $this->columnsUsedBySubject($result->placements, $subjectByEnrollmentId, 200)
        );
    }
}
