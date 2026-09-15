<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionRoom;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Services\Generation\RequirementCalculator;
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
        \App\Models\SubjectSlotAssignment::create([
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
            new \App\Services\Generation\Strategies\MixedSeatingStrategy(4)
        )->first();
        $this->assertSame(1, $whatIfRequirement->roomsNeeded);

        // The override must never persist — the session's own setting is
        // untouched by having run a what-if calculation against it.
        $this->assertSame('strict', $session->fresh()->seating_strategy);
    }
}
