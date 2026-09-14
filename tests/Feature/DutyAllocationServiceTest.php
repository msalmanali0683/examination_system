<?php

namespace Tests\Feature;

use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Services\Generation\DutyAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DutyAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seatOneStudent(ExamSession $session, Subject $subject, TimeSlot $slot, Room $room): void
    {
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);

        SeatAssignment::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'row_number' => 1,
            'column_number' => 1,
        ]);
    }

    public function test_assigns_the_configured_number_of_invigilators_to_every_room_in_use(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 2]);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $this->seatOneStudent($session, $subject, $slot, $room);
        $teachers = Teacher::factory()->count(2)->create(['is_active' => true]);

        // Give both teachers a minimum of 0 so this test isolates "does
        // every room get its invigilators" from the separate fairness
        // concern of reaching everyone's minimum (covered elsewhere).
        foreach ($teachers as $teacher) {
            SessionTeacherConstraint::create([
                'exam_session_id' => $session->id,
                'teacher_id' => $teacher->id,
                'min_duties' => 0,
            ]);
        }

        $result = (new DutyAllocationService)->generate($session);

        $this->assertCount(2, $result->placements);
        $this->assertDatabaseCount('duty_assignments', 2);
        $this->assertTrue($result->warnings->isEmpty());
    }

    public function test_no_seating_means_no_slots_need_invigilators(): void
    {
        $session = ExamSession::factory()->create();
        Teacher::factory()->count(3)->create(['is_active' => true]);

        $result = (new DutyAllocationService)->generate($session);

        $this->assertCount(0, $result->placements);
        $this->assertDatabaseCount('duty_assignments', 0);
    }

    public function test_regenerating_leaves_locked_duties_untouched(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 1]);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $this->seatOneStudent($session, $subject, $slot, $room);

        $lockedTeacher = Teacher::factory()->create(['is_active' => true]);
        Teacher::factory()->count(2)->create(['is_active' => true]);

        DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $lockedTeacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'is_locked' => true,
        ]);

        (new DutyAllocationService)->generate($session);

        // Only 1 invigilator/room and it's already locked, so no fresh
        // duty should be created for this room/slot.
        $this->assertDatabaseCount('duty_assignments', 1);
        $this->assertDatabaseHas('duty_assignments', [
            'exam_session_id' => $session->id,
            'teacher_id' => $lockedTeacher->id,
            'is_locked' => true,
        ]);
    }

    public function test_excluded_and_day_unavailable_teachers_are_never_assigned(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 1]);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']); // Monday
        $subject = Subject::factory()->create();
        $this->seatOneStudent($session, $subject, $slot, $room);

        $excluded = Teacher::factory()->create(['is_active' => true]);
        $unavailableMonday = Teacher::factory()->create(['is_active' => true]);
        $eligible = Teacher::factory()->create(['is_active' => true]);

        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $excluded->id,
            'is_excluded' => true,
        ]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $unavailableMonday->id,
            'is_excluded' => false,
            'unavailable_days' => [1],
        ]);

        (new DutyAllocationService)->generate($session);

        $this->assertDatabaseHas('duty_assignments', ['teacher_id' => $eligible->id]);
        $this->assertDatabaseMissing('duty_assignments', ['teacher_id' => $excluded->id]);
        $this->assertDatabaseMissing('duty_assignments', ['teacher_id' => $unavailableMonday->id]);
    }

    public function test_teacher_subject_exclusion_keeps_a_teacher_off_their_own_subjects_slot(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 1, 'teacher_subject_exclusion' => true]);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();

        $owner = Teacher::factory()->create(['is_active' => true]); // teaches this subject
        $other = Teacher::factory()->create(['is_active' => true]);

        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'teacher_id' => $owner->id,
        ]);
        SeatAssignment::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'row_number' => 1,
            'column_number' => 1,
        ]);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);

        (new DutyAllocationService)->generate($session);

        $this->assertDatabaseHas('duty_assignments', ['teacher_id' => $other->id]);
        $this->assertDatabaseMissing('duty_assignments', ['teacher_id' => $owner->id]);
    }
}
