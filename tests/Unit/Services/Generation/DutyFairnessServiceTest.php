<?php

namespace Tests\Unit\Services\Generation;

use App\Services\Generation\DTOs\DutyPlacement;
use App\Services\Generation\DutyFairnessService;
use PHPUnit\Framework\TestCase;

class DutyFairnessServiceTest extends TestCase
{
    private function teacher(int $id, int $min = 0, int $max = 5): array
    {
        return ['id' => $id, 'minDuties' => $min, 'maxDuties' => $max];
    }

    public function test_two_rooms_in_the_same_slot_get_two_different_teachers(): void
    {
        $slots = [['id' => 100, 'roomIds' => [1, 2], 'unavailableTeacherIds' => []]];
        $teachers = [$this->teacher(1), $this->teacher(2)];

        $result = (new DutyFairnessService)->generate($slots, $teachers, [], 1);

        $this->assertCount(2, $result->placements);
        $this->assertNotSame($result->placements[0]->teacherId, $result->placements[1]->teacherId);
        $this->assertTrue($result->warnings->isEmpty());
    }

    public function test_load_is_balanced_across_slots_by_running_count(): void
    {
        $slots = [
            ['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => []],
            ['id' => 200, 'roomIds' => [1], 'unavailableTeacherIds' => []],
        ];
        $teachers = [$this->teacher(1), $this->teacher(2)];

        $result = (new DutyFairnessService)->generate($slots, $teachers, [], 1);

        // Ties go to the first teacher in the pool, so slot 100 gets
        // teacher 1; that raises their count, so slot 200 goes to teacher 2.
        $this->assertSame(1, $result->placements[0]->teacherId);
        $this->assertSame(2, $result->placements[1]->teacherId);
    }

    public function test_a_teacher_at_their_max_is_skipped_in_favor_of_someone_under_the_cap(): void
    {
        $slots = [['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => []]];
        $teachers = [$this->teacher(1, max: 1), $this->teacher(2, max: 5)];
        // Teacher 1 already has 1 duty elsewhere (locked), hitting their max.
        $locked = [new DutyPlacement(1, 999, 999)];

        $result = (new DutyFairnessService)->generate($slots, $teachers, $locked, 1);

        $this->assertCount(1, $result->placements);
        $this->assertSame(2, $result->placements[0]->teacherId);
    }

    public function test_a_teacher_unavailable_for_the_slot_is_never_picked(): void
    {
        $slots = [['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => [1]]];
        $teachers = [$this->teacher(1), $this->teacher(2)];

        $result = (new DutyFairnessService)->generate($slots, $teachers, [], 1);

        $this->assertSame(2, $result->placements[0]->teacherId);
    }

    public function test_locked_placements_fill_their_room_and_keep_their_teacher_out_of_other_rooms_that_slot(): void
    {
        $slots = [['id' => 100, 'roomIds' => [1, 2], 'unavailableTeacherIds' => []]];
        $teachers = [$this->teacher(9), $this->teacher(10)];
        $locked = [new DutyPlacement(9, 100, 1)]; // room 1 already fully covered by teacher 9

        $result = (new DutyFairnessService)->generate($slots, $teachers, $locked, invigilatorsPerRoom: 1);

        // Only room 2 needed a fresh pick; teacher 9 is unavailable (already
        // on duty this slot), so it goes to teacher 10.
        $this->assertCount(1, $result->placements);
        $this->assertSame(10, $result->placements[0]->teacherId);
        $this->assertSame(2, $result->placements[0]->roomId);
    }

    public function test_a_room_with_no_eligible_teachers_produces_a_warning_instead_of_a_crash(): void
    {
        $slots = [['id' => 100, 'roomIds' => [5], 'unavailableTeacherIds' => []]];

        $result = (new DutyFairnessService)->generate($slots, [], [], 1);

        $this->assertCount(0, $result->placements);
        $this->assertCount(1, $result->warnings);
        $this->assertSame('understaffed', $result->warnings->first()->type);
        $this->assertSame(5, $result->warnings->first()->roomId);
    }

    public function test_avoids_giving_the_same_teacher_two_adjacent_slots_when_another_teacher_is_available(): void
    {
        $slots = [
            ['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => [], 'adjacentSlotIds' => [200]],
            ['id' => 200, 'roomIds' => [1], 'unavailableTeacherIds' => [], 'adjacentSlotIds' => [100, 300]],
            ['id' => 300, 'roomIds' => [1], 'unavailableTeacherIds' => [], 'adjacentSlotIds' => [200]],
        ];
        $teachers = [$this->teacher(1), $this->teacher(2)];

        $result = (new DutyFairnessService)->generate($slots, $teachers, [], 1);

        $byTeacher = collect($result->placements)->pluck('teacherId', 'timeSlotId');

        $this->assertNotSame($byTeacher[100], $byTeacher[200]);
        $this->assertNotSame($byTeacher[200], $byTeacher[300]);
        $this->assertTrue($result->warnings->isEmpty());
    }

    public function test_falls_back_to_a_consecutive_slot_when_no_other_teacher_is_eligible(): void
    {
        $slots = [
            ['id' => 100, 'roomIds' => [1], 'unavailableTeacherIds' => [], 'adjacentSlotIds' => [200]],
            ['id' => 200, 'roomIds' => [1], 'unavailableTeacherIds' => [], 'adjacentSlotIds' => [100]],
        ];
        // Only one eligible teacher exists at all, so back-to-back is
        // unavoidable — the preference must not turn into a hard block that
        // leaves the second slot understaffed.
        $teachers = [$this->teacher(1)];

        $result = (new DutyFairnessService)->generate($slots, $teachers, [], 1);

        $this->assertCount(2, $result->placements);
        $this->assertSame(1, $result->placements[0]->teacherId);
        $this->assertSame(1, $result->placements[1]->teacherId);
        $this->assertTrue($result->warnings->isEmpty());
    }
}
