<?php

namespace Tests\Feature;

use App\Livewire\Sessions\Index;
use App\Livewire\Sessions\RoomSelection;
use App\Livewire\Sessions\TeacherConstraints;
use App\Livewire\Sessions\TimeSlots;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\User;
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

    public function test_marking_a_teacher_unavailable_on_a_day_persists_and_reverting_removes_the_row(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $teacher = Teacher::factory()->create();

        $component = Livewire::actingAs($staff)->test(TeacherConstraints::class, ['examSession' => $session]);

        // Mark Saturday (ISO 6) unavailable.
        $component->call('toggleDayAvailable', $teacher->id, 6);

        $constraint = \App\Models\SessionTeacherConstraint::where('exam_session_id', $session->id)
            ->where('teacher_id', $teacher->id)
            ->first();
        $this->assertSame([6], $constraint->unavailable_days);
        $this->assertFalse($constraint->isAvailableOn(\Carbon\Carbon::parse('2026-04-25'))); // a Saturday
        $this->assertTrue($constraint->isAvailableOn(\Carbon\Carbon::parse('2026-04-20'))); // a Monday

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
}
