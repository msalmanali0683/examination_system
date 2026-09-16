<?php

namespace Tests\Unit\Services\Generation\Strategies;

use App\Services\Generation\Strategies\GroupedWithOverflowStrategy;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class GroupedWithOverflowStrategyTest extends TestCase
{
    private function enrollment(int $id, int $subjectId, string $section = 'A'): object
    {
        return (object) ['id' => $id, 'subject_id' => $subjectId, 'section' => $section];
    }

    private function enrollments(int $count, int $subjectId, string $section, int &$nextId): array
    {
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $result[] = $this->enrollment($nextId++, $subjectId, $section);
        }

        return $result;
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

    private function roomOf(array $placements): Collection
    {
        return collect($placements)->pluck('roomId', 'enrollmentId');
    }

    public function test_strict_grouping_with_same_subject_overflow_fills_leftover_seats_with_another_section(): void
    {
        $nextId = 1;
        // Room seats 10; subject 100 section A has 7 -> 3 leftover seats,
        // filled by subject 100 section B (5 students, 3 fit, 2 carry over).
        $primary = $this->enrollments(7, 100, 'A', $nextId);
        $overflow = $this->enrollments(5, 100, 'B', $nextId);
        $unrelated = $this->enrollments(2, 200, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'same_subject');
        $result = $strategy->allocate(collect([...$primary, ...$overflow, ...$unrelated]), [
            $this->room(1, 10, 1),
            $this->room(2, 10, 1),
            $this->room(3, 10, 1), // headroom so the unrelated subject has somewhere to land
        ]);

        $this->assertTrue($result->warnings->isEmpty());
        $roomOf = $this->roomOf($result->placements);

        foreach ($primary as $e) {
            $this->assertSame(1, $roomOf[$e->id], "primary enrollment {$e->id} should be in room 1");
        }

        $overflowInRoom1 = collect($overflow)->filter(fn ($e) => $roomOf[$e->id] === 1);
        $this->assertCount(3, $overflowInRoom1);

        // Subject 200 (a different subject entirely) must never appear in
        // room 1 under 'same_subject' overflow.
        foreach ($unrelated as $e) {
            $this->assertNotSame(1, $roomOf[$e->id]);
        }
    }

    public function test_strict_grouping_with_other_subject_overflow_never_uses_the_same_subject(): void
    {
        $nextId = 1;
        $primary = $this->enrollments(7, 100, 'A', $nextId);
        $sameSubjectOtherSection = $this->enrollments(5, 100, 'B', $nextId);
        $otherSubject = $this->enrollments(3, 200, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'other_subject');
        $result = $strategy->allocate(collect([...$primary, ...$sameSubjectOtherSection, ...$otherSubject]), [
            $this->room(1, 10, 1),
            $this->room(2, 10, 1),
        ]);

        $roomOf = $this->roomOf($result->placements);

        foreach ($otherSubject as $e) {
            $this->assertSame(1, $roomOf[$e->id], "other-subject enrollment {$e->id} should fill room 1's leftover seats");
        }

        foreach ($sameSubjectOtherSection as $e) {
            $this->assertNotSame(1, $roomOf[$e->id], 'same subject, different section must not be used as overflow under other_subject');
        }
    }

    public function test_same_subject_then_other_prefers_the_same_subject_when_one_is_available(): void
    {
        $nextId = 1;
        // Room seats 10; primary fills 7, leaving exactly 3 leftover. The
        // same-subject section has more than enough (5) to use up every
        // one of those 3 seats itself, so the other-subject group never
        // gets a chance to fill anything in this room.
        $primary = $this->enrollments(7, 100, 'A', $nextId);
        $sameSubjectOtherSection = $this->enrollments(5, 100, 'B', $nextId);
        $otherSubject = $this->enrollments(2, 200, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'same_subject_then_other');
        $result = $strategy->allocate(collect([...$primary, ...$sameSubjectOtherSection, ...$otherSubject]), [
            $this->room(1, 10, 1),
            $this->room(2, 10, 1), // headroom for the leftover same-subject and other-subject groups
        ]);

        $roomOf = $this->roomOf($result->placements);

        $sameSubjectInRoom1 = collect($sameSubjectOtherSection)->filter(fn ($e) => $roomOf[$e->id] === 1);
        $this->assertCount(3, $sameSubjectInRoom1, 'the same subject\'s other section should fill all 3 leftover seats first');

        foreach ($otherSubject as $e) {
            $this->assertNotSame(1, $roomOf[$e->id], 'a different subject must not be used while a same-subject candidate is still available');
        }
    }

    public function test_same_subject_then_other_falls_back_to_a_different_subject_once_the_same_subject_pool_is_empty(): void
    {
        $nextId = 1;
        // Room seats 10; primary (subject 100) fills 6, same-subject
        // section B only has 2 (leaves 2 seats still empty after it's
        // used up), so a different subject must fill the remainder.
        $primary = $this->enrollments(6, 100, 'A', $nextId);
        $sameSubjectOtherSection = $this->enrollments(2, 100, 'B', $nextId);
        $otherSubject = $this->enrollments(2, 200, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'same_subject_then_other');
        $result = $strategy->allocate(collect([...$primary, ...$sameSubjectOtherSection, ...$otherSubject]), [
            $this->room(1, 10, 1),
        ]);

        $this->assertTrue($result->warnings->isEmpty());
        $roomOf = $this->roomOf($result->placements);

        foreach ([...$primary, ...$sameSubjectOtherSection, ...$otherSubject] as $e) {
            $this->assertSame(1, $roomOf[$e->id], "enrollment {$e->id} should have been seated in the one room, mixing same-subject then other-subject overflow");
        }
    }

    public function test_same_subject_then_other_falls_back_immediately_when_no_same_subject_candidate_exists(): void
    {
        $nextId = 1;
        $primary = $this->enrollments(7, 100, 'A', $nextId);
        $otherSubject = $this->enrollments(3, 200, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'same_subject_then_other');
        $result = $strategy->allocate(collect([...$primary, ...$otherSubject]), [
            $this->room(1, 10, 1),
        ]);

        $this->assertTrue($result->warnings->isEmpty());
        $roomOf = $this->roomOf($result->placements);

        foreach ($otherSubject as $e) {
            $this->assertSame(1, $roomOf[$e->id]);
        }
    }

    public function test_combine_sections_grouping_with_other_subject_overflow(): void
    {
        $nextId = 1;
        // Combine Sections already merges every section of subject 100
        // (4 + 3 = 7), leaving 3 leftover seats for a different subject.
        $sectionA = $this->enrollments(4, 100, 'A', $nextId);
        $sectionB = $this->enrollments(3, 100, 'B', $nextId);
        $otherSubject = $this->enrollments(3, 200, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject', overflowSource: 'other_subject');
        $result = $strategy->allocate(collect([...$sectionA, ...$sectionB, ...$otherSubject]), [
            $this->room(1, 10, 1),
        ]);

        $roomOf = $this->roomOf($result->placements);

        foreach ([...$sectionA, ...$sectionB, ...$otherSubject] as $e) {
            $this->assertSame(1, $roomOf[$e->id]);
        }

        $this->assertTrue($result->warnings->isEmpty());
    }

    public function test_no_compatible_overflow_candidate_leaves_the_room_partly_empty_without_error(): void
    {
        $nextId = 1;
        $primary = $this->enrollments(4, 100, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'same_subject');
        $result = $strategy->allocate(collect($primary), [$this->room(1, 10, 1)]);

        $this->assertCount(4, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());
    }

    public function test_overflow_pulls_from_multiple_groups_to_fill_the_room_as_full_as_possible(): void
    {
        $nextId = 1;
        $primary = $this->enrollments(6, 100, 'A', $nextId);
        $filler1 = $this->enrollments(2, 200, 'A', $nextId);
        $filler2 = $this->enrollments(2, 300, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'other_subject');
        $result = $strategy->allocate(collect([...$primary, ...$filler1, ...$filler2]), [
            $this->room(1, 10, 1),
        ]);

        $this->assertCount(10, $result->placements);
        $this->assertTrue($result->warnings->isEmpty());
    }

    public function test_room_exclusivity_is_still_respected_no_third_group_double_books_a_seat(): void
    {
        $nextId = 1;
        $primary = $this->enrollments(5, 100, 'A', $nextId);
        $overflow = $this->enrollments(5, 200, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'other_subject');
        $result = $strategy->allocate(collect([...$primary, ...$overflow]), [
            $this->room(1, 10, 1),
        ]);

        $seatKeys = collect($result->placements)->map(fn ($p) => $p->roomId.':'.$p->row.':'.$p->column);
        $this->assertSame($seatKeys->count(), $seatKeys->unique()->count(), 'no two students should ever land on the same seat');
    }

    public function test_running_out_of_rooms_still_produces_warnings_not_a_crash(): void
    {
        $nextId = 1;
        $primary = $this->enrollments(3, 100, 'A', $nextId);

        $strategy = new GroupedWithOverflowStrategy(groupBy: 'subject_section', overflowSource: 'other_subject');
        $result = $strategy->allocate(collect($primary), [$this->room(1, 2, 1)]);

        $this->assertCount(2, $result->placements);
        $this->assertCount(1, $result->warnings);
    }
}
