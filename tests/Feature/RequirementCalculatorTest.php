<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionRoom;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\Strategies\MixedSeatingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequirementCalculatorTest extends TestCase
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

    private function assignSubjectToSlot(ExamSession $session, Subject $subject, TimeSlot $slot): void
    {
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);
    }

    public function test_requirement_is_met_with_enough_rooms_and_teachers(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 2]);
        $room = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);
        $this->assignSubjectToSlot($session, $subject, $slot);

        Teacher::factory()->count(3)->create(['is_active' => true]);

        $result = (new RequirementCalculator)->calculate($session);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->isMet());
        $this->assertSame(0, $result->first()->roomsShortfall());
        $this->assertSame(0, $result->first()->teachersShortfall());
        $this->assertSame(10, $result->first()->seatsAvailable);
        $this->assertSame(0, $result->first()->seatsShortfall());
    }

    /**
     * Seating itself doesn't need teachers — only duty assignment does,
     * and that stage (which runs after seating) already copes with a
     * shortfall via warnings instead of refusing to run. A slot with
     * plenty of room but too few teachers must still count as met, so
     * Generate Seating isn't blocked by a number that has nothing to do
     * with seating.
     */
    public function test_a_teacher_shortfall_alone_does_not_prevent_a_slot_from_being_met(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 2]);
        $room = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);
        $this->assignSubjectToSlot($session, $subject, $slot);

        // Only one teacher exists for the whole system, but the slot
        // needs invigilators_per_room (2) — a genuine teacher shortfall.
        Teacher::factory()->create(['is_active' => true]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertGreaterThan(0, $requirement->teachersShortfall());
        $this->assertTrue($requirement->isMet());
        $this->assertTrue((new RequirementCalculator)->isFullyMet($session));
    }

    public function test_seats_available_sums_capacity_across_every_active_room(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        $roomA = Room::factory()->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        $roomB = Room::factory()->create(['rows' => 3, 'columns' => 1, 'capacity' => 3]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $roomA->id, 'is_active' => true]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $roomB->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 6);
        $this->assignSubjectToSlot($session, $subject, $slot);

        Teacher::factory()->count(2)->create(['is_active' => true]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertSame(8, $requirement->seatsAvailable);
        $this->assertSame(0, $requirement->seatsShortfall());
    }

    public function test_room_shortfall_counts_rooms_needed_from_the_full_room_pool(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);

        $activeRoom = Room::factory()->create(['rows' => 3, 'columns' => 1, 'capacity' => 3]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $activeRoom->id, 'is_active' => true]);

        // Exists in the system but not activated for this session.
        Room::factory()->create(['rows' => 3, 'columns' => 1, 'capacity' => 3]);

        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);
        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);
        $this->assignSubjectToSlot($session, $subject, $slot);

        Teacher::factory()->count(5)->create(['is_active' => true]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertFalse($requirement->isMet());
        $this->assertTrue($requirement->hasUnseatedStudents);
        $this->assertSame(2, $requirement->roomsNeeded); // 5 students split across two 3-capacity rooms
        $this->assertSame(1, $requirement->roomsAvailable);
        $this->assertSame(1, $requirement->roomsShortfall());
    }

    public function test_shortfall_is_flagged_even_when_the_ideal_room_count_matches_the_active_count(): void
    {
        // The system has two big rooms (capacity 8 each) that would let
        // previewAgainstAllRooms() answer "2 rooms needed" — but this
        // session only has two much smaller rooms actually activated, so
        // seating against the real active pool still leaves students
        // unseated even though 2 needed == 2 active.
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);

        $activeRoom1 = Room::factory()->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        $activeRoom2 = Room::factory()->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $activeRoom1->id, 'is_active' => true]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $activeRoom2->id, 'is_active' => true]);

        // Exist in the system but not activated for this session.
        Room::factory()->create(['rows' => 4, 'columns' => 2, 'capacity' => 8]);
        Room::factory()->create(['rows' => 4, 'columns' => 2, 'capacity' => 8]);

        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);
        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $this->enrollStudents($session, $subjectA, 'BSAI 1A', 8);
        $this->enrollStudents($session, $subjectB, 'BSAI 1A', 8);
        $this->assignSubjectToSlot($session, $subjectA, $slot);
        $this->assignSubjectToSlot($session, $subjectB, $slot);

        Teacher::factory()->count(5)->create(['is_active' => true]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertTrue($requirement->hasUnseatedStudents);
        $this->assertSame(2, $requirement->roomsAvailable);
        $this->assertGreaterThan($requirement->roomsAvailable, $requirement->roomsNeeded);
        $this->assertGreaterThan(0, $requirement->roomsShortfall());
        $this->assertFalse($requirement->isMet());
    }

    public function test_room_shortfall_reflects_the_true_minimum_when_even_every_system_room_falls_short(): void
    {
        // Regression: when every room in the system is already active for
        // this session and it's still not enough, the old code just
        // bumped roomsNeeded to roomsAvailable+1 — understating a bigger
        // true shortfall and implying "activate one more room" when
        // there wasn't a spare room anywhere to activate.
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 12);
        $this->assignSubjectToSlot($session, $subject, $slot);

        Teacher::factory()->count(5)->create(['is_active' => true]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertTrue($requirement->hasUnseatedStudents);
        $this->assertSame(1, $requirement->roomsAvailable);
        // True minimum: three 5-capacity rooms (5 + 5 + 2), not a flat "+1" guess.
        $this->assertSame(3, $requirement->roomsNeeded);
        $this->assertSame(2, $requirement->roomsShortfall());
        $this->assertTrue($requirement->exceedsSystemWideRooms());
        $this->assertFalse($requirement->isMet());
    }

    public function test_rooms_shortfall_is_not_treated_as_system_wide_when_more_rooms_exist_to_activate(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);

        $activeRoom = Room::factory()->create(['rows' => 3, 'columns' => 1, 'capacity' => 3]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $activeRoom->id, 'is_active' => true]);

        // Exists in the system but not activated for this session.
        Room::factory()->create(['rows' => 3, 'columns' => 1, 'capacity' => 3]);

        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);
        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);
        $this->assignSubjectToSlot($session, $subject, $slot);

        Teacher::factory()->count(5)->create(['is_active' => true]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertFalse($requirement->exceedsSystemWideRooms());
    }

    public function test_excluding_a_teacher_reduces_available_count(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 2);
        $this->assignSubjectToSlot($session, $subject, $slot);

        $teachers = Teacher::factory()->count(2)->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teachers[0]->id,
            'is_excluded' => true,
        ]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertSame(1, $requirement->teachersAvailable);
    }

    public function test_teacher_unavailable_on_one_day_only_affects_that_day(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);

        $monday = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']); // Monday
        $tuesday = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-21', 'start_time' => '11:00']); // Tuesday

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $this->enrollStudents($session, $subjectA, 'BSAI 1A', 1);
        $this->enrollStudents($session, $subjectB, 'BSAI 1A', 1);
        $this->assignSubjectToSlot($session, $subjectA, $monday);
        $this->assignSubjectToSlot($session, $subjectB, $tuesday);

        $teacher = Teacher::factory()->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'is_excluded' => false,
            'unavailable_days' => [1], // Monday
        ]);

        $requirements = (new RequirementCalculator)->calculate($session)->keyBy('timeSlotId');

        $this->assertSame(0, $requirements[$monday->id]->teachersAvailable);
        $this->assertSame(1, $requirements[$tuesday->id]->teachersAvailable);
    }

    public function test_adjacency_only_warnings_do_not_count_as_unseated(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'mixed', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create(['rows' => 3, 'columns' => 2, 'capacity' => 6]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        $subjectA = Subject::factory()->create();
        $subjectB = Subject::factory()->create();
        $this->enrollStudents($session, $subjectA, 'BSAI 1A', 3);
        $this->enrollStudents($session, $subjectB, 'BSAI 1A', 3);
        $this->assignSubjectToSlot($session, $subjectA, $slot);
        $this->assignSubjectToSlot($session, $subjectB, $slot);

        Teacher::factory()->count(5)->create(['is_active' => true]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertFalse($requirement->hasUnseatedStudents);
        $this->assertSame(6, $requirement->studentCount);
    }

    public function test_a_slot_with_an_unresolved_clash_is_not_met_even_with_full_capacity(): void
    {
        // Regression: Capacity Check only ever simulated room/teacher
        // capacity, so a slot could show "Ready" here while Pin Subjects
        // to Slots was warning about a real student clash the timetable
        // generator couldn't avoid (conflict_note gets set when it placed
        // a subject anyway because no clash-free day was left).
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 3);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
            'conflict_note' => 'Clashes with CS101 (12 shared students) — no clash-free day remained; placed anyway.',
        ]);

        Teacher::factory()->count(3)->create(['is_active' => true]);

        $requirement = (new RequirementCalculator)->calculate($session)->first();

        $this->assertSame(0, $requirement->roomsShortfall());
        $this->assertSame(0, $requirement->teachersShortfall());
        $this->assertFalse($requirement->hasUnseatedStudents);
        $this->assertTrue($requirement->hasUnresolvedClash);
        $this->assertFalse($requirement->isMet());
        // The admin needs to know *what* the clash is, not just that one
        // exists, so the conflict message travels with the requirement.
        $this->assertSame(
            ['Clashes with CS101 (12 shared students) — no clash-free day remained; placed anyway.'],
            $requirement->clashDetails
        );
    }

    public function test_a_strategy_override_simulates_a_different_strategy_without_touching_the_sessions_saved_one(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);

        // Four small rooms Strict would pick one-per-subject, plus one
        // room with 4 columns that only Mixed (4 subjects/room) can use
        // efficiently as a single room for all four subjects at once.
        for ($i = 0; $i < 4; $i++) {
            Room::factory()->create(['rows' => 2, 'columns' => 1, 'capacity' => 2]);
        }
        Room::factory()->create(['rows' => 2, 'columns' => 4, 'capacity' => 8]);

        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);

        foreach (range(1, 4) as $i) {
            $subject = Subject::factory()->create();
            $this->enrollStudents($session, $subject, 'BSAI 1A', 2);
            $this->assignSubjectToSlot($session, $subject, $slot);
        }

        $realRequirement = (new RequirementCalculator)->calculate($session)->first();
        $this->assertSame(4, $realRequirement->roomsNeeded);

        $whatIfRequirement = (new RequirementCalculator)->calculate(
            $session,
            new MixedSeatingStrategy(4)
        )->first();
        $this->assertSame(1, $whatIfRequirement->roomsNeeded);

        // The override must never persist — the session's own setting is
        // untouched by having run a what-if calculation against it.
        $this->assertSame('strict', $session->fresh()->seating_strategy);
    }
}
