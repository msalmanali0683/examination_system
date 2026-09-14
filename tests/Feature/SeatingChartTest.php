<?php

namespace Tests\Feature;

use App\Livewire\Sessions\SeatingChart;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\SessionRoom;
use App\Models\Student;
use App\Models\Subject;
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
        $room = Room::factory()->create(['rows' => 5, 'columns' => 5]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
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
        $room = Room::factory()->create(['rows' => 5, 'columns' => 5]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
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
        $room = Room::factory()->create(['rows' => 5, 'columns' => 5]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
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
        $room = Room::factory()->create(['rows' => 5, 'columns' => 5]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $inactiveRoom = Room::factory()->create(['rows' => 5, 'columns' => 5]);
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
        $room = Room::factory()->create(['rows' => 2, 'columns' => 2]);
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
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
        $room = Room::factory()->create();
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seat = $this->seat($session, $slot, $room, 1, 1);

        $component = Livewire::actingAs($staff)->test(SeatingChart::class, ['examSession' => $session]);

        $component->call('toggleLock', $seat->id);
        $this->assertTrue($seat->fresh()->is_locked);

        $component->call('toggleLock', $seat->id);
        $this->assertFalse($seat->fresh()->is_locked);
    }

    public function test_user_without_edit_assignments_permission_cannot_move_a_seat(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'edit_assignments', 'granted' => false]);
        $session = ExamSession::factory()->create();
        $room = Room::factory()->create();
        SessionRoom::create(['exam_session_id' => $session->id, 'room_id' => $room->id, 'is_active' => true]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $seat = $this->seat($session, $slot, $room, 1, 1);

        Livewire::actingAs($staff)
            ->test(SeatingChart::class, ['examSession' => $session])
            ->call('moveSeat', $seat->enrollment_id, $room->id, 2, 2)
            ->assertForbidden();
    }
}
