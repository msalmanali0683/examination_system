<?php

namespace Tests\Feature;

use App\Livewire\Sessions\Index;
use App\Livewire\Sessions\RoomSelection;
use App\Livewire\Sessions\Show;
use App\Livewire\Sessions\TeacherConstraints;
use App\Livewire\Sessions\TimeSlots;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\SessionRoom;
use App\Models\SessionTeacherConstraint;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExamSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_a_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'Midterm Spring 2026')
            ->set('start_date', '2026-04-20')
            ->set('end_date', '2026-04-25')
            ->call('save');

        $this->assertDatabaseHas('exam_sessions', ['name' => 'Midterm Spring 2026']);
    }

    public function test_editing_session_details_saves_department_name_and_report_stamp(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(Show::class, ['examSession' => $session])
            ->call('editDetails')
            ->set('department_name', 'Department of Computer Science')
            ->set('report_status', 'final')
            ->set('report_version', 'v2')
            ->call('saveDetails');

        $session->refresh();
        $this->assertSame('Department of Computer Science', $session->department_name);
        $this->assertSame('final', $session->report_status);
        $this->assertSame('v2', $session->report_version);
        $this->assertSame('FINAL — v2', $session->reportStampLabel());
    }

    public function test_department_name_and_report_stamp_fall_back_to_defaults_when_left_blank(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(Show::class, ['examSession' => $session])
            ->call('editDetails')
            ->set('department_name', '')
            ->call('saveDetails');

        $session->refresh();
        $this->assertNull($session->department_name);
        $this->assertSame(config('exam.department_name'), $session->effectiveDepartmentName());
        $this->assertSame('tentative', $session->report_status);
        $this->assertSame('TENTATIVE — SUBJECT TO CHANGE', $session->reportStampLabel());
    }

    public function test_end_date_cannot_be_before_start_date(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'Bad Session')
            ->set('start_date', '2026-04-25')
            ->set('end_date', '2026-04-20')
            ->call('save')
            ->assertHasErrors(['end_date']);
    }

    public function test_toggling_a_room_adds_and_removes_it_from_the_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create();

        $component = Livewire::actingAs($staff)->test(RoomSelection::class, ['examSession' => $session]);

        $component->call('toggleRoom', $room->id);
        $this->assertDatabaseHas('session_rooms', ['exam_session_id' => $session->id, 'room_id' => $room->id]);

        $component->call('toggleRoom', $room->id);
        $this->assertDatabaseMissing('session_rooms', ['exam_session_id' => $session->id, 'room_id' => $room->id]);
    }

    public function test_capacity_override_is_clamped_to_room_capacity(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create(['capacity' => 50]);

        $component = Livewire::actingAs($staff)->test(RoomSelection::class, ['examSession' => $session]);
        $component->call('toggleRoom', $room->id);
        $component->call('updateCapacityOverride', $room->id, '999');

        $this->assertDatabaseHas('session_rooms', [
            'exam_session_id' => $session->id,
            'room_id' => $room->id,
            'capacity_override' => 50,
        ]);
    }

    public function test_select_all_rooms_includes_every_active_room_and_leaves_existing_overrides(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $alreadyIncluded = Room::factory()->create(['capacity' => 50]);
        $notYetIncluded = Room::factory()->create();
        $inactiveRoom = Room::factory()->create(['is_active' => false]);

        SessionRoom::create([
            'exam_session_id' => $session->id, 'room_id' => $alreadyIncluded->id, 'is_active' => true, 'capacity_override' => 30,
        ]);

        Livewire::actingAs($staff)
            ->test(RoomSelection::class, ['examSession' => $session])
            ->call('selectAllRooms');

        $this->assertDatabaseHas('session_rooms', ['exam_session_id' => $session->id, 'room_id' => $alreadyIncluded->id, 'capacity_override' => 30]);
        $this->assertDatabaseHas('session_rooms', ['exam_session_id' => $session->id, 'room_id' => $notYetIncluded->id]);
        $this->assertDatabaseMissing('session_rooms', ['exam_session_id' => $session->id, 'room_id' => $inactiveRoom->id]);
    }

    public function test_deselect_all_rooms_removes_every_room_from_the_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $roomA = Room::factory()->create();
        $roomB = Room::factory()->create();

        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $roomA->id, 'is_active' => true]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $roomB->id, 'is_active' => true]);

        Livewire::actingAs($staff)
            ->test(RoomSelection::class, ['examSession' => $session])
            ->call('deselectAllRooms');

        $this->assertDatabaseCount('session_rooms', 0);
    }

    public function test_excluding_a_teacher_creates_a_constraint_row_and_reverting_removes_it(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create();

        $component = Livewire::actingAs($staff)->test(TeacherConstraints::class, ['examSession' => $session]);

        $component->call('toggleExcluded', $teacher->id);
        $this->assertDatabaseHas('session_teacher_constraints', [
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'is_excluded' => true,
        ]);

        $component->call('toggleExcluded', $teacher->id);
        $this->assertDatabaseMissing('session_teacher_constraints', [
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_setting_min_duties_persists_even_when_not_excluded(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create();

        Livewire::actingAs($staff)
            ->test(TeacherConstraints::class, ['examSession' => $session])
            ->call('updateMinDuties', $teacher->id, '3');

        $this->assertDatabaseHas('session_teacher_constraints', [
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'is_excluded' => false,
            'min_duties' => 3,
        ]);
    }

    public function test_bulk_apply_sets_min_and_max_duties_for_every_active_teacher(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teachers = Teacher::factory()->count(3)->create(['is_active' => true]);
        Teacher::factory()->create(['is_active' => false]); // must be left alone

        Livewire::actingAs($staff)
            ->test(TeacherConstraints::class, ['examSession' => $session])
            ->set('bulkMinDuties', '3')
            ->set('bulkMaxDuties', '5')
            ->call('applyBulkDuties');

        foreach ($teachers as $teacher) {
            $this->assertDatabaseHas('session_teacher_constraints', [
                'exam_session_id' => $session->id,
                'teacher_id' => $teacher->id,
                'min_duties' => 3,
                'max_duties' => 5,
            ]);
        }

        $this->assertDatabaseCount('session_teacher_constraints', 3);
    }

    public function test_bulk_apply_with_min_greater_than_max_is_rejected(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create();

        Livewire::actingAs($staff)
            ->test(TeacherConstraints::class, ['examSession' => $session])
            ->set('bulkMinDuties', '5')
            ->set('bulkMaxDuties', '2')
            ->call('applyBulkDuties');

        $this->assertDatabaseMissing('session_teacher_constraints', [
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_bulk_apply_with_blank_fields_clears_existing_overrides(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'min_duties' => 4,
            'max_duties' => 8,
        ]);

        Livewire::actingAs($staff)
            ->test(TeacherConstraints::class, ['examSession' => $session])
            ->set('bulkMinDuties', '')
            ->set('bulkMaxDuties', '')
            ->call('applyBulkDuties');

        // Back to session defaults for everyone — no longer differs, so the row is removed.
        $this->assertDatabaseMissing('session_teacher_constraints', [
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_bulk_toggle_day_marks_every_active_teacher_unavailable_and_reverting_clears_it(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teachers = Teacher::factory()->count(3)->create(['is_active' => true]);
        Teacher::factory()->create(['is_active' => false]); // must be left alone

        $component = Livewire::actingAs($staff)->test(TeacherConstraints::class, ['examSession' => $session]);

        // Turn Saturday (ISO 6) off for everyone.
        $component->call('toggleDayForAll', 6, false);

        foreach ($teachers as $teacher) {
            $this->assertDatabaseHas('session_teacher_constraints', [
                'exam_session_id' => $session->id,
                'teacher_id' => $teacher->id,
            ]);
            $constraint = SessionTeacherConstraint::where('exam_session_id', $session->id)
                ->where('teacher_id', $teacher->id)->first();
            $this->assertSame([6], $constraint->unavailable_days);
        }
        $this->assertDatabaseCount('session_teacher_constraints', 3);

        // Turn Saturday back on for everyone -> rows go back to "no override".
        $component->call('toggleDayForAll', 6, true);
        $this->assertDatabaseCount('session_teacher_constraints', 0);
    }

    public function test_bulk_toggle_day_preserves_other_existing_unavailable_days(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create(['is_active' => true]);
        SessionTeacherConstraint::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'unavailable_days' => [1], // already unavailable Monday
        ]);

        Livewire::actingAs($staff)
            ->test(TeacherConstraints::class, ['examSession' => $session])
            ->call('toggleDayForAll', 6, false); // now also off Saturday

        $constraint = SessionTeacherConstraint::where('exam_session_id', $session->id)
            ->where('teacher_id', $teacher->id)->first();
        $this->assertEqualsCanonicalizing([1, 6], $constraint->unavailable_days);
    }

    public function test_marking_a_teacher_unavailable_on_a_day_persists_and_reverting_removes_the_row(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create();

        $component = Livewire::actingAs($staff)->test(TeacherConstraints::class, ['examSession' => $session]);

        // Mark Saturday (ISO 6) unavailable.
        $component->call('toggleDayAvailable', $teacher->id, 6);

        $constraint = SessionTeacherConstraint::where('exam_session_id', $session->id)
            ->where('teacher_id', $teacher->id)
            ->first();
        $this->assertSame([6], $constraint->unavailable_days);
        $this->assertFalse($constraint->isAvailableOn(Carbon::parse('2026-04-25'))); // a Saturday
        $this->assertTrue($constraint->isAvailableOn(Carbon::parse('2026-04-20'))); // a Monday

        // Toggling the same day back on should remove the row entirely
        // (no longer differs from the session default).
        $component->call('toggleDayAvailable', $teacher->id, 6);
        $this->assertDatabaseMissing('session_teacher_constraints', [
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_staff_can_create_a_time_slot(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->set('date', '2026-04-20')
            ->set('start_time', '09:00')
            ->set('end_time', '10:30')
            ->set('label', 'Morning')
            ->call('save');

        $this->assertDatabaseHas('time_slots', [
            'exam_session_id' => $session->id,
            'label' => 'Morning',
        ]);
    }

    public function test_time_slot_end_time_must_be_after_start_time(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();

        Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->set('date', '2026-04-20')
            ->set('start_time', '10:30')
            ->set('end_time', '09:00')
            ->call('save')
            ->assertHasErrors(['end_time']);
    }

    public function test_user_without_manage_sessions_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_sessions', 'granted' => false]);

        $this->actingAs($staff)
            ->get('/sessions')
            ->assertForbidden();
    }

    public function test_reset_enrollments_wipes_enrollments_and_dependent_data_and_reverts_status_to_draft(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        $teacher = Teacher::factory()->create();
        $room = Room::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);

        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);

        SeatAssignment::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'row_number' => 1,
            'column_number' => 1,
        ]);

        DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
        ]);

        Livewire::actingAs($staff)
            ->test(Show::class, ['examSession' => $session])
            ->call('resetEnrollments');

        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('seat_assignments', 0);
        $this->assertDatabaseCount('subject_slot_assignments', 0);
        $this->assertDatabaseCount('duty_assignments', 0);
        $this->assertSame('draft', $session->fresh()->status);

        // Shared catalog data (subjects/students/rooms) is untouched.
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    public function test_reset_enrollments_is_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        Enrollment::factory()->create(['exam_session_id' => $session->id]);

        Livewire::actingAs($staff)
            ->test(Show::class, ['examSession' => $session])
            ->call('resetEnrollments');

        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_deleting_a_session_removes_it_and_every_dependent_row(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $room = Room::factory()->create();
        $teacher = Teacher::factory()->create();
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);

        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        SessionTeacherConstraint::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'is_excluded' => true]);
        $enrollment = Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => $subject->id]);
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);
        SeatAssignment::create(['exam_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id, 'row_number' => 1, 'column_number' => 1]);
        DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id]);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteSession', $session->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('exam_sessions', ['id' => $session->id]);
        $this->assertDatabaseCount('time_slots', 0);
        $this->assertDatabaseCount('session_rooms', 0);
        $this->assertDatabaseCount('session_teacher_constraints', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('subject_slot_assignments', 0);
        $this->assertDatabaseCount('seat_assignments', 0);
        $this->assertDatabaseCount('duty_assignments', 0);

        // Shared catalog data is untouched.
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('teachers', ['id' => $teacher->id]);
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    public function test_deleting_a_finalized_session_is_blocked(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteSession', $session->id);

        $this->assertDatabaseHas('exam_sessions', ['id' => $session->id]);
    }

    public function test_user_without_manage_enrollments_permission_cannot_reset(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_enrollments', 'granted' => false]);
        $session = ExamSession::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id]);

        Livewire::actingAs($staff)
            ->test(Show::class, ['examSession' => $session])
            ->call('resetEnrollments')
            ->assertForbidden();

        $this->assertDatabaseCount('enrollments', 1);
    }
}
