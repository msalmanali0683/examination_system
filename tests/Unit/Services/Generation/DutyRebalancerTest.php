<?php

namespace Tests\Unit\Services\Generation;

use App\Services\Generation\DTOs\DutyPlacement;
use App\Services\Generation\DTOs\DutyResult;
use App\Services\Generation\DutyRebalancer;
use PHPUnit\Framework\TestCase;

class DutyRebalancerTest extends TestCase
{
    private function teacher(int $id, int $min = 0, int $max = 5): array
    {
        return ['id' => $id, 'minDuties' => $min, 'maxDuties' => $max];
    }

    public function test_swaps_a_duty_from_an_over_min_donor_to_an_under_min_teacher(): void
    {
        $teachers = [$this->teacher(1, min: 2), $this->teacher(2, min: 0)];
        $slots = [
            ['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => []],
            ['id' => 200, 'roomIds' => [1], 'unavailableTeacherIds' => []],
        ];
        $initial = new DutyResult([
            new DutyPlacement(2, 100, 1),
            new DutyPlacement(2, 200, 1),
        ], collect());

        $result = (new DutyRebalancer)->rebalance($initial, $teachers, $slots, []);

        $teacherIds = collect($result->placements)->pluck('teacherId')->all();
        $this->assertSame([1, 1], $teacherIds);
        $this->assertTrue($result->warnings->where('type', 'unmet_minimum')->isEmpty());
    }

    public function test_never_swaps_a_donor_below_their_own_minimum(): void
    {
        $teachers = [$this->teacher(1, min: 1), $this->teacher(2, min: 1)];
        $slots = [['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => []]];
        $initial = new DutyResult([new DutyPlacement(2, 100, 1)], collect());

        $result = (new DutyRebalancer)->rebalance($initial, $teachers, $slots, []);

        // Donor (teacher 2) is already at their own minimum of 1, so giving
        // up this duty would drop them below it — the swap must not happen.
        $this->assertSame(2, $result->placements[0]->teacherId);
        $this->assertCount(1, $result->warnings->where('type', 'unmet_minimum'));
    }

    public function test_never_assigns_a_teacher_unavailable_for_that_slot(): void
    {
        $teachers = [$this->teacher(1, min: 1), $this->teacher(2, min: 0)];
        $slots = [['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => [1]]];
        $initial = new DutyResult([new DutyPlacement(2, 100, 1)], collect());

        $result = (new DutyRebalancer)->rebalance($initial, $teachers, $slots, []);

        $this->assertSame(2, $result->placements[0]->teacherId);
        $this->assertCount(1, $result->warnings->where('type', 'unmet_minimum'));
    }

    public function test_never_double_books_a_teacher_already_on_duty_that_slot(): void
    {
        $teachers = [$this->teacher(1, min: 2), $this->teacher(2, min: 0)];
        $slots = [['id' => 100, 'roomIds' => [1, 2], 'unavailableTeacherIds' => []]];
        // Teacher 1 already has room 1 this slot; a swap that would also
        // give them room 2 in the same slot must be rejected.
        $initial = new DutyResult([
            new DutyPlacement(1, 100, 1),
            new DutyPlacement(2, 100, 2),
        ], collect());

        $result = (new DutyRebalancer)->rebalance($initial, $teachers, $slots, []);

        $teacherIds = collect($result->placements)->pluck('teacherId')->all();
        $this->assertSame([1, 2], $teacherIds);
        $this->assertCount(1, $result->warnings->where('type', 'unmet_minimum'));
    }

    public function test_prefers_a_non_adjacent_swap_over_one_that_would_create_a_back_to_back_duty(): void
    {
        $teachers = [$this->teacher(1, min: 2), $this->teacher(2, min: 0)];
        $locked = [new DutyPlacement(1, 100, 1)]; // teacher 1 already on duty slot 100
        $slots = [
            ['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => []],
            ['id' => 200, 'roomIds' => [1], 'unavailableTeacherIds' => [], 'adjacentSlotIds' => [100]],
            ['id' => 300, 'roomIds' => [1], 'unavailableTeacherIds' => []],
        ];
        // Two possible donor slots for teacher 1's missing second duty: 200
        // (adjacent to their existing slot 100) and 300 (not adjacent).
        $initial = new DutyResult([
            new DutyPlacement(2, 200, 1),
            new DutyPlacement(2, 300, 1),
        ], collect());

        $result = (new DutyRebalancer)->rebalance($initial, $teachers, $slots, $locked);

        $byTeacher = collect($result->placements)->pluck('teacherId', 'timeSlotId');
        $this->assertSame(2, $byTeacher[200]); // left alone — would be back-to-back with slot 100
        $this->assertSame(1, $byTeacher[300]); // swapped instead
        $this->assertTrue($result->warnings->where('type', 'unmet_minimum')->isEmpty());
    }

    public function test_still_swaps_into_an_adjacent_slot_when_it_is_the_only_way_to_reach_the_minimum(): void
    {
        $teachers = [$this->teacher(1, min: 2), $this->teacher(2, min: 0)];
        $locked = [new DutyPlacement(1, 100, 1)];
        $slots = [
            ['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => []],
            ['id' => 200, 'roomIds' => [1], 'unavailableTeacherIds' => [], 'adjacentSlotIds' => [100]],
        ];
        // The only other duty to swap from is adjacent to teacher 1's
        // existing slot — the preference must not block the swap outright.
        $initial = new DutyResult([new DutyPlacement(2, 200, 1)], collect());

        $result = (new DutyRebalancer)->rebalance($initial, $teachers, $slots, $locked);

        $this->assertSame(1, $result->placements[0]->teacherId);
        $this->assertTrue($result->warnings->where('type', 'unmet_minimum')->isEmpty());
    }

    public function test_a_locked_duty_never_gets_swapped_away_and_blocks_the_teachers_slot(): void
    {
        $teachers = [$this->teacher(1, min: 1), $this->teacher(2, min: 0)];
        $slots = [['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => []]];
        $locked = [new DutyPlacement(1, 100, 1)]; // teacher 1 already locked into slot 100
        // The only unlocked placement is teacher 2 in a different slot.
        $initial = new DutyResult([new DutyPlacement(2, 200, 1)], collect());
        $slotsWithBoth = array_merge($slots, [['id' => 200, 'roomIds' => [1], 'unavailableTeacherIds' => []]]);

        $result = (new DutyRebalancer)->rebalance($initial, $teachers, $slotsWithBoth, $locked);

        // Teacher 1's minimum of 1 is already satisfied by the locked duty,
        // so no swap should be attempted at all.
        $this->assertSame(2, $result->placements[0]->teacherId);
        $this->assertTrue($result->warnings->where('type', 'unmet_minimum')->isEmpty());
    }
}
