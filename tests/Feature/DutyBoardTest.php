<?php

namespace Tests\Feature;

use App\Livewire\Sessions\DutyBoard;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DutyBoardTest extends TestCase
{
    use RefreshDatabase;

    private function duty(ExamSession $session, TimeSlot $slot, Room $room, Teacher $teacher, bool $locked = false): DutyAssignment
    {
        return DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'is_locked' => $locked,
        ]);
    }

    public function test_reassigning_a_duty_updates_the_teacher_and_locks_it(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20']);
        $oldTeacher = Teacher::factory()->create(['is_active' => true]);
        $newTeacher = Teacher::factory()->create(['is_active' => true]);
        $duty = $this->duty($session, $slot, $room, $oldTeacher);

        Livewire::actingAs($staff)
            ->test(DutyBoard::class, ['examSession' => $session])
            ->call('reassignDuty', $duty->id, $newTeacher->id);

        $duty->refresh();
        $this->assertSame($newTeacher->id, $duty->teacher_id);
        $this->assertTrue($duty->is_locked);
    }

    public function test_cannot_reassign_a_locked_duty(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $oldTeacher = Teacher::factory()->create(['is_active' => true]);
        $newTeacher = Teacher::factory()->create(['is_active' => true]);
        $duty = $this->duty($session, $slot, $room, $oldTeacher, locked: true);

        Livewire::actingAs($staff)
            ->test(DutyBoard::class, ['examSession' => $session])
            ->call('reassignDuty', $duty->id, $newTeacher->id);

        $this->assertSame($oldTeacher->id, $duty->fresh()->teacher_id);
    }

    public function test_cannot_reassign_to_an_excluded_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $oldTeacher = Teacher::factory()->create(['is_active' => true]);
        $excludedTeacher = Teacher::factory()->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $excludedTeacher->id,
            'is_excluded' => true,
        ]);
        $duty = $this->duty($session, $slot, $room, $oldTeacher);

        Livewire::actingAs($staff)
            ->test(DutyBoard::class, ['examSession' => $session])
            ->call('reassignDuty', $duty->id, $excludedTeacher->id);

        $this->assertSame($oldTeacher->id, $duty->fresh()->teacher_id);
    }

    public function test_cannot_reassign_to_a_teacher_already_on_duty_that_slot(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $teacherA = Teacher::factory()->create(['is_active' => true]);
        $teacherB = Teacher::factory()->create(['is_active' => true]);
        $dutyA = $this->duty($session, $slot, $roomA, $teacherA);
        $this->duty($session, $slot, $roomB, $teacherB); // teacherB already on duty this slot

        Livewire::actingAs($staff)
            ->test(DutyBoard::class, ['examSession' => $session])
            ->call('reassignDuty', $dutyA->id, $teacherB->id);

        $this->assertSame($teacherA->id, $dutyA->fresh()->teacher_id);
    }

    public function test_toggle_duty_lock_flips_the_flag(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $duty = $this->duty($session, $slot, $room, $teacher);

        $component = Livewire::actingAs($staff)->test(DutyBoard::class, ['examSession' => $session]);

        $component->call('toggleDutyLock', $duty->id);
        $this->assertTrue($duty->fresh()->is_locked);

        $component->call('toggleDutyLock', $duty->id);
        $this->assertFalse($duty->fresh()->is_locked);
    }

    public function test_user_without_edit_assignments_permission_cannot_reassign(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'edit_assignments', 'granted' => false]);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $newTeacher = Teacher::factory()->create(['is_active' => true]);
        $duty = $this->duty($session, $slot, $room, $teacher);

        Livewire::actingAs($staff)
            ->test(DutyBoard::class, ['examSession' => $session])
            ->call('reassignDuty', $duty->id, $newTeacher->id)
            ->assertForbidden();
    }

    public function test_generate_duties_is_blocked_until_seating_exists(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(DutyBoard::class, ['examSession' => $session])
            ->call('regenerate');

        $this->assertDatabaseCount('duty_assignments', 0);
        $this->assertSame('draft', $session->fresh()->status);
    }

    public function test_generate_duties_creates_assignments_and_marks_the_session_generated(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'draft', 'invigilators_per_room' => 1]);
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
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
        Teacher::factory()->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(DutyBoard::class, ['examSession' => $session])
            ->call('regenerate');

        $this->assertDatabaseCount('duty_assignments', 1);
        $this->assertSame('generated', $session->fresh()->status);
    }

    public function test_show_teacher_duties_lists_every_duty_in_order_with_lock_state(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create(['name' => 'Dr Naveed']);
        $roomA = Room::factory()->create(['name' => 'Room A']);
        $roomB = Room::factory()->create(['name' => 'Room B']);
        $morning = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00', 'end_time' => '11:00']);
        $afternoon = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-21', 'start_time' => '13:00', 'end_time' => '15:00']);

        // Deliberately created out of chronological order — the modal
        // must still list them earliest first.
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $afternoon->id, 'room_id' => $roomB->id, 'is_locked' => true]);
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $morning->id, 'room_id' => $roomA->id, 'is_locked' => false]);

        $component = Livewire::actingAs($staff)->test(DutyBoard::class, ['examSession' => $session]);
        $component->call('showTeacherDuties', $teacher->id);

        $details = $component->get('teacherDutyDetails');
        $this->assertSame('Dr Naveed', $details['teacherName']);
        $this->assertCount(2, $details['duties']);
        $this->assertSame('20 Apr 2026', $details['duties'][0]['date']);
        $this->assertSame('Room A', $details['duties'][0]['room']);
        $this->assertFalse($details['duties'][0]['locked']);
        $this->assertSame('21 Apr 2026', $details['duties'][1]['date']);
        $this->assertSame('Room B', $details['duties'][1]['room']);
        $this->assertTrue($details['duties'][1]['locked']);
    }

    public function test_duty_fairness_table_reflects_min_max_and_status(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $teacher = Teacher::factory()->create(['is_active' => true]);
        $this->duty($session, $slot, $room, $teacher);

        $component = Livewire::actingAs($staff)->test(DutyBoard::class, ['examSession' => $session]);

        $fairness = $component->viewData('dutyFairness')->firstWhere('teacher.id', $teacher->id);
        $this->assertSame(1, $fairness->count);
    }
}
