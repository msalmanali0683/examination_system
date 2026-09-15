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

    public function test_subjects_sharing_a_student_are_never_placed_on_the_same_day_even_in_different_slots(): void
    {
        // Two slots on day 1 (100, 101), two on day 2 (200, 201). Subjects
        // 1 and 2 share students. Without day-level checking they'd both
        // fit day 1 (different exact slots there) — but a real student
        // can't sit two papers the same day, so they must land on
        // different days entirely.
        $graph = [
            1 => [2 => 5],
            2 => [1 => 5],
        ];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [],
            timeSlotIds: [100, 101, 200, 201],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2]),
            slotDays: [100 => 'day1', 101 => 'day1', 200 => 'day2', 201 => 'day2'],
        );

        $dayOf = fn ($slotId) => in_array($slotId, [100, 101]) ? 'day1' : 'day2';
        $this->assertNotSame($dayOf($result->assignments[1]), $dayOf($result->assignments[2]));
        $this->assertTrue($result->conflicts->isEmpty());
    }

    public function test_clash_free_subjects_spread_across_days_instead_of_piling_into_the_first(): void
    {
        // 3 days, 2 slots each, 6 mutually clash-free subjects (nothing in
        // the conflict graph at all). Every subject is "clash-free" against
        // every day, so a purely clash-avoidance algorithm would happily
        // pile all 6 into day 1's two slots. Load-balancing should instead
        // spread them 2-per-day across all 3 days.
        $slotDays = [100 => 'day1', 101 => 'day1', 200 => 'day2', 201 => 'day2', 300 => 'day3', 301 => 'day3'];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2, 3, 4, 5, 6],
            pinned: [],
            timeSlotIds: [100, 101, 200, 201, 300, 301],
            conflictGraph: [],
            subjectLabels: $this->labels([1, 2, 3, 4, 5, 6]),
            slotDays: $slotDays,
        );

        $dayCounts = collect($result->assignments)->countBy(fn ($slotId) => $slotDays[$slotId]);
        $this->assertSame(['day1' => 2, 'day2' => 2, 'day3' => 2], $dayCounts->sortKeys()->all());
    }

    public function test_a_day_already_used_by_a_pinned_subject_still_counts_toward_load_balancing(): void
    {
        // Subject 1 is pinned to day1 (slot 100). Subject 2 is clash-free
        // with everything, so — day1 already carrying the pinned subject's
        // load — it should prefer the genuinely empty day2 rather than
        // treating day1 as if nothing were there yet.
        $slotDays = [100 => 'day1', 101 => 'day1', 200 => 'day2', 201 => 'day2'];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [1 => 100],
            timeSlotIds: [100, 101, 200, 201],
            conflictGraph: [],
            subjectLabels: $this->labels([1, 2]),
            slotDays: $slotDays,
        );

        $this->assertSame('day2', $slotDays[$result->assignments[2]]);
    }
}
