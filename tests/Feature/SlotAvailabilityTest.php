<?php

namespace Tests\Feature;

use App\Livewire\Rooms\Index as RoomsIndex;
use App\Livewire\Sessions\DutyBoard;
use App\Livewire\Sessions\ItemAvailability;
use App\Livewire\Sessions\SeatingChart;
use App\Livewire\Sessions\TeacherConstraints;
use App\Livewire\Sessions\Timetable;
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
use App\Models\User;
use App\Services\Generation\DutyAllocationService;
use App\Services\Generation\RequirementCalculator;
use App\Services\Generation\SeatAllocationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A room or teacher can be switched off for specific time slots (e.g. free
 * on Monday except that day's 2nd slot).
 */
class SlotAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    /** Monday 2026-04-20 09:00 (slot 1), Monday 12:00 (slot 2), Tuesday 09:00 (slot 3). */
    private function threeSlots(ExamSession $session): array
    {
        return [
            TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00', 'end_time' => '11:00']),
            TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '12:00', 'end_time' => '14:00']),
            TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-21', 'start_time' => '09:00', 'end_time' => '11:00']),
        ];
    }

    private function subjectInSlot(ExamSession $session, TimeSlot $slot, int $students): Subject
    {
        $subject = Subject::factory()->for($session)->create();
        for ($i = 0; $i < $students; $i++) {
            Enrollment::factory()->create([
                'exam_session_id' => $session->id,
                'student_id' => Student::factory()->for($session),
                'subject_id' => $subject->id,
            ]);
        }
        SubjectSlotAssignment::create(['exam_session_id' => $session->id, 'subject_id' => $subject->id, 'time_slot_id' => $slot->id]);

        return $subject;
    }

    private function page(ExamSession $session, string $type, int $id)
    {
        return Livewire::actingAs($this->staff())->test(ItemAvailability::class, ['examSession' => $session, 'type' => $type, 'item' => $id]);
    }

    // ------------------------------------------------------------- the page

    public function test_a_room_can_be_switched_off_for_one_slot_and_back_on(): void
    {
        $session = ExamSession::factory()->create();
        [$slot1, $slot2] = $this->threeSlots($session);
        $room = Room::factory()->for($session)->create();

        $page = $this->page($session, 'rooms', $room->id);

        $page->call('toggleSlot', $slot2->id);
        $this->assertSame([$slot2->id], $room->unavailableSlots()->pluck('time_slots.id')->all());
        $this->assertNotContains($slot1->id, $room->unavailableSlots()->pluck('time_slots.id')->all());

        $page->call('toggleSlot', $slot2->id);
        $this->assertSame(0, $room->unavailableSlots()->count());
    }

    public function test_a_teacher_can_be_free_on_monday_except_its_second_slot(): void
    {
        $session = ExamSession::factory()->create();
        [$slot1, $slot2, $slot3] = $this->threeSlots($session);
        $teacher = Teacher::factory()->for($session)->create();

        $this->page($session, 'teachers', $teacher->id)->call('toggleSlot', $slot2->id);

        $off = $teacher->unavailableSlots()->pluck('time_slots.id')->all();
        $this->assertSame([$slot2->id], $off);
        // the rest of Monday, and Tuesday, are untouched
        $this->assertNotContains($slot1->id, $off);
        $this->assertNotContains($slot3->id, $off);
    }

    public function test_a_whole_day_can_be_switched_off_and_on_at_once(): void
    {
        $session = ExamSession::factory()->create();
        [$slot1, $slot2, $slot3] = $this->threeSlots($session);
        $room = Room::factory()->for($session)->create();

        $page = $this->page($session, 'rooms', $room->id);

        $page->call('toggleDay', '2026-04-20');
        $this->assertEqualsCanonicalizing([$slot1->id, $slot2->id], $room->unavailableSlots()->pluck('time_slots.id')->all());

        $page->call('toggleDay', '2026-04-20');
        $this->assertSame(0, $room->unavailableSlots()->count());

        // a partly-off day switches fully off first
        $page->call('toggleSlot', $slot1->id)->call('toggleDay', '2026-04-20');
        $this->assertEqualsCanonicalizing([$slot1->id, $slot2->id], $room->unavailableSlots()->pluck('time_slots.id')->all());
        $this->assertNotContains($slot3->id, $room->unavailableSlots()->pluck('time_slots.id')->all());
    }

    public function test_make_all_available_clears_everything(): void
    {
        $session = ExamSession::factory()->create();
        [$slot1, $slot2] = $this->threeSlots($session);
        $teacher = Teacher::factory()->for($session)->create();

        $this->page($session, 'teachers', $teacher->id)
            ->call('toggleSlot', $slot1->id)->call('toggleSlot', $slot2->id)
            ->call('makeAllAvailable');

        $this->assertSame(0, $teacher->unavailableSlots()->count());
    }

    public function test_the_page_numbers_slots_within_each_day_and_shows_which_are_off(): void
    {
        $session = ExamSession::factory()->create();
        [, $slot2] = $this->threeSlots($session);
        $teacher = Teacher::factory()->for($session)->create(['name' => 'Huria Ali']);
        $teacher->unavailableSlots()->attach($slot2->id);

        $this->page($session, 'teachers', $teacher->id)
            ->assertViewHas('item', fn ($item) => $item->name === 'Huria Ali')
            ->assertSee('Monday, 20 Apr 2026')
            ->assertSee('Slot 1')
            ->assertSee('Slot 2')
            ->assertSee('12:00 &ndash; 14:00', false)
            ->assertSee('1 of 3 slot(s) unavailable')
            ->assertSee('Unavailable');
    }

    public function test_a_teachers_whole_weekday_rule_shows_on_the_page_and_cant_be_undone_there(): void
    {
        $session = ExamSession::factory()->create();
        $this->threeSlots($session); // Monday, Monday, Tuesday
        $teacher = Teacher::factory()->for($session)->create();
        SessionTeacherConstraint::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'unavailable_days' => [1]]);

        $this->page($session, 'teachers', $teacher->id)->assertSee('Off every Monday');
    }

    public function test_an_excluded_teacher_gets_a_heads_up(): void
    {
        $session = ExamSession::factory()->create();
        $this->threeSlots($session);
        $teacher = Teacher::factory()->for($session)->create();
        SessionTeacherConstraint::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'is_excluded' => true]);

        $this->page($session, 'teachers', $teacher->id)->assertSee('excluded from the whole session');
    }

    public function test_a_session_without_time_slots_says_so(): void
    {
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create();

        $this->page($session, 'rooms', $room->id)->assertSee('No time slots yet');
    }

    public function test_only_this_sessions_rooms_teachers_and_slots_can_be_touched(): void
    {
        $session = ExamSession::factory()->create();
        $other = ExamSession::factory()->create();
        [$mySlot] = $this->threeSlots($session);
        [$theirSlot] = $this->threeSlots($other);
        $myRoom = Room::factory()->for($session)->create();
        $theirRoom = Room::factory()->for($other)->create();
        $theirTeacher = Teacher::factory()->for($other)->create();

        // (HTTP first: Livewire's test helper swaps out the exception handler.)
        $this->actingAs($this->staff())->get(route('sessions.availability', [$session, 'rooms', $theirRoom->id]))->assertNotFound();
        $this->actingAs($this->staff())->get(route('sessions.availability', [$session, 'teachers', $theirTeacher->id]))->assertNotFound();
        $this->actingAs($this->staff())->get(route('sessions.availability', [$session, 'rooms', $myRoom->id]))->assertOk();

        $this->assertThrows(fn () => $this->page($session, 'rooms', $theirRoom->id), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->page($session, 'teachers', $theirTeacher->id), ModelNotFoundException::class);

        $this->assertThrows(fn () => $this->page($session, 'rooms', $myRoom->id)->call('toggleSlot', $theirSlot->id), ModelNotFoundException::class);
        $this->assertSame(0, $myRoom->unavailableSlots()->count());
        $this->assertNotNull($mySlot);
    }

    public function test_the_type_and_item_cannot_be_switched_from_the_browser(): void
    {
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create();
        $other = Room::factory()->for($session)->create();

        $this->assertThrows(fn () => $this->page($session, 'rooms', $room->id)->set('type', 'teachers'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->page($session, 'rooms', $room->id)->set('itemId', $other->id), CannotUpdateLockedPropertyException::class);
    }

    public function test_permissions_follow_what_is_being_edited(): void
    {
        $session = ExamSession::factory()->create();
        $room = Room::factory()->for($session)->create();
        $teacher = Teacher::factory()->for($session)->create();

        $noRooms = $this->staff();
        $noRooms->permissionOverrides()->create(['permission' => 'manage_rooms', 'granted' => false]);
        $this->actingAs($noRooms)->get(route('sessions.availability', [$session, 'rooms', $room->id]))->assertForbidden();
        $this->actingAs($noRooms)->get(route('sessions.availability', [$session, 'teachers', $teacher->id]))->assertOk();

        $noTeachers = $this->staff();
        $noTeachers->permissionOverrides()->create(['permission' => 'manage_teachers', 'granted' => false]);
        $this->actingAs($noTeachers)->get(route('sessions.availability', [$session, 'teachers', $teacher->id]))->assertForbidden();
        $this->actingAs($noTeachers)->get(route('sessions.availability', [$session, 'rooms', $room->id]))->assertOk();
    }

    public function test_a_finalized_session_refuses_changes(): void
    {
        $session = ExamSession::factory()->create(['status' => 'finalized', 'locked_at' => now()]);
        [$slot] = $this->threeSlots($session);
        $room = Room::factory()->for($session)->create();

        $this->page($session, 'rooms', $room->id)
            ->call('toggleSlot', $slot->id)
            ->call('toggleDay', '2026-04-20');

        $this->assertSame(0, $room->unavailableSlots()->count());
    }

    public function test_deleting_a_slot_or_the_item_removes_its_availability_rows(): void
    {
        $session = ExamSession::factory()->create();
        [$slot1, $slot2] = $this->threeSlots($session);
        $room = Room::factory()->for($session)->create();
        $teacher = Teacher::factory()->for($session)->create();
        $room->unavailableSlots()->attach([$slot1->id, $slot2->id]);
        $teacher->unavailableSlots()->attach([$slot1->id, $slot2->id]);

        $slot1->delete();
        $this->assertSame([$slot2->id], $room->unavailableSlots()->pluck('time_slots.id')->all());
        $this->assertSame([$slot2->id], $teacher->unavailableSlots()->pluck('time_slots.id')->all());

        $room->delete();
        $teacher->delete();
        $this->assertDatabaseCount('room_unavailable_slots', 0);
        $this->assertDatabaseCount('teacher_unavailable_slots', 0);
    }

    public function test_the_rooms_and_teachers_lists_link_to_it_and_show_how_many_slots_are_off(): void
    {
        $session = ExamSession::factory()->create();
        [$slot1, $slot2] = $this->threeSlots($session);
        $room = Room::factory()->for($session)->create();
        $teacher = Teacher::factory()->for($session)->create(['is_active' => true]);
        $room->unavailableSlots()->attach([$slot1->id, $slot2->id]);
        $teacher->unavailableSlots()->attach($slot2->id);

        Livewire::actingAs($this->staff())->test(RoomsIndex::class, ['examSession' => $session])
            ->assertSee(route('sessions.availability', [$session, 'rooms', $room->id]), false)
            ->assertSee('(2 off)');

        Livewire::actingAs($this->staff())->test(TeacherConstraints::class, ['examSession' => $session])
            ->assertSee(route('sessions.availability', [$session, 'teachers', $teacher->id]), false)
            ->assertSee('(1 off)');
    }

    // ------------------------------------------------------- what it changes

    public function test_seating_never_uses_a_room_in_a_slot_it_is_switched_off_for(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        [$slot1, $slot2] = $this->threeSlots($session);
        $roomA = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $roomB = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $this->subjectInSlot($session, $slot1, 4);
        $this->subjectInSlot($session, $slot2, 4);
        $roomA->unavailableSlots()->attach($slot2->id);

        (new SeatAllocationService)->generate($session->fresh());

        $slot2Rooms = SeatAssignment::where('exam_session_id', $session->id)->where('time_slot_id', $slot2->id)->pluck('room_id')->unique()->all();
        $this->assertSame([$roomB->id], $slot2Rooms);
        $this->assertSame(4, SeatAssignment::where('time_slot_id', $slot2->id)->count());
        // ...but it's still fair game in the slots it wasn't switched off for
        $this->assertSame(4, SeatAssignment::where('time_slot_id', $slot1->id)->count());
    }

    public function test_students_go_unseated_when_every_room_is_off_for_their_slot(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict']);
        [$slot1] = $this->threeSlots($session);
        $room = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $this->subjectInSlot($session, $slot1, 3);
        $room->unavailableSlots()->attach($slot1->id);

        $result = (new SeatAllocationService)->generate($session->fresh());

        $this->assertSame(0, SeatAssignment::count());
        $this->assertSame(3, $result->warnings->where('type', 'unseated')->count());
    }

    public function test_the_capacity_check_counts_only_the_rooms_and_teachers_usable_in_each_slot(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        [$slot1, $slot2] = $this->threeSlots($session);
        $roomA = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $this->subjectInSlot($session, $slot1, 3);
        $this->subjectInSlot($session, $slot2, 3);

        $off = Teacher::factory()->for($session)->create(['is_active' => true]);
        Teacher::factory()->for($session)->count(3)->create(['is_active' => true]);
        $roomA->unavailableSlots()->attach($slot2->id);
        $off->unavailableSlots()->attach($slot2->id);

        $rows = (new RequirementCalculator)->calculate($session)->keyBy('timeSlotId');

        $this->assertSame([2, 20, 4], [$rows[$slot1->id]->roomsAvailable, $rows[$slot1->id]->seatsAvailable, $rows[$slot1->id]->teachersAvailable]);
        $this->assertSame([1, 10, 3], [$rows[$slot2->id]->roomsAvailable, $rows[$slot2->id]->seatsAvailable, $rows[$slot2->id]->teachersAvailable]);
    }

    public function test_a_teacher_already_off_that_day_or_excluded_is_not_counted_twice(): void
    {
        $session = ExamSession::factory()->create(['seating_strategy' => 'strict', 'invigilators_per_room' => 1]);
        [, $slot2] = $this->threeSlots($session); // Monday 12:00
        Room::factory()->for($session)->create(['rows' => 5, 'columns' => 2, 'capacity' => 10]);
        $this->subjectInSlot($session, $slot2, 2);

        $mondayOff = Teacher::factory()->for($session)->create(['is_active' => true]);
        $excluded = Teacher::factory()->for($session)->create(['is_active' => true]);
        $onlySlotOff = Teacher::factory()->for($session)->create(['is_active' => true]);
        Teacher::factory()->for($session)->count(2)->create(['is_active' => true]);
        SessionTeacherConstraint::create(['exam_session_id' => $session->id, 'teacher_id' => $mondayOff->id, 'unavailable_days' => [1]]);
        SessionTeacherConstraint::create(['exam_session_id' => $session->id, 'teacher_id' => $excluded->id, 'is_excluded' => true]);
        foreach ([$mondayOff, $excluded, $onlySlotOff] as $teacher) {
            $teacher->unavailableSlots()->attach($slot2->id);
        }

        $row = (new RequirementCalculator)->calculate($session)->firstWhere('timeSlotId', $slot2->id);

        // 5 active - 1 excluded - 1 off Mondays - 1 off just this slot = 2
        $this->assertSame(2, $row->teachersAvailable);
    }

    public function test_duty_allocation_skips_a_teacher_in_a_slot_they_are_off_for_but_not_the_rest_of_the_day(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 1]);
        [$slot1, $slot2] = $this->threeSlots($session); // both Monday
        $room = Room::factory()->for($session)->create();

        foreach ([$slot1, $slot2] as $slot) {
            $student = Student::factory()->for($session)->create();
            $enrollment = Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => $student->id, 'subject_id' => Subject::factory()->for($session)]);
            SeatAssignment::create(['exam_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'time_slot_id' => $slot->id, 'room_id' => $room->id, 'row_number' => 1, 'column_number' => 1]);
        }

        // Two teachers only, each must work at least one duty: the one who is
        // off for slot 2 has to take slot 1, and the other takes slot 2.
        $offSlot2 = Teacher::factory()->for($session)->create(['is_active' => true]);
        $other = Teacher::factory()->for($session)->create(['is_active' => true]);
        foreach ([$offSlot2, $other] as $teacher) {
            SessionTeacherConstraint::create(['exam_session_id' => $session->id, 'teacher_id' => $teacher->id, 'min_duties' => 1, 'max_duties' => 1]);
        }
        $offSlot2->unavailableSlots()->attach($slot2->id);

        (new DutyAllocationService)->generate($session);

        $this->assertDatabaseHas('duty_assignments', ['time_slot_id' => $slot1->id, 'teacher_id' => $offSlot2->id]);
        $this->assertDatabaseHas('duty_assignments', ['time_slot_id' => $slot2->id, 'teacher_id' => $other->id]);
        $this->assertDatabaseMissing('duty_assignments', ['time_slot_id' => $slot2->id, 'teacher_id' => $offSlot2->id]);
    }

    public function test_the_duty_board_refuses_a_teacher_who_is_off_for_that_slot_and_hides_them_from_the_choices(): void
    {
        $session = ExamSession::factory()->create(['invigilators_per_room' => 1]);
        [$slot1, $slot2] = $this->threeSlots($session);
        $room = Room::factory()->for($session)->create();
        $current = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Current Holder']);
        $off = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Switched Off']);
        $free = Teacher::factory()->for($session)->create(['is_active' => true, 'name' => 'Free Teacher']);
        $duty = DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $current->id, 'time_slot_id' => $slot2->id, 'room_id' => $room->id]);
        $off->unavailableSlots()->attach($slot2->id);

        $board = Livewire::actingAs($this->staff())->test(DutyBoard::class, ['examSession' => $session])
            ->set('activeSlotId', $slot2->id);

        $names = $board->viewData('rooms')->first()['rows']->first()['options']->pluck('name')->all();
        $this->assertContains('Free Teacher', $names);
        $this->assertContains('Current Holder', $names); // whoever holds the duty stays selectable
        $this->assertNotContains('Switched Off', $names);

        $board->call('reassignDuty', $duty->id, $off->id)->assertSee('unavailable for this slot');
        $this->assertSame($current->id, $duty->fresh()->teacher_id);

        // the same teacher is fine in the slot they weren't switched off for
        $other = DutyAssignment::create(['exam_session_id' => $session->id, 'teacher_id' => $current->id, 'time_slot_id' => $slot1->id, 'room_id' => $room->id]);
        $board->call('reassignDuty', $other->id, $off->id);
        $this->assertSame($off->id, $other->fresh()->teacher_id);
        $this->assertNotNull($free);
    }

    public function test_a_seat_cannot_be_dragged_into_a_room_that_is_off_for_its_slot(): void
    {
        $session = ExamSession::factory()->create();
        [$slot1, $slot2] = $this->threeSlots($session);
        $roomA = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 5]);
        $roomB = Room::factory()->for($session)->create(['rows' => 5, 'columns' => 5]);
        $roomB->unavailableSlots()->attach($slot2->id);

        $seatIn = function (TimeSlot $slot) use ($session, $roomA) {
            $enrollment = Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => Student::factory()->for($session), 'subject_id' => Subject::factory()->for($session)]);

            return SeatAssignment::create(['exam_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'time_slot_id' => $slot->id, 'room_id' => $roomA->id, 'row_number' => 1, 'column_number' => 1]);
        };
        $blocked = $seatIn($slot2);
        $allowed = $seatIn($slot1);

        $board = Livewire::actingAs($this->staff())->test(SeatingChart::class, ['examSession' => $session]);

        $board->call('moveSeat', $blocked->enrollment_id, $roomB->id, 2, 2)->assertSee('unavailable for this slot');
        $this->assertSame($roomA->id, $blocked->fresh()->room_id);

        $board->call('moveSeat', $allowed->enrollment_id, $roomB->id, 2, 2);
        $this->assertSame($roomB->id, $allowed->fresh()->room_id);
    }

    public function test_timetable_generation_keeps_a_subject_out_of_a_slot_whose_rooms_are_all_off(): void
    {
        $session = ExamSession::factory()->create(['respect_room_capacity' => true, 'seating_strategy' => 'strict']);
        $slotA = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-20', 'start_time' => '09:00']);
        $slotB = TimeSlot::factory()->create(['exam_session_id' => $session->id, 'date' => '2026-04-21', 'start_time' => '09:00']);
        $room = Room::factory()->for($session)->create(['rows' => 3, 'columns' => 1, 'capacity' => 3]);
        $room->unavailableSlots()->attach($slotA->id); // the earlier slot has no usable room

        $subject = Subject::factory()->for($session)->create();
        foreach (range(1, 3) as $i) {
            Enrollment::factory()->create(['exam_session_id' => $session->id, 'student_id' => Student::factory()->for($session), 'subject_id' => $subject->id]);
        }

        Livewire::actingAs($this->staff())->test(Timetable::class, ['examSession' => $session])->call('generateTimetable');

        $this->assertSame($slotB->id, SubjectSlotAssignment::where('subject_id', $subject->id)->value('time_slot_id'));
    }

    public function test_the_pin_dropdown_shows_each_slots_own_seat_capacity(): void
    {
        $session = ExamSession::factory()->create();
        [$slot1, $slot2] = $this->threeSlots($session);
        $roomA = Room::factory()->for($session)->create(['capacity' => 30, 'rows' => 6, 'columns' => 5]);
        Room::factory()->for($session)->create(['capacity' => 20, 'rows' => 4, 'columns' => 5]);
        $roomA->unavailableSlots()->attach($slot2->id);

        $page = Livewire::actingAs($this->staff())->test(Timetable::class, ['examSession' => $session]);

        $this->assertSame(50, $page->viewData('seatsAvailableTotal'));
        $this->assertSame(20, $page->viewData('seatsAvailableBySlot')->get($slot2->id));
        $this->assertNull($page->viewData('seatsAvailableBySlot')->get($slot1->id)); // untouched slots use the total
    }
}
