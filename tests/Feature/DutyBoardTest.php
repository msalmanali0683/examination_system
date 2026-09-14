<?php

namespace Tests\Feature;

use App\Livewire\Sessions\DutyBoard;
use App\Models\DutyAssignment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SessionTeacherConstraint;
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
}
