<?php

namespace Tests\Feature;

use App\Livewire\Sessions\SeatingChart;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectSlotAssignment;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SeatingChartTest extends TestCase
{
    use RefreshDatabase;

    private function seat(ExamSession $session, TimeSlot $slot, Room $room, int $row, int $column, bool $locked = false): SeatAssignment
    {
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);

        return SeatAssignment::create([
            'exam_session_id' => $session->id,
            'enrollment_id' => $enrollment->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
            'row_number' => $row,
            'column_number' => $column,
            'is_locked' => $locked,
        ]);
    }

    public function test_moving_a_seat_to_an_empty_cell_relocates_and_locks_it(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 5]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seat = $this->seat($session, $slot, $room, 1, 1);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('moveSeat', $seat->enrollment_id, $room->id, 2, 3);

        $seat->refresh();
        $this->assertSame(2, $seat->row_number);
        $this->assertSame(3, $seat->column_number);
        $this->assertTrue($seat->is_locked);
    }

    public function test_cannot_move_a_locked_seat(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 5]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seat = $this->seat($session, $slot, $room, 1, 1, locked: true);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('moveSeat', $seat->enrollment_id, $room->id, 2, 2);

        $seat->refresh();
        $this->assertSame(1, $seat->row_number);
        $this->assertSame(1, $seat->column_number);
    }

    public function test_cannot_move_onto_an_occupied_seat(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 5]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seatA = $this->seat($session, $slot, $room, 1, 1);
        $this->seat($session, $slot, $room, 2, 2);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('moveSeat', $seatA->enrollment_id, $room->id, 2, 2);

        $seatA->refresh();
        $this->assertSame(1, $seatA->row_number);
        $this->assertSame(1, $seatA->column_number);
    }

    public function test_cannot_move_to_a_room_not_active_for_the_session(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 5]);
        $inactiveRoom = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 5, 'is_active' => false]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seat = $this->seat($session, $slot, $room, 1, 1);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('moveSeat', $seat->enrollment_id, $inactiveRoom->id, 1, 1);

        $seat->refresh();
        $this->assertSame($room->id, $seat->room_id);
    }

    public function test_cannot_move_outside_the_rooms_grid(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['rows' => 2, 'columns' => 2]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seat = $this->seat($session, $slot, $room, 1, 1);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('moveSeat', $seat->enrollment_id, $room->id, 5, 5);

        $seat->refresh();
        $this->assertSame(1, $seat->row_number);
    }

    public function test_toggle_lock_flips_the_flag(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seat = $this->seat($session, $slot, $room, 1, 1);

        $component = Livewire::actingAs($staff)->test(SeatingChart::class, ['examSession' => $session]);

        $component->call('toggleLock', $seat->id);
        $this->assertTrue($seat->fresh()->is_locked);

        $component->call('toggleLock', $seat->id);
        $this->assertFalse($seat->fresh()->is_locked);
    }

    public function test_the_displayed_capacity_uses_the_sessions_override_not_the_rooms_raw_capacity(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['rows' => 10, 'columns' => 5, 'capacity' => 50, 'capacity' => 40]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $this->seat($session, $slot, $room, 1, 1);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('selectSlot', $slot->id)
            ->assertSee('1 / 40 seated')
            ->assertDontSee('1 / 50 seated');
    }

    public function test_user_without_edit_assignments_permission_cannot_move_a_seat(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'edit_assignments', 'granted' => false]);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create();
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seat = $this->seat($session, $slot, $room, 1, 1);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('moveSeat', $seat->enrollment_id, $room->id, 2, 2)
            ->assertForbidden();
    }

    public function test_generate_seating_is_blocked_when_capacity_requirement_is_not_met(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        // No active rooms in session at all — guarantees a shortfall.
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('regenerate');

        $this->assertDatabaseCount('seat_assignments', 0);
    }

    /**
     * A teacher shortfall alone must never block generating seating —
     * only rooms/seats matter at that stage. Duty assignment (which does
     * need teachers) runs afterwards and already copes with a shortfall
     * via warnings instead of refusing to run.
     */
    public function test_generate_seating_proceeds_despite_a_teacher_shortfall(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 2]);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
        ]);

        // Only one teacher exists at all, but the slot needs
        // invigilators_per_room (2) — a genuine, unfixable-here shortfall.
        Teacher::factory()->for($session)->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('regenerate');

        $this->assertDatabaseCount('seat_assignments', 1);
    }

    /**
     * A same-day (not same-slot) alert means two papers from the same
     * semester share a calendar day — seating runs per-slot, so it has no
     * effect on whether this slot can be seated. It must never block
     * generation, same as a teacher shortfall above.
     */
    public function test_generate_seating_proceeds_despite_a_same_day_alert(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $subject = Subject::factory()->create();
        $student = Student::factory()->create();
        Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
        ]);
        SubjectSlotAssignment::create([
            'exam_session_id' => $session->id,
            'subject_id' => $subject->id,
            'time_slot_id' => $slot->id,
            'conflict_note' => 'CS101 and CS202 share 5 student(s) but were placed on the same day — no clash-free day remained. Consider adding a day/slot or pinning one of them elsewhere.',
        ]);

        Teacher::factory()->for($session)->count(3)->create(['is_active' => true]);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('regenerate');

        $this->assertDatabaseCount('seat_assignments', 1);
    }
}
