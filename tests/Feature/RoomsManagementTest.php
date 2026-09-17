<?php

namespace Tests\Feature;

use App\Livewire\Rooms\Index;
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

class RoomsManagementTest extends TestCase
{
    use RefreshDatabase;

    private function seatIn(Room $room, string $status = 'generated', int $row = 15, int $column = 4): SeatAssignment
    {
        $session = ExamSession::factory()->create(['status' => $status]);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
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
        ]);
    }

    public function test_staff_can_create_a_room(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'ITC-310')
            ->set('rows', 20)
            ->set('columns', 5)
            ->set('capacity', 100)
            ->set('room_type', 'regular')
            ->call('save');

        $this->assertDatabaseHas('rooms', ['name' => 'ITC-310', 'capacity' => 100]);
    }

    public function test_capacity_cannot_exceed_rows_times_columns(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'ITC-311')
            ->set('rows', 5)
            ->set('columns', 5)
            ->set('capacity', 999)
            ->set('room_type', 'regular')
            ->call('save')
            ->assertHasErrors(['capacity']);

        $this->assertDatabaseMissing('rooms', ['name' => 'ITC-311']);
    }

    public function test_room_names_must_be_unique(): void
    {
        Room::factory()->create(['name' => 'ITC-310']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('name', 'ITC-310')
            ->set('rows', 5)
            ->set('columns', 5)
            ->set('capacity', 25)
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_shrinking_a_rooms_grid_is_blocked_when_it_would_strand_existing_seats(): void
    {
        $room = Room::factory()->create(['rows' => 20, 'columns' => 5, 'capacity' => 100]);
        $this->seatIn($room, 'generated', row: 15, column: 4); // outside a 10x5 grid
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('editRoom', $room->id)
            ->set('rows', 10)
            ->set('columns', 5)
            ->set('capacity', 50)
            ->call('save')
            ->assertHasErrors(['rows']);

        $this->assertSame(20, $room->fresh()->rows);
    }

    public function test_shrinking_a_rooms_grid_is_allowed_when_no_seats_fall_outside_it(): void
    {
        $room = Room::factory()->create(['rows' => 20, 'columns' => 5, 'capacity' => 100]);
        $this->seatIn($room, 'generated', row: 8, column: 4); // still fits a 10x5 grid
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('editRoom', $room->id)
            ->set('rows', 10)
            ->set('columns', 5)
            ->set('capacity', 50)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(10, $room->fresh()->rows);
    }

    public function test_shrinking_a_rooms_grid_is_allowed_when_the_only_affected_session_is_finalized(): void
    {
        $room = Room::factory()->create(['rows' => 20, 'columns' => 5, 'capacity' => 100]);
        $this->seatIn($room, 'finalized', row: 15, column: 4);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('editRoom', $room->id)
            ->set('rows', 10)
            ->set('columns', 5)
            ->set('capacity', 50)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(10, $room->fresh()->rows);
    }

    public function test_a_room_with_no_history_can_be_deleted(): void
    {
        $room = Room::factory()->create();
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteRoom', $room->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    /**
     * Deleting a room cascades to its seat_assignments (cascadeOnDelete()
     * on seat_assignments.room_id) — for a finalized session that would
     * silently erase part of its permanent seating chart, so this is
     * blocked the same way deleting the session itself is blocked while
     * finalized.
     */
    public function test_deleting_a_room_used_in_a_finalized_sessions_seating_is_blocked(): void
    {
        $room = Room::factory()->create();
        $seat = $this->seatIn($room, 'finalized');
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteRoom', $room->id)
            ->assertSee("can't be deleted");

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('seat_assignments', ['id' => $seat->id]);
    }

    public function test_deleting_a_room_used_in_a_finalized_sessions_duty_roster_is_blocked(): void
    {
        $room = Room::factory()->create();
        $session = ExamSession::factory()->create(['status' => 'finalized']);
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $teacher = Teacher::factory()->create();

        $duty = DutyAssignment::create([
            'exam_session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'time_slot_id' => $slot->id,
            'room_id' => $room->id,
        ]);

        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteRoom', $room->id)
            ->assertSee("can't be deleted");

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('duty_assignments', ['id' => $duty->id]);
    }

    public function test_deleting_a_room_used_only_in_a_non_finalized_session_is_allowed(): void
    {
        $room = Room::factory()->create();
        $this->seatIn($room, 'generated');
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->call('deleteRoom', $room->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_user_without_manage_rooms_permission_is_forbidden(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $staff->permissionOverrides()->create(['permission' => 'manage_rooms', 'granted' => false]);

        $this->actingAs($staff)
            ->get('/rooms')
            ->assertForbidden();
    }

    public function test_per_page_selector_controls_how_many_rooms_are_shown(): void
    {
        Room::factory()->count(15)->create();
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Index::class)
            ->set('perPage', 10)
            ->assertViewHas('rooms', fn ($rooms) => $rooms->count() === 10 && $rooms->total() === 15);
    }
}
