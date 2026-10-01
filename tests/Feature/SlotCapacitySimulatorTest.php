<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\Generation\SlotCapacitySimulator;
use App\Services\Generation\Strategies\CombineSectionsSeatingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlotCapacitySimulatorTest extends TestCase
{
    use RefreshDatabase;

    private static int $rollNoSequence = 0;

    private function enrollStudents(ExamSession $session, Subject $subject, string $section, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $student = Student::factory()->create(['roll_no' => str_pad((string) ++self::$rollNoSequence, 8, '0', STR_PAD_LEFT)]);
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => $student->id,
                'subject_id' => $subject->id,
                'section' => $section,
            ]);
        }
    }

    public function test_it_works_directly_from_enrollments_with_no_timetable_or_subject_slot_assignments_at_all(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 1]);
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);
        Teacher::factory()->for($session)->count(3)->create(['is_active' => true]);

        // No TimeSlot, no SubjectSlotAssignment created anywhere in this test.
        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result->first()->studentCount);
        $this->assertSame(1, $result->first()->roomsNeeded);
    }

    public function test_clash_free_subjects_are_packed_together_as_tightly_as_capacity_allows(): void
    {
        // 4 rooms, capacity 20 each = plenty of room for all four subjects
        // (10+8+6+4 = 28 students, needing at most 4 rooms since Strict
        // gives each subject its own room) — none of them share a student,
        // so a capacity-aware packer should fit every one of them into a
        // single simulated slot instead of splitting into fixed batches.
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(4)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);

        $big = Subject::factory()->create();
        $medium = Subject::factory()->create();
        $small = Subject::factory()->create();
        $tiny = Subject::factory()->create();
        $this->enrollStudents($session, $big, 'A', 10);
        $this->enrollStudents($session, $medium, 'A', 8);
        $this->enrollStudents($session, $small, 'A', 6);
        $this->enrollStudents($session, $tiny, 'A', 4);

        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertCount(1, $result);
        $this->assertSame(28, $result->first()->studentCount);
        $this->assertSame(4, $result->first()->roomsNeeded);
    }

    public function test_a_slot_only_takes_as_many_subjects_as_its_rooms_can_hold_then_opens_another(): void
    {
        // Same 4 rooms as above (so the first four subjects exactly fill
        // them), plus a fifth clash-free subject that has nowhere left to
        // go in that slot — it must open a second, smaller slot rather
        // than being force-fit or dropped. Demonstrates slots naturally
        // needing different numbers of rooms (4 vs 1), not a fixed count.
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(4)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);

        $subjects = Subject::factory()->count(5)->create();
        $this->enrollStudents($session, $subjects[0], 'A', 10);
        $this->enrollStudents($session, $subjects[1], 'A', 8);
        $this->enrollStudents($session, $subjects[2], 'A', 6);
        $this->enrollStudents($session, $subjects[3], 'A', 4);
        $this->enrollStudents($session, $subjects[4], 'A', 3);

        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertCount(2, $result);
        $this->assertSame(4, $result->first()->roomsNeeded);
        $this->assertSame(28, $result->first()->studentCount);
        $this->assertSame(1, $result->last()->roomsNeeded);
        $this->assertSame(3, $result->last()->studentCount);
    }

    public function test_max_subjects_per_slot_caps_how_many_are_packed_together(): void
    {
        // Same setup as the "packed together" test above — plenty of room
        // capacity for all four in one slot — but capped at 1 subject per
        // slot, so each one must get its own even though nothing forces it.
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(4)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);

        $subjects = Subject::factory()->count(4)->create();
        foreach ($subjects as $subject) {
            $this->enrollStudents($session, $subject, 'A', 5);
        }

        $result = (new SlotCapacitySimulator)->simulate($session, maxSubjectsPerSlot: 1);

        $this->assertCount(4, $result);
        $this->assertTrue($result->every(fn ($r) => $r->studentCount === 5));
    }

    public function test_minimum_subjects_per_slot_prefers_filling_under_minimum_slots_first(): void
    {
        // 4 rooms, plenty of capacity — each subject needs only one room.
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(4)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $subjectC = Subject::factory()->create();
        $subjectD = Subject::factory()->create();
        $this->enrollStudents($session, $subjectA, 'A', 4);
        $this->enrollStudents($session, $subjectB, 'A', 5);
        $this->enrollStudents($session, $subjectC, 'A', 4);
        $this->enrollStudents($session, $subjectD, 'A', 5);

        // A and C share a student, added last so it doesn't shift which
        // subject is encountered first — they can never share a slot.
        // (Both now total 5 students, same as B and D.)
        $shared = Student::factory()->create(['roll_no' => 'SHARED01']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $shared->id, 'subject_id' => $subjectA->id, 'section' => 'A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $shared->id, 'subject_id' => $subjectC->id, 'section' => 'A']);

        // Without a minimum, A and B fill the first slot; C can't join (clash
        // with A) so it opens a second slot, which D then joins too since D
        // fits the first slot it's offered — leaving an uneven 3-and-1 split.
        $withoutMin = (new SlotCapacitySimulator)->simulate($session);
        $this->assertCount(2, $withoutMin);
        $this->assertEqualsCanonicalizing([5, 15], $withoutMin->pluck('studentCount')->all());

        // With a minimum of 2, D is offered to the still-below-minimum slot
        // (just C) before the already-satisfied slot (A and B already has
        // 2) is grown further — evening the split to 2-and-2 (10-and-10).
        $withMin = (new SlotCapacitySimulator)->simulate($session, minSubjectsPerSlot: 2);
        $this->assertCount(2, $withMin);
        $this->assertEqualsCanonicalizing([10, 10], $withMin->pluck('studentCount')->all());
    }

    public function test_every_section_of_a_subject_stays_in_the_same_simulated_slot(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(3)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);

        $multiSection = Subject::factory()->create();
        $this->enrollStudents($session, $multiSection, 'BSAI 1A', 6);
        $this->enrollStudents($session, $multiSection, 'BSAI 1B', 4);
        $other = Subject::factory()->create();
        $this->enrollStudents($session, $other, 'BSAI 2A', 3);

        $result = (new SlotCapacitySimulator)->simulate($session);

        // Both subjects fit in one slot, and the multi-section subject's
        // two sections (6 + 4) both count — nothing from it leaks into a
        // second slot.
        $this->assertCount(1, $result);
        $this->assertSame(13, $result->first()->studentCount);
    }

    public function test_subjects_sharing_a_student_are_never_placed_in_the_same_simulated_slot(): void
    {
        // Plenty of room capacity for both subjects together, but one
        // student is enrolled in both — a real clash the real timetable
        // could never allow, so the simulator must not allow it either,
        // even though it would otherwise pack them into one slot.
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(4)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $shared = Student::factory()->create(['roll_no' => 'SHARED01']);

        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $shared->id, 'subject_id' => $subjectA->id, 'section' => 'A']);
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $shared->id, 'subject_id' => $subjectB->id, 'section' => 'A']);
        $this->enrollStudents($session, $subjectA, 'A', 4);
        $this->enrollStudents($session, $subjectB, 'A', 4);

        $result = (new SlotCapacitySimulator)->simulate($session);

        // Each subject (1 shared student + 4 of its own) lands in its own
        // slot — if they'd been packed together the shared student would
        // push one slot's count to 9, not two slots of 5 each.
        $this->assertCount(2, $result);
        $this->assertSame([5, 5], $result->pluck('studentCount')->sort()->values()->all());
    }

    public function test_teachers_available_excludes_teachers_excluded_from_this_session(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);

        $available = Teacher::factory()->for($session)->create(['is_active' => true]);
        $excluded = Teacher::factory()->for($session)->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $excluded->id,
            'is_excluded' => true,
        ]);

        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertSame(1, $result->first()->teachersAvailable);
    }

    public function test_teachers_available_excludes_teachers_capped_at_zero_max_duties(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);

        $available = Teacher::factory()->for($session)->create(['is_active' => true]);
        $zeroMax = Teacher::factory()->for($session)->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $zeroMax->id,
            'is_excluded' => false,
            'max_duties' => 0,
        ]);

        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertSame(1, $result->first()->teachersAvailable);
    }

    public function test_shortfall_is_flagged_even_when_roomsused_is_capped_by_an_exhausted_room_pool(): void
    {
        // Only 2 rooms exist in the whole system (capacity 5 each = 10
        // total), both active in the session, and one subject has more
        // students than the entire system can seat. roomsUsed can never
        // exceed 2 (there's nowhere else to place anyone), which must not
        // be allowed to read as "0 shortfall" against roomsAvailable=2.
        $session = ExamSession::factory()->create(['invigilators_per_room' => 1]);
        Room::factory()->for($session)->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        Room::factory()->for($session)->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 15);

        $result = (new SlotCapacitySimulator)->simulate($session);

        $requirement = $result->first();
        $this->assertTrue($requirement->hasUnseatedStudents);
        $this->assertSame(2, $requirement->roomsAvailable);
        $this->assertGreaterThan($requirement->roomsAvailable, $requirement->roomsNeeded);
        $this->assertGreaterThan(0, $requirement->roomsShortfall());
        $this->assertFalse($requirement->isMet());
        $this->assertSame(5, array_sum(array_column($requirement->unseatedBreakdown, 'count')), 'all 10 seats taken, 5 of the 15 enrolled go unseated');
        $this->assertSame('BSAI 1A', $requirement->unseatedBreakdown[0]['section']);
    }

    public function test_room_breakdown_and_unseated_breakdown_together_account_for_every_enrolled_student(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['name' => 'Only Room', 'rows' => 4, 'columns' => 1, 'capacity' => 4]);

        $subject = Subject::factory()->create(['code' => 'CS999']);
        $this->enrollStudents($session, $subject, 'BSAI 3C', 6);

        $requirement = (new SlotCapacitySimulator)->simulate($session)->first();

        $this->assertTrue($requirement->hasUnseatedStudents);
        $seated = array_sum(array_column($requirement->roomBreakdown[0]['sections'], 'count'));
        $unseated = array_sum(array_column($requirement->unseatedBreakdown, 'count'));
        $this->assertSame(4, $seated);
        $this->assertSame(2, $unseated);
        $this->assertSame('CS999', $requirement->unseatedBreakdown[0]['subjectCode']);
        $this->assertSame('BSAI 3C', $requirement->unseatedBreakdown[0]['section']);
    }

    public function test_unseated_breakdown_is_empty_when_everyone_gets_a_seat(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);

        $requirement = (new SlotCapacitySimulator)->simulate($session)->first();

        $this->assertFalse($requirement->hasUnseatedStudents);
        $this->assertSame([], $requirement->unseatedBreakdown);
    }

    public function test_with_day_and_slot_labels_numbers_slots_by_day_then_resets(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(4)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);

        $subjects = Subject::factory()->count(4)->create();
        foreach ($subjects as $subject) {
            $this->enrollStudents($session, $subject, 'A', 5);
        }

        // Capped at 1 per slot forces 4 separate simulated slots.
        $result = (new SlotCapacitySimulator)->simulate($session, maxSubjectsPerSlot: 1);
        $this->assertCount(4, $result);

        $labeled = SlotCapacitySimulator::withDayAndSlotLabels($result, 3);

        $this->assertSame('Day 1, Slot 1 (1 subject)', $labeled->get(0)->label);
        $this->assertSame('Day 1, Slot 2 (1 subject)', $labeled->get(1)->label);
        $this->assertSame('Day 1, Slot 3 (1 subject)', $labeled->get(2)->label);
        $this->assertSame('Day 2, Slot 1 (1 subject)', $labeled->get(3)->label);
    }

    public function test_with_day_and_slot_labels_treats_a_zero_or_negative_per_day_as_one(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'A', 5);

        $result = (new SlotCapacitySimulator)->simulate($session);
        $labeled = SlotCapacitySimulator::withDayAndSlotLabels($result, 0);

        $this->assertSame('Day 1, Slot 1 (1 subject)', $labeled->first()->label);
    }

    /**
     * Strict (the default when no strategy is given) gives each subject+section its own room, so two
     * 5-student sections of the same subject can't share the one 10-seat room this test gives them —
     * the second section finds nowhere left to go and the whole subject goes unseated. Passing
     * CombineSectionsSeatingStrategy instead groups by subject only, so the real "what if I used
     * Combine Sections" answer is: both sections fit together, fully seated, one room.
     */
    public function test_a_strategy_override_changes_what_fits_compared_to_the_strict_default(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['name' => 'ITC-310', 'rows' => 10, 'columns' => 1, 'capacity' => 10]);

        $subject = Subject::factory()->create(['code' => 'CS101']);
        $this->enrollStudents($session, $subject, 'BSCS 1A', 5);
        $this->enrollStudents($session, $subject, 'BSCS 1B', 5);

        $strict = (new SlotCapacitySimulator)->simulate($session)->first();
        $this->assertTrue($strict->hasUnseatedStudents);
        $this->assertSame(5, array_sum(array_column($strict->unseatedBreakdown, 'count')));

        $combined = (new SlotCapacitySimulator(new CombineSectionsSeatingStrategy))->simulate($session)->first();
        $this->assertFalse($combined->hasUnseatedStudents);
        $this->assertCount(1, $combined->roomBreakdown);
        $this->assertSame(10, $combined->roomBreakdown[0]['filled']);
        $this->assertCount(2, $combined->roomBreakdown[0]['sections'], 'both sections share the one room');
    }

    public function test_no_strategy_argument_defaults_to_strict(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'A', 5);
        $this->enrollStudents($session, $subject, 'B', 5);

        $default = (new SlotCapacitySimulator)->simulate($session)->first();
        $this->assertTrue($default->hasUnseatedStudents, 'same outcome as the explicit Strict case above');
    }

    public function test_subjects_lists_the_bins_subject_even_when_it_goes_entirely_unseated(): void
    {
        // No active rooms at all: the one subject can never be placed anywhere, so every one of its
        // students is unseated (it appears in unseatedBreakdown and nowhere in roomBreakdown) — but
        // `subjects` must still name it, since that's the timetable-level grouping decision, not the
        // seating outcome.
        $session = ExamSession::factory()->create();
        $subject = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $this->enrollStudents($session, $subject, 'BSCS 1A', 3);

        $result = (new SlotCapacitySimulator)->simulate($session)->first();

        $this->assertTrue($result->hasUnseatedStudents);
        $this->assertSame([], $result->roomBreakdown, 'nothing could be seated with zero active rooms');
        $this->assertSame(3, array_sum(array_column($result->unseatedBreakdown, 'count')));
        $this->assertSame(['CS101'], collect($result->subjects)->pluck('code')->all());
        $this->assertSame('Intro to Programming', $result->subjects[0]['title']);
        $this->assertSame('1st', $result->subjects[0]['semester']);
    }

    public function test_subjects_lists_every_subject_sharing_a_bin(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(2)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);
        $subjectA = Subject::factory()->create(['code' => 'CS101']);
        $subjectB = Subject::factory()->create(['code' => 'EE201']);
        $this->enrollStudents($session, $subjectA, 'A', 5);
        $this->enrollStudents($session, $subjectB, 'B', 3);

        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertCount(1, $result, 'both clash-free subjects fit together in one simulated slot');
        $this->assertSame(['CS101', 'EE201'], collect($result->first()->subjects)->pluck('code')->sort()->values()->all());
    }

    public function test_datesheet_rows_by_day_groups_by_day_and_lists_every_subject_in_each_slot(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(4)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);

        $subjects = Subject::factory()->count(4)->create();
        foreach ($subjects as $i => $subject) {
            $subject->update(['code' => 'SUBJ'.$i]);
            $this->enrollStudents($session, $subject, 'A', 5);
        }

        // Capped at 1 per slot forces 4 separate simulated slots, 2 per day at per_day=2.
        $result = (new SlotCapacitySimulator)->simulate($session, maxSubjectsPerSlot: 1);
        $labeled = SlotCapacitySimulator::withDayAndSlotLabels($result, 2);

        $rowsByDay = SlotCapacitySimulator::datesheetRowsByDay($labeled);

        $this->assertCount(2, $rowsByDay);
        $this->assertCount(2, $rowsByDay->get(1));
        $this->assertCount(2, $rowsByDay->get(2));
        $allCodes = $rowsByDay->flatten()->pluck('code')->sort()->values()->all();
        $this->assertSame(['SUBJ0', 'SUBJ1', 'SUBJ2', 'SUBJ3'], $allCodes);
    }

    public function test_datesheet_rows_by_day_without_day_labels_collapses_into_one_day(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'A', 5);

        // No withDayAndSlotLabels() call — day/slotInDay stay null on every SlotRequirement.
        $result = (new SlotCapacitySimulator)->simulate($session);
        $rowsByDay = SlotCapacitySimulator::datesheetRowsByDay($result);

        $this->assertCount(1, $rowsByDay);
        $this->assertTrue($rowsByDay->has(1));
    }

    public function test_no_enrollments_yet_returns_an_empty_collection(): void
    {
        $session = ExamSession::factory()->create();

        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertTrue($result->isEmpty());
    }

    public function test_a_subject_with_more_students_than_one_room_holds_needs_multiple_rooms(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 2]);
        Room::factory()->for($session)->count(2)->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 8);

        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertSame(2, $result->first()->roomsNeeded);
        $this->assertSame(4, $result->first()->teachersNeeded);
    }

    public function test_room_breakdown_names_the_room_its_capacity_and_which_section_fills_it(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['name' => 'ITC-310', 'rows' => 10, 'columns' => 1, 'capacity' => 10]);

        $subject = Subject::factory()->create(['code' => 'CS101', 'title' => 'Intro to Programming']);
        $this->enrollStudents($session, $subject, 'BSCS 1A', 6);

        $breakdown = (new SlotCapacitySimulator)->simulate($session)->first()->roomBreakdown;

        $this->assertCount(1, $breakdown);
        $room = $breakdown[0];
        $this->assertSame('ITC-310', $room['roomName']);
        $this->assertSame(10, $room['capacity']);
        $this->assertSame(6, $room['filled']);
        $this->assertSame(4, $room['remaining']);
        $this->assertCount(1, $room['sections']);
        $this->assertSame('CS101', $room['sections'][0]['subjectCode']);
        $this->assertSame('Intro to Programming', $room['sections'][0]['subjectTitle']);
        $this->assertSame('BSCS 1A', $room['sections'][0]['section']);
        $this->assertSame(6, $room['sections'][0]['count']);
    }

    public function test_room_breakdown_splits_a_group_too_large_for_one_room_across_several(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['name' => 'Room A', 'rows' => 5, 'columns' => 1, 'capacity' => 5]);
        Room::factory()->for($session)->create(['name' => 'Room B', 'rows' => 5, 'columns' => 1, 'capacity' => 5]);

        $subject = Subject::factory()->create(['code' => 'EE201']);
        $this->enrollStudents($session, $subject, 'BSEE 2A', 8);

        $breakdown = (new SlotCapacitySimulator)->simulate($session)->first()->roomBreakdown;

        $this->assertCount(2, $breakdown);
        $this->assertSame(8, collect($breakdown)->sum('filled'));
        foreach ($breakdown as $room) {
            $this->assertSame('EE201', $room['sections'][0]['subjectCode']);
        }
    }

    public function test_room_breakdown_is_empty_when_nothing_is_enrolled(): void
    {
        $session = ExamSession::factory()->create();

        $result = (new SlotCapacitySimulator)->simulate($session);

        $this->assertTrue($result->isEmpty());
    }
}
