<?php

namespace Tests\Unit\Services\Generation;

use App\Services\Generation\TimetableGenerator;
use PHPUnit\Framework\TestCase;

class TimetableGeneratorTest extends TestCase
{
    private function labels(array $ids): array
    {
        return collect($ids)->mapWithKeys(fn ($id) => [$id => "Subject {$id}"])->all();
    }

    public function test_conflicting_subjects_are_placed_in_different_slots(): void
    {
        // Subject 1 and 2 share students; subject 3 shares with neither.
        $graph = [
            1 => [2 => 5],
            2 => [1 => 5],
        ];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2, 3],
            pinned: [],
            timeSlotIds: [100, 200],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2, 3]),
        );

        $this->assertNotSame($result->assignments[1], $result->assignments[2]);
        $this->assertTrue($result->conflicts->isEmpty());
        $this->assertCount(3, $result->assignments);
    }

    public function test_pinned_subjects_are_never_moved_and_block_their_slot(): void
    {
        $graph = [
            1 => [2 => 3],
            2 => [1 => 3],
        ];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [1 => 200],
            timeSlotIds: [100, 200],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2]),
        );

        $this->assertSame(200, $result->assignments[1]);
        $this->assertSame(100, $result->assignments[2]);
        $this->assertTrue($result->conflicts->isEmpty());
    }

    public function test_most_conflicted_subject_is_placed_first_to_avoid_getting_stuck(): void
    {
        // Subject 1 conflicts with both 2 and 3; 2 and 3 don't conflict with
        // each other. Only 2 slots. If 2 or 3 were placed before 1, subject 1
        // could get stuck sharing a slot with one of them. Most-conflicts-first
        // avoids that by placing 1 while both slots are still open.
        $graph = [
            1 => [2 => 2, 3 => 2],
            2 => [1 => 2],
            3 => [1 => 2],
        ];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2, 3],
            pinned: [],
            timeSlotIds: [100, 200],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2, 3]),
        );

        $this->assertNotSame($result->assignments[1], $result->assignments[2]);
        $this->assertNotSame($result->assignments[1], $result->assignments[3]);
        $this->assertTrue($result->conflicts->isEmpty());
    }

    public function test_unavoidable_clash_is_reported_instead_of_silently_dropped(): void
    {
        // Three mutually-conflicting subjects, only 2 slots: impossible to
        // fully avoid a clash for all three.
        $graph = [
            1 => [2 => 4, 3 => 4],
            2 => [1 => 4, 3 => 4],
            3 => [1 => 4, 2 => 4],
        ];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2, 3],
            pinned: [],
            timeSlotIds: [100, 200],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2, 3]),
        );

        $this->assertCount(3, $result->assignments);
        $this->assertTrue($result->conflicts->isNotEmpty());
        $this->assertStringContainsString('share 4 student', $result->conflicts->first()->message);
    }

    public function test_no_time_slots_reports_every_subject_as_unplaceable(): void
    {
        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [],
            timeSlotIds: [],
            conflictGraph: [],
            subjectLabels: $this->labels([1, 2]),
        );

        $this->assertSame([], $result->assignments);
        $this->assertCount(2, $result->conflicts);
    }

    public function test_earliest_clash_free_slot_is_preferred_over_a_later_one(): void
    {
        $result = (new TimetableGenerator)->generate(
            subjectIds: [1],
            pinned: [],
            timeSlotIds: [100, 200, 300],
            conflictGraph: [],
            subjectLabels: $this->labels([1]),
        );

        $this->assertSame(100, $result->assignments[1]);
    }
}
