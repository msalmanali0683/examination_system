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

    public function test_roomsfit_readjusts_a_subject_away_from_a_slot_that_cannot_seat_it_alongside_others(): void
    {
        // Slot 100 (day1) already holds subject 1. Room capacity only
        // allows one subject per slot. Subject 2 is clash-free with 1 (so
        // pure clash-avoidance would happily share slot 100 with it) but
        // the capacity check must redirect it to slot 200 (day2) instead.
        $roomsFit = fn (array $subjectIds) => count($subjectIds) <= 1;

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [1 => 100],
            timeSlotIds: [100, 200],
            conflictGraph: [],
            subjectLabels: $this->labels([1, 2]),
            slotDays: [100 => 'day1', 200 => 'day2'],
            roomsFit: $roomsFit,
        );

        $this->assertSame(200, $result->assignments[2]);
        $this->assertTrue($result->conflicts->isEmpty());
    }

    public function test_a_subject_alone_in_its_own_slot_is_never_treated_as_a_problem(): void
    {
        // Two subjects, two slots, capacity only allows one subject per
        // slot — each subject ends up alone in its own slot. That's a
        // perfectly fine outcome and must not be reported as a conflict.
        $roomsFit = fn (array $subjectIds) => count($subjectIds) <= 1;

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [],
            timeSlotIds: [100, 200],
            conflictGraph: [],
            subjectLabels: $this->labels([1, 2]),
            roomsFit: $roomsFit,
        );

        $this->assertNotSame($result->assignments[1], $result->assignments[2]);
        $this->assertTrue($result->conflicts->isEmpty());
    }

    public function test_roomsfit_still_places_a_subject_when_nothing_fits_anywhere_and_reports_it(): void
    {
        // Capacity never fits, even for a single subject alone (e.g. it
        // genuinely needs more seats than any active room setup provides).
        // It must still be placed somewhere rather than dropped, with the
        // shortfall reported instead of silently ignored.
        $roomsFit = fn (array $subjectIds) => false;

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1],
            pinned: [],
            timeSlotIds: [100],
            conflictGraph: [],
            subjectLabels: $this->labels([1]),
            roomsFit: $roomsFit,
        );

        $this->assertSame(100, $result->assignments[1]);
        $this->assertTrue($result->conflicts->isNotEmpty());
        $this->assertStringContainsString('room capacity', $result->conflicts->first()->message);
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

    public function test_different_semester_subjects_sharing_a_student_are_allowed_the_same_day_different_slot(): void
    {
        // Subject 1 (semester 1) and subject 2 (semester 3) share a
        // student — a repeater sitting an earlier-semester paper alongside
        // their current one. Only one day exists, so the only way to place
        // subject 2 at all is to share subject 1's day at a different
        // slot — proving that's allowed rather than being blocked outright
        // (which the old "never share a day" rule would have done).
        $graph = [1 => [2 => 1], 2 => [1 => 1]];
        $slotDays = [100 => 'day1', 101 => 'day1'];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [1 => 100],
            timeSlotIds: [100, 101],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2]),
            slotDays: $slotDays,
            semesterBySubject: [1 => [1], 2 => [3]],
        );

        $this->assertSame(101, $result->assignments[2]);
        $this->assertTrue($result->conflicts->isEmpty());
    }

    public function test_different_semester_subjects_sharing_a_student_still_avoid_the_exact_same_slot(): void
    {
        // Same repeater scenario, but only one slot exists that day (slot
        // 100 already taken by subject 1) — subject 2 must be pushed to
        // day2 rather than landing in the literal same slot as 1.
        $graph = [1 => [2 => 1], 2 => [1 => 1]];
        $slotDays = [100 => 'day1', 200 => 'day2'];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [1 => 100],
            timeSlotIds: [100, 200],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2]),
            slotDays: $slotDays,
            semesterBySubject: [1 => [1], 2 => [3]],
        );

        $this->assertSame(200, $result->assignments[2]);
        $this->assertTrue($result->conflicts->isEmpty());
    }

    public function test_same_semester_overflow_spreads_across_the_days_slots_as_far_apart_as_possible(): void
    {
        // 3 same-semester subjects (1, 2, 3), all mutually sharing
        // students (the whole cohort), but only 2 days exist — one day
        // must carry two of that semester's papers. When forced, they
        // should land in that day's first and last slot (max gap), not
        // two adjacent slots.
        $graph = [
            1 => [2 => 5, 3 => 5],
            2 => [1 => 5, 3 => 5],
            3 => [1 => 5, 2 => 5],
        ];
        $slotDays = [100 => 'day1', 101 => 'day1', 102 => 'day1', 200 => 'day2'];
        $semesters = [1 => [2], 2 => [2], 3 => [2]];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2, 3],
            pinned: [],
            timeSlotIds: [100, 101, 102, 200],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2, 3]),
            slotDays: $slotDays,
            semesterBySubject: $semesters,
        );

        // Exactly one day ends up with two of the three (day2 only has one
        // slot, so it can hold at most one).
        $dayCounts = collect($result->assignments)->countBy(fn ($slotId) => $slotDays[$slotId]);
        $this->assertSame(2, $dayCounts['day1']);
        $this->assertSame(1, $dayCounts['day2']);

        // Whichever two subjects share day1, they must be in its first
        // and last slot (100 and 102), never the adjacent 101/102 or 100/101.
        $day1Slots = collect($result->assignments)->filter(fn ($slotId) => $slotDays[$slotId] === 'day1')->values();
        $this->assertEqualsCanonicalizing([100, 102], $day1Slots->all());
    }

    public function test_subjects_with_no_semester_data_still_default_to_never_sharing_a_day(): void
    {
        // No $semesterBySubject entries at all for either subject — must
        // fall back to the original "always separate days" behavior
        // rather than assuming they're safely different semesters.
        $graph = [1 => [2 => 1], 2 => [1 => 1]];
        $slotDays = [100 => 'day1', 101 => 'day1', 200 => 'day2', 201 => 'day2'];

        $result = (new TimetableGenerator)->generate(
            subjectIds: [1, 2],
            pinned: [],
            timeSlotIds: [100, 101, 200, 201],
            conflictGraph: $graph,
            subjectLabels: $this->labels([1, 2]),
            slotDays: $slotDays,
        );

        $this->assertNotSame($slotDays[$result->assignments[1]], $slotDays[$result->assignments[2]]);
        $this->assertTrue($result->conflicts->isEmpty());
    }
}
