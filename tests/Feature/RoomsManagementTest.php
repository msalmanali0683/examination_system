<?php

namespace Tests\Feature;

use App\Livewire\Rooms\Index;
use App\Models\Enrollment;
use App\Models\ExamSession;
use App\Models\Room;
use App\Models\SeatAssignment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoomsManagementTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function seatIn(Room $room, int $row = 15, int $column = 4): SeatAssignment
    {
        $session = $room->examSession;
        $slot = TimeSlot::factory()->create(['exam_session_id' => $session->id]);
        $enrollment = Enrollment::factory()->create([
            'exam_session_id' => $session->id,
            'student_id' => Student::factory()->for($session),
            'subject_id' => Subject::factory()->for($session),
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

    public function test_staff_can_create_a_room_inside_the_session(): void
    {
        $session = ExamSession::factory()->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('name', 'ITC-310')
            ->set('rows', 20)
            ->set('columns', 5)
            ->set('capacity', 100)
            ->set('room_type', 'regular')
            ->call('save');

        $this->assertDatabaseHas('rooms', ['name' => 'ITC-310', 'capacity' => 100, 'exam_session_id' => $session->id]);
    }

    public function test_capacity_cannot_exceed_rows_times_columns(): void
    {
        $session = ExamSession::factory()->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('name', 'ITC-311')
            ->set('rows', 5)
            ->set('columns', 5)
            ->set('capacity', 999)
            ->set('room_type', 'regular')
            ->call('save')
            ->assertHasErrors(['capacity']);

        $this->assertDatabaseMissing('rooms', ['name' => 'ITC-311']);
    }

    public function test_room_names_must_be_unique_within_a_session(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['name' => 'ITC-310']);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('name', 'ITC-310')
            ->set('rows', 5)
            ->set('columns', 5)
            ->set('capacity', 25)
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_another_session_can_use_the_same_room_name(): void
    {
        $sessionA = ExamSession::factory()->create();
        $sessionB = ExamSession::factory()->create();
        Room::factory()->for($sessionA)->create(['name' => 'ITC-310']);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $sessionB])
            ->set('name', 'ITC-310')
            ->set('rows', 5)
            ->set('columns', 5)
            ->set('capacity', 25)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $sessionA->rooms()->count());
        $this->assertSame(1, $sessionB->rooms()->count());
    }

    public function test_only_this_sessions_rooms_are_listed(): void
    {
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();
        Room::factory()->for($session)->create(['name' => 'Mine']);
        Room::factory()->for($other)->create(['name' => 'Theirs']);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->assertSee('Mine')
            ->assertDontSee('Theirs');
    }

    public function test_a_room_from_another_session_cannot_be_edited_toggled_or_deleted(): void
    {
        $session = ExamSession::factory()->create();
        $foreign = Room::factory()->for(ExamSession::factory()->create())->create(['is_active' => true]);

        foreach (['editRoom', 'toggleActive', 'deleteRoom'] as $action) {
            $this->assertThrows(
                fn () => Livewire::actingAs($this->staff())->test(Index::class, ['examSession' => $session])->call($action, $foreign->id),
                ModelNotFoundException::class
            );
        }

        $this->assertDatabaseHas('rooms', ['id' => $foreign->id, 'is_active' => true]);
    }

    public function test_toggling_active_flips_status(): void
    {
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create(['is_active' => true]);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('toggleActive', $room->id);

        $this->assertFalse($room->fresh()->is_active);
    }

    public function test_shrinking_a_rooms_grid_is_blocked_when_it_would_strand_existing_seats(): void
    {
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $room = Room::factory()->for($session)->create(['rows' => 20, 'columns' => 5, 'capacity' => 100]);
        $this->seatIn($room, row: 15, column: 4); // outside a 10x5 grid

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
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
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $room = Room::factory()->for($session)->create(['rows' => 20, 'columns' => 5, 'capacity' => 100]);
        $this->seatIn($room, row: 8, column: 4); // still fits a 10x5 grid

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('editRoom', $room->id)
            ->set('rows', 10)
            ->set('columns', 5)
            ->set('capacity', 50)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(10, $room->fresh()->rows);
    }

    public function test_a_room_can_be_deleted_and_takes_its_seats_with_it(): void
    {
        $session = ExamSession::factory()->create(['status' => 'generated']);
        $room = Room::factory()->for($session)->create();
        $seat = $this->seatIn($room);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteRoom', $room->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $this->assertDatabaseMissing('seat_assignments', ['id' => $seat->id]);
    }

    public function test_a_finalized_session_refuses_room_changes(): void
    {
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        $room = Room::factory()->for($session)->create(['name' => 'ITC-310']);
        $seat = $this->seatIn($room);

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->call('deleteRoom', $room->id)
            ->assertSee('finalized')
            ->set('name', 'ITC-999')
            ->set('rows', 5)
            ->set('columns', 5)
            ->set('capacity', 25)
            ->call('save');

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('seat_assignments', ['id' => $seat->id]);
        $this->assertDatabaseMissing('rooms', ['name' => 'ITC-999']);
    }

    public function test_user_without_manage_rooms_permission_is_forbidden(): void
    {
        $staff = $this->staff();
        $staff->permissionOverrides()->create(['permission' => 'manage_rooms', 'granted' => false]);
        $session = ExamSession::factory()->create();

        $this->actingAs($staff)
            ->get(route('sessions.rooms.index', $session))
            ->assertForbidden();
    }

    public function test_per_page_selector_controls_how_many_rooms_are_shown(): void
    {
        $session = ExamSession::factory()->create();
        Room::factory()->for($session)->count(15)->create();

        Livewire::actingAs($this->staff())
            ->test(Index::class, ['examSession' => $session])
            ->set('perPage', 10)
            ->assertViewHas('rooms', fn ($rooms) => $rooms->count() === 10 && $rooms->total() === 15);
    }
}
