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
use App\Services\Generation\SlotCapacitySimulator;
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
        Room::factory()->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => Room::first()->id, 'is_active' => true]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);
        Teacher::factory()->count(3)->create(['is_active' => true]);

        // No TimeSlot, no SubjectSlotAssignment created anywhere in this test.
        $result = (new SlotCapacitySimulator)->simulate($session, subjectsPerSlot: 2);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result->first()->studentCount);
        $this->assertSame(1, $result->first()->roomsNeeded);
    }

    public function test_subjects_are_grouped_into_slots_largest_first(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->count(4)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);
        foreach (Room::all() as $room) {
            SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        }

        $big = Subject::factory()->create();
        $medium = Subject::factory()->create();
        $small = Subject::factory()->create();
        $tiny = Subject::factory()->create();
        $this->enrollStudents($session, $big, 'A', 10);
        $this->enrollStudents($session, $medium, 'A', 8);
        $this->enrollStudents($session, $small, 'A', 6);
        $this->enrollStudents($session, $tiny, 'A', 4);

        $result = (new SlotCapacitySimulator)->simulate($session, subjectsPerSlot: 2);

        $this->assertCount(2, $result);
        // Largest two subjects (10 + 8) share the first simulated slot.
        $this->assertSame(18, $result->first()->studentCount);
        // The remaining two (6 + 4) share the second.
        $this->assertSame(10, $result->last()->studentCount);
    }

    public function test_every_section_of_a_subject_stays_in_the_same_simulated_slot(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->count(3)->create(['rows' => 20, 'columns' => 1, 'capacity' => 20]);
        foreach (Room::all() as $room) {
            SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        }

        $multiSection = Subject::factory()->create();
        $this->enrollStudents($session, $multiSection, 'BSAI 1A', 6);
        $this->enrollStudents($session, $multiSection, 'BSAI 1B', 4);
        $other = Subject::factory()->create();
        $this->enrollStudents($session, $other, 'BSAI 2A', 3);

        $result = (new SlotCapacitySimulator)->simulate($session, subjectsPerSlot: 2);

        // Both subjects fit in one slot (subjectsPerSlot=2), and the
        // multi-section subject's two sections (6 + 4) both count —
        // nothing from it leaks into a second slot.
        $this->assertCount(1, $result);
        $this->assertSame(13, $result->first()->studentCount);
    }

    public function test_teachers_available_excludes_teachers_excluded_from_this_session(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => Room::first()->id, 'is_active' => true]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);

        $available = Teacher::factory()->create(['is_active' => true]);
        $excluded = Teacher::factory()->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $excluded->id,
            'is_excluded' => true,
        ]);

        $result = (new SlotCapacitySimulator)->simulate($session, subjectsPerSlot: 1);

        $this->assertSame(1, $result->first()->teachersAvailable);
    }

    public function test_teachers_available_excludes_teachers_capped_at_zero_max_duties(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->create(['rows' => 10, 'columns' => 1, 'capacity' => 10]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => Room::first()->id, 'is_active' => true]);

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 5);

        $available = Teacher::factory()->create(['is_active' => true]);
        $zeroMax = Teacher::factory()->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $zeroMax->id,
            'is_excluded' => false,
            'max_duties' => 0,
        ]);

        $result = (new SlotCapacitySimulator)->simulate($session, subjectsPerSlot: 1);

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
        Room::factory()->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        Room::factory()->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        foreach (Room::all() as $room) {
            SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        }

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 15);

        $result = (new SlotCapacitySimulator)->simulate($session, subjectsPerSlot: 1);

        $requirement = $result->first();
        $this->assertTrue($requirement->hasUnseatedStudents);
        $this->assertSame(2, $requirement->roomsAvailable);
        $this->assertGreaterThan($requirement->roomsAvailable, $requirement->roomsNeeded);
        $this->assertGreaterThan(0, $requirement->roomsShortfall());
        $this->assertFalse($requirement->isMet());
    }

    public function test_no_enrollments_yet_returns_an_empty_collection(): void
    {
        $session = ExamSession::factory()->create();

        $result = (new SlotCapacitySimulator)->simulate($session, subjectsPerSlot: 2);

        $this->assertTrue($result->isEmpty());
    }

    public function test_a_subject_with_more_students_than_one_room_holds_needs_multiple_rooms(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 2]);
        Room::factory()->count(2)->create(['rows' => 5, 'columns' => 1, 'capacity' => 5]);
        foreach (Room::all() as $room) {
            SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        }

        $subject = Subject::factory()->create();
        $this->enrollStudents($session, $subject, 'BSAI 1A', 8);

        $result = (new SlotCapacitySimulator)->simulate($session, subjectsPerSlot: 1);

        $this->assertSame(2, $result->first()->roomsNeeded);
        $this->assertSame(4, $result->first()->teachersNeeded);
    }
}
