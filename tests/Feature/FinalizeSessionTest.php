<?php

namespace Tests\Feature;

use App\Livewire\Rooms\Index as RoomsIndex;
use App\Livewire\Sessions\DutyBoard;
use App\Livewire\Sessions\EnrollmentImport;
use App\Livewire\Sessions\GenerationConstraints;
use App\Livewire\Sessions\SeatingChart;
use App\Livewire\Sessions\Show;
use App\Livewire\Sessions\TeacherConstraints;
use App\Livewire\Sessions\TimeSlots;
use App\Livewire\Sessions\Timetable;
use App\Models\DutyAssignment;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinalizeSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_can_finalize_a_generated_session(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $session = ExamSession::factory()->create(['status' => 'generated']);

        Livewire::actingAs($head)
            ->test(Show::class, ['examSession' => $session])
            ->call('finalize');

        $session->refresh();
        $this->assertSame('finalized', $session->status);
        $this->assertNotNull($session->locked_at);
        $this->assertDatabaseHas('activity_logs', [
            'exam_session_id' => $session->id,
            'action' => 'session.finalized',
        ]);
    }

    public function test_a_draft_session_cannot_be_finalized(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $session = ExamSession::factory()->create(['status' => 'draft']);

        Livewire::actingAs($head)
            ->test(Show::class, ['examSession' => $session])
            ->call('finalize');

        $this->assertSame('draft', $session->fresh()->status);
    }

    public function test_staff_without_finalize_permission_cannot_finalize(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'generated']);

        Livewire::actingAs($staff)
            ->test(Show::class, ['examSession' => $session])
            ->call('finalize')
            ->assertForbidden();
    }

    public function test_head_can_unlock_a_finalized_session(): void
    {
        $head = User::factory()->create(['role' => 'head']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);

        Livewire::actingAs($head)
            ->test(Show::class, ['examSession' => $session])
            ->call('unlock');

        $session->refresh();
        $this->assertSame('generated', $session->status);
        $this->assertNull($session->locked_at);
        $this->assertDatabaseHas('activity_logs', [
            'exam_session_id' => $session->id,
            'action' => 'session.unlocked',
        ]);
    }

    public function test_room_changes_are_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $room = Room::factory()->for($session)->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(RoomsIndex::class, ['examSession' => $session])
            ->call('toggleActive', $room->id)
            ->call('deleteRoom', $room->id);

        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'is_active' => true]);
    }

    public function test_teacher_constraints_are_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $teacher = Teacher::factory()->for($session)->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(TeacherConstraints::class, ['examSession' => $session])
            ->call('toggleExcluded', $teacher->id);

        $this->assertDatabaseMissing('session_teacher_constraints', ['exam_session_id' => $session->id, 'teacher_id' => $teacher->id]);
    }

    public function test_time_slots_are_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);

        Livewire::actingAs($staff)
            ->test(TimeSlots::class, ['examSession' => $session])
            ->set('date', '2026-05-01')
            ->set('start_time', '09:00')
            ->set('end_time', '10:00')
            ->call('save');

        $this->assertDatabaseCount('time_slots', 0);
    }

    public function test_generation_constraints_are_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now(), 'invigilators_per_room' => 2]);

        Livewire::actingAs($staff)
            ->test(GenerationConstraints::class, ['examSession' => $session])
            ->set('invigilators_per_room', 5)
            ->call('saveSettings');

        $this->assertSame(2, $session->fresh()->invigilators_per_room);
    }

    public function test_generate_timetable_is_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $subject = Subject::factory()->create();
        Enrollment::factory()->create(['exam_session_id' => $session->id, 'subject_id' => $subject->id]);

        Livewire::actingAs($staff)
            ->test(Timetable::class, ['examSession' => $session])
            ->call('generateTimetable');

        $this->assertDatabaseCount('subject_slot_assignments', 0);
    }

    public function test_seat_moves_are_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $room = Room::factory()->for($session)->create(['rows' => 2, 'columns' => 2, 'capacity' => 4]);
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

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('moveSeat', $enrollment->id, $room->id, 2, 2);

        $this->assertDatabaseHas('seat_assignments', [
            'enrollment_id' => $enrollment->id,
            'row_number' => 1,
            'column_number' => 1,
        ]);
    }

    public function test_duty_reassignment_is_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $room = Room::factory()->for($session)->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $teacherA = Teacher::factory()->for($session)->create(['is_active' => true]);
        $teacherB = Teacher::factory()->for($session)->create(['is_active' => true]);
        $duty = DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacherA->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
        ]);

        Livewire::actingAs($staff)
            ->test(DutyBoard::class, ['examSession' => $session])
            ->call('reassignDuty', $duty->id, $teacherB->id);

        $this->assertDatabaseHas('duty_assignments', ['id' => $duty->id, 'teacher_id' => $teacherA->id]);
    }

    public function test_enrollment_import_commit_is_blocked_on_a_finalized_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);

        Livewire::actingAs($staff)
            ->test(EnrollmentImport::class, ['examSession' => $session])
            ->call('commitImport');

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_finalized_session_read_only_banner_is_shown(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);

        Livewire::actingAs($staff)
            ->test(Show::class, ['examSession' => $session])
            ->assertSee('read-only');
    }
}
